<?php
require_once $basePath . '/vendor/autoload.php';

use phpseclib3\Net\SSH2;
use RouterOS\Client;
use RouterOS\Config;
use RouterOS\Query;

$storeFile = $basePath . '/app/data/router.json';

function wl_read_store(string $file): array
{
    if (!file_exists($file)) {
        return [];
    }
    $raw = file_get_contents($file);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function wl_write_store(string $file, array $data): bool
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return (bool) file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

function wl_parse_terse_line(string $line): array
{
    $entry = [];
    if (preg_match_all('/(\\S+)=("([^"]*)"|\\S+)/', $line, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $key = $m[1] ?? '';
            $rawVal = $m[2] ?? '';
            $quotedVal = $m[3] ?? '';
            $val = $quotedVal !== '' ? $quotedVal : trim($rawVal, '"');
            if ($key !== '') {
                $entry[$key] = $val;
            }
        }
    }
    return $entry;
}

$errors = [];
$messages = [];
$fetchErrors = [];
$serverStatus = [];

$routers = wl_read_store($storeFile);
$apRouters = array_values(array_filter($routers, fn($r) => strtolower(trim($r['category'] ?? '')) === 'ap'));
$serverRouters = array_values(array_filter($routers, fn($r) => strtolower(trim($r['category'] ?? '')) === 'server'));

function wl_fetch_pppoe_data(array $servers, array &$fetchErrors, array &$serverStatus): array
{
    $active = [];
    $inactive = [];
    $summary = ['total' => 0, 'active' => 0, 'inactive' => 0];

    foreach ($servers as $router) {
        $addressRaw = trim($router['address'] ?? '');
        $username = $router['username'] ?? '';
        $password = $router['password'] ?? '';
        $name = $router['name'] ?? 'Router';

        if ($addressRaw === '' || $username === '' || $password === '') {
            $fetchErrors[] = "Lewati {$name}: kredensial belum lengkap.";
            $serverStatus[] = ['name' => $name, 'status' => 'error', 'message' => 'Kredensial belum lengkap'];
            continue;
        }

        $host = $addressRaw;
        $port = 22;
        if (strpos($addressRaw, ':') !== false) {
            [$hp, $pp] = explode(':', $addressRaw, 2);
            $host = $hp;
            $port = is_numeric($pp) ? (int) $pp : 22;
        }

        try {
            $ssh = new SSH2($host, $port);
            $ssh->setTimeout(3);
            if (!$ssh->login($username, $password)) {
                $fetchErrors[] = "Login gagal ke {$name} ({$host}:{$port}).";
                $serverStatus[] = ['name' => $name, 'status' => 'error', 'message' => 'Login gagal'];
                continue;
            }

            $secretOut = $ssh->exec('/ppp secret print detail without-paging terse');
            $secrets = [];
            if ($secretOut !== false && trim((string) $secretOut) !== '') {
                foreach (preg_split('/\r?\n/', trim((string) $secretOut)) as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $entry = wl_parse_terse_line($line);
                    $u = $entry['name'] ?? '';
                    if ($u !== '') {
                        $secrets[$u] = [
                            'user' => $u,
                            'profile' => $entry['profile'] ?? '',
                            'server' => $name,
                        ];
                    }
                }
            }

            $activeOut = $ssh->exec('/ppp active print detail without-paging terse');
            $activeUsers = [];
            if ($activeOut !== false && trim((string) $activeOut) !== '') {
                foreach (preg_split('/\r?\n/', trim((string) $activeOut)) as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $entry = wl_parse_terse_line($line);
                    $user = $entry['name'] ?? ($entry['user'] ?? '');
                    $row = [
                        'server' => $name,
                        'user' => $user,
                        'profile' => $entry['profile'] ?? ($secrets[$user]['profile'] ?? ''),
                        'remote' => $entry['address'] ?? '',
                        'caller' => $entry['caller-id'] ?? ($entry['caller'] ?? ''),
                    ];
                    $active[] = $row;
                    if ($user !== '') {
                        $activeUsers[$user] = true;
                    }
                }
            }

            foreach ($secrets as $u => $info) {
                if (!isset($activeUsers[$u])) {
                    $inactive[] = $info;
                }
            }

            $serverStatus[] = ['name' => $name, 'status' => 'ok', 'message' => 'Terhubung'];
            $summary['total'] += count($secrets);
            $summary['active'] += count($activeUsers);
            $summary['inactive'] += max(0, count($secrets) - count($activeUsers));
        } catch (Throwable $e) {
            $fetchErrors[] = "Gagal konek ke {$name}: " . $e->getMessage();
            continue;
        }
    }

    return ['active' => $active, 'inactive' => $inactive, 'summary' => $summary];
}

// Jika format=pppoe diminta, balas JSON ringkasan PPPoE (active+inactive) lalu hentikan render HTML
if (isset($_GET['format']) && $_GET['format'] === 'pppoe') {
    $pppoeData = wl_fetch_pppoe_data($serverRouters, $fetchErrors, $serverStatus);
    header('Content-Type: application/json');
    echo json_encode([
        'active' => $pppoeData['active'] ?? [],
        'inactive_users' => $pppoeData['inactive'] ?? [],
        'summary' => $pppoeData['summary'] ?? ['total' => 0, 'active' => 0, 'inactive' => 0],
        'errors' => $fetchErrors,
        'servers' => $serverStatus,
        'timestamp' => date('c'),
    ]);
    exit;
}

// Fetch wireless registration (client) per AP
function wl_fetch_registrations(array $aps, array &$errors, array &$statuses): array
{
    $data = [];
    foreach ($aps as $router) {
        $addressRaw = trim($router['address'] ?? '');
        $username = $router['username'] ?? '';
        $password = $router['password'] ?? '';
        $name = $router['name'] ?? 'Router';

        if ($addressRaw === '' || $username === '' || $password === '') {
            $errors[] = "Lewati {$name}: kredensial belum lengkap.";
            $statuses[] = ['name' => $name, 'status' => 'error', 'message' => 'Kredensial belum lengkap'];
            continue;
        }

        $host = $addressRaw;
        $port = 22;
        $apiHost = $addressRaw;
        $apiPort = 8728;
        if (strpos($addressRaw, ':') !== false) {
            [$hp, $pp] = explode(':', $addressRaw, 2);
            $host = $hp;
            $apiHost = $hp;
            if (is_numeric($pp)) {
                $port = (int) $pp;
                $apiPort = (int) $pp;
            }
        }

        $connected = false;

        // Coba API RouterOS (8728) lebih dulu
        $apiError = '';
        try {
            $cfg = new Config([
                'host' => $apiHost,
                'user' => $username,
                'pass' => $password,
                'port' => $apiPort,
                'timeout' => 3,
                'socket_timeout' => 5,
                'attempts' => 1,
            ]);
            $client = new Client($cfg);
            $query = new Query('/interface/wireless/registration-table/print');
            $resp = $client->query($query)->read();
            $clients = [];
            foreach ($resp as $row) {
                $clients[] = [
                    'interface' => $row['interface'] ?? '-',
                    'mac' => $row['mac-address'] ?? '-',
                    'rx' => $row['rx-rate'] ?? '-',
                    'tx' => $row['tx-rate'] ?? '-',
                    'signal' => $row['signal-strength'] ?? ($row['signal'] ?? '-'),
                    'uptime' => $row['uptime'] ?? '-',
                    'last_ip' => $row['last-ip'] ?? ($row['last-ip-address'] ?? ''),
                    'radio_name' => $row['radio-name'] ?? '',
                ];
            }
            $data[] = [
                'router' => $name,
                'address' => $addressRaw,
                'clients' => $clients,
            ];
            $statuses[] = ['name' => $name, 'status' => 'ok', 'message' => 'Terhubung (API)'];
            $connected = true;
        } catch (Throwable $e) {
            $apiError = "API gagal ke {$name}: " . $e->getMessage();
        }

        if ($connected) {
            continue;
        }

        // Fallback SSH
        try {
            $ssh = new SSH2($host, $port);
            $ssh->setTimeout(3);
            if (!$ssh->login($username, $password)) {
                $errors[] = $apiError !== '' ? $apiError : "Login SSH gagal ke {$name} ({$host}:{$port}).";
                $statuses[] = ['name' => $name, 'status' => 'error', 'message' => 'Login SSH gagal'];
                continue;
            }
            $out = $ssh->exec('/interface wireless registration-table print detail without-paging terse');
            if ($out === false || trim((string) $out) === '') {
                $errors[] = $apiError !== '' ? $apiError : "Tidak ada client dari {$name}.";
                $statuses[] = ['name' => $name, 'status' => 'error', 'message' => 'Tidak ada client'];
                continue;
            }
            $lines = preg_split('/\r?\n/', trim((string) $out));
            $clients = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $entry = wl_parse_terse_line($line);
                if (!empty($entry)) {
                    $clients[] = [
                        'interface' => $entry['interface'] ?? '-',
                        'mac' => $entry['mac-address'] ?? '-',
                        'rx' => $entry['rx-rate'] ?? '-',
                        'tx' => $entry['tx-rate'] ?? '-',
                        'signal' => $entry['signal-strength'] ?? ($entry['signal'] ?? '-'),
                        'uptime' => $entry['uptime'] ?? '-',
                        'last_ip' => $entry['last-ip'] ?? '',
                        'radio_name' => $entry['radio-name'] ?? '',
                    ];
                }
            }
            $data[] = [
                'router' => $name,
                'address' => $addressRaw,
                'clients' => $clients,
            ];
            $statuses[] = ['name' => $name, 'status' => 'ok', 'message' => 'Terhubung (SSH)'];
        } catch (Throwable $e) {
            $errMsg = "SSH gagal ke {$name} ({$host}:{$port}): " . $e->getMessage();
            $errors[] = $apiError !== '' ? $apiError : $errMsg;
            $statuses[] = ['name' => $name, 'status' => 'error', 'message' => $e->getMessage()];
            continue;
        }
    }
    return $data;
}

// JSON untuk registration
if (isset($_GET['format']) && $_GET['format'] === 'reg') {
    $regErrors = [];
    $regStatuses = [];
    $regData = wl_fetch_registrations($apRouters, $regErrors, $regStatuses);
    $summary = ['total' => 0, 'errors' => count($regErrors), 'routers' => count($apRouters)];
    foreach ($regData as $row) {
        $summary['total'] += count($row['clients'] ?? []);
    }
    header('Content-Type: application/json');
    echo json_encode([
        'data' => $regData,
        'errors' => $regErrors,
        'servers' => $regStatuses,
        'summary' => $summary,
        'timestamp' => date('c'),
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'add_router_from_pppoe') {
    $targetName = trim((string) ($_POST['name'] ?? ''));
    $targetAddress = trim((string) ($_POST['address'] ?? ''));
    $note = trim((string) ($_POST['note'] ?? 'Ditambahkan dari PPPoE active'));
    if ($targetName === '' || $targetAddress === '') {
        $errors[] = 'Nama dan alamat wajib diisi.';
    } else {
        $existing = wl_read_store($storeFile);
        $exists = false;
        foreach ($existing as $row) {
            if (strcasecmp($row['name'] ?? '', $targetName) === 0 || strcasecmp($row['address'] ?? '', $targetAddress) === 0) {
                $exists = true;
                break;
            }
        }
        if ($exists) {
            $errors[] = 'Router sudah ada di daftar.';
        } else {
            // set default cred (sesuai permintaan) agar langsung bisa dipakai untuk wireless
            $defaultUser = 'rondi';
            $defaultPass = '21184662';
            $existing[] = [
                'id' => uniqid('rt_', true),
                'name' => $targetName,
                'address' => $targetAddress,
                'username' => $defaultUser,
                'password' => $defaultPass,
                'category' => 'ap',
                'note' => $note,
            ];
            wl_write_store($storeFile, $existing);
            $messages[] = "Router {$targetName} disimpan.";
            $apRouters = array_values(array_filter($existing, fn($r) => strtolower(trim($r['category'] ?? '')) === 'ap'));
        }
    }
}

$addErrors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'add_router_manual') {
    $targetName = trim((string) ($_POST['name'] ?? ''));
    $targetAddress = trim((string) ($_POST['address'] ?? ''));
    $targetUser = trim((string) ($_POST['username'] ?? ''));
    $targetPass = trim((string) ($_POST['password'] ?? ''));
    $note = trim((string) ($_POST['note'] ?? ''));

    if ($targetName === '' || $targetAddress === '' || $targetUser === '' || $targetPass === '') {
        $addErrors[] = 'Nama, alamat, username, password wajib diisi.';
    } else {
        $existing = wl_read_store($storeFile);
        $exists = false;
        foreach ($existing as $row) {
            if (strcasecmp($row['name'] ?? '', $targetName) === 0 || strcasecmp($row['address'] ?? '', $targetAddress) === 0) {
                $exists = true;
                break;
            }
        }
        if ($exists) {
            $addErrors[] = 'Router sudah ada di daftar.';
        } else {
            $existing[] = [
                'id' => uniqid('rt_', true),
                'name' => $targetName,
                'address' => $targetAddress,
                'username' => $targetUser,
                'password' => $targetPass,
                'category' => 'ap',
                'note' => $note,
            ];
            wl_write_store($storeFile, $existing);
            $messages[] = "Router {$targetName} disimpan.";
            $apRouters = array_values(array_filter($existing, fn($r) => strtolower(trim($r['category'] ?? '')) === 'ap'));
        }
    }
}
?>

<section class="page-head">
  <div>
    <h1>Wireless Registration (AP)</h1>
    <p>Daftar client wireless per AP (kategori AP di router.json).</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <button class="btn" type="button" id="openAddManual">Add Router</button>
    <button class="btn" type="button" id="openAddRouter">Pilih dari PPPoE Active</button>
  </div>
</section>

<div class="panel" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px;">
  <button class="btn" type="button" id="regRefresh">Refresh</button>
  <label class="label" style="display:flex;gap:6px;align-items:center;">
    Filter
    <input id="regFilter" placeholder="cari router / radio / mac" style="padding:8px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--text);">
  </label>
  <label class="label" style="display:flex;gap:6px;align-items:center;">
    Auto reload (detik)
    <select id="regAuto" style="padding:8px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--text);">
      <option value="0">Off</option>
      <option value="5">5</option>
      <option value="10">10</option>
      <option value="30">30</option>
    </select>
  </label>
  <div class="label" id="regSummary">Memuat...</div>
</div>

<div class="label" id="regErrors" style="color:#f87171;margin: -6px 0 8px 0;"></div>
<div id="regCards" style="display:grid;gap:12px;"></div>

<?php if (!empty($errors)): ?>
  <div class="alert">
    <?php foreach ($errors as $err): ?>
      <div><?php echo htmlspecialchars($err); ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php if (!empty($messages)): ?>
  <div class="note">
    <?php foreach ($messages as $msg): ?>
      <div><?php echo htmlspecialchars($msg); ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="panel" style="margin-bottom:12px;">
  <h4>Router AP (router.json)</h4>
  <?php if (empty($apRouters)): ?>
    <div class="label">Belum ada router kategori AP.</div>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th>Nama</th>
          <th>Alamat</th>
          <th>Username</th>
          <th>Catatan</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($apRouters as $r): ?>
          <tr>
            <td><?php echo htmlspecialchars($r['name'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars($r['address'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars($r['username'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars($r['note'] ?? ''); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="modal-backdrop" id="addRouterModal">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="label">Pilih AP dari PPPoE Active</div>
        <h4>Pilih AP</h4>
        <div class="modal-subtitle">Filter berdasarkan identity/user atau IP. Klik item untuk simpan ke daftar AP.</div>
      </div>
      <button class="icon-button" type="button" data-addrouter-close>&times;</button>
    </div>
    <div class="modal-body">
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px;">
        <input id="addRouterFilter" placeholder="Filter identity atau IP..." style="flex:1;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--text);">
        <button class="btn" type="button" id="refreshPppoe">Segarkan</button>
      </div>
      <div class="label" id="addRouterStatus">Memuat...</div>
      <div id="addRouterList" style="max-height:360px;overflow-y:auto;display:grid;gap:8px;margin-top:8px;"></div>
      <div style="display:flex;justify-content:flex-end;margin-top:10px;">
        <button class="icon-button" type="button" data-addrouter-close>Tutup</button>
      </div>
      <form method="post" id="addRouterForm" style="display:none;">
        <input type="hidden" name="_action" value="add_router_from_pppoe">
        <input type="hidden" name="name" id="addRouterName">
        <input type="hidden" name="address" id="addRouterAddress">
        <input type="hidden" name="note" id="addRouterNote">
      </form>
    </div>
  </div>
</div>

<div class="modal-backdrop" id="addManualModal">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="label">Tambah Router AP</div>
        <h4>Input manual</h4>
      </div>
      <button class="icon-button" type="button" data-addmanual-close>&times;</button>
    </div>
    <div class="modal-body">
      <?php if (!empty($addErrors)): ?>
        <div class="alert">
          <?php foreach ($addErrors as $err): ?>
            <div><?php echo htmlspecialchars($err); ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <form method="post" class="report-list">
        <input type="hidden" name="_action" value="add_router_manual">
        <label>
          <div class="label">Nama</div>
          <input name="name" required style="width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--text);">
        </label>
        <label>
          <div class="label">Alamat / Host (bisa host:port)</div>
          <input name="address" required style="width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--text);">
        </label>
        <label>
          <div class="label">Username</div>
          <input name="username" required style="width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--text);">
        </label>
        <label>
          <div class="label">Password</div>
          <input type="password" name="password" required style="width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--text);">
        </label>
        <label>
          <div class="label">Catatan</div>
          <input name="note" style="width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--text);">
        </label>
        <div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;">
          <button class="icon-button" type="button" data-addmanual-close>Batal</button>
          <button class="btn" type="submit">Simpan</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
  document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('addRouterModal');
    const openBtn = document.getElementById('openAddRouter');
    const closeBtns = modal ? modal.querySelectorAll('[data-addrouter-close]') : [];
    const statusEl = document.getElementById('addRouterStatus');
    const listEl = document.getElementById('addRouterList');
    const filterInput = document.getElementById('addRouterFilter');
    const refreshBtn = document.getElementById('refreshPppoe');
    const form = document.getElementById('addRouterForm');
    const nameInput = document.getElementById('addRouterName');
    const addressInput = document.getElementById('addRouterAddress');
    const noteInput = document.getElementById('addRouterNote');
    const regCards = document.getElementById('regCards');
    const regSummary = document.getElementById('regSummary');
    const regErrors = document.getElementById('regErrors');
    const regRefresh = document.getElementById('regRefresh');
    const regFilter = document.getElementById('regFilter');
    const regAuto = document.getElementById('regAuto');
    const addManualModal = document.getElementById('addManualModal');
    const addManualOpen = document.getElementById('openAddManual');
    const addManualClose = addManualModal ? addManualModal.querySelectorAll('[data-addmanual-close]') : [];

    let rawData = [];
    let regData = [];
    let regTimer = null;

    function openModal() {
      if (!modal) return;
      modal.classList.add('open');
      document.body.classList.add('modal-open');
      loadData();
    }

    function closeModal() {
      if (!modal) return;
      modal.classList.remove('open');
      if (!document.querySelector('.modal-backdrop.open')) {
        document.body.classList.remove('modal-open');
      }
    }

    function renderList() {
      if (!listEl) return;
      const filterVal = (filterInput?.value || '').toLowerCase().trim();
      listEl.innerHTML = '';
      const filtered = rawData.filter((row) => {
        const blob = [row.server || '', row.user || '', row.remote || ''].join(' ').toLowerCase();
        return blob.includes(filterVal);
      });
      if (filtered.length === 0) {
        listEl.innerHTML = '<div class="label">Tidak ada data.</div>';
        return;
      }
      filtered.forEach((row) => {
        const card = document.createElement('div');
        card.className = 'card';
        card.style.display = 'grid';
        card.style.gridTemplateColumns = '1fr auto';
        card.style.gap = '8px';

        const left = document.createElement('div');
        left.innerHTML = `
          <div style="font-weight:700;">${row.user || '-'} <span class="label">(${row.remote || '-'})</span></div>
          <div class="label">${row.server || '-'}</div>
          <div class="label"><span style="color:#22c55e;font-weight:700;">AKTIF</span> ${row.profile ? ` ${row.profile}` : ''}</div>
        `;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn';
        btn.textContent = 'Pilih';
        btn.addEventListener('click', () => {
          if (!form) return;
          if (nameInput) nameInput.value = row.user || row.remote || 'AP';
          if (addressInput) addressInput.value = row.remote || '';
          if (noteInput) noteInput.value = `Dari PPPoE server ${row.server}`;
          form.submit();
        });

        card.appendChild(left);
        card.appendChild(btn);
        listEl.appendChild(card);
      });
    }

    async function loadData() {
      if (!statusEl) return;
      statusEl.textContent = 'Memuat...';
      listEl.innerHTML = '';
      try {
        const url = new URL(window.location.href);
        url.searchParams.set('page', 'wireless');
        url.searchParams.set('format', 'pppoe');
        const res = await fetch(url.toString(), { cache: 'no-store' });
        if (!res.ok) {
          statusEl.textContent = `Gagal memuat data (HTTP ${res.status})`;
          listEl.innerHTML = '<div class="label">Tidak ada data.</div>';
          return;
        }
        const json = await res.json();
        rawData = Array.isArray(json.active) ? json.active : [];

        const parts = [];
        if (Array.isArray(json.servers)) {
          json.servers.forEach((s) => {
            const ok = (s.status || '') === 'ok';
            parts.push(`<div>${s.name || 'server'}: <span style="color:${ok ? '#22c55e' : '#f87171'};">${ok ? 'OK' : (s.message || 'Gagal')}</span></div>`);
          });
        }
        if (Array.isArray(json.errors) && json.errors.length) {
          json.errors.forEach((e) => parts.push(`<div>${e}</div>`));
        }
        parts.push(`<div>Menemukan ${rawData.length} akun PPPoE.</div>`);
        statusEl.innerHTML = parts.join('');

        renderList();
      } catch (e) {
        statusEl.textContent = 'Gagal memuat data (periksa koneksi/API Mikrotik).';
        listEl.innerHTML = '<div class="label">Tidak ada data.</div>';
      }
    }

    if (openBtn) openBtn.addEventListener('click', openModal);
    closeBtns.forEach((btn) => btn.addEventListener('click', closeModal));
    if (modal) {
      modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
      });
    }
    if (filterInput) {
      filterInput.addEventListener('input', renderList);
    }
    if (refreshBtn) {
      refreshBtn.addEventListener('click', loadData);
    }

    function renderReg() {
      if (!regCards) return;
      const filterVal = (regFilter?.value || '').toLowerCase().trim();
      regCards.innerHTML = '';
      const filtered = regData.map((row) => {
        const keep = !filterVal || (row.router || '').toLowerCase().includes(filterVal) || (row.address || '').toLowerCase().includes(filterVal);
        if (!keep) {
          const clients = (row.clients || []).filter((c) => {
            const blob = [c.interface || '', c.mac || '', c.radio_name || ''].join(' ').toLowerCase();
            return blob.includes(filterVal);
          });
          return { ...row, clients };
        }
        return row;
      });

      filtered.forEach((row, idx) => {
        const card = document.createElement('div');
        card.className = 'reg-card';
        const clientCount = (row.clients || []).length;
        const head = document.createElement('div');
        head.className = 'reg-headline';
        head.innerHTML = `
          <div class="reg-title">${idx + 1}. ${row.router || '-'}</div>
          <div class="reg-sub">${clientCount} client${clientCount !== 1 ? 's' : ''}</div>
        `;
        card.appendChild(head);

        const wrapper = document.createElement('div');
        wrapper.className = 'reg-list';

        const headerRow = document.createElement('div');
        headerRow.className = 'reg-client head';
        headerRow.innerHTML = `
          <div>RADIO</div>
          <div>SIGNAL</div>
          <div>TX</div>
          <div>RX</div>
          <div>UPTIME</div>
          <div>MAC</div>
          <div>LAST IP</div>
          <div>RADIO NAME</div>
        `;
        wrapper.appendChild(headerRow);

        (row.clients || []).forEach((c) => {
          const item = document.createElement('div');
          item.className = 'reg-client';
          item.innerHTML = `
            <div>
              <div class="muted">RADIO</div>
              <div>${c.interface || '-'}</div>
            </div>
            <div>
              <div class="muted">SIGNAL</div>
              <div><span class="signal-badge">${c.signal || '-'}</span></div>
            </div>
            <div>
              <div class="muted">TX</div>
              <div>${c.tx || '-'}</div>
            </div>
            <div>
              <div class="muted">RX</div>
              <div>${c.rx || '-'}</div>
            </div>
            <div>
              <div class="muted">UPTIME</div>
              <div>${c.uptime || '-'}</div>
            </div>
            <div>
              <div class="muted">MAC</div>
              <div>${c.mac || '-'}</div>
            </div>
            <div>
              <div class="muted">LAST IP</div>
              <div>${c.last_ip || '-'}</div>
            </div>
            <div>
              <div class="muted">RADIO NAME</div>
              <div>${c.radio_name || '-'}</div>
            </div>
          `;
          wrapper.appendChild(item);
        });
        card.appendChild(wrapper);
        regCards.appendChild(card);
      });
    }

    async function loadReg() {
      if (regSummary) regSummary.textContent = 'Memuat...';
      if (regCards) regCards.innerHTML = '';
      if (regErrors) regErrors.textContent = '';
      try {
        const url = new URL(window.location.href);
        url.searchParams.set('page', 'wireless');
        url.searchParams.set('format', 'reg');
        const res = await fetch(url.toString(), { cache: 'no-store' });
        const json = await res.json();
        regData = Array.isArray(json.data) ? json.data : [];
        regData.sort((a, b) => {
          const ra = (a.router || '').toString().toLowerCase();
          const rb = (b.router || '').toString().toLowerCase();
          if (ra === rb) {
            return (a.address || '').toString().localeCompare((b.address || '').toString(), undefined, { sensitivity: 'base', numeric: true });
          }
          return ra.localeCompare(rb, undefined, { sensitivity: 'base', numeric: true });
        });
        const summary = json.summary || { total: 0, errors: 0 };
        if (regSummary) regSummary.textContent = `Memuat ${summary.total} client (${summary.errors} error)`;
        if (regErrors) {
          const list = Array.isArray(json.errors) ? json.errors : [];
          regErrors.innerHTML = list.length ? list.map((e) => `• ${e}`).join('<br>') : '';
        }
        if (!regData.length && regCards) {
          regCards.innerHTML = '<div class="label">Tidak ada data.</div>';
        }
        renderReg();
      } catch (e) {
        if (regSummary) regSummary.textContent = 'Gagal memuat data wireless';
        if (regErrors) regErrors.textContent = 'Periksa koneksi atau kredensial router AP.';
        if (regCards) regCards.innerHTML = '<div class="label">Tidak ada data.</div>';
      }
    }

    function setupRegAuto() {
      if (!regAuto) return;
      const val = parseInt(regAuto.value || '0', 10);
      if (regTimer) {
        clearInterval(regTimer);
        regTimer = null;
      }
      if (val > 0) {
        regTimer = setInterval(loadReg, val * 1000);
      }
    }

    if (regRefresh) regRefresh.addEventListener('click', loadReg);
    if (regFilter) regFilter.addEventListener('input', renderReg);
    if (regAuto) regAuto.addEventListener('change', setupRegAuto);

    if (addManualOpen && addManualModal) {
      addManualOpen.addEventListener('click', () => {
        addManualModal.classList.add('open');
        document.body.classList.add('modal-open');
      });
    }
    addManualClose.forEach((btn) => btn.addEventListener('click', () => {
      addManualModal.classList.remove('open');
      if (!document.querySelector('.modal-backdrop.open')) {
        document.body.classList.remove('modal-open');
      }
    }));

    // initial load
    loadReg();
    setupRegAuto();
  });
</script>

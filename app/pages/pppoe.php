<?php
require_once $basePath . '/vendor/autoload.php';

use phpseclib3\Net\SSH2;

$storeFile = $basePath . '/app/data/router.json';

function pppoe_read_store(string $file): array
{
    if (!file_exists($file)) {
        return [];
    }
    $raw = file_get_contents($file);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function pppoe_parse_output(string $raw): array
{
    $lines = preg_split('/\r?\n/', trim($raw));
    $parsed = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $entry = [];
        if (preg_match_all('/(\\S+)=("([^"]*)"|.+?)(?=\\s+\\S+=|$)/', $line, $matches, PREG_SET_ORDER)) {
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
        if (!empty($entry)) {
            $parsed[] = $entry;
        }
    }
    return $parsed;
}

function pppoe_uptime_seconds(string $uptime): int
{
    $uptime = trim($uptime);
    if ($uptime === '') {
        return 0;
    }
    $map = [
        'w' => 604800,
        'd' => 86400,
        'h' => 3600,
        'm' => 60,
        's' => 1,
    ];
    if (preg_match_all('/(\d+)([wdhms])/', $uptime, $matches, PREG_SET_ORDER)) {
        $seconds = 0;
        foreach ($matches as $m) {
            $seconds += ((int) ($m[1] ?? 0)) * ($map[$m[2] ?? 's'] ?? 0);
        }
        return $seconds;
    }
    if (preg_match('/^(\d+):(\d+):(\d+)$/', $uptime, $m)) {
        return ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + ((int) $m[3]);
    }
    return 0;
}

$routers = pppoe_read_store($storeFile);
$serverRouters = array_values(array_filter($routers, function ($r) {
    return strtolower(trim($r['category'] ?? '')) === 'server';
}));

$connections = [];
$errors = [];
$filterProfile = isset($_GET['profile']) ? trim((string) $_GET['profile']) : '';
$filterRouter = isset($_GET['router']) ? trim((string) $_GET['router']) : '';
$profileOptions = [];
$profileMap = [];

foreach ($serverRouters as $router) {
    $addressRaw = trim($router['address'] ?? '');
    $username = $router['username'] ?? '';
    $password = $router['password'] ?? '';
    $name = $router['name'] ?? 'Router';

    if ($addressRaw === '' || $username === '' || $password === '') {
        $errors[] = "Lewati {$name}: alamat/username/password belum lengkap.";
        continue;
    }

    $host = $addressRaw;
    $port = 22;
    if (strpos($addressRaw, ':') !== false) {
        [$hostPart, $portPart] = explode(':', $addressRaw, 2);
        $host = $hostPart;
        $port = is_numeric($portPart) ? (int) $portPart : 22;
    }

    try {
        $ssh = new SSH2($host, $port);
        $ssh->setTimeout(5);
        if (!$ssh->login($username, $password)) {
            $errors[] = "Login gagal ke {$name} ({$host}:{$port}).";
            continue;
        }

        // Ambil mapping profile dari PPP secret
        $secretProfiles = [];
        $secretOutput = $ssh->exec('/ppp secret print detail without-paging terse');
        if ($secretOutput !== false && trim((string) $secretOutput) !== '') {
            $parsedSecret = pppoe_parse_output((string) $secretOutput);
            foreach ($parsedSecret as $row) {
                $nm = $row['name'] ?? '';
                $pr = $row['profile'] ?? '';
                if ($nm !== '' && $pr !== '') {
                    $secretProfiles[$nm] = $pr;
                    $profileOptions[] = $pr;
                    $profileMap[strtolower($name)][] = $pr;
                }
            }
        }

        $output = $ssh->exec('/ppp active print detail without-paging terse');
        if ($output === false || trim((string) $output) === '') {
            $errors[] = "Tidak ada output PPPoE dari {$name}.";
            continue;
        }

        $parsed = pppoe_parse_output((string) $output);
        foreach ($parsed as $row) {
            $user = $row['name'] ?? ($row['user'] ?? '-');
            $profile = $row['profile'] ?? ($secretProfiles[$user] ?? '-');
            if ($profile !== '-' && $profile !== '') {
                $profileOptions[] = $profile;
                $profileMap[strtolower($name)][] = $profile;
            }
            $connections[] = [
                'router' => $name,
                'address' => $host,
                'user' => $user,
                'profile' => $profile,
                'caller' => $row['caller-id'] ?? ($row['caller'] ?? '-'),
                'remote' => $row['address'] ?? '-',
                'uptime' => $row['uptime'] ?? '-',
                'uptime_seconds' => pppoe_uptime_seconds($row['uptime'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        $errors[] = "Gagal konek ke {$name} ({$host}:{$port}): " . $e->getMessage();
        continue;
    }
}

$profileOptions = array_values(array_filter(array_unique(array_map('trim', $profileOptions))));
sort($profileOptions, SORT_NATURAL | SORT_FLAG_CASE);
if ($filterProfile !== '' && !in_array($filterProfile, $profileOptions, true)) {
    $profileOptions[] = $filterProfile;
    sort($profileOptions, SORT_NATURAL | SORT_FLAG_CASE);
}

// Normalisasi map profil per router
foreach ($profileMap as $rk => $list) {
    $unique = array_values(array_filter(array_unique(array_map('trim', $list))));
    sort($unique, SORT_NATURAL | SORT_FLAG_CASE);
    $profileMap[$rk] = $unique;
}
?>

<section class="page-head">
  <div>
    <h1>PPPoE Aktif</h1>
    <p>Menampilkan sesi PPPoE aktif dari router kategori <strong>server</strong>.</p>
  </div>
</section>

<?php if (!empty($errors)): ?>
  <div class="alert">
    <?php foreach ($errors as $err): ?>
      <div><?php echo htmlspecialchars($err); ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="cards">
  <div class="card">
    <h3>Router Server</h3>
    <div class="stat-value"><?php echo count($serverRouters); ?></div>
    <div class="label">Kategori = server</div>
  </div>
  <div class="card">
    <h3>PPPoE Aktif</h3>
    <div class="stat-value"><?php echo count($connections); ?></div>
    <div class="label">Total sesi</div>
  </div>
</div>

<?php if (empty($connections)): ?>
  <div class="label">Belum ada koneksi aktif atau koneksi ke router gagal.</div>
<?php else: ?>
  <div class="panel">
    <h4>Sesi PPPoE</h4>
    <form id="pppoeFilterForm" style="margin-bottom:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <label class="label" style="display:flex;gap:6px;align-items:center;">
        Router (kategori server):
        <select id="routerFilter" name="router" style="padding:8px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--text);">
          <option value="">Semua</option>
          <?php foreach ($serverRouters as $sr): ?>
            <?php $selected = strcasecmp($filterRouter, $sr['name'] ?? '') === 0 ? 'selected' : ''; ?>
            <option value="<?php echo htmlspecialchars($sr['name'] ?? ''); ?>" <?php echo $selected; ?>>
              <?php echo htmlspecialchars($sr['name'] ?? ''); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="label" style="display:flex;gap:6px;align-items:center;">
        Filter Profil:
        <select id="profileFilter" name="profile" style="padding:8px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--text);min-width:180px;">
          <option value="">Semua profil</option>
          <?php foreach ($profileOptions as $opt): ?>
            <?php $selected = strcasecmp($filterProfile, $opt) === 0 ? 'selected' : ''; ?>
            <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $selected; ?>>
              <?php echo htmlspecialchars($opt); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="label" style="display:flex;gap:6px;align-items:center;">
        Live Search:
        <input id="pppoeSearch" type="search" placeholder="Cari user/profil/ip" style="padding:8px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--text);min-width:200px;">
      </label>
      <a class="icon-button" href="?page=pppoe">Reset</a>
    </form>
    <table class="table" id="pppoeTable">
      <thead>
        <tr>
          <th>Router</th>
          <th>User</th>
          <th>
            <button type="button" class="icon-button" id="sortProfile">Profil</button>
          </th>
          <th>Caller</th>
          <th>Remote IP</th>
          <th>
            <button type="button" class="icon-button" id="sortUptime">Uptime</button>
          </th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($connections as $c): ?>
          <?php
            $searchBlob = strtolower(($c['router'] ?? '') . ' ' . ($c['user'] ?? '') . ' ' . ($c['profile'] ?? '') . ' ' . ($c['caller'] ?? '') . ' ' . ($c['remote'] ?? '') . ' ' . ($c['uptime'] ?? ''));
          ?>
          <tr
            data-router="<?php echo htmlspecialchars(strtolower($c['router'] ?? '')); ?>"
            data-profile="<?php echo htmlspecialchars(strtolower($c['profile'] ?? '')); ?>"
            data-profile-raw="<?php echo htmlspecialchars($c['profile'] ?? ''); ?>"
            data-uptime="<?php echo htmlspecialchars($c['uptime_seconds']); ?>"
            data-search="<?php echo htmlspecialchars($searchBlob); ?>"
          >
            <td><?php echo htmlspecialchars($c['router']); ?></td>
            <td><?php echo htmlspecialchars($c['user']); ?></td>
            <td class="text-wrap"><?php echo htmlspecialchars($c['profile']); ?></td>
            <td><?php echo htmlspecialchars($c['caller']); ?></td>
            <td>
              <?php if (!empty($c['remote']) && $c['remote'] !== '-'): ?>
                <a href="http://<?php echo rawurlencode($c['remote']); ?>" target="_blank" rel="noopener noreferrer">
                  <?php echo htmlspecialchars($c['remote']); ?>
                </a>
              <?php else: ?>
                -
              <?php endif; ?>
            </td>
            <td><?php echo htmlspecialchars($c['uptime']); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    const profileMap = <?php echo json_encode($profileMap, JSON_UNESCAPED_UNICODE); ?>;
    const profileSelect = document.getElementById('profileFilter');
    const searchInput = document.getElementById('pppoeSearch');
    const table = document.getElementById('pppoeTable');
    const rows = table ? Array.from(table.querySelectorAll('tbody tr')) : [];
    const routerSelect = document.getElementById('routerFilter');
    const sortProfileBtn = document.getElementById('sortProfile');
    const sortUptimeBtn = document.getElementById('sortUptime');
    let sortState = { field: 'uptime', dir: 'asc' };

    function rebuildProfileOptions() {
      if (!profileSelect) return;
      const routerVal = (routerSelect?.value || '').toLowerCase().trim();

      let profiles = [];
      if (routerVal && profileMap[routerVal]) {
        profiles = profileMap[routerVal];
      } else {
        const all = new Set();
        Object.values(profileMap).forEach((arr) => {
          arr.forEach((p) => all.add(p));
        });
        profiles = Array.from(all).sort((a, b) => a.localeCompare(b));
      }

      const current = (profileSelect.value || '').toLowerCase();
      profileSelect.innerHTML = '<option value=\"\">Semua profil</option>';
      profiles.forEach((label) => {
        const val = label.toLowerCase();
        const opt = document.createElement('option');
        opt.value = val;
        opt.textContent = label;
        if (val === current) {
          opt.selected = true;
        }
        profileSelect.appendChild(opt);
      });
    }

    function applyFilters() {
      const profileVal = (profileSelect?.value || '').toLowerCase().trim();
      const searchVal = (searchInput?.value || '').toLowerCase().trim();
      const routerVal = (routerSelect?.value || '').toLowerCase().trim();

      rows.forEach((row) => {
        const rowRouter = (row.dataset.router || '').toLowerCase();
        const rowProfile = (row.dataset.profile || '').toLowerCase();
        const rowSearch = (row.dataset.search || '').toLowerCase();

        const matchRouter = !routerVal || rowRouter === routerVal;
        const matchProfile = !profileVal || rowProfile === profileVal;
        const matchSearch = !searchVal || rowSearch.includes(searchVal);

        row.style.display = matchRouter && matchProfile && matchSearch ? '' : 'none';
      });
    }

    function onRouterChange() {
      rebuildProfileOptions();
      applyFilters();
    }

    function applySort(field) {
      if (!table) return;
      if (sortState.field === field) {
        sortState.dir = sortState.dir === 'asc' ? 'desc' : 'asc';
      } else {
        sortState.field = field;
        sortState.dir = 'asc';
      }

      const tbody = table.querySelector('tbody');
      const sorted = [...rows].sort((a, b) => {
        if (field === 'profile') {
          const pa = (a.dataset.profileRaw || '').toLowerCase();
          const pb = (b.dataset.profileRaw || '').toLowerCase();
          return sortState.dir === 'asc' ? pa.localeCompare(pb) : pb.localeCompare(pa);
        }
        const ua = parseFloat(a.dataset.uptime || '0');
        const ub = parseFloat(b.dataset.uptime || '0');
        return sortState.dir === 'asc' ? ua - ub : ub - ua;
      });

      tbody.innerHTML = '';
      sorted.forEach((r) => tbody.appendChild(r));
      applyFilters();

      if (sortProfileBtn) {
        sortProfileBtn.textContent = 'Profil ' + (sortState.field === 'profile' ? (sortState.dir === 'asc' ? '↑' : '↓') : '');
      }
      if (sortUptimeBtn) {
        sortUptimeBtn.textContent = 'Uptime ' + (sortState.field === 'uptime' ? (sortState.dir === 'asc' ? '↑' : '↓') : '');
      }
    }

    if (profileSelect) {
      profileSelect.addEventListener('change', applyFilters);
    }
    if (searchInput) {
      searchInput.addEventListener('input', applyFilters);
    }
    if (routerSelect) {
      routerSelect.addEventListener('change', onRouterChange);
    }
    if (sortProfileBtn) {
      sortProfileBtn.addEventListener('click', () => applySort('profile'));
    }
    if (sortUptimeBtn) {
      sortUptimeBtn.addEventListener('click', () => applySort('uptime'));
    }

    rebuildProfileOptions();
    applyFilters();
    applySort('uptime');
  });
</script>

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
$messages = [];
$filterProfile = isset($_GET['profile']) ? trim((string) $_GET['profile']) : '';
$filterRouter = isset($_GET['router']) ? trim((string) $_GET['router']) : '';
$profileOptions = [];
$profileMap = [];
$secrets = [];
$activeKeys = [];
$inactiveUsers = [];
$serverStats = [];

if (!function_exists('ros_quote')) {
    function ros_quote(string $val): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $val) . '"';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'update_pppoe_profile') {
    $targetRouter = trim((string) ($_POST['router'] ?? ''));
    $targetUser = trim((string) ($_POST['user'] ?? ''));
    $newProfile = trim((string) ($_POST['profile'] ?? ''));

    if ($targetRouter === '' || $targetUser === '' || $newProfile === '') {
        $errors[] = 'Router, user, dan profile wajib diisi.';
    } else {
        $routerData = null;
        foreach ($serverRouters as $r) {
            if (strcasecmp($r['name'] ?? '', $targetRouter) === 0) {
                $routerData = $r;
                break;
            }
        }

        if (!$routerData) {
            $errors[] = "Router {$targetRouter} tidak ditemukan atau bukan kategori server.";
        } else {
            $addressRaw = trim($routerData['address'] ?? '');
            $username = $routerData['username'] ?? '';
            $password = $routerData['password'] ?? '';
            if ($addressRaw === '' || $username === '' || $password === '') {
                $errors[] = "Kredensial router {$targetRouter} belum lengkap.";
            } else {
                $host = $addressRaw;
                $port = 22;
                if (strpos($addressRaw, ':') !== false) {
                    [$hp, $pp] = explode(':', $addressRaw, 2);
                    $host = $hp;
                    $port = is_numeric($pp) ? (int) $pp : 22;
                }

                try {
                    $ssh = new SSH2($host, $port);
                    $ssh->setTimeout(5);
                    if (!$ssh->login($username, $password)) {
                        $errors[] = "Login gagal ke {$targetRouter}.";
                    } else {
                        $setCmd = '/ppp secret set [find name=' . ros_quote($targetUser) . '] profile=' . ros_quote($newProfile);
                        $ssh->exec($setCmd);
                        $dropCmd = '/ppp active remove [find name=' . ros_quote($targetUser) . ']';
                        $ssh->exec($dropCmd);
                        $messages[] = "Profil {$targetUser} di {$targetRouter} diset ke {$newProfile} dan koneksi aktif di-drop.";
                    }
                } catch (Throwable $e) {
                    $errors[] = "Gagal update router {$targetRouter}: " . $e->getMessage();
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'add_pppoe_user') {
    $targetRouter = trim((string) ($_POST['router'] ?? ''));
    $targetUser = trim((string) ($_POST['username'] ?? ''));
    $targetProfile = trim((string) ($_POST['profile'] ?? ''));

    if ($targetRouter === '' || $targetUser === '' || $targetProfile === '') {
        $errors[] = 'Router, username, dan profile wajib diisi.';
    } else {
        $routerData = null;
        foreach ($serverRouters as $r) {
            if (strcasecmp($r['name'] ?? '', $targetRouter) === 0) {
                $routerData = $r;
                break;
            }
        }

        if (!$routerData) {
            $errors[] = "Router {$targetRouter} tidak ditemukan atau bukan kategori server.";
        } else {
            $addressRaw = trim($routerData['address'] ?? '');
            $username = $routerData['username'] ?? '';
            $password = $routerData['password'] ?? '';
            if ($addressRaw === '' || $username === '' || $password === '') {
                $errors[] = "Kredensial router {$targetRouter} belum lengkap.";
            } else {
                $host = $addressRaw;
                $port = 22;
                if (strpos($addressRaw, ':') !== false) {
                    [$hp, $pp] = explode(':', $addressRaw, 2);
                    $host = $hp;
                    $port = is_numeric($pp) ? (int) $pp : 22;
                }

                try {
                    $ssh = new SSH2($host, $port);
                    $ssh->setTimeout(5);
                    if (!$ssh->login($username, $password)) {
                        $errors[] = "Login gagal ke {$targetRouter}.";
                    } else {
                        $addCmd = '/ppp secret add name=' . ros_quote($targetUser) . ' password=' . ros_quote($targetUser) . ' profile=' . ros_quote($targetProfile) . ' service=any disabled=no';
                        $ssh->exec($addCmd);
                        $messages[] = "User PPPoE {$targetUser} ditambahkan ke {$targetRouter} (password=username, service=any).";
                    }
                } catch (Throwable $e) {
                    $errors[] = "Gagal tambah user di {$targetRouter}: " . $e->getMessage();
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'delete_pppoe_secret') {
    $targetRouter = trim((string) ($_POST['router'] ?? ''));
    $targetUser = trim((string) ($_POST['user'] ?? ''));

    if ($targetRouter === '' || $targetUser === '') {
        $errors[] = 'Router dan user wajib diisi untuk menghapus secret.';
    } else {
        $routerData = null;
        foreach ($serverRouters as $r) {
            if (strcasecmp($r['name'] ?? '', $targetRouter) === 0) {
                $routerData = $r;
                break;
            }
        }

        if (!$routerData) {
            $errors[] = "Router {$targetRouter} tidak ditemukan atau bukan kategori server.";
        } else {
            $addressRaw = trim($routerData['address'] ?? '');
            $username = $routerData['username'] ?? '';
            $password = $routerData['password'] ?? '';
            if ($addressRaw === '' || $username === '' || $password === '') {
                $errors[] = "Kredensial router {$targetRouter} belum lengkap.";
            } else {
                $host = $addressRaw;
                $port = 22;
                if (strpos($addressRaw, ':') !== false) {
                    [$hp, $pp] = explode(':', $addressRaw, 2);
                    $host = $hp;
                    $port = is_numeric($pp) ? (int) $pp : 22;
                }

                try {
                    $ssh = new SSH2($host, $port);
                    $ssh->setTimeout(5);
                    if (!$ssh->login($username, $password)) {
                        $errors[] = "Login gagal ke {$targetRouter}.";
                    } else {
                        $dropCmd = '/ppp active remove [find name=' . ros_quote($targetUser) . ']';
                        $ssh->exec($dropCmd);
                        $delCmd = '/ppp secret remove [find name=' . ros_quote($targetUser) . ']';
                        $ssh->exec($delCmd);
                        $messages[] = "Secret {$targetUser} di {$targetRouter} dihapus dan koneksi aktif di-drop.";
                    }
                } catch (Throwable $e) {
                    $errors[] = "Gagal hapus secret di {$targetRouter}: " . $e->getMessage();
                }
            }
        }
    }
}

foreach ($serverRouters as $router) {
    $serverName = $router['name'] ?? 'Router';
    if (!isset($serverStats[$serverName])) {
        $serverStats[$serverName] = ['total' => 0, 'active' => 0];
    }
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
                    $secrets[] = [
                        'router' => $name,
                        'user' => $nm,
                        'profile' => $pr,
                    ];
                    $serverStats[$serverName]['total']++;
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
            $activeKeys[strtolower($name) . '|' . strtolower($user)] = true;
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
            $serverStats[$serverName]['active']++;
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

// Hitung user tidak aktif (ada di secret tapi tidak aktif)
foreach ($secrets as $s) {
    $key = strtolower($s['router'] ?? '') . '|' . strtolower($s['user'] ?? '');
    if (!isset($activeKeys[$key])) {
        $inactiveUsers[] = $s;
    }
}

$totalUsers = count($secrets);
$totalActive = count($connections);
$totalInactive = count($inactiveUsers);

foreach ($serverStats as $name => $stat) {
    $inactive = ($stat['total'] ?? 0) - ($stat['active'] ?? 0);
    $serverStats[$name]['inactive'] = max(0, $inactive);
}

if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json');
    echo json_encode([
        'total_users' => $totalUsers,
        'total_active' => $totalActive,
        'total_inactive' => $totalInactive,
        'servers' => $serverStats,
        'active' => array_values(array_map(function ($row) {
            return [
                'router' => $row['router'] ?? '',
                'user' => $row['user'] ?? '',
                'profile' => $row['profile'] ?? '',
                'caller' => $row['caller'] ?? '',
                'remote' => $row['remote'] ?? '',
                'uptime' => $row['uptime'] ?? '',
                'uptime_seconds' => $row['uptime_seconds'] ?? 0,
            ];
        }, $connections)),
        'timestamp' => date('c'),
    ]);
    exit;
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
<?php if (!empty($messages)): ?>
  <div class="note">
    <?php foreach ($messages as $msg): ?>
      <div><?php echo htmlspecialchars($msg); ?></div>
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
    <div class="stat-value" id="cardActive"><?php echo $totalActive; ?></div>
    <div class="label">Total sesi</div>
  </div>
  <div class="card">
    <h3>Total User</h3>
    <div class="stat-value" id="cardTotal"><?php echo $totalUsers; ?></div>
    <div class="label">Dari PPP secret</div>
    <?php if (!empty($serverStats)): ?>
      <button class="btn" type="button" id="openServerStats">Detail per Server</button>
    <?php endif; ?>
  </div>
  <div class="card">
    <h3>Tidak Aktif</h3>
    <div class="stat-value" id="cardInactive"><?php echo $totalInactive; ?></div>
    <div class="label">Secret tidak ada sesi aktif</div>
    <?php if ($totalInactive > 0): ?>
      <button class="btn" type="button" id="openInactive">Lihat</button>
    <?php endif; ?>
  </div>
</div>

<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:10px 0;">
  <button class="icon-button" id="autoRefreshToggle">Auto Refresh: Off</button>
  <label class="label" style="display:flex;align-items:center;gap:6px;">
    Interval:
    <select id="autoRefreshInterval" style="padding:8px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--text);">
      <option value="1000">1 detik</option>
      <option value="2000">2 detik</option>
      <option value="3000">3 detik</option>
      <option value="4000">4 detik</option>
      <option value="5000" selected>5 detik</option>
    </select>
  </label>
  <span class="label">Auto refresh memuat ulang data dan uptime.</span>
  <button class="btn" type="button" id="openAddUser">+ Tambah PPPoE User</button>
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
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($connections as $c): ?>
          <?php
            $searchBlob = strtolower(($c['router'] ?? '') . ' ' . ($c['user'] ?? '') . ' ' . ($c['profile'] ?? '') . ' ' . ($c['caller'] ?? '') . ' ' . ($c['remote'] ?? '') . ' ' . ($c['uptime'] ?? ''));
          ?>
          <tr
            data-router="<?php echo htmlspecialchars(strtolower($c['router'] ?? '')); ?>"
            data-router-raw="<?php echo htmlspecialchars($c['router'] ?? ''); ?>"
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
            <td>
              <button
                type="button"
                class="btn ghost pppoe-edit"
                data-router="<?php echo htmlspecialchars($c['router'] ?? '', ENT_QUOTES); ?>"
                data-user="<?php echo htmlspecialchars($c['user'] ?? '', ENT_QUOTES); ?>"
                data-profile="<?php echo htmlspecialchars($c['profile'] ?? '', ENT_QUOTES); ?>"
              >Edit</button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<div class="modal-backdrop" id="pppoeModal">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="label">Edit PPPoE User</div>
        <h4 id="pppoeModalTitle">Edit Profile</h4>
        <div class="modal-subtitle">Pilih profil baru, koneksi aktif akan di-drop setelah simpan.</div>
      </div>
      <button class="icon-button" type="button" data-pppoe-close>&times;</button>
    </div>
    <div class="modal-body">
      <form method="post" class="report-list" id="pppoeEditForm">
        <input type="hidden" name="_action" id="pppoeAction" value="update_pppoe_profile">
        <input type="hidden" name="user" id="pppoeEditUser">

        <label>
          <div class="label">Router</div>
          <select id="pppoeEditRouter" name="router" style="width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--text);" required>
            <?php foreach ($serverRouters as $sr): ?>
              <option value="<?php echo htmlspecialchars($sr['name'] ?? ''); ?>">
                <?php echo htmlspecialchars($sr['name'] ?? ''); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>
          <div class="label">User</div>
          <input id="pppoeEditUserLabel" type="text" style="width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--text);" disabled>
        </label>

        <label>
          <div class="label">Profil</div>
          <select id="pppoeEditProfile" name="profile" style="width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--text);" required></select>
        </label>

        <div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;">
          <button class="icon-button" type="button" data-pppoe-close>Batal</button>
          <button class="icon-button" type="button" id="pppoeDeleteBtn" style="border-color:rgba(248,113,113,0.6);color:#fecdd3;">Hapus Secret</button>
          <button class="btn" type="submit">Simpan & Drop Koneksi</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal-backdrop" id="inactiveModal">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="label">User Tidak Aktif</div>
        <h4>PPP Secret tanpa sesi aktif</h4>
      </div>
      <button class="icon-button" type="button" data-inactive-close>&times;</button>
    </div>
      <div class="modal-body">
      <?php if (empty($inactiveUsers)): ?>
        <div class="label">Semua user sedang aktif.</div>
      <?php else: ?>
        <div class="label" style="margin-bottom:8px;">Klik profil untuk edit di tabel utama.</div>
        <table class="table">
          <thead>
            <tr>
              <th>Router</th>
              <th>User</th>
              <th>Profile</th>
              <th>Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($inactiveUsers as $u): ?>
              <tr>
                <td><?php echo htmlspecialchars($u['router'] ?? '-'); ?></td>
                <td><?php echo htmlspecialchars($u['user'] ?? '-'); ?></td>
                <td class="text-wrap"><?php echo htmlspecialchars($u['profile'] ?? '-'); ?></td>
                <td>
                  <button
                    type="button"
                    class="btn ghost pppoe-edit"
                    data-router="<?php echo htmlspecialchars($u['router'] ?? '', ENT_QUOTES); ?>"
                    data-user="<?php echo htmlspecialchars($u['user'] ?? '', ENT_QUOTES); ?>"
                    data-profile="<?php echo htmlspecialchars($u['profile'] ?? '', ENT_QUOTES); ?>"
                  >Edit/Hapus</button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="modal-backdrop" id="serverModal">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="label">Statistik Server</div>
        <h4>Total User per Server</h4>
      </div>
      <button class="icon-button" type="button" data-server-close>&times;</button>
    </div>
    <div class="modal-body">
      <?php if (empty($serverStats)): ?>
        <div class="label">Belum ada data server.</div>
      <?php else: ?>
        <table class="table">
          <thead>
            <tr>
              <th>Router</th>
              <th>Total User</th>
              <th>Aktif</th>
              <th>Tidak Aktif</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($serverStats as $name => $stat): ?>
              <tr>
                <td><?php echo htmlspecialchars($name); ?></td>
                <td><?php echo (int) ($stat['total'] ?? 0); ?></td>
                <td><?php echo (int) ($stat['active'] ?? 0); ?></td>
                <td><?php echo (int) ($stat['inactive'] ?? 0); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="modal-backdrop" id="addUserModal">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="label">Tambah PPPoE User</div>
        <h4>Tambah PPPoE User</h4>
        <div class="modal-subtitle">Password akan disamakan dengan username, service=any.</div>
      </div>
      <button class="icon-button" type="button" data-add-close>&times;</button>
    </div>
    <div class="modal-body">
      <form method="post" class="report-list" id="addUserForm">
        <input type="hidden" name="_action" value="add_pppoe_user">

        <label>
          <div class="label">Router (server)</div>
          <select id="addUserRouter" name="router" style="width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--text);" required>
            <?php foreach ($serverRouters as $sr): ?>
              <option value="<?php echo htmlspecialchars($sr['name'] ?? ''); ?>">
                <?php echo htmlspecialchars($sr['name'] ?? ''); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>
          <div class="label">Username</div>
          <input name="username" id="addUserName" style="width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--text);" placeholder="username" required>
        </label>

        <label>
          <div class="label">Profile</div>
          <select id="addUserProfile" name="profile" style="width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--text);" required></select>
        </label>

        <div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;">
          <button class="icon-button" type="button" data-add-close>Batal</button>
          <button class="btn" type="submit">Simpan</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    const profileMap = <?php echo json_encode($profileMap, JSON_UNESCAPED_UNICODE); ?>;
    const profileSelect = document.getElementById('profileFilter');
    const searchInput = document.getElementById('pppoeSearch');
    const table = document.getElementById('pppoeTable');
    let rows = table ? Array.from(table.querySelectorAll('tbody tr')) : [];
    const routerSelect = document.getElementById('routerFilter');
    const sortProfileBtn = document.getElementById('sortProfile');
    const sortUptimeBtn = document.getElementById('sortUptime');
    let sortState = { field: 'uptime', dir: 'asc' };
    const modal = document.getElementById('pppoeModal');
    const modalClose = modal ? modal.querySelectorAll('[data-pppoe-close]') : [];
    const editForm = document.getElementById('pppoeEditForm');
    const editRouterInput = document.getElementById('pppoeEditRouter');
    const editUserInput = document.getElementById('pppoeEditUser');
    const editUserLabel = document.getElementById('pppoeEditUserLabel');
    const editProfileSelect = document.getElementById('pppoeEditProfile');
    const actionInput = document.getElementById('pppoeAction');
    const deleteBtn = document.getElementById('pppoeDeleteBtn');
    const inactiveModal = document.getElementById('inactiveModal');
    const inactiveClose = inactiveModal ? inactiveModal.querySelectorAll('[data-inactive-close]') : [];
    const inactiveOpen = document.getElementById('openInactive');
    const serverModal = document.getElementById('serverModal');
    const serverClose = serverModal ? serverModal.querySelectorAll('[data-server-close]') : [];
    const serverOpen = document.getElementById('openServerStats');
    const autoRefreshToggle = document.getElementById('autoRefreshToggle');
    const autoRefreshInterval = document.getElementById('autoRefreshInterval');
    const addUserModal = document.getElementById('addUserModal');
    const addUserClose = addUserModal ? addUserModal.querySelectorAll('[data-add-close]') : [];
    const addUserOpen = document.getElementById('openAddUser');
    const addUserRouter = document.getElementById('addUserRouter');
    const addUserProfile = document.getElementById('addUserProfile');
    let refreshIntervalMs = parseInt(localStorage.getItem('pppoeAutoRefreshMs') || '5000', 10);
    let autoTimer = null;

    function closeModalElement(el) {
      if (!el) return;
      el.classList.remove('open');
      if (!document.querySelector('.modal-backdrop.open')) {
        document.body.classList.remove('modal-open');
      }
    }

    function openModalElement(el) {
      if (!el) return;
      document.querySelectorAll('.modal-backdrop.open').forEach((m) => m.classList.remove('open'));
      el.classList.add('open');
      document.body.classList.add('modal-open');
    }

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

    function buildModalProfiles(routerRaw, currentProfile) {
      if (!editProfileSelect) return;
      const key = (routerRaw || '').toLowerCase();
      const opts = profileMap[key] || [];
      editProfileSelect.innerHTML = '';
      if (!opts.length) {
        const opt = document.createElement('option');
        opt.value = currentProfile;
        opt.textContent = currentProfile || 'Profil tidak diketahui';
        opt.selected = true;
        editProfileSelect.appendChild(opt);
        return;
      }
      opts.forEach((p) => {
        const opt = document.createElement('option');
        opt.value = p;
        opt.textContent = p;
        if (p === currentProfile) opt.selected = true;
        editProfileSelect.appendChild(opt);
      });
    }

    function buildAddProfiles(routerRaw) {
      if (!addUserProfile) return;
      const key = (routerRaw || '').toLowerCase();
      const opts = profileMap[key] || [];
      addUserProfile.innerHTML = '';
      if (!opts.length) {
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = 'Profil belum ada';
        addUserProfile.appendChild(opt);
        return;
      }
      opts.forEach((p, idx) => {
        const opt = document.createElement('option');
        opt.value = p;
        opt.textContent = p;
        if (idx === 0) opt.selected = true;
        addUserProfile.appendChild(opt);
      });
    }

    function bindEditButtons() {
      document.querySelectorAll('.pppoe-edit').forEach((btn) => {
        btn.addEventListener('click', () => {
          const routerRaw = btn.dataset.router || '';
          const user = btn.dataset.user || '';
          const profile = btn.dataset.profile || '';

          if (actionInput) actionInput.value = 'update_pppoe_profile';
          if (editRouterInput) editRouterInput.value = routerRaw;
          if (editUserInput) editUserInput.value = user;
          if (editUserLabel) editUserLabel.value = user;

          buildModalProfiles(routerRaw, profile);

          openModalElement(modal);
        });
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

    function syncTable(activeList) {
      if (!table) return;
      const tbody = table.querySelector('tbody');
      if (!tbody) return;
      const esc = (s) =>
        (s || '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#39;');

      const currentMap = {};
      rows.forEach((r) => {
        currentMap[r.dataset.key || ''] = r;
      });

      const nextRows = [];
      activeList.forEach((c) => {
        const key = `${(c.router || '').toLowerCase()}|${(c.user || '').toLowerCase()}`;
        let tr = currentMap[key];
        const searchBlob = [
          c.router || '',
          c.user || '',
          c.profile || '',
          c.caller || '',
          c.remote || '',
          c.uptime || '',
        ]
          .join(' ')
          .toLowerCase();
        const remoteHtml = c.remote
          ? `<a href="http://${encodeURIComponent(c.remote)}" target="_blank" rel="noopener noreferrer">${esc(c.remote)}</a>`
          : '-';

        if (!tr) {
          tr = document.createElement('tr');
          tr.innerHTML = `
            <td></td>
            <td></td>
            <td class="text-wrap"></td>
            <td></td>
            <td></td>
            <td></td>
            <td>
              <button type="button" class="btn ghost pppoe-edit">Edit</button>
            </td>
          `;
          tbody.appendChild(tr);
        }

        const cells = tr.children;
        cells[0].textContent = c.router || '-';
        cells[1].textContent = c.user || '-';
        cells[2].textContent = c.profile || '-';
        cells[3].textContent = c.caller || '-';
        cells[4].innerHTML = remoteHtml;
        cells[5].textContent = c.uptime || '-';

        const btn = cells[6]?.querySelector('.pppoe-edit');
        if (btn) {
          btn.dataset.router = c.router || '';
          btn.dataset.user = c.user || '';
          btn.dataset.profile = c.profile || '';
        }

        tr.dataset.key = key;
        tr.dataset.router = (c.router || '').toLowerCase();
        tr.dataset.routerRaw = c.router || '';
        tr.dataset.profile = (c.profile || '').toLowerCase();
        tr.dataset.profileRaw = c.profile || '';
        tr.dataset.uptime = String(c.uptime_seconds || 0);
        tr.dataset.search = searchBlob;
        nextRows.push(tr);
      });

      // remove rows not in next list
      const nextKeys = new Set(nextRows.map((r) => r.dataset.key || ''));
      Object.keys(currentMap).forEach((k) => {
        if (!nextKeys.has(k) && currentMap[k].parentElement) {
          currentMap[k].parentElement.removeChild(currentMap[k]);
        }
      });

      rows = nextRows;
      bindEditButtons();
      applyFilters();
      applySort(sortState.field, false);
    }

    function onRouterChange() {
      rebuildProfileOptions();
      applyFilters();
    }

    function applySort(field, toggle = true) {
      if (!table) return;
      if (toggle && sortState.field === field) {
        sortState.dir = sortState.dir === 'asc' ? 'desc' : 'asc';
      } else if (toggle || sortState.field !== field) {
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

      const up = '↑';
      const down = '↓';
      if (sortProfileBtn) {
        sortProfileBtn.textContent = 'Profil ' + (sortState.field === 'profile' ? (sortState.dir === 'asc' ? up : down) : '');
      }
      if (sortUptimeBtn) {
        sortUptimeBtn.textContent = 'Uptime ' + (sortState.field === 'uptime' ? (sortState.dir === 'asc' ? up : down) : '');
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

    function buildModalProfiles(routerRaw, currentProfile) {
      if (!editProfileSelect) return;
      const key = (routerRaw || '').toLowerCase();
      const opts = profileMap[key] || [];
      editProfileSelect.innerHTML = '';
      if (!opts.length) {
        const opt = document.createElement('option');
        opt.value = currentProfile;
        opt.textContent = currentProfile || 'Profil tidak diketahui';
        opt.selected = true;
        editProfileSelect.appendChild(opt);
        return;
      }
      opts.forEach((p) => {
        const opt = document.createElement('option');
        opt.value = p;
        opt.textContent = p;
        if (p === currentProfile) opt.selected = true;
        editProfileSelect.appendChild(opt);
      });
    }

    document.querySelectorAll('.pppoe-edit').forEach((btn) => {
      btn.addEventListener('click', () => {
        const routerRaw = btn.dataset.router || '';
        const user = btn.dataset.user || '';
        const profile = btn.dataset.profile || '';

        if (actionInput) actionInput.value = 'update_pppoe_profile';
        if (editRouterInput) editRouterInput.value = routerRaw;
        if (editUserInput) editUserInput.value = user;
        if (editUserLabel) editUserLabel.value = user;

        buildModalProfiles(routerRaw, profile);

        closeModalElement(inactiveModal);
        openModalElement(modal);
      });
    });

    if (editRouterInput) {
      editRouterInput.addEventListener('change', () => {
        const currentProfile = editProfileSelect ? editProfileSelect.value : '';
        buildModalProfiles(editRouterInput.value, currentProfile);
      });
    }

    bindEditButtons();
    modalClose.forEach((btn) => {
      btn.addEventListener('click', () => {
        closeModalElement(modal);
      });
    });

    if (modal) {
      modal.addEventListener('click', (e) => {
        if (e.target === modal) {
          closeModalElement(modal);
        }
      });
    }
    if (deleteBtn && actionInput && editForm) {
      deleteBtn.addEventListener('click', () => {
        if (!confirm('Hapus secret user ini? Ini juga akan drop koneksi aktif.')) return;
        actionInput.value = 'delete_pppoe_secret';
        editForm.submit();
      });
    }
    if (inactiveOpen && inactiveModal) {
      inactiveOpen.addEventListener('click', () => {
        closeModalElement(modal);
        openModalElement(inactiveModal);
      });
    }
    inactiveClose.forEach((btn) => {
      btn.addEventListener('click', () => {
        closeModalElement(inactiveModal);
      });
    });
    if (inactiveModal) {
      inactiveModal.addEventListener('click', (e) => {
        if (e.target === inactiveModal) {
          closeModalElement(inactiveModal);
        }
      });
    }
    if (serverOpen && serverModal) {
      serverOpen.addEventListener('click', () => {
        closeModalElement(modal);
        closeModalElement(inactiveModal);
        openModalElement(serverModal);
      });
    }
    serverClose.forEach((btn) => {
      btn.addEventListener('click', () => {
        closeModalElement(serverModal);
      });
    });
    if (serverModal) {
      serverModal.addEventListener('click', (e) => {
        if (e.target === serverModal) {
          closeModalElement(serverModal);
        }
      });
    }

    if (addUserOpen && addUserModal) {
      addUserOpen.addEventListener('click', () => {
        const routerVal = addUserRouter ? addUserRouter.value : '';
        buildAddProfiles(routerVal);
        openModalElement(addUserModal);
        if (addUserRouter) addUserRouter.focus();
      });
    }
    addUserClose.forEach((btn) => {
      btn.addEventListener('click', () => {
        closeModalElement(addUserModal);
      });
    });
    if (addUserModal) {
      addUserModal.addEventListener('click', (e) => {
        if (e.target === addUserModal) {
          closeModalElement(addUserModal);
        }
      });
    }
    if (addUserRouter) {
      addUserRouter.addEventListener('change', () => buildAddProfiles(addUserRouter.value));
      buildAddProfiles(addUserRouter.value);
    }

    function updateAutoRefreshLabel(on) {
      if (!autoRefreshToggle) return;
      autoRefreshToggle.textContent = on ? 'Auto Refresh: On' : 'Auto Refresh: Off';
    }

    function stopAutoRefresh() {
      if (autoTimer) {
        clearInterval(autoTimer);
        autoTimer = null;
      }
      localStorage.setItem('pppoeAutoRefresh', 'off');
      updateAutoRefreshLabel(false);
    }

    async function doRefresh() {
      try {
        const params = new URLSearchParams(window.location.search);
        params.set('format', 'json');
        const res = await fetch(window.location.pathname + '?' + params.toString(), { cache: 'no-store' });
        if (!res.ok) {
          return;
        }
        const data = await res.json();
        const cardActive = document.getElementById('cardActive');
        const cardTotal = document.getElementById('cardTotal');
        const cardInactive = document.getElementById('cardInactive');
        if (cardActive && typeof data.total_active !== 'undefined') cardActive.textContent = data.total_active;
        if (cardTotal && typeof data.total_users !== 'undefined') cardTotal.textContent = data.total_users;
        if (cardInactive && typeof data.total_inactive !== 'undefined') cardInactive.textContent = data.total_inactive;
        if (Array.isArray(data.active)) {
          rebuildTable(data.active);
        }
      } catch (e) {
        // Biarkan auto refresh tetap jalan; mungkin koneksi sementara.
      }
    }

    function startAutoRefresh() {
      stopAutoRefresh();
      doRefresh();
      autoTimer = setInterval(() => {
        doRefresh();
      }, refreshIntervalMs);
      localStorage.setItem('pppoeAutoRefresh', 'on');
      localStorage.setItem('pppoeAutoRefreshMs', String(refreshIntervalMs));
      updateAutoRefreshLabel(true);
    }

    if (autoRefreshToggle) {
      const stored = localStorage.getItem('pppoeAutoRefresh');
      if (stored === 'on') {
        startAutoRefresh();
      } else {
        updateAutoRefreshLabel(false);
      }

      autoRefreshToggle.addEventListener('click', () => {
        if (localStorage.getItem('pppoeAutoRefresh') === 'on') {
          stopAutoRefresh();
        } else {
          startAutoRefresh();
        }
      });
    }

    if (autoRefreshInterval) {
      // Set default selected based on stored value
      autoRefreshInterval.value = String(refreshIntervalMs);
      autoRefreshInterval.addEventListener('change', () => {
        const val = parseInt(autoRefreshInterval.value || '5000', 10);
        refreshIntervalMs = isNaN(val) ? 5000 : val;
        localStorage.setItem('pppoeAutoRefreshMs', String(refreshIntervalMs));
        if (localStorage.getItem('pppoeAutoRefresh') === 'on') {
          startAutoRefresh();
        }
      });
    }

    rebuildProfileOptions();
    applyFilters();
    applySort('uptime');
  });
</script>

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
$sortDir = strtolower($_GET['sort'] ?? 'desc');
$sortDir = in_array($sortDir, ['asc', 'desc'], true) ? $sortDir : 'desc';

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

        $output = $ssh->exec('/ppp active print detail without-paging terse');
        if ($output === false || trim((string) $output) === '') {
            $errors[] = "Tidak ada output PPPoE dari {$name}.";
            continue;
        }

        $parsed = pppoe_parse_output((string) $output);
        foreach ($parsed as $row) {
            $connections[] = [
                'router' => $name,
                'address' => $host,
                'user' => $row['name'] ?? ($row['user'] ?? '-'),
                'service' => $row['service'] ?? '-',
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

if (!empty($connections)) {
    $uptimes = array_column($connections, 'uptime_seconds');
    $sortFlag = $sortDir === 'asc' ? SORT_ASC : SORT_DESC;
    array_multisort($uptimes, $sortFlag, SORT_NUMERIC, $connections);
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
    <div class="label">Klik kolom Uptime untuk urutkan (saat ini: <?php echo $sortDir === 'asc' ? 'pendek → panjang' : 'panjang → pendek'; ?>).</div>
    <table class="table">
      <thead>
        <tr>
          <th>Router</th>
          <th>User</th>
          <th>Service</th>
          <th>Caller</th>
          <th>Remote IP</th>
          <th>
            <a href="?page=pppoe&sort=<?php echo $sortDir === 'asc' ? 'desc' : 'asc'; ?>" style="color:inherit;">
              Uptime <?php echo $sortDir === 'asc' ? '↑' : '↓'; ?>
            </a>
          </th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($connections as $c): ?>
          <tr>
            <td><?php echo htmlspecialchars($c['router'] . ' (' . $c['address'] . ')'); ?></td>
            <td><?php echo htmlspecialchars($c['user']); ?></td>
            <td><?php echo htmlspecialchars($c['service']); ?></td>
            <td><?php echo htmlspecialchars($c['caller']); ?></td>
            <td><?php echo htmlspecialchars($c['remote']); ?></td>
            <td><?php echo htmlspecialchars($c['uptime']); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

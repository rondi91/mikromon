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

$routers = pppoe_read_store($storeFile);
$serverRouters = array_values(array_filter($routers, function ($r) {
    return strtolower(trim($r['category'] ?? '')) === 'server';
}));

$connections = [];
$errors = [];

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
            ];
        }
    } catch (Throwable $e) {
        $errors[] = "Gagal konek ke {$name} ({$host}:{$port}): " . $e->getMessage();
        continue;
    }
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
    <table class="table">
      <thead>
        <tr>
          <th>Router</th>
          <th>User</th>
          <th>Service</th>
          <th>Caller</th>
          <th>Remote IP</th>
          <th>Uptime</th>
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

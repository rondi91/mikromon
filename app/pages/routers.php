<?php
$storeFile = $basePath . '/app/data/router.json';

if (!function_exists('router_read_store')) {
    function router_read_store(string $file): array
    {
        if (!file_exists($file)) {
            return [];
        }
        $raw = file_get_contents($file);
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}

if (!function_exists('router_write_store')) {
    function router_write_store(string $file, array $data): bool
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return (bool) file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    }
}

$routers = router_read_store($storeFile);
$notice = null;
$editId = isset($_GET['edit']) ? (string) $_GET['edit'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_form'] ?? '') === 'router') {
    $method = strtoupper($_POST['_method'] ?? 'POST');
    $id = trim($_POST['id'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $note = trim($_POST['note'] ?? '');

    if ($method === 'POST') {
        if ($name === '' || $address === '') {
            $notice = ['type' => 'error', 'message' => 'Nama dan alamat wajib diisi.'];
        } else {
            $routers[] = [
                'id' => uniqid('rt_', true),
                'name' => $name,
                'address' => $address,
                'username' => $username,
                'note' => $note,
                'created_at' => date('c'),
            ];
            router_write_store($storeFile, $routers);
            $notice = ['type' => 'success', 'message' => 'Router ditambahkan.'];
        }
    } elseif ($method === 'PUT') {
        if ($id === '') {
            $notice = ['type' => 'error', 'message' => 'ID router tidak ditemukan.'];
        } else {
            $found = false;
            foreach ($routers as &$router) {
                if ($router['id'] === $id) {
                    $found = true;
                    $router['name'] = $name !== '' ? $name : $router['name'];
                    $router['address'] = $address !== '' ? $address : $router['address'];
                    $router['username'] = $username !== '' ? $username : $router['username'];
                    $router['note'] = $note !== '' ? $note : $router['note'];
                    $router['updated_at'] = date('c');

                    if ($router['name'] === '' || $router['address'] === '') {
                        $notice = ['type' => 'error', 'message' => 'Nama dan alamat tidak boleh kosong.'];
                    }
                    break;
                }
            }
            unset($router);

            if (!$found) {
                $notice = ['type' => 'error', 'message' => 'Data router tidak ditemukan.'];
            } elseif ($notice === null) {
                router_write_store($storeFile, $routers);
                $notice = ['type' => 'success', 'message' => 'Router diperbarui.'];
            }
        }
    } elseif ($method === 'DELETE') {
        if ($id === '') {
            $notice = ['type' => 'error', 'message' => 'ID router tidak ditemukan.'];
        } else {
            $before = count($routers);
            $routers = array_values(array_filter($routers, fn($row) => ($row['id'] ?? '') !== $id));
            if ($before === count($routers)) {
                $notice = ['type' => 'error', 'message' => 'Data router tidak ditemukan.'];
            } else {
                router_write_store($storeFile, $routers);
                $notice = ['type' => 'success', 'message' => 'Router dihapus.'];
            }
        }
    }

    // Refresh data setelah operasi
    $routers = router_read_store($storeFile);
}

$editItem = null;
if ($editId !== '') {
    foreach ($routers as $row) {
        if (($row['id'] ?? '') === $editId) {
            $editItem = $row;
            break;
        }
    }
}
?>

<section class="page-head">
  <div>
    <h1>Router Mikrotik</h1>
    <p>Kelola daftar router Mikrotik untuk provisioning/monitoring.</p>
  </div>
  <a class="btn" href="?page=routers">Reset Form</a>
</section>

<div class="note">
  Endpoint API JSON: <code>/public/router.php?resource=routers</code> (GET, POST, PUT, DELETE).
</div>

<?php if ($notice): ?>
  <div class="<?php echo $notice['type'] === 'error' ? 'alert' : 'note'; ?>">
    <?php echo htmlspecialchars($notice['message']); ?>
  </div>
<?php endif; ?>

<div class="panel-grid">
  <div class="panel">
    <h4><?php echo $editItem ? 'Ubah Router' : 'Tambah Router'; ?></h4>
    <form method="post" class="report-list">
      <input type="hidden" name="_form" value="router">
      <input type="hidden" name="_method" value="<?php echo $editItem ? 'PUT' : 'POST'; ?>">
      <?php if ($editItem): ?>
        <input type="hidden" name="id" value="<?php echo htmlspecialchars($editItem['id']); ?>">
      <?php endif; ?>

      <label>
        <div class="label">Nama Router</div>
        <input style="width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--text);" name="name" value="<?php echo htmlspecialchars($editItem['name'] ?? ''); ?>" required>
      </label>

      <label>
        <div class="label">Alamat / Host</div>
        <input style="width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--text);" name="address" value="<?php echo htmlspecialchars($editItem['address'] ?? ''); ?>" required placeholder="10.0.0.1:8728">
      </label>

      <label>
        <div class="label">Username (opsional)</div>
        <input style="width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--text);" name="username" value="<?php echo htmlspecialchars($editItem['username'] ?? ''); ?>">
      </label>

      <label>
        <div class="label">Catatan</div>
        <textarea style="width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--text);min-height:80px;" name="note"><?php echo htmlspecialchars($editItem['note'] ?? ''); ?></textarea>
      </label>

      <button class="btn" type="submit"><?php echo $editItem ? 'Simpan Perubahan' : 'Tambah Router'; ?></button>
    </form>
  </div>

  <div class="panel">
    <h4>Daftar Router</h4>
    <?php if (empty($routers)): ?>
      <div class="label">Belum ada data router.</div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>Nama</th>
            <th>Alamat</th>
            <th>Username</th>
            <th style="text-align:right;">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($routers as $row): ?>
            <tr>
              <td><?php echo htmlspecialchars($row['name'] ?? '-'); ?></td>
              <td><?php echo htmlspecialchars($row['address'] ?? '-'); ?></td>
              <td><?php echo htmlspecialchars($row['username'] ?? '-'); ?></td>
              <td style="text-align:right;" class="table-actions">
                <a class="btn ghost" href="?page=routers&edit=<?php echo urlencode($row['id']); ?>">Edit</a>
                <form method="post" style="display:inline;" onsubmit="return confirm('Hapus router ini?');">
                  <input type="hidden" name="_form" value="router">
                  <input type="hidden" name="_method" value="DELETE">
                  <input type="hidden" name="id" value="<?php echo htmlspecialchars($row['id']); ?>">
                  <button class="btn ghost" type="submit">Hapus</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

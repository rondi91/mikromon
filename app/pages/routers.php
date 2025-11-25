<?php
$storeFile = $basePath . '/app/data/router.json';
$categoryFile = $basePath . '/app/data/category.json';

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
$categories = router_read_store($categoryFile);
$categories = is_array($categories) ? array_values(array_filter(array_map('trim', $categories))) : [];
foreach ($routers as $r) {
    $cat = trim($r['category'] ?? '');
    if ($cat !== '' && !in_array($cat, $categories, true)) {
        $categories[] = $cat;
    }
}
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_form'] ?? '') === 'router') {
    $method = strtoupper($_POST['_method'] ?? 'POST');
    $id = trim($_POST['id'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $newCategory = trim($_POST['new_category'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $note = trim($_POST['note'] ?? '');

    $finalCategory = $newCategory !== '' ? $newCategory : $category;
    if ($newCategory !== '' && !in_array($newCategory, $categories, true)) {
        $categories[] = $newCategory;
        router_write_store($categoryFile, $categories);
    }

    if ($method === 'POST') {
        if ($name === '' || $address === '') {
            $notice = ['type' => 'error', 'message' => 'Nama dan alamat wajib diisi.'];
        } else {
            $routers[] = [
                'id' => uniqid('rt_', true),
                'name' => $name,
                'address' => $address,
                'username' => $username,
                'password' => $password,
                'category' => $finalCategory,
                'location' => $location,
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
                    $router['password'] = $password !== '' ? $password : ($router['password'] ?? '');
                    if ($finalCategory !== '') {
                        $router['category'] = $finalCategory;
                    }
                    if ($location !== '') {
                        $router['location'] = $location;
                    }
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
    $categories = router_read_store($categoryFile);
    $categories = is_array($categories) ? array_values(array_filter(array_map('trim', $categories))) : [];
    foreach ($routers as $r) {
        $cat = trim($r['category'] ?? '');
        if ($cat !== '' && !in_array($cat, $categories, true)) {
            $categories[] = $cat;
        }
    }
}
?>

<section class="page-head">
  <div>
    <h1>Router Mikrotik</h1>
    <p>Kelola daftar router Mikrotik untuk provisioning/monitoring.</p>
  </div>
  <button class="btn" type="button" id="openAddRouter">+ Tambah Router</button>
</section>

<div class="note">
  Endpoint API JSON: <code>/public/router.php?resource=routers</code> (GET, POST, PUT, DELETE) dengan field <code>name, address, username, password, category, location, note</code>. <br>
  Kategori: <code>/public/router.php?resource=categories</code> (GET, POST name).
</div>

<?php if ($notice): ?>
  <div class="<?php echo $notice['type'] === 'error' ? 'alert' : 'note'; ?>">
    <?php echo htmlspecialchars($notice['message']); ?>
  </div>
<?php endif; ?>

<div class="panel-grid">
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
            <th>Kategori</th>
            <th>Lokasi</th>
            <th>Password</th>
            <th style="text-align:right;">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($routers as $row): ?>
            <tr>
              <td><?php echo htmlspecialchars($row['name'] ?? '-'); ?></td>
              <td><?php echo htmlspecialchars($row['address'] ?? '-'); ?></td>
              <td><?php echo htmlspecialchars($row['username'] ?? '-'); ?></td>
              <td><?php echo htmlspecialchars($row['category'] ?? '-'); ?></td>
              <td><?php echo htmlspecialchars($row['location'] ?? '-'); ?></td>
              <td><?php echo isset($row['password']) && $row['password'] !== '' ? '****' : '-'; ?></td>
              <td style="text-align:right;" class="table-actions">
                <button
                  type="button"
                  class="btn ghost router-edit"
                  data-id="<?php echo htmlspecialchars($row['id'] ?? '', ENT_QUOTES); ?>"
                  data-name="<?php echo htmlspecialchars($row['name'] ?? '', ENT_QUOTES); ?>"
                  data-address="<?php echo htmlspecialchars($row['address'] ?? '', ENT_QUOTES); ?>"
                  data-username="<?php echo htmlspecialchars($row['username'] ?? '', ENT_QUOTES); ?>"
                  data-category="<?php echo htmlspecialchars($row['category'] ?? '', ENT_QUOTES); ?>"
                  data-location="<?php echo htmlspecialchars($row['location'] ?? '', ENT_QUOTES); ?>"
                  data-note="<?php echo htmlspecialchars($row['note'] ?? '', ENT_QUOTES); ?>"
                >Edit</button>
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

<div class="modal-backdrop" id="routerModal">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="label">Router Mikrotik</div>
        <h4 id="routerModalTitle">Tambah Router</h4>
        <div class="modal-subtitle">Isi detail router lalu simpan.</div>
      </div>
      <button class="icon-button" type="button" data-modal-close>&times;</button>
    </div>
    <div class="modal-body">
      <form method="post" class="report-list" id="routerForm">
        <input type="hidden" name="_form" value="router">
        <input type="hidden" name="_method" id="routerMethod" value="POST">
        <input type="hidden" name="id" id="routerId" value="">

        <label>
          <div class="label">Nama Router</div>
          <input id="routerName" style="width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--text);" name="name" value="" required>
        </label>

        <label>
          <div class="label">Alamat / Host</div>
          <input id="routerAddress" style="width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--text);" name="address" value="" required placeholder="10.0.0.1:8728">
        </label>

        <label>
          <div class="label">Username (opsional)</div>
          <input id="routerUsername" style="width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--text);" name="username" value="">
        </label>

        <label>
          <div class="label">Password (opsional)</div>
          <input id="routerPassword" type="password" style="width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--text);" name="password" value="" placeholder="Kosongkan jika tidak diubah">
        </label>

        <label>
          <div class="label">Kategori</div>
          <select id="routerCategory" name="category" style="width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--text);">
            <option value="">Pilih kategori</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?php echo htmlspecialchars($cat); ?>">
                <?php echo htmlspecialchars($cat); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="label">atau buat baru:</div>
          <input id="routerNewCategory" style="width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--text);" name="new_category" value="" placeholder="Tambah kategori baru">
        </label>

        <label>
          <div class="label">Lokasi</div>
          <input id="routerLocation" style="width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--text);" name="location" value="" placeholder="Gudang / Site / Rack">
        </label>

        <label>
          <div class="label">Catatan</div>
          <textarea id="routerNote" style="width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,0.04);color:var(--text);min-height:80px;" name="note"></textarea>
        </label>

        <div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;">
          <button class="icon-button" type="button" data-modal-close>Batal</button>
          <button class="btn" type="submit" id="routerSubmitLabel">Tambah Router</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('routerModal');
    const openBtn = document.getElementById('openAddRouter');
    const form = document.getElementById('routerForm');
    const title = document.getElementById('routerModalTitle');
    const submitLabel = document.getElementById('routerSubmitLabel');
    const methodInput = document.getElementById('routerMethod');
    const idInput = document.getElementById('routerId');
    const nameInput = document.getElementById('routerName');
    const addressInput = document.getElementById('routerAddress');
    const usernameInput = document.getElementById('routerUsername');
    const passwordInput = document.getElementById('routerPassword');
    const categorySelect = document.getElementById('routerCategory');
    const newCategoryInput = document.getElementById('routerNewCategory');
    const locationInput = document.getElementById('routerLocation');
    const noteInput = document.getElementById('routerNote');

    const closeButtons = modal.querySelectorAll('[data-modal-close]');

    function openModal(mode, data = {}) {
      modal.classList.add('open');
      document.body.classList.add('modal-open');
      const isEdit = mode === 'edit';
      title.textContent = isEdit ? 'Edit Router' : 'Tambah Router';
      submitLabel.textContent = isEdit ? 'Simpan Perubahan' : 'Tambah Router';
      methodInput.value = isEdit ? 'PUT' : 'POST';
      idInput.value = isEdit ? (data.id || '') : '';
      nameInput.value = data.name || '';
      addressInput.value = data.address || '';
      usernameInput.value = data.username || '';
      passwordInput.value = '';
      categorySelect.value = data.category || '';
      newCategoryInput.value = '';
      locationInput.value = data.location || '';
      noteInput.value = data.note || '';
    }

    function closeModal() {
      modal.classList.remove('open');
      document.body.classList.remove('modal-open');
      form.reset();
      methodInput.value = 'POST';
      idInput.value = '';
    }

    if (openBtn) {
      openBtn.addEventListener('click', function () {
        openModal('add');
      });
    }

    document.querySelectorAll('.router-edit').forEach(function (btn) {
      btn.addEventListener('click', function () {
        openModal('edit', btn.dataset);
      });
    });

    closeButtons.forEach(function (btn) {
      btn.addEventListener('click', closeModal);
    });

    modal.addEventListener('click', function (event) {
      if (event.target === modal) {
        closeModal();
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && modal.classList.contains('open')) {
        closeModal();
      }
    });
  });
</script>

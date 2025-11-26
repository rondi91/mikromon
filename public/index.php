<?php
$basePath = dirname(__DIR__);

$pages = [
    'dashboard' => ['title' => 'Dashboard'],
    'users' => ['title' => 'Pengguna'],
    'routers' => ['title' => 'Router Mikrotik'],
    'pppoe' => ['title' => 'PPPoE Aktif'],
    'wireless' => ['title' => 'Wireless AP'],
    'reports' => ['title' => 'Laporan'],
];

$page = $_GET['page'] ?? 'dashboard';
$notFound = false;

if (!array_key_exists($page, $pages)) {
    $page = 'dashboard';
    $notFound = true;
}

$pageTitle = $pages[$page]['title'];
$pageFile = $basePath . '/app/pages/' . $page . '.php';

if (!file_exists($pageFile)) {
    $page = 'dashboard';
    $pageTitle = $pages[$page]['title'];
    $pageFile = $basePath . '/app/pages/' . $page . '.php';
    $notFound = true;
}

// Jika permintaan format JSON khusus halaman wireless, balas sebelum render HTML
if (
    $page === 'wireless'
    && isset($_GET['format'])
    && in_array($_GET['format'], ['pppoe', 'reg'], true)
) {
    include $pageFile;
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> — Mikromon Admin</title>
    <link rel="stylesheet" href="assets/css/style.css">
  </head>
  <body>
    <div class="app">
      <?php include $basePath . '/app/partials/header.php'; ?>
      <div class="layout">
        <?php include $basePath . '/app/partials/sidebar.php'; ?>
        <main class="content">
          <?php include $pageFile; ?>
        </main>
      </div>
    </div>
    <script src="assets/js/main.js"></script>
  </body>
</html>

<?php
// Router sederhana untuk JSON API (menu + CRUD routers Mikrotik)

$basePath = dirname(__DIR__);
$storeFile = $basePath . '/app/data/router.json';
$categoryFile = $basePath . '/app/data/category.json';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$pages = [
    ['key' => 'dashboard', 'title' => 'Dashboard'],
    ['key' => 'users', 'title' => 'Pengguna'],
    ['key' => 'routers', 'title' => 'Router Mikrotik'],
    ['key' => 'pppoe', 'title' => 'PPPoE Aktif'],
    ['key' => 'reports', 'title' => 'Laporan'],
];

function json_response(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function read_store(string $file): array
{
    if (!file_exists($file)) {
        return [];
    }
    $raw = file_get_contents($file);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function write_store(string $file, array $data): bool
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return (bool) file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

$resource = $_GET['resource'] ?? '';
$target = $resource === 'items' ? 'routers' : $resource;
$method = $_SERVER['REQUEST_METHOD'];
$body = file_get_contents('php://input');
$payload = $body ? json_decode($body, true) : [];

if ($resource === 'menu' && $method === 'GET') {
    json_response(200, ['data' => $pages]);
}

$categories = read_store($categoryFile);
$categories = is_array($categories) ? array_values(array_filter(array_map('trim', $categories))) : [];

if ($resource === 'categories') {
    if ($method === 'GET') {
        json_response(200, ['data' => $categories]);
    }

    if ($method === 'POST') {
        $name = trim($payload['name'] ?? '');
        if ($name === '') {
            json_response(400, ['error' => 'name wajib diisi']);
        }

        $exists = false;
        foreach ($categories as $cat) {
            if (strcasecmp($cat, $name) === 0) {
                $exists = true;
                $name = $cat;
                break;
            }
        }

        if (!$exists) {
            $categories[] = $name;
            write_store($categoryFile, $categories);
        }

        json_response(201, ['data' => $categories]);
    }

    json_response(405, ['error' => 'Metode tidak diizinkan']);
}

if ($target !== 'routers') {
    json_response(404, ['error' => 'Resource tidak ditemukan']);
}

$items = read_store($storeFile);

if ($method === 'GET') {
    json_response(200, ['data' => $items]);
}

if ($method === 'POST') {
    $name = trim($payload['name'] ?? '');
    $address = trim($payload['address'] ?? '');
    $username = trim($payload['username'] ?? '');
    $password = trim($payload['password'] ?? '');
    $category = trim($payload['category'] ?? '');
    $location = trim($payload['location'] ?? '');
    $note = trim($payload['note'] ?? '');

    if ($name === '' || $address === '') {
        json_response(400, ['error' => 'name dan address wajib diisi']);
    }

    if ($category !== '') {
        $exists = false;
        foreach ($categories as $cat) {
            if (strcasecmp($cat, $category) === 0) {
                $exists = true;
                $category = $cat;
                break;
            }
        }
        if (!$exists) {
            $categories[] = $category;
            write_store($categoryFile, $categories);
        }
    }

    $new = [
        'id' => uniqid('rt_', true),
        'name' => $name,
        'address' => $address,
        'username' => $username,
        'password' => $password,
        'category' => $category,
        'location' => $location,
        'note' => $note,
        'created_at' => date('c'),
    ];
    $items[] = $new;

    write_store($storeFile, $items);
    json_response(201, ['data' => $new]);
}

if (in_array($method, ['PUT', 'PATCH'], true)) {
    $id = $_GET['id'] ?? '';
    if ($id === '') {
        json_response(400, ['error' => 'id wajib di query string']);
    }

    $found = false;
    foreach ($items as &$item) {
        if ($item['id'] === $id) {
            $found = true;
            $item['name'] = trim($payload['name'] ?? $item['name']);
            $item['address'] = trim($payload['address'] ?? $item['address']);
            $item['username'] = trim($payload['username'] ?? $item['username']);
            $newPassword = trim($payload['password'] ?? '');
            $item['password'] = $newPassword !== '' ? $newPassword : ($item['password'] ?? '');
            $newCategory = trim($payload['category'] ?? '');
            if ($newCategory !== '') {
                $exists = false;
                foreach ($categories as $cat) {
                    if (strcasecmp($cat, $newCategory) === 0) {
                        $exists = true;
                        $newCategory = $cat;
                        break;
                    }
                }
                if (!$exists) {
                    $categories[] = $newCategory;
                    write_store($categoryFile, $categories);
                }
                $item['category'] = $newCategory;
            }
            $newLocation = trim($payload['location'] ?? '');
            if ($newLocation !== '') {
                $item['location'] = $newLocation;
            }
            $item['note'] = trim($payload['note'] ?? $item['note']);
            $item['updated_at'] = date('c');

            if ($item['name'] === '' || $item['address'] === '') {
                json_response(400, ['error' => 'name dan address wajib diisi']);
            }
            break;
        }
    }
    unset($item);

    if (!$found) {
        json_response(404, ['error' => 'Data tidak ditemukan']);
    }

    write_store($storeFile, $items);
    json_response(200, ['data' => $items]);
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if ($id === '') {
        json_response(400, ['error' => 'id wajib di query string']);
    }

    $before = count($items);
    $items = array_values(array_filter($items, fn($row) => ($row['id'] ?? '') !== $id));

    if ($before === count($items)) {
        json_response(404, ['error' => 'Data tidak ditemukan']);
    }

    write_store($storeFile, $items);
    json_response(200, ['message' => 'Berhasil dihapus']);
}

json_response(405, ['error' => 'Metode tidak diizinkan']);

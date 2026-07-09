<?php
// AJAX endpoint: remove product image keyed by product name (or product id lookup).

declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not authorized']);
    exit;
}

function ds_slug(string $name): string {
    $s = strtolower($name);
    $s = preg_replace('/[^a-z0-9]+/i', '-', $s) ?? '';
    $s = trim($s, '-');
    $s = preg_replace('/-+/', '-', $s) ?? '';
    if ($s === '') {
        $s = 'product';
    }
    return $s;
}

$productName = isset($_POST['product_name']) ? trim((string)$_POST['product_name']) : '';
$productId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
if ($productName === '' && $productId > 0) {
    $prod = getProductById($productId);
    if ($prod && !empty($prod['name'])) {
        $productName = (string)$prod['name'];
    }
}

if ($productName === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing product name']);
    exit;
}

$slug = ds_slug($productName);
$dir = __DIR__ . '/../assets/uploads/products';
$exts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
$removed = 0;

if (is_dir($dir)) {
    foreach ($exts as $ext) {
        $path = $dir . '/' . $slug . '.' . $ext;
        if (is_file($path) && @unlink($path)) {
            $removed++;
        }
    }
}

echo json_encode([
    'success' => true,
    'slug' => $slug,
    'removed' => $removed,
]);
exit;

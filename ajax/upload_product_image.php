<?php
// AJAX endpoint: upload product image keyed by product name (shared across same names)
// Saves to assets/uploads/products/{slugified_name}.{ext}

declare(strict_types=1);

// Strictly return JSON
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Only allow when an admin/manager is logged in
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not authorized']);
    exit;
}

// Helper to slugify product name for filename use
function ds_slug(string $name): string {
    $s = strtolower($name);
    // Replace non-alphanumeric with hyphens
    $s = preg_replace('/[^a-z0-9]+/i', '-', $s) ?? '';
    $s = trim($s, '-');
    // collapse multiple hyphens
    $s = preg_replace('/-+/', '-', $s) ?? '';
    if ($s === '') $s = 'product';
    return $s;
}

// Validate inputs
$productName = isset($_POST['product_name']) ? trim((string)$_POST['product_name']) : '';
$productId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
if ($productName === '' && $productId > 0) {
    // Try to look up by id if name wasn't provided
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

if (!isset($_FILES['image'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No file provided']);
    exit;
}

$file = $_FILES['image'];
if (!empty($file['error'])) {
    $err = (int)$file['error'];
    $map = [
        UPLOAD_ERR_INI_SIZE => 'File too large (server limit)',
        UPLOAD_ERR_FORM_SIZE => 'File too large',
        UPLOAD_ERR_PARTIAL => 'Upload incomplete',
        UPLOAD_ERR_NO_FILE => 'No file uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Server temp dir missing',
        UPLOAD_ERR_CANT_WRITE => 'Server failed to write file',
        UPLOAD_ERR_EXTENSION => 'Upload blocked by extension'
    ];
    $msg = $map[$err] ?? 'Upload error';
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $msg, 'code' => $err]);
    exit;
}

// Enforce allowed MIME types/extensions
$allowed = [
    'image/jpeg' => 'jpg',
    'image/jpg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
];
$tmpPath = (string)$file['tmp_name'];
$size = (int)$file['size'];
if (!is_uploaded_file($tmpPath)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid upload']);
    exit;
}

// Size guard: accept up to 5 MB (actual PHP ini may be lower)
if ($size <= 0 || $size > 5 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'File too large (max 5 MB). If this persists, please reduce image size.']);
    exit;
}

// Detect MIME type using finfo for safety
$finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
$mime = $finfo ? (finfo_file($finfo, $tmpPath) ?: '') : (string)$file['type'];
if ($finfo) finfo_close($finfo);
if (!isset($allowed[$mime])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unsupported file type: ' . $mime]);
    exit;
}
$ext = $allowed[$mime];

// Build paths
$slug = ds_slug($productName);
$dir = __DIR__ . '/../assets/uploads/products';
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}

// Remove existing files for this slug with any allowed extension (replace behavior)
foreach (array_values(array_unique(array_values($allowed))) as $e) {
    $candidate = $dir . '/' . $slug . '.' . $e;
    if (is_file($candidate)) @unlink($candidate);
}

$target = $dir . '/' . $slug . '.' . $ext;
if (!@move_uploaded_file($tmpPath, $target)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save uploaded file']);
    exit;
}

// Build public URL
$url = '/assets/uploads/products/' . rawurlencode($slug . '.' . $ext) . '?v=' . time();
echo json_encode(['success' => true, 'url' => $url, 'slug' => $slug]);
exit;

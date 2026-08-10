<?php

declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/categories.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not authorized']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}
if (empty($_SESSION['manager_csrf']) || !hash_equals((string)$_SESSION['manager_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token.']);
    exit;
}
$categoryId = (int)($_POST['category_id'] ?? 0);
if (!getCategoryById($categoryId) || empty($_FILES['image'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Category and image are required.']);
    exit;
}
$file = $_FILES['image'];
if (!empty($file['error']) || !is_uploaded_file((string)$file['tmp_name']) || (int)$file['size'] <= 0 || (int)$file['size'] > 5 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid image or file exceeds 5 MB.']);
    exit;
}
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = $finfo ? (string)finfo_file($finfo, (string)$file['tmp_name']) : '';
if ($finfo) finfo_close($finfo);
if (!isset($allowed[$mime])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unsupported image type.']);
    exit;
}
$dir = getCategoryImageStorageDirectory();
if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Category image storage is not writable.']);
    exit;
}
$filename = $categoryId . '.' . $allowed[$mime];
$destination = $dir . '/' . $filename;
$temporary = $dir . '/.' . $categoryId . '-' . bin2hex(random_bytes(8)) . '.upload';
if (!move_uploaded_file((string)$file['tmp_name'], $temporary)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to save image.']);
    exit;
}
$backup = null;
try {
    if (is_file($destination)) {
        $backup = $destination . '.backup-' . bin2hex(random_bytes(4));
        if (!rename($destination, $backup)) throw new RuntimeException('Unable to replace the existing image.');
    }
    if (!rename($temporary, $destination)) throw new RuntimeException('Unable to publish the uploaded image.');
    $path = categoryUploadReference($filename);
    setCategoryImagePath($categoryId, $path);
    foreach (array_unique(array_values($allowed)) as $ext) {
        $old = $dir . '/' . $categoryId . '.' . $ext;
        if ($old !== $destination && is_file($old)) @unlink($old);
    }
    if ($backup !== null && is_file($backup)) @unlink($backup);
    echo json_encode(['success' => true, 'url' => getCategoryImageBaseUrl() . '/' . rawurlencode($filename) . '?v=' . time()]);
} catch (Throwable $e) {
    if (is_file($temporary)) @unlink($temporary);
    if (is_file($destination)) @unlink($destination);
    if ($backup !== null && is_file($backup)) @rename($backup, $destination);
    error_log('upload_category_image error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to save category image.']);
}
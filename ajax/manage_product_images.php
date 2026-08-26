<?php

declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

function productImageJsonError(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function absoluteProductImageUrl(string $url): string
{
    if (preg_match('#^https?://#i', $url)) return $url;
    $secure = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    return ($secure ? 'https' : 'http') . '://' . $host . '/' . ltrim($url, '/');
}

function productImageResponse(string $sku): array
{
    $images = getProductImages($sku);
    foreach ($images as &$image) $image['absolute_url'] = absoluteProductImageUrl((string)$image['url']);
    unset($image);
    return [
        'success' => true,
        'sku' => $sku,
        'images' => $images,
        'limit' => PRODUCT_IMAGE_LIMIT,
        'primary_url' => $images ? (string)$images[0]['url'] : null,
    ];
}

if (empty($_SESSION['admin'])) productImageJsonError('Not authorized.', 403);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') productImageJsonError('POST required.', 405);
if (empty($_SESSION['manager_csrf']) || !hash_equals((string)$_SESSION['manager_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
    productImageJsonError('Your session expired. Refresh the manager portal and try again.', 403);
}

$productId = (int)($_POST['product_id'] ?? 0);
$product = $productId > 0 ? getProductById($productId) : null;
if (!$product) productImageJsonError('Product not found.', 404);
$sku = trim((string)$product['name']);
$action = strtolower(trim((string)($_POST['action'] ?? 'list')));

try {
    if ($action === 'upload') {
        if (empty($_FILES['image']) || !is_array($_FILES['image'])) productImageJsonError('Choose an image to upload.');
        $file = $_FILES['image'];
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) productImageJsonError('The image upload did not complete.');
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > 5 * 1024 * 1024) productImageJsonError('Images must be 5 MB or smaller.');
        $temporaryPath = (string)($file['tmp_name'] ?? '');
        if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) productImageJsonError('The uploaded image could not be verified.');
        addProductImageFromPath($sku, $temporaryPath);
    } elseif ($action === 'set_primary') {
        setPrimaryProductImage($sku, (int)($_POST['image_id'] ?? 0));
    } elseif ($action === 'reorder') {
        $ids = json_decode((string)($_POST['image_ids'] ?? ''), true);
        if (!is_array($ids)) productImageJsonError('A valid image order is required.');
        reorderProductImages($sku, $ids);
    } elseif ($action === 'delete') {
        deleteProductImage($sku, (int)($_POST['image_id'] ?? 0));
    } elseif ($action !== 'list') {
        productImageJsonError('Unknown image action.');
    }
    echo json_encode(productImageResponse($sku));
} catch (InvalidArgumentException $error) {
    productImageJsonError($error->getMessage());
} catch (Throwable $error) {
    error_log('manage_product_images error: ' . $error->getMessage());
    productImageJsonError($error->getMessage(), 500);
}
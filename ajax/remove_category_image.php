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
if (!getCategoryById($categoryId)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Category not found.']);
    exit;
}
$dir = getCategoryImageStorageDirectory();
foreach (['jpg', 'png', 'webp', 'gif'] as $ext) {
    $path = $dir . '/' . $categoryId . '.' . $ext;
    if (is_file($path)) @unlink($path);
}
setCategoryImagePath($categoryId, null);
$category = getCategoryById($categoryId);
$parent = $category && !empty($category['parent_id']) ? getCategoryById((int)$category['parent_id']) : null;
echo json_encode(['success' => true, 'url' => resolveCategoryImage($category ?: [], $parent)]);
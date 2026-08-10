<?php

declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/categories.php';
require_once __DIR__ . '/../includes/category_baseline.php';

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
$token = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['manager_csrf']) || !hash_equals((string)$_SESSION['manager_csrf'], $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token. Refresh the page and try again.']);
    exit;
}

try {
    $action = (string)($_POST['action'] ?? '');
    $result = null;
    if ($action === 'save_group') {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $result = saveCategoryGroup((string)($_POST['name'] ?? ''), $id, !isset($_POST['active']) || (int)$_POST['active'] === 1);
    } elseif ($action === 'reorder_group') {
        reorderCategoryGroup((int)($_POST['id'] ?? 0), (string)($_POST['direction'] ?? ''));
    } elseif ($action === 'delete_group') {
        deleteCategoryGroup((int)($_POST['id'] ?? 0));
    } elseif ($action === 'save_category') {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $result = saveCategory([
            'name' => (string)($_POST['name'] ?? ''),
            'group_id' => (int)($_POST['group_id'] ?? 0),
            'parent_id' => !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null,
            'active' => (int)($_POST['active'] ?? 0) === 1,
            'featured_homepage' => (int)($_POST['featured_homepage'] ?? 0) === 1,
        ], $id);
    } elseif ($action === 'reorder_category') {
        reorderCategory((int)($_POST['id'] ?? 0), (string)($_POST['direction'] ?? ''));
    } elseif ($action === 'delete_category') {
        deleteCategory((int)($_POST['id'] ?? 0));
    } elseif ($action === 'replace_assignments') {
        $decoded = json_decode((string)($_POST['skus'] ?? '[]'), true);
        if (!is_array($decoded)) throw new InvalidArgumentException('Invalid SKU selection.');
        replaceCategoryAssignments((int)($_POST['id'] ?? 0), $decoded);
    } elseif ($action === 'restore_baseline') {
        if ((string)($_POST['confirmation'] ?? '') !== 'RESET CATEGORIES') {
            throw new InvalidArgumentException('Type RESET CATEGORIES exactly to confirm the baseline restore.');
        }
        $result = restoreCategoryBaseline(true);
    } else {
        throw new InvalidArgumentException('Unknown category action.');
    }
    echo json_encode(['success' => true, 'id' => is_int($result) ? $result : null, 'summary' => is_array($result) ? $result : null]);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('manage_categories error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to save category changes.']);
}
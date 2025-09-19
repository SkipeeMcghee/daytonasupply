<?php
chdir(__DIR__ . '/..');
session_start();
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'invalid_method']); exit;
}
$dark = isset($_POST['darkmode']) ? (int)($_POST['darkmode'] ? 1 : 0) : null;
if ($dark === null) { echo json_encode(['success' => false, 'error' => 'missing']); exit; }

$userId = $_SESSION['customer']['id'] ?? $_SESSION['customer_id'] ?? $_SESSION['user_id'] ?? null;
if (!$userId) { echo json_encode(['success' => false, 'error' => 'not_logged_in']); exit; }

try {
    $db = getDb();
    // Use id column by default; fallback to customer_id if present
    $stmt = $db->prepare('UPDATE customers SET darkmode = :dm WHERE id = :id');
    $stmt->execute([':dm' => $dark, ':id' => $userId]);
    if ($stmt->rowCount() === 0) {
        $stmt = $db->prepare('UPDATE customers SET darkmode = :dm WHERE customer_id = :id');
        $stmt->execute([':dm' => $dark, ':id' => $userId]);
    }
    echo json_encode(['success' => true, 'darkmode' => $dark]);
} catch (Exception $e) {
    error_log('update_darkmode error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'db_error']);
}



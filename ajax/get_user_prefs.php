<?php
chdir(__DIR__ . '/..');
session_start();
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');
$userId = $_SESSION['customer']['id'] ?? $_SESSION['customer_id'] ?? $_SESSION['user_id'] ?? null;
if (!$userId) { echo json_encode(['logged_in' => false]); exit; }

try {
    $db = getDb();
    $stmt = $db->prepare('SELECT darkmode FROM customers WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $stmt = $db->prepare('SELECT darkmode FROM customers WHERE customer_id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    echo json_encode(['logged_in' => true, 'darkmode' => isset($row['darkmode']) ? (int)$row['darkmode'] : null]);
} catch (Exception $e) {
    error_log('get_user_prefs error: ' . $e->getMessage());
    echo json_encode(['logged_in' => true, 'darkmode' => null]);
}

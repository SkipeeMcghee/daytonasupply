<?php
// Admin script to reload product inventory from data/inventory.json.  You
// should protect this script behind authentication; in this simple
// example it requires the admin to be logged in via the manager portal.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
session_start();

// Allow execution only if admin logged in
if (!isset($_SESSION['admin'])) {
    header('Location: /managerportal.php');
    exit;
}

$db = getDb();
$inventoryPath = __DIR__ . '/../data/inventory.json';
if (!file_exists($inventoryPath)) {
    echo 'Inventory file not found.';
    exit;
}
// Begin transaction: clear products table and reload
$db->beginTransaction();
$db->exec('DELETE FROM products');
$json = file_get_contents($inventoryPath);
$items = json_decode($json, true);
$stmt = $db->prepare('INSERT INTO products(name, description, price) VALUES(:name, :desc, :price)');
foreach ($items as $item) {
    $stmt->execute([
        ':name' => $item['name'] ?? '',
        ':desc' => $item['description'] ?? '',
        ':price' => $item['price'] ?? 0
    ]);
}
$db->commit();
header('Location: /managerportal.php');
exit;
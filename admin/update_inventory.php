<?php
// Admin script to reset the product inventory from a JSON file.  This
// should be accessible only to logged‑in office managers.  It reads
// the contents of data/inventory.json, deletes all existing rows
// from the products table and inserts the JSON items afresh.  The
// JSON file should contain an array of objects with at least
// "name", "description" and "price" keys.

session_start();
require_once __DIR__ . '/../includes/db.php';
// Only proceed if admin is authenticated
if (!isset($_SESSION['admin'])) {
    header('Location: /managerportal.php');
    exit;
}

$db = getDb();
try {
    $db->beginTransaction();
    // Remove all existing products to prevent duplicates
    $db->exec('DELETE FROM products');
    // Read inventory from JSON file
    $inventoryPath = __DIR__ . '/../data/inventory.json';
    if (!file_exists($inventoryPath)) {
        // If the file does not exist, roll back and display an error
        $db->rollBack();
        echo '<p>Inventory file not found at ' . htmlspecialchars($inventoryPath) . '.</p>';
        exit;
    }
    $json = file_get_contents($inventoryPath);
    $items = json_decode($json, true);
    if (!is_array($items)) {
        $db->rollBack();
        echo '<p>Failed to decode inventory JSON.</p>';
        exit;
    }
    $stmt = $db->prepare('INSERT INTO products(name, description, price) VALUES(:name, :description, :price)');
    foreach ($items as $item) {
        $stmt->execute([
            ':name' => $item['name'] ?? '',
            ':description' => $item['description'] ?? '',
            ':price' => isset($item['price']) ? (float)$item['price'] : 0
        ]);
    }
    $db->commit();
    // Redirect back to the manager portal once done
    header('Location: /managerportal.php');
    exit;
} catch (Exception $e) {
    $db->rollBack();
    echo '<p>Error updating inventory: ' . htmlspecialchars($e->getMessage()) . '</p>';
    exit;
}
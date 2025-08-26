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
    // Use relative path to manager portal when redirecting from within admin
    header('Location: ../managerportal.php');
    exit;
}

$db = getDb();
try {
    $db->beginTransaction();
    // Capture mapping of old product IDs to their names before clearing the table.  This
    // allows us to update existing order_items records when the inventory is
    // reloaded.  We fetch all current products into an associative array keyed
    // by the product name for easy lookup.  If there are duplicate names the
    // last seen product will win; this mirrors typical behaviour of replacing
    // products by name.
    $oldProducts = [];
    $stmtOld = $db->query('SELECT id, name FROM products');
    while ($row = $stmtOld->fetch(PDO::FETCH_ASSOC)) {
        $oldProducts[$row['name']] = (int)$row['id'];
    }

    // Instead of deleting all products (which rewrites IDs and harms
    // historical order data), perform upserts by product name. The
    // project previously used a separate `sku` column; that column
    // is being removed and we treat the `name` column as the stable
    // identifier going forward.

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
    // Prepare statements for upsert: find by name, update, insert.
    // We no longer rely on a separate `sku` column in the DB.
    $stmtFindName = $db->prepare('SELECT id FROM products WHERE name = :name LIMIT 1');
    $stmtUpdate  = $db->prepare('UPDATE products SET name = :name, description = :description, price = :price WHERE id = :id');
    $stmtInsert  = $db->prepare('INSERT INTO products(name, description, price) VALUES(:name, :description, :price)');

    $newProducts = [];
    foreach ($items as $item) {
        // Ignore any 'sku' field present in the JSON; use `name` as the
        // canonical key for matching/upserting products.
        $name = $item['name'] ?? '';
        $description = $item['description'] ?? '';
        $price = isset($item['price']) ? (float)$item['price'] : 0;

        $existingId = null;
        if ($name !== '') {
            $stmtFindName->execute([':name' => $name]);
            $r = $stmtFindName->fetch(PDO::FETCH_ASSOC);
            if ($r) $existingId = (int)$r['id'];
        }
        if ($existingId) {
            $stmtUpdate->execute([':name' => $name, ':description' => $description, ':price' => $price, ':id' => $existingId]);
            $newProducts[$name] = $existingId;
        } else {
            $stmtInsert->execute([':name' => $name, ':description' => $description, ':price' => $price]);
            $newProducts[$name] = (int)$db->lastInsertId();
        }
    }

    $db->commit();
    // Redirect back to the manager portal once done
    header('Location: ../managerportal.php');
    exit;
} catch (Exception $e) {
    $db->rollBack();
    echo '<p>Error updating inventory: ' . htmlspecialchars($e->getMessage()) . '</p>';
    exit;
}
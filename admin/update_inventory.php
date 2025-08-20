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

    // Remove all existing products to prevent duplicates.  We'll also reset
    // Remove all existing products to prevent duplicates.
    $db->exec('DELETE FROM products');
    // In MySQL, AUTO_INCREMENT will reset automatically if you use TRUNCATE
    // Uncomment the following line if you want to reset IDs in MySQL:
    // $db->exec('TRUNCATE TABLE products');

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
    // Prepare insert statement for products
    $stmt = $db->prepare('INSERT INTO products(name, description, price) VALUES(:name, :description, :price)');
    // We'll build a mapping from product names to their new IDs so that
    // we can update order_items rows that refer to old product IDs.  We
    // cannot rely on the order of the JSON array to match previous IDs.
    $newProducts = [];
    foreach ($items as $item) {
        $name = $item['name'] ?? '';
        $description = $item['description'] ?? '';
        $price = isset($item['price']) ? (float)$item['price'] : 0;
        $stmt->execute([
            ':name' => $name,
            ':description' => $description,
            ':price' => $price
        ]);
        // Record the new ID keyed by product name
        $newId = (int)$db->lastInsertId();
        $newProducts[$name] = $newId;
    }

    // Update order_items to point to the new product IDs.  For each
    // product name that existed previously and still exists in the JSON,
    // set the product_id of matching order_items rows to the new ID.  This
    // preserves the relationship between historical orders and the new
    // product definitions.  If a name no longer exists in the new
    // inventory we leave those order_items unchanged.
    $stmtUpdate = $db->prepare('UPDATE order_items SET product_id = :newId WHERE product_id = :oldId');
    foreach ($oldProducts as $name => $oldId) {
        if (isset($newProducts[$name])) {
            $stmtUpdate->execute([
                ':newId' => $newProducts[$name],
                ':oldId' => $oldId
            ]);
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
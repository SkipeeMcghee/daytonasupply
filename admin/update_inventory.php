<?php
// Admin script to reset the product inventory from a JSON file.  This
// should be accessible only to logged‑in office managers.  It reads
// the contents of data/inventory.json, deletes all existing rows
// from the products table and inserts the JSON items afresh.  The
// JSON file should contain an array of objects with at least
// "name", "description" and "price" keys.

session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
// Only proceed if admin is authenticated
if (!isset($_SESSION['admin'])) {
    // Use relative path to manager portal when redirecting from within admin
    header('Location: ../managerportal.php');
    exit;
}

$db = getDb();
try {
    // Ensure a filesystem backup exists for SQLite before destructive operations
    $dbFile = __DIR__ . '/../data/database.sqlite';
    if (is_file($dbFile)) {
        $bak = __DIR__ . '/../data/database.sqlite.bak';
        if (!is_file($bak)) {
            copy($dbFile, $bak);
        }
    }
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
    // After upserts, rebuild the products table in alphabetical order and
    // remap product IDs so they are sequential based on name order.
    // Build mapping of old_id -> name for all current products
    $stmtAll = $db->query('SELECT id, name, description, price FROM products');
    $all = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
    $oldById = [];
    foreach ($all as $r) {
        $oldById[(int)$r['id']] = $r;
    }
    // Create a new products table and insert rows ordered by name so ids are sequential
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $db->exec('CREATE TABLE IF NOT EXISTS products_new (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, description TEXT, price REAL NOT NULL)');
        // Clear any existing rows in products_new to ensure deterministic IDs
        $db->exec('DELETE FROM products_new');
    } else {
        // MySQL / MariaDB: ensure a compatible table definition
        $db->exec('CREATE TABLE IF NOT EXISTS products_new (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, description TEXT, price DOUBLE NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        // Truncate to remove any existing rows
        $db->exec('TRUNCATE TABLE products_new');
    }
    // Insert rows from the inventory JSON in alphabetical order by name
    usort($items, function($a, $b) { return strcasecmp($a['name'] ?? '', $b['name'] ?? ''); });
    $insNew = $db->prepare('INSERT INTO products_new(name, description, price) VALUES(:name, :description, :price)');
    foreach ($items as $it) {
        $insNew->execute([':name' => $it['name'] ?? '', ':description' => $it['description'] ?? '', ':price' => isset($it['price']) ? (float)$it['price'] : 0.0]);
    }
    // Build mapping: name -> new_id
    $mapStmt = $db->query('SELECT id, name FROM products_new');
    $nameToNewId = [];
    while ($r = $mapStmt->fetch(PDO::FETCH_ASSOC)) {
        $nameToNewId[$r['name']] = (int)$r['id'];
    }
    // Now remap order_items.product_id using product name to find new id. For rows
    // where the product name cannot be found, leave product_id as-is to avoid data loss.
    $updateItem = $db->prepare('UPDATE order_items SET product_id = :new WHERE product_id = :old');
    foreach ($oldById as $oldId => $row) {
        $pname = $row['name'];
        if (isset($nameToNewId[$pname])) {
            $newId = $nameToNewId[$pname];
            if ($newId !== $oldId) {
                $updateItem->execute([':new' => $newId, ':old' => $oldId]);
            }
        }
    }
    // Swap tables: for SQLite drop and rename, for MySQL use RENAME TABLE then drop old
    if ($driver === 'sqlite') {
        // SQLite: temporarily disable foreign keys for the swap
        $db->exec('PRAGMA foreign_keys = OFF');
        $db->exec('DROP TABLE products');
        $db->exec('ALTER TABLE products_new RENAME TO products');
        $db->exec('PRAGMA foreign_keys = ON');
    } else {
    // MySQL/MariaDB: perform a safer swap. First ensure no external
    // foreign key references to this database's products table exist
    // (cross-schema references can cause DROP/RENAME to fail).
    $currentDb = $db->query('SELECT DATABASE()')->fetchColumn();
    $fkCheck = $db->prepare(
        'SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE REFERENCED_TABLE_SCHEMA = :db AND REFERENCED_TABLE_NAME = "products"'
    );
    $fkCheck->execute([':db' => $currentDb]);
    $externalFks = [];
    while ($r = $fkCheck->fetch(PDO::FETCH_ASSOC)) {
        // If a referencing table is in a different schema, collect it
        if ($r['TABLE_SCHEMA'] !== $currentDb) {
            $externalFks[] = $r;
        }
    }
    if (count($externalFks) > 0) {
        $db->rollBack();
        echo '<p>Cannot update inventory because foreign key references to <code>products</code> exist in other databases. Please remove or update those references and try again.</p>';
        exit;
    }

    // Temporarily disable foreign key checks for the atomic swap
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    // Use a timestamped backup name to avoid collisions with previous runs
    $ts = preg_replace('/[^0-9]/', '', date('Ymd_His')) . '_' . getmypid();
    $oldName = 'products_old_' . $ts;
    // If a previous run left a table with this exact name, remove it
    $db->exec('DROP TABLE IF EXISTS ' . $oldName);
    // Atomically rename tables: products -> products_old_<ts>, products_new -> products
    $db->exec('RENAME TABLE products TO ' . $oldName . ', products_new TO products');
    // Try to drop the old table created by the rename; if it fails leave it in place
    try {
        $db->exec('DROP TABLE IF EXISTS ' . $oldName);
    } catch (Exception $_) {
        // Keep the backup table for manual inspection; do not abort the update
    }
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    $db->commit();
    // Invalidate any cached product listings so the portal/catalogue reflect updates
    invalidateProductsCache();
    // Redirect back to the manager portal once done
    header('Location: ../managerportal.php');
    exit;
} catch (Exception $e) {
    $db->rollBack();
    echo '<p>Error updating inventory: ' . htmlspecialchars($e->getMessage()) . '</p>';
    exit;
}
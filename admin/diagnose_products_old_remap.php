<?php
// Diagnostic script to find why some order_items still reference products_old
// after attempting the automated remap. Run from the project root with:
// php admin/diagnose_products_old_remap.php

require_once __DIR__ . '/../includes/db.php';
$db = getDb();
$driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

echo "Driver: $driver\n\n";

// Does products_old exist?
try {
    if ($driver === 'mysql') {
        $tbl = $db->query("SHOW TABLES LIKE 'products_old'")->fetchColumn();
        echo "products_old exists: " . ($tbl ? 'yes' : 'no') . "\n\n";
    } else {
        $res = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='products_old'")->fetchColumn();
        echo "products_old exists: " . ($res ? 'yes' : 'no') . "\n\n";
    }
} catch (Exception $e) {
    echo "Error checking products_old existence: " . $e->getMessage() . "\n\n";
}

// Count remaining references
$countStmt = $db->query('SELECT COUNT(*) FROM order_items WHERE product_id IN (SELECT id FROM products_old)');
$remaining = (int)$countStmt->fetchColumn();
echo "Remaining order_items referencing products_old: $remaining\n\n";

if ($remaining === 0) {
    echo "Nothing left to remap. You can DROP TABLE products_old if desired.\n";
    exit(0);
}

// Show up to 200 sample rows that remain unmatched, with the old product name and whether a product row exists with the same name
if ($driver === 'mysql') {
    $sql = "SELECT oi.id AS order_item_id, oi.order_id, oi.product_id AS old_product_id, po.name AS old_name,
                   p.id AS new_product_id, p.name AS new_name
            FROM order_items oi
            JOIN products_old po ON oi.product_id = po.id
            LEFT JOIN products p ON p.name COLLATE utf8mb4_unicode_ci = po.name COLLATE utf8mb4_unicode_ci
            LIMIT 200";
} else {
    // SQLite: correlated subquery to find matching product id by name
    $sql = "SELECT oi.id AS order_item_id, oi.order_id, oi.product_id AS old_product_id, po.name AS old_name,
                   (SELECT p.id FROM products p WHERE p.name = po.name LIMIT 1) AS new_product_id,
                   (SELECT p.name FROM products p WHERE p.name = po.name LIMIT 1) AS new_name
            FROM order_items oi
            JOIN products_old po ON oi.product_id = po.id
            LIMIT 200";
}

$stmt = $db->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Sample remaining order_items (up to 200):\n";
foreach ($rows as $r) {
    printf("order_item_id=%d order_id=%d old_product_id=%d old_name='%s' new_product_id=%s new_name='%s'\n",
        $r['order_item_id'], $r['order_id'], $r['old_product_id'], $r['old_name'],
        ($r['new_product_id'] === null ? 'NULL' : $r['new_product_id']), ($r['new_name'] === null ? 'NULL' : $r['new_name']));
}

// Show which product names from products_old have no exact match in products
if ($driver === 'mysql') {
    $sql2 = "SELECT po.name, COUNT(*) AS cnt
             FROM products_old po
             LEFT JOIN products p ON p.name COLLATE utf8mb4_unicode_ci = po.name COLLATE utf8mb4_unicode_ci
             WHERE p.id IS NULL
             GROUP BY po.name
             ORDER BY cnt DESC
             LIMIT 200";
} else {
    $sql2 = "SELECT po.name, COUNT(*) AS cnt
             FROM products_old po
             LEFT JOIN products p ON p.name = po.name
             WHERE p.id IS NULL
             GROUP BY po.name
             ORDER BY cnt DESC
             LIMIT 200";
}

$stmt2 = $db->query($sql2);
$missing = $stmt2->fetchAll(PDO::FETCH_ASSOC);

echo "\nProduct names present in products_old but missing from products (top 200):\n";
foreach ($missing as $m) {
    printf("name='%s' references=%d\n", $m['name'], $m['cnt']);
}

// Show duplicate names in products which could cause ambiguity during remap
$dupStmt = $db->query("SELECT name, COUNT(*) AS cnt FROM products GROUP BY name HAVING COUNT(*) > 1");
$dups = $dupStmt->fetchAll(PDO::FETCH_ASSOC);
if (count($dups) > 0) {
    echo "\nWARNING: Duplicate product names exist in products (this can make automated remap ambiguous):\n";
    foreach ($dups as $d) {
        printf("name='%s' count=%d\n", $d['name'], $d['cnt']);
    }
} else {
    echo "\nNo duplicate product names found in products.\n";
}

// If MySQL, also show table create statements to inspect collations
if ($driver === 'mysql') {
    echo "\nSHOW CREATE TABLE products:\n";
    $c1 = $db->query('SHOW CREATE TABLE products')->fetch(PDO::FETCH_ASSOC);
    echo $c1['Create Table'] . "\n\n";

    echo "SHOW CREATE TABLE products_old:\n";
    $c2 = $db->query('SHOW CREATE TABLE products_old')->fetch(PDO::FETCH_ASSOC);
    echo $c2['Create Table'] . "\n\n";
}

echo "Done.\n";

?>

<?php
// Quick DB inspector for orders and order_items
chdir(__DIR__ . '/..');
require __DIR__ . '/../includes/db.php';
try {
    $db = getDb();
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "OK: Connected via driver: $driver\n";
    $orders = (int)$db->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    $items = (int)$db->query("SELECT COUNT(*) FROM order_items")->fetchColumn();
    echo "orders: $orders\n";
    echo "order_items: $items\n";
    echo "\nRecent orders (up to 5):\n";
    $stmt = $db->query("SELECT id, customer_id, status, total, created_at FROM orders ORDER BY created_at DESC LIMIT 5");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        echo "(no recent orders)\n";
    } else {
        foreach ($rows as $r) {
            echo json_encode($r) . "\n";
        }
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}


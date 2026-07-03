<?php
// Simulate web environment by setting environment variables as in .htaccess
putenv('DB_DRIVER=mysql');
putenv('DB_HOST=localhost');
putenv('DB_NAME=daytona_supply');
putenv('DB_USER=brian');
putenv('DB_PASS=273h4ui^N');

chdir(__DIR__ . '/..');
require __DIR__ . '/../includes/db.php';
try {
    $db = getDb();
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "Connected PDO driver: $driver\n";
    if ($driver === 'mysql') {
        $tables = [];
        $stmt = $db->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "MySQL tables: " . implode(', ', array_slice($tables,0,50)) . "\n";
        $hasOrders = in_array('orders', $tables, true) || in_array('Orders', $tables, true);
        echo "orders table exists: " . ($hasOrders ? 'yes' : 'no') . "\n";
    } else {
        echo "Not connected to MySQL (driver=$driver)\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}


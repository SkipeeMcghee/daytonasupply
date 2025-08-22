<?php
// Lightweight DB bootstrap tester. Place this on the production host and run
// `php tools/db_test.php` or browse to it to see the immediate DB connection
// result. It uses the same getDb() implementation as the site.
chdir(__DIR__ . '/..');
ini_set('display_errors', '1');
error_reporting(E_ALL);
try {
    require __DIR__ . '/../includes/db.php';
    $db = getDb();
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "OK: Connected to database via PDO driver: " . $driver . "\n";
    // Print a quick sanity check: existing tables
    $tables = [];
    if ($driver === 'sqlite') {
        $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table'");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $stmt = $db->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    echo "Tables: " . implode(', ', array_slice($tables, 0, 20)) . "\n";
    exit(0);
} catch (Exception $e) {
    $msg = '[' . date('c') . '] db_test error: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    echo "ERROR: " . $e->getMessage() . "\n";
    @file_put_contents(__DIR__ . '/../data/logs/catalogue_errors.log', $msg, FILE_APPEND | LOCK_EX);
    exit(1);
}

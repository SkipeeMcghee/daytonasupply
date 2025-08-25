<?php
// Migration script to copy data from the local SQLite database to a
// MySQL database.  This script should be run from the command line.
// It will read from data/database.sqlite and insert rows into the
// MySQL database specified by DB_DRIVER=mysql, DB_HOST, DB_NAME,
// DB_USER and DB_PASS environment variables.  The MySQL schema
// (e.g. created via mysql_schema.sql) must already exist.

// Usage (from project root):
//   php migrate_sqlite_to_mysql.php

function connectSqlite(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

function connectMysql(): PDO
{
    $driver = getenv('DB_DRIVER') ?: 'mysql';
    if (strcasecmp($driver, 'mysql') !== 0) {
        throw new RuntimeException("DB_DRIVER must be mysql to run this script");
    }
    $host = getenv('DB_HOST') ?: 'localhost';
    $db   = getenv('DB_NAME') ?: '';
    $user = getenv('DB_USER') ?: '';
    $pass = getenv('DB_PASS') ?: '';
    if ($db === '') {
        throw new RuntimeException('Please set DB_NAME environment variable');
    }
    $dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

function copyTable(PDO $src, PDO $dest, string $table, array $columns): void
{
    // Fetch all rows from source table
    $rows = $src->query('SELECT ' . implode(',', $columns) . ' FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        return;
    }
    // Build insert statement
    $placeholders = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
    $stmt = $dest->prepare('INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES ' . $placeholders);
    foreach ($rows as $row) {
        $stmt->execute(array_values($row));
    }
}

try {
    $sqlitePath = __DIR__ . '/data/database.sqlite';
    if (!file_exists($sqlitePath)) {
        throw new RuntimeException('SQLite database not found at ' . $sqlitePath);
    }
    $sqlite = connectSqlite($sqlitePath);
    $mysql  = connectMysql();
    echo "Copying customers...\n";
    copyTable($sqlite, $mysql, 'customers', [
        'id', 'name', 'business_name', 'phone', 'email', 'billing_line1', 'billing_line2', 'billing_city', 'billing_state', 'billing_postal_code', 'shipping_line1', 'shipping_line2', 'shipping_city', 'shipping_state', 'shipping_postal_code', 'password_hash', 'is_verified', 'verification_token', 'reset_token', 'reset_token_expires'
    ]);
    echo "Copying products...\n";
    copyTable($sqlite, $mysql, 'products', ['id','name','description','price']);
    echo "Copying orders...\n";
    copyTable($sqlite, $mysql, 'orders', ['id','customer_id','status','total','created_at','archived']);
    echo "Copying order_items...\n";
    copyTable($sqlite, $mysql, 'order_items', ['id','order_id','product_id','quantity']);
    echo "Copying admin...\n";
    copyTable($sqlite, $mysql, 'admin', ['id','password_hash']);
    echo "Migration complete!\n";
} catch (Exception $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
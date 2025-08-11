<?php
// Database connection and initialisation functions for Daytona Supply.

/**
 * Returns a PDO connection to the SQLite database.  If the database
 * file does not exist the necessary tables will be created.
 *
 * The database file lives at ``data/database.sqlite`` relative to the
 * repository root.  Tables created:
 *
 * - customers(id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, business_name TEXT,
 *   phone TEXT, email TEXT UNIQUE, billing_address TEXT, shipping_address TEXT,
 *   password_hash TEXT)
 * - products(id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, description TEXT,
 *   price REAL)
 * - orders(id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER,
 *   status TEXT, total REAL, created_at TEXT)
 * - order_items(id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER,
 *   product_id INTEGER, quantity INTEGER)
 * - admin(id INTEGER PRIMARY KEY AUTOINCREMENT, password_hash TEXT)
 *
 * You should not call this function multiple times within a request;
 * instead call it once and reuse the returned PDO instance.
 */
function getDb(): PDO
{
    static $db = null;
    if ($db !== null) {
        return $db;
    }
    $dbFile = __DIR__ . '/../data/database.sqlite';
    $initNeeded = !file_exists($dbFile);
    $dsn = 'sqlite:' . $dbFile;
    $db = new PDO($dsn);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if ($initNeeded) {
        initDatabase($db);
    }
    return $db;
}

/**
 * Create required tables and seed initial data.  If an ``inventory.json``
 * file exists in the data directory its contents will be loaded into
 * the products table.  A default admin user with password "admin" is
 * created if none exists.
 */
function initDatabase(PDO $db): void
{
    // Create tables
    $db->exec('CREATE TABLE IF NOT EXISTS customers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        business_name TEXT,
        phone TEXT,
        email TEXT UNIQUE NOT NULL,
        billing_address TEXT,
        shipping_address TEXT,
        password_hash TEXT NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        description TEXT,
        price REAL NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_id INTEGER NOT NULL,
        status TEXT NOT NULL,
        total REAL NOT NULL,
        created_at TEXT NOT NULL,
        FOREIGN KEY(customer_id) REFERENCES customers(id)
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS order_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        quantity INTEGER NOT NULL,
        FOREIGN KEY(order_id) REFERENCES orders(id),
        FOREIGN KEY(product_id) REFERENCES products(id)
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS admin (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        password_hash TEXT NOT NULL
    )');
    // Seed admin account if none exists
    $stmt = $db->query('SELECT COUNT(*) FROM admin');
    $count = $stmt->fetchColumn();
    if ($count == 0) {
        // Use PHP's password_hash for secure hashing.  Default algorithm
        // automatically chooses a suitable cost and includes salt.
        $hash = password_hash('admin', PASSWORD_DEFAULT);
        $ins = $db->prepare('INSERT INTO admin(password_hash) VALUES(:hash)');
        $ins->execute([':hash' => $hash]);
    }
    // Seed products from inventory.json if table empty
    $stmt = $db->query('SELECT COUNT(*) FROM products');
    $prodCount = $stmt->fetchColumn();
    if ($prodCount == 0) {
        $inventoryPath = __DIR__ . '/../data/inventory.json';
        if (file_exists($inventoryPath)) {
            $json = file_get_contents($inventoryPath);
            $items = json_decode($json, true);
            if (is_array($items)) {
                $insert = $db->prepare('INSERT INTO products(name, description, price) VALUES(:name, :desc, :price)');
                foreach ($items as $item) {
                    $insert->execute([
                        ':name' => $item['name'] ?? '',
                        ':desc' => $item['description'] ?? '',
                        ':price' => $item['price'] ?? 0.0
                    ]);
                }
            }
        }
    }
}
?>
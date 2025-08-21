<?php
// Database connection and initialisation functions for Daytona Supply.

// NOTE: This file is a local copy of the original `includes/db.php` from the
// Daytona Supply repository.  It is included here so the application can
// function in this environment.  The implementation is intentionally
// simplified but maintains the same public API as the original file.

/**
 * Returns a PDO connection to the SQLite database.  If the database
 * file does not exist the necessary tables will be created.
 *
 * The database file lives at ``data/database.sqlite`` relative to the
 * project root.  Tables created:
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
 */
function getDb(): PDO
{
    static $db = null;
    if ($db !== null) {
        return $db;
    }
    // Determine which database driver to use.  Default to SQLite for
    // development, but allow MySQL by setting the DB_DRIVER
    // environment variable to "mysql" and providing DB_HOST, DB_NAME,
    // DB_USER and DB_PASS.  For SQLite, DB_PATH can override the
    // location of the database file.
    $driver = getenv('DB_DRIVER') ?: 'sqlite';
    if (strcasecmp($driver, 'mysql') === 0) {
        // Connect to MySQL
        $host = getenv('DB_HOST') ?: 'localhost';
        $name = getenv('DB_NAME') ?: 'daytona_supply';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: '';
        $dsn = "mysql:host=$host;dbname=$name;charset=utf8mb4";
        $db = new PDO($dsn, $user, $pass);
    // Use real prepared statements where possible and surface errors
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Disable emulated prepares so the driver uses native prepares
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    // Use associative arrays by default for consistency
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // For MySQL we expect that the schema has been created ahead of time
        return $db;
    }
    // Default: SQLite
    $dbFile = getenv('DB_PATH') ?: __DIR__ . '/../data/database.sqlite';
    $initNeeded = !file_exists($dbFile);
    $dsn = 'sqlite:' . $dbFile;
    $db = new PDO($dsn);
    // Ensure errors are raised as exceptions, prefer native prepares and
    // set a sane default fetch mode to avoid callers having to pass the
    // fetch mode repeatedly.
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    if ($initNeeded) {
        // Ensure the data directory exists
        $dir = dirname($dbFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        initDatabase($db);
    }
    // Always run migrations to ensure schema is up to date for SQLite
    migrateDatabase($db);
    return $db;
}

/**
 * Apply any necessary schema migrations when the database already exists.
 * Currently ensures the orders table has an 'archived' column so orders
 * can be archived/unarchived without losing their ID numbers.  If the
 * column is missing it will be added with a default value of 0.
 *
 * @param PDO $db
 */
function migrateDatabase(PDO $db): void
{
    // Ensure 'verified' column exists in customers table
    $result = $db->query("PRAGMA table_info(customers)");
    $columns = $result->fetchAll(PDO::FETCH_ASSOC);
    $hasVerified = false;
    foreach ($columns as $col) {
        if ($col['name'] === 'verified') {
            $hasVerified = true;
            break;
        }
    }
    if (!$hasVerified) {
        $db->exec("ALTER TABLE customers ADD COLUMN verified INTEGER DEFAULT 0");
    }
    // Helper closure to determine if a column exists on a table
    $columnExists = function (string $table, string $column) use ($db): bool {
        $stmt = $db->prepare("PRAGMA table_info(" . $table . ")");
        $stmt->execute();
        $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $col) {
            if (strcasecmp($col['name'], $column) === 0) {
                return true;
            }
        }
        return false;
    };
    // orders.archived column to allow archiving/unarchiving orders
    if (!$columnExists('orders', 'archived')) {
        $db->exec('ALTER TABLE orders ADD COLUMN archived INTEGER DEFAULT 0');
    }
    // customers.is_verified column to track whether a customer has verified their email
    if (!$columnExists('customers', 'is_verified')) {
        $db->exec('ALTER TABLE customers ADD COLUMN is_verified INTEGER DEFAULT 0');
    }
    // customers.verification_token column to store email verification tokens
    if (!$columnExists('customers', 'verification_token')) {
        $db->exec('ALTER TABLE customers ADD COLUMN verification_token TEXT');
    }

    // customers.reset_token column to store password reset tokens (hashed)
    if (!$columnExists('customers', 'reset_token')) {
        $db->exec('ALTER TABLE customers ADD COLUMN reset_token TEXT');
    }
    // customers.reset_token_expires column to store expiration timestamp for reset tokens
    if (!$columnExists('customers', 'reset_token_expires')) {
        $db->exec('ALTER TABLE customers ADD COLUMN reset_token_expires TEXT');
    }

    // Ensure at least one admin account exists.  If the admin table is
    // empty, insert a default admin with password 'admin'.  The
    // password_hash() function is used to securely store the password.
    $adminCount = (int)$db->query('SELECT COUNT(*) FROM admin')->fetchColumn();
    if ($adminCount === 0) {
        $defaultHash = password_hash('admin', PASSWORD_DEFAULT);
        $stmtIns = $db->prepare('INSERT INTO admin(password_hash) VALUES(:hash)');
        $stmtIns->execute([':hash' => $defaultHash]);
    }
}

/**
 * Create required tables and seed initial data.
 *
 * In a real application this function would also seed products from
 * ``data/inventory.json`` and create an admin account if none exists.
 * For brevity those steps are omitted here.
 */
function initDatabase(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS customers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        business_name TEXT,
        phone TEXT,
        email TEXT UNIQUE NOT NULL,
        billing_address TEXT,
        shipping_address TEXT,
        password_hash TEXT NOT NULL,
        -- 0 means the user has not verified their email, 1 means verified
        is_verified INTEGER DEFAULT 0,
        -- random token used for email verification
        verification_token TEXT,
        -- password reset token (hashed) and expiry timestamp
        reset_token TEXT,
        reset_token_expires TEXT
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

    // Seed a default admin account if none exists yet.  We only want to
    // insert the default admin on initial setup when there are no rows
    // in the admin table.  The default password is "admin" and is
    // hashed using PHP's password_hash() for security.  If you change
    // this password later via the manager portal or directly in the
    // database, the default insertion will be skipped on subsequent
    // runs.
    $existsStmt = $db->query('SELECT COUNT(*) FROM admin');
    $count = (int)$existsStmt->fetchColumn();
    if ($count === 0) {
        $defaultHash = password_hash('admin', PASSWORD_DEFAULT);
        $insAdmin = $db->prepare('INSERT INTO admin(password_hash) VALUES(:hash)');
        $insAdmin->execute([':hash' => $defaultHash]);
    }

    // If a pre-defined inventory JSON exists and products table is empty,
    // seed the products from that file.  This allows the catalogue to
    // populate automatically on first run without requiring manual
    // insertion.  The file should contain a JSON array of objects with
    // keys "name", "description", and "price".
    $prodCount = (int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn();
    $inventoryFile = __DIR__ . '/../data/inventory.json';
    if ($prodCount === 0 && is_readable($inventoryFile)) {
        $json = file_get_contents($inventoryFile);
        $items = json_decode($json, true);
        if (is_array($items)) {
            $insProd = $db->prepare('INSERT INTO products(name, description, price) VALUES(:name, :description, :price)');
            foreach ($items as $item) {
                if (!empty($item['name'])) {
                    $insProd->execute([
                        ':name' => $item['name'],
                        ':description' => $item['description'] ?? '',
                        ':price' => isset($item['price']) ? (float)$item['price'] : 0.0
                    ]);
                }
            }
        }
    }
}

?>
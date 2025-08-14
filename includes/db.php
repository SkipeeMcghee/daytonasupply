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
    $dbFile = __DIR__ . '/../data/database.sqlite';
    $initNeeded = !file_exists($dbFile);
    $dsn = 'sqlite:' . $dbFile;
    $db = new PDO($dsn);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if ($initNeeded) {
        // Ensure the data directory exists
        $dir = dirname($dbFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        initDatabase($db);
    }

    // Always run migrations to ensure schema is up to date
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
    /**
     * Perform schema migrations to ensure the database has all required
     * columns.  SQLite supports adding columns via ALTER TABLE, which
     * allows us to evolve the schema over time without destroying
     * existing data.  We check for missing columns and add them with
     * sensible defaults.
     */
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
        verification_token TEXT
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
}

?>
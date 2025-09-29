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
    // Global fallback reason recorded when MySQL is requested but fails.
    if (!array_key_exists('DB_FALLBACK_REASON', $GLOBALS)) {
        $GLOBALS['DB_FALLBACK_REASON'] = null;
    }
    if ($db !== null) {
        return $db;
    }
    // Load local environment overrides (if present) early so they affect
    // getenv() calls below. This file should use putenv() to set DB_*
    // variables when you need to force MySQL for web and CLI processes.
    $localCfg = __DIR__ . '/config.local.php';
    if (is_readable($localCfg)) {
        require_once $localCfg;
    }
    // Determine whether to use MySQL or SQLite. Default to SQLite for
    // development. However, prefer MySQL when DB_DRIVER=mysql is set or
    // when DB_HOST/DB_NAME/DB_USER are provided (common in production).
    $driver = getenv('DB_DRIVER') ?: '';
    $hasMySqlEnv = (getenv('DB_HOST') || getenv('DB_NAME') || getenv('DB_USER'));
    if ($driver === '' && $hasMySqlEnv) {
        // Prefer MySQL when core env vars are present
        $driver = 'mysql';
    }
    if ($driver === '') $driver = 'sqlite';
    if (strcasecmp($driver, 'mysql') === 0) {
        // Connect to MySQL
        $host = getenv('DB_HOST') ?: 'localhost';
        $name = getenv('DB_NAME') ?: 'daytona_supply';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: '';
        $dsn = "mysql:host=$host;dbname=$name;charset=utf8mb4";
        // Try to connect to MySQL; if it fails, log and gracefully fall back to
        // the bundled SQLite file so the site remains available.
        try {
            $db = new PDO($dsn, $user, $pass);
            // Use real prepared statements where possible and surface errors
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // Disable emulated prepares so the driver uses native prepares
            $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            // Use associative arrays by default for consistency
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            // For MySQL we expect that the schema has been created ahead of time
            // Ensure order/order_items snapshot columns exist in MySQL so
            // historical order snapshots are recorded when creating orders.
            try {
                ensureMySQLOrderSnapshotSchema($db);
                ensureMySQLFavoritesSchema($db);
                ensureMySQLDealsSchema($db);
            } catch (Exception $schemaEx) {
                // Log but allow connection to proceed; createOrder will fail if
                // schema is not suitable. We log to help diagnostics.
                error_log('ensureMySQLOrderSnapshotSchema error: ' . $schemaEx->getMessage());
            }
            return $db;
        } catch (Exception $e) {
            // Log the MySQL connection failure and record fallback reason.
            $errorRef = null;
            try { $errorRef = bin2hex(random_bytes(6)); } catch (Exception $_) { $errorRef = substr(md5(uniqid('', true)), 0, 12); }
            $msg = '[' . date('c') . '] getDb mysql connection failed (' . $errorRef . '): ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
            error_log($msg);
            // Persist to project-level locations for diagnosis
            $candidates = [__DIR__ . '/../data/logs', __DIR__ . '/../data', __DIR__];
            foreach ($candidates as $dir) {
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                @file_put_contents(rtrim($dir, '\\/').'/catalogue_errors.log', $msg, FILE_APPEND | LOCK_EX);
            }
            // Record fallback reason so other code can detect temporary fallback
            $GLOBALS['DB_FALLBACK_REASON'] = $msg;
            error_log('getDb: falling back to SQLite due to MySQL error (see catalogue_errors.log)');
            // Optionally prevent silent fallback in production by setting DB_STRICT=1
            $strict = getenv('DB_STRICT');
            if ($strict === '1' || $strict === 'true') {
                throw $e;
            }
            // Continue to the SQLite fallback below so the app remains usable.
        }
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
    // Best-effort ensure for SQLite-specific schema like new columns and favorites PK
    try { ensureSQLiteDealsSchema($db); } catch (Exception $_) {}
    try { ensureSQLiteFavoritesSchema($db); } catch (Exception $_) {}
    // Run migrations only when the DB was just created, or when explicitly
    // requested via RUN_MIGRATIONS=1. Avoiding migrations on every request
    // prevents repeated PRAGMA/ALTER operations that slow response times.
    if ($initNeeded || getenv('RUN_MIGRATIONS') === '1') {
        migrateDatabase($db);
    }
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

    // New address component columns.  We keep the legacy billing_address and
    // shipping_address columns for backwards compatibility but add discrete
    // components so future forms store street, street2, city, state and zip.
    $addrCols = [
        'billing_street', 'billing_street2', 'billing_city', 'billing_state', 'billing_zip',
        'shipping_street', 'shipping_street2', 'shipping_city', 'shipping_state', 'shipping_zip'
    ];
    foreach ($addrCols as $col) {
        if (!$columnExists('customers', $col)) {
            $db->exec('ALTER TABLE customers ADD COLUMN ' . $col . ' TEXT');
        }
    }

    // Legacy single-line address migration removed — data should have been
    // migrated separately before removing legacy columns. If you need to
    // run a one-off migration, use a controlled script that maps the
    // legacy fields into the discrete columns and verify results.

    // customers.reset_token column to store password reset tokens (hashed)
    if (!$columnExists('customers', 'reset_token')) {
        $db->exec('ALTER TABLE customers ADD COLUMN reset_token TEXT');
    }
    // customers.reset_token_expires column to store expiration timestamp for reset tokens
    if (!$columnExists('customers', 'reset_token_expires')) {
        $db->exec('ALTER TABLE customers ADD COLUMN reset_token_expires TEXT');
    }

    // Ensure order_items snapshot columns exist for SQLite as well. These
    // columns allow us to record a snapshot of the product's name,
    // description and price at the time the order was placed so that
    // historical orders remain accurate even if the product row changes.
    if (!$columnExists('order_items', 'product_name')) {
        $db->exec('ALTER TABLE order_items ADD COLUMN product_name TEXT NULL');
    }
    if (!$columnExists('order_items', 'product_description')) {
        $db->exec('ALTER TABLE order_items ADD COLUMN product_description TEXT NULL');
    }
    if (!$columnExists('order_items', 'product_price')) {
        // Use REAL for SQLite numeric prices
        $db->exec('ALTER TABLE order_items ADD COLUMN product_price REAL NULL');
    }

    // Backfill existing order_items with product data when available. This
    // is best-effort and will only populate rows where the snapshot fields
    // are currently NULL and the referenced product still exists in the
    // products table.
    try {
        $db->beginTransaction();
        // Populate product_name where missing
        $db->exec("UPDATE order_items SET product_name = (SELECT p.name FROM products p WHERE p.id = order_items.product_id) WHERE product_name IS NULL AND EXISTS (SELECT 1 FROM products p WHERE p.id = order_items.product_id)");
        // Populate product_description where missing
        $db->exec("UPDATE order_items SET product_description = (SELECT p.description FROM products p WHERE p.id = order_items.product_id) WHERE product_description IS NULL AND EXISTS (SELECT 1 FROM products p WHERE p.id = order_items.product_id)");
        // Populate product_price where missing
        $db->exec("UPDATE order_items SET product_price = (SELECT p.price FROM products p WHERE p.id = order_items.product_id) WHERE product_price IS NULL AND EXISTS (SELECT 1 FROM products p WHERE p.id = order_items.product_id)");
        $db->commit();
    } catch (Exception $e) {
        try { $db->rollBack(); } catch (Exception $_) {}
        error_log('migrateDatabase backfill error: ' . $e->getMessage());
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
    // Ensure signup_attempts table exists to record per-IP signup attempts
    $db->exec('CREATE TABLE IF NOT EXISTS signup_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT NOT NULL,
        attempted_at TEXT NOT NULL
    )');

    // Ensure favorites table exists for SQLite: composite primary key (id, sku)
    $db->exec('CREATE TABLE IF NOT EXISTS favorites (
        id INTEGER NOT NULL,
        sku TEXT NOT NULL,
        PRIMARY KEY (id, sku)
    )');

    // Ensure products.deal column exists for SQLite
    $hasDeal = false;
    try {
        $cols = $db->query('PRAGMA table_info(products)');
        while ($row = $cols->fetch(PDO::FETCH_ASSOC)) {
            if (strcasecmp($row['name'] ?? '', 'deal') === 0) { $hasDeal = true; break; }
        }
    } catch (Exception $_) { /* ignore */ }
    if (!$hasDeal) {
        try { $db->exec('ALTER TABLE products ADD COLUMN deal INTEGER DEFAULT 0'); } catch (Exception $e) { error_log('SQLite add products.deal failed: ' . $e->getMessage()); }
    }
}

/** Ensure SQLite has products.deal column; safe to call repeatedly. */
function ensureSQLiteDealsSchema(PDO $db): void
{
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if (strcasecmp($driver, 'sqlite') !== 0) return;
    $has = false;
    try {
        $stmt = $db->query('PRAGMA table_info(products)');
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) { if (strcasecmp($r['name'] ?? '', 'deal') === 0) { $has = true; break; } }
    } catch (Exception $e) { error_log('ensureSQLiteDealsSchema error: ' . $e->getMessage()); }
    if (!$has) {
        try { $db->exec('ALTER TABLE products ADD COLUMN deal INTEGER DEFAULT 0'); } catch (Exception $e) { error_log('ensureSQLiteDealsSchema ALTER failed: ' . $e->getMessage()); }
    }
}

/**
 * Ensure the MySQL schema contains necessary snapshot columns for orders
 * and order_items so historical snapshots are preserved. This is a
 * best-effort helper and will only run for MySQL connections.
 */
function ensureMySQLOrderSnapshotSchema(PDO $db): void
{
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if (strcasecmp($driver, 'mysql') !== 0) return;
    // Ensure orders table exists
    $db->exec("CREATE TABLE IF NOT EXISTS orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        status VARCHAR(64) NOT NULL,
    total DECIMAL(12,2) NOT NULL,
    po_number VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        archived TINYINT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Ensure order_items table exists and has snapshot columns
    $db->exec("CREATE TABLE IF NOT EXISTS order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity INT NOT NULL,
        product_name VARCHAR(255),
        product_description TEXT,
        product_price DECIMAL(12,2),
        CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Ensure snapshot columns exist (ALTER if necessary)
    $cols = [];
    $stmt = $db->query("SHOW COLUMNS FROM order_items");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) { $cols[] = $r['Field']; }
    if (!in_array('product_name', $cols, true)) {
        $db->exec("ALTER TABLE order_items ADD COLUMN product_name VARCHAR(255) NULL");
    }
    if (!in_array('product_description', $cols, true)) {
        $db->exec("ALTER TABLE order_items ADD COLUMN product_description TEXT NULL");
    }
    if (!in_array('product_price', $cols, true)) {
        $db->exec("ALTER TABLE order_items ADD COLUMN product_price DECIMAL(12,2) NULL");
    }
}

/**
 * Ensure the MySQL schema contains the favorites table used to store per-customer favorites.
 * Table structure: favorites(id INT NOT NULL, sku VARCHAR(255) NOT NULL, PRIMARY KEY(id, sku))
 */
function ensureMySQLFavoritesSchema(PDO $db): void
{
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if (strcasecmp($driver, 'mysql') !== 0) return;
    $db->exec("CREATE TABLE IF NOT EXISTS favorites (
        id INT NOT NULL,
        sku VARCHAR(255) NOT NULL,
        PRIMARY KEY (id, sku),
        CONSTRAINT fk_favorites_customer FOREIGN KEY (id) REFERENCES customers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Verify primary key is (id, sku); if not, fix it
    try {
        $cols = [];
        $stmt = $db->query("SHOW KEYS FROM favorites WHERE Key_name='PRIMARY'");
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) { $cols[] = $r['Column_name']; }
        $want = ['id','sku'];
        $colsLower = array_map('strtolower', $cols);
        if (count($cols) !== 2 || $colsLower[0] !== 'id' || $colsLower[1] !== 'sku') {
            $db->beginTransaction();
            // Drop and recreate composite primary key
            $db->exec('ALTER TABLE favorites DROP PRIMARY KEY');
            $db->exec('ALTER TABLE favorites ADD PRIMARY KEY (id, sku)');
            $db->commit();
        }
        // Drop any stray unique index on sku that would prevent multiple favorites per user
        $uniques = $db->query("SHOW INDEX FROM favorites WHERE Non_unique=0 AND Key_name<>'PRIMARY'");
        while ($idx = $uniques->fetch(PDO::FETCH_ASSOC)) {
            $key = $idx['Key_name'] ?? '';
            if ($key) {
                // Inspect columns for this index
                $colsStmt = $db->prepare('SHOW INDEX FROM favorites WHERE Key_name = :k');
                $colsStmt->execute([':k' => $key]);
                $colNames = [];
                while ($cr = $colsStmt->fetch(PDO::FETCH_ASSOC)) { $colNames[] = strtolower($cr['Column_name'] ?? ''); }
                if (count($colNames) === 1 && $colNames[0] === 'sku') {
                    try { $db->exec('DROP INDEX `' . str_replace('`','',$key) . '` ON favorites'); } catch (Exception $di) { error_log('ensureMySQLFavoritesSchema drop unique sku index failed: ' . $di->getMessage()); }
                }
            }
        }
    } catch (Exception $e) { error_log('ensureMySQLFavoritesSchema PK/unique check error: ' . $e->getMessage()); try { $db->rollBack(); } catch (Exception $_) {} }
}

/**
 * Ensure MySQL has a 'deal' column on products (TINYINT DEFAULT 0)
 */
function ensureMySQLDealsSchema(PDO $db): void
{
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if (strcasecmp($driver, 'mysql') !== 0) return;
    $cols = [];
    try {
        $stmt = $db->query('SHOW COLUMNS FROM products');
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) { $cols[] = $r['Field']; }
    } catch (Exception $e) { error_log('ensureMySQLDealsSchema SHOW COLUMNS error: ' . $e->getMessage()); return; }
    if (!in_array('deal', $cols, true)) {
        try { $db->exec('ALTER TABLE products ADD COLUMN deal TINYINT(1) DEFAULT 0'); }
        catch (Exception $e) { error_log('ensureMySQLDealsSchema ALTER failed: ' . $e->getMessage()); }
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
        price REAL NOT NULL,
        deal INTEGER DEFAULT 0
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_id INTEGER NOT NULL,
        status TEXT NOT NULL,
    total REAL NOT NULL,
    po_number TEXT,
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

    // Favorites table for SQLite
    $db->exec('CREATE TABLE IF NOT EXISTS favorites (
        id INTEGER NOT NULL,
        sku TEXT NOT NULL,
        PRIMARY KEY (id, sku)
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

    // Optional first-run seeding from inventory.json (SQLite only).
    // Disabled by default to avoid unintended overwrites; enable by setting
    // ENABLE_INVENTORY_SEED=1 in the environment before first run.
    $prodCount = (int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn();
    $inventoryFile = __DIR__ . '/../data/inventory.json';
    $seedEnabled = getenv('ENABLE_INVENTORY_SEED') === '1';
    if ($prodCount === 0 && $seedEnabled && is_readable($inventoryFile)) {
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
    } elseif ($prodCount === 0 && !$seedEnabled) {
        // No seed performed; leave products empty until inventory is updated via admin
        error_log('initDatabase: inventory seed skipped (ENABLE_INVENTORY_SEED not set)');
    }
}

/** Ensure SQLite favorites table uses composite primary key (id, sku). If not, rebuild table safely. */
function ensureSQLiteFavoritesSchema(PDO $db): void
{
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    if (strcasecmp($driver, 'sqlite') !== 0) return;
    // If table doesn't exist, create with correct schema
    try { $db->exec('CREATE TABLE IF NOT EXISTS favorites (id INTEGER NOT NULL, sku TEXT NOT NULL, PRIMARY KEY (id, sku))'); } catch (Exception $e) { /* ignore */ }
    try {
        $stmt = $db->query('PRAGMA table_info(favorites)');
        $pkCols = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($row['pk']) && (int)$row['pk'] > 0) { $pkCols[(int)$row['pk']] = strtolower($row['name']); }
        }
        // pk index in SQLite starts at 1 and orders columns; collect and sort by index
        if (!empty($pkCols)) { ksort($pkCols); }
        $pkList = array_values($pkCols);
        $ok = (count($pkList) === 2 && $pkList[0] === 'id' && $pkList[1] === 'sku');
        if (!$ok) {
            // Rebuild table to enforce composite primary key
            $db->beginTransaction();
            $db->exec('CREATE TABLE IF NOT EXISTS favorites_new (id INTEGER NOT NULL, sku TEXT NOT NULL, PRIMARY KEY (id, sku))');
            // Insert unique pairs only
            $db->exec('INSERT OR IGNORE INTO favorites_new (id, sku) SELECT id, sku FROM favorites');
            $db->exec('DROP TABLE IF EXISTS favorites');
            $db->exec('ALTER TABLE favorites_new RENAME TO favorites');
            $db->commit();
        }
        // Drop any unique index on sku-only
        try {
            $ilist = $db->query("PRAGMA index_list('favorites')");
            while ($ir = $ilist->fetch(PDO::FETCH_ASSOC)) {
                $iname = $ir['name'] ?? null; $unique = (int)($ir['unique'] ?? 0);
                if ($iname && $unique === 1) {
                    $iinfo = $db->query("PRAGMA index_info('" . str_replace("'","''", $iname) . "')");
                    $cols = [];
                    while ($ci = $iinfo->fetch(PDO::FETCH_ASSOC)) { $cols[] = strtolower($ci['name'] ?? ''); }
                    if (count($cols) === 1 && $cols[0] === 'sku') {
                        try { $db->exec('DROP INDEX IF EXISTS ' . $iname); } catch (Exception $dx) { error_log('ensureSQLiteFavoritesSchema drop unique sku index failed: ' . $dx->getMessage()); }
                    }
                }
            }
        } catch (Exception $e2) { error_log('ensureSQLiteFavoritesSchema index_list error: ' . $e2->getMessage()); }
    } catch (Exception $e) {
        try { $db->rollBack(); } catch (Exception $_) {}
        error_log('ensureSQLiteFavoritesSchema error: ' . $e->getMessage());
    }
}

?>
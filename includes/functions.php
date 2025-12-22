<?php
/**
 * Set customer verification status and send notification email.
 * @param int $customerId
 * @param bool $verified
 * @return void
 */
function setCustomerVerifiedStatus(int $customerId, bool $verified): void {
    $db = getDb();
    $db->prepare('UPDATE customers SET is_verified = :verified WHERE id = :id')
       ->execute([':verified' => $verified ? 1 : 0, ':id' => $customerId]);
    $customer = getCustomerById($customerId);
    if ($customer) {
        $email = $customer['email'];
        $name = $customer['name'];
        $subject = $verified ? 'Your account has been verified' : 'Your account has been unverified';
        $body = $verified
            ? "Hello $name,\n\nYour Daytona Supply account has been verified. You can now place orders."
            : "Hello $name,\n\nYour Daytona Supply account has been unverified. Please contact support if you have questions.";
        sendEmail($email, $subject, $body);
    }
}
/**
 * Get all customers filtered by verification status.
 * @param bool $verified True for verified, false for unverified
 * @return array
 */
function getCustomersByVerified(bool $verified): array {
    $db = getDb();
    $stmt = $db->prepare('SELECT * FROM customers WHERE is_verified = :verified ORDER BY id DESC');
    $stmt->execute([':verified' => $verified ? 1 : 0]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
// Common functions for Daytona Supply site.  These functions wrap
// database queries and other reusable logic.

require_once __DIR__ . '/db.php';

// Attempt to load Composer autoloader if PHPMailer and other vendor
// packages were installed via composer.  This will register the
// PHPMailer classes automatically.  If the autoloader file does not
// exist, it is silently ignored and the fallback mail() function will
// be used.
$vendorAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_readable($vendorAutoload)) {
    require_once $vendorAutoload;
}

// Optionally load local configuration containing environment variables.
@include __DIR__ . '/config.local.php';

// Ensure application timezone is set to local (Daytona / Eastern Time).
date_default_timezone_set('America/New_York');

// Ensure COMPANY_EMAIL environment variable has a sensible default.
if (!getenv('COMPANY_EMAIL')) {
    putenv('COMPANY_EMAIL=packinggenerals@gmail.com');
}

// Ensure NOTIFY_EMAIL defaults to COMPANY_EMAIL (used for internal notifications)
if (!getenv('NOTIFY_EMAIL')) {
    $defaultFrom = getenv('COMPANY_EMAIL') ?: 'packinggenerals@gmail.com';
    putenv('NOTIFY_EMAIL=' . $defaultFrom);
}

// Attempt to load Composer autoloader if PHPMailer and other vendor
// packages were installed via composer.  This will register the
// PHPMailer classes automatically.  If the autoloader file does not
// exist, it is silently ignored and the fallback mail() function will
// be used. (autoload handled below)
/**
 * Update customer information. Password will be updated only if provided.
 *
 * @param int $id
 * @param array $data
 * @return int|false Number of rows affected, 0 if no-op, or false on error.
 */
function updateCustomer(int $id, array $data)
{
    $db = getDb();
    // Acceptable input keys from forms. We no longer accept legacy
    // billing_address/shipping_address here — use the discrete keys only.
    $fields = ['name', 'business_name', 'phone', 'email', 'billing_street', 'billing_street2', 'billing_city', 'billing_state', 'billing_zip', 'shipping_street', 'shipping_street2', 'shipping_city', 'shipping_state', 'shipping_zip'];
    $sets = [];
    $params = [':id' => $id];
    // Map form/legacy field names to actual DB column names found in the
    // customers table. This allows the form to continue sending legacy
    // keys while the DB uses different column names (for example
    // billing_street -> billing_line1).
    $fieldMap = [
        'billing_street' => 'billing_line1',
        'billing_street2' => 'billing_line2',
        'billing_city' => 'billing_city',
        'billing_state' => 'billing_state',
        'billing_zip' => 'billing_postal_code',
        'shipping_street' => 'shipping_line1',
        'shipping_street2' => 'shipping_line2',
        'shipping_city' => 'shipping_city',
        'shipping_state' => 'shipping_state',
        'shipping_zip' => 'shipping_postal_code',
    ];

    // Ensure we only attempt to update columns that actually exist in the DB
    $existingCols = array_map('strval', getTableColumns('customers'));
    $ignored = [];
    foreach ($fields as $field) {
        if (isset($data[$field])) {
            $col = $fieldMap[$field] ?? $field;
            if (!in_array($col, $existingCols, true)) {
                $ignored[] = $field . '->' . $col;
                continue;
            }
            // Use the DB column name in the SET clause but keep the param
            // name unique by using the original form field name as the
            // parameter key. This prevents collisions and keeps logging
            // readable.
            $sets[] = "$col = :$field";
            $params[":" . $field] = $data[$field];
        }
    }
    if (!empty($data['password'])) {
        $sets[] = 'password_hash = :password_hash';
        $params[':password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
    }
    if (empty($sets)) {
        if (!empty($ignored)) {
            error_log('updateCustomer: ignored non-existent columns for customers: ' . implode(', ', $ignored));
        }
        // Nothing to update
        return 0;
    }
    // We intentionally no longer build or write legacy concatenated
    // billing_address/shipping_address values. The discrete columns are
    // authoritative and are mapped above via $fieldMap.

    $sql = 'UPDATE customers SET ' . implode(', ', $sets) . ' WHERE id = :id';
    // Diagnostic: if updating billing_postal_code (posted as billing_zip),
    // compare current DB value and the new value so we can see why it
    // might not change (e.g., identical value or normalization differences).
    try {
        if (isset($params[':billing_zip'])) {
            try {
                $col = $fieldMap['billing_zip'] ?? 'billing_zip';
                // map to DB column used earlier
                if ($col === 'billing_zip') $col = 'billing_postal_code';
                $check = $db->prepare('SELECT ' . $col . ' FROM customers WHERE id = :id');
                $check->execute([':id' => $id]);
                $current = $check->fetchColumn();
                $new = (string)$params[':billing_zip'];
                error_log('updateCustomer debug: id=' . $id . ' column=' . $col . ' current=' . var_export($current, true) . ' new=' . var_export($new, true));
            } catch (Exception $e) {
                error_log('updateCustomer debug select error: ' . $e->getMessage());
            }
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    } catch (Exception $e) {
        error_log('updateCustomer error (id=' . $id . '): ' . $e->getMessage() . ' params=' . print_r($params, true));
        return false;
    }
}

/**
 * Retrieve all products from the database ordered by ID.
 *
 * @return array An array of associative arrays describing products.
 */
function getAllProducts(): array
{
    $cacheKey = 'daytona_all_products_v1';
    $useApc = function_exists('apcu_fetch');
    if ($useApc) {
        $cached = @apcu_fetch($cacheKey, $ok);
        if ($ok && is_array($cached)) return $cached;
    } else {
        $cacheFile = __DIR__ . '/../data/cache_products.json';
        if (is_readable($cacheFile)) {
            $json = @file_get_contents($cacheFile);
            $arr = json_decode($json, true);
            if (is_array($arr)) return $arr;
        }
    }
    $db = getDb();
    // Always return products ordered by name (our SKU) for consistent UI ordering
    $stmt = $db->query('SELECT * FROM products ORDER BY name ASC');
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Cache the result for subsequent requests
    if ($useApc) {
        @apcu_store($cacheKey, $products, 300); // 5 minutes
    } else {
        // best-effort write
        @file_put_contents(__DIR__ . '/../data/cache_products.json', json_encode($products), LOCK_EX);
    }
    return $products;
}

/**
 * Retrieve all products directly from the database, bypassing any cache.
 * Useful for admin/manager views where immediate consistency is required.
 *
 * @return array An array of associative arrays describing products.
 */
function getAllProductsFresh(): array
{
    $db = getDb();
    $stmt = $db->query('SELECT * FROM products ORDER BY name ASC');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Invalidate the products cache used by getAllProducts(). This clears the APCu
 * entry when available and deletes the on-disk cache file. Safe to call even if
 * the cache does not exist.
 */
function invalidateProductsCache(): void
{
    $cacheKey = 'daytona_all_products_v1';
    if (function_exists('apcu_delete')) {
        @apcu_delete($cacheKey);
    }
    $cacheFile = __DIR__ . '/../data/cache_products.json';
    if (is_file($cacheFile)) {
        @unlink($cacheFile);
    }
}

/**
 * Return list of columns for a table. Supports MySQL (SHOW COLUMNS) and SQLite (PRAGMA).
 * @param string $table
 * @return string[]
 */
function getTableColumns(string $table): array
{
    $db = getDb();
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    try {
        if ($driver === 'sqlite') {
            $stmt = $db->query('PRAGMA table_info(' . $table . ')');
            $cols = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $cols[] = $row['name'];
            }
            return $cols;
        }
        // default to MySQL-compatible
        $stmt = $db->query('SHOW COLUMNS FROM `' . $table . '`');
        $cols = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cols[] = $row['Field'];
        }
        return $cols;
    } catch (Exception $e) {
        error_log('getTableColumns error for ' . $table . ': ' . $e->getMessage());
        return [];
    }
}

/**
 * Escape user-supplied terms for use with SQL LIKE queries. This will
 * escape common wildcard characters and wrap the term with percent
 * signs for contains-style matches. Use with prepared statements.
 *
 * @param string $term
 * @param PDO|null $db Optional PDO to access driver-specific escape rules (not used)
 * @return string
 */
function likeTerm(string $term): string
{
    // Replace backslash first to avoid double-escaping
    $term = str_replace('\\', '\\\\', $term);
    // Escape % and _ which are SQL wildcards
    $term = str_replace(['%', '_'], ['\\%', '\\_'], $term);
    // Wrap with wildcards for a contains match
    return '%' . $term . '%';
}

/**
 * Normalize and validate a simple scalar input. Trims and enforces a
 * maximum length to prevent overly long values being used in queries
 * or stored. Returns the default if the resulting value is empty.
 *
 * @param mixed $value
 * @param int $maxLen
 * @param string|null $default
 * @return string|null
 */
function normalizeScalar($value, int $maxLen = 255, ?string $default = null): ?string
{
    $s = trim((string)($value ?? ''));
    if ($s === '') return $default;
    if (strlen($s) > $maxLen) {
        $s = substr($s, 0, $maxLen);
    }
    return $s;
}

/**
 * Retrieve a single product by its ID.
 *
 * @param int $id Product ID
 * @return array|null Associative array describing the product or null if not found.
 */
function getProductById(int $id): ?array
{
    $db = getDb();
    $stmt = $db->prepare('SELECT * FROM products WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $prod = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($prod) return $prod;
    // DB lookup missed — attempt a best-effort fallback to the on-disk
    // product cache so the site can still display product details when
    // the database is temporarily unavailable or the row is missing.
    try {
        $cacheFile = __DIR__ . '/../data/cache_products.json';
        if (is_readable($cacheFile)) {
            $json = @file_get_contents($cacheFile);
            $arr = $json ? json_decode($json, true) : null;
            if (is_array($arr)) {
                foreach ($arr as $p) {
                    if (isset($p['id']) && (int)$p['id'] === $id) {
                        error_log('getProductById: falling back to cache_products.json for id=' . $id);
                        return $p;
                    }
                }
            }
        }
    } catch (Exception $e) {
        // ignore and return null below
    }
    return null;
}

/**
 * Return a best-effort display SKU/name for a product row.
 * Supports products that may use 'name', 'sku' or 'code' as the identifier.
 */
function getProductDisplayName(array $prod): string
{
    if (empty($prod)) return '';
    if (!empty($prod['name'])) return (string)$prod['name'];
    if (!empty($prod['sku'])) return (string)$prod['sku'];
    if (!empty($prod['code'])) return (string)$prod['code'];
    return '';
}

/**
 * Return a best-effort product description.
 */
function getProductDescription(array $prod): string
{
    if (empty($prod)) return '';
    if (!empty($prod['description'])) return (string)$prod['description'];
    if (!empty($prod['desc'])) return (string)$prod['desc'];
    // fallback to name/sku if no description
    return getProductDisplayName($prod);
}

/**
 * Return a best-effort product price as float. Supports columns like
 * 'price', 'unit_price', 'list_price'. Returns 0.0 when unknown.
 */
function getProductPrice(array $prod): float
{
    if (empty($prod)) return 0.0;
    // Prefer deal_price when deal is active and a deal price exists
    if (!empty($prod['deal'])) {
        if (isset($prod['deal_price']) && $prod['deal_price'] !== '' && $prod['deal_price'] !== null) {
            return (float)$prod['deal_price'];
        }
    }
    foreach (['price', 'unit_price', 'list_price', 'cost'] as $c) {
        if (isset($prod[$c]) && $prod[$c] !== '') {
            return (float)$prod[$c];
        }
    }
    return 0.0;
}

/**
 * Get the list of favorite SKUs for a customer from the favorites table.
 * The favorites table is expected to have columns (id, sku) where id = customer id.
 * Returns an array of SKU strings (product name values).
 */
function getFavoriteSkusByCustomerId(int $customerId): array
{
    $db = getDb();
    try {
        // Ensure the table exists; if not, this will throw and we return empty
        $stmt = $db->prepare('SELECT sku FROM favorites WHERE id = :id');
        $stmt->execute([':id' => $customerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        return array_values(array_filter(array_map('strval', (array)$rows)));
    } catch (Exception $e) {
        error_log('getFavoriteSkusByCustomerId error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Toggle a favorite for a given customer and product id.
 * Uses the product name as the SKU value in favorites.sku per requirements.
 * Returns true if now favorited, false if removed or on failure.
 */
function toggleFavoriteForCustomerProduct(int $customerId, int $productId): bool
{
    $db = getDb();
    try {
        $prod = getProductById($productId);
        if (!$prod) return false;
        $sku = getProductDisplayName($prod);
        if ($sku === '') return false;
        // Check if exists
        $check = $db->prepare('SELECT 1 FROM favorites WHERE id = :id AND sku = :sku LIMIT 1');
        $check->execute([':id' => $customerId, ':sku' => $sku]);
        $exists = (bool)$check->fetchColumn();
        if ($exists) {
            $del = $db->prepare('DELETE FROM favorites WHERE id = :id AND sku = :sku');
            $del->execute([':id' => $customerId, ':sku' => $sku]);
            return false; // now unfavorited
        } else {
            $ins = $db->prepare('INSERT INTO favorites (id, sku) VALUES (:id, :sku)');
            $ins->execute([':id' => $customerId, ':sku' => $sku]);
            return true; // now favorited
        }
    } catch (Exception $e) {
        error_log('toggleFavoriteForCustomerProduct error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Create a new order record and its associated items.  The cart array
 * must map product IDs to quantities.  The total is calculated
 * automatically.
 *
 * @param int $customerId ID of the customer placing the order
 * @param array $cart Map of product IDs to quantities
 * @return int Newly created order ID
 */
function createOrder(int $customerId, array $cart, float $taxAmount = 0.0, ?string $poNumber = null): int
{
    $db = getDb();
    $db->beginTransaction();
    try {
        // Calculate order total. Support new cart shape where each entry
        // may be a snapshot array (product_name, product_description,
        // product_price, quantity) or the legacy productId => qty map.
        $total = 0.0;
        foreach ($cart as $productId => $entry) {
            if (is_array($entry) && isset($entry['quantity'])) {
                $qty = (int)$entry['quantity'];
                $price = isset($entry['product_price']) ? (float)$entry['product_price'] : null;
                if ($price === null || $price === 0.0) {
                    $prod = getProductById((int)$productId);
                    if ($prod) $price = (float)$prod['price']; else $price = 0.0;
                }
                $total += $price * $qty;
            } else {
                $qty = (int)$entry;
                $prod = getProductById((int)$productId);
                if ($prod) {
                    $total += $prod['price'] * $qty;
                }
            }
        }
        // Apply tax amount if provided
        if ($taxAmount && $taxAmount > 0) {
            $total += $taxAmount;
        }
        // Insert order
        $insOrder = $db->prepare('INSERT INTO orders(customer_id, status, total, po_number, created_at) VALUES(:customer_id, :status, :total, :po_number, :created_at)');
        $insOrder->execute([
            ':customer_id' => $customerId,
            ':status' => 'Pending',
            ':total' => $total,
            ':po_number' => $poNumber,
            ':created_at' => date('c')
        ]);
        $orderId = (int)$db->lastInsertId();
        if (empty($orderId)) {
            // Order insert failed to produce an ID — fail loudly so calling
            // code (checkout) can report an error and we don't send emails
            // with an empty order id.
            $db->rollBack();
            throw new Exception('Failed to create order (no insert id returned)');
        }
    // Insert order items and snapshot product name/description/price
        // if the order_items table contains those columns. Fall back to
        // a minimal insert when the migration hasn't been applied yet.
        $cols = array_map('strval', getTableColumns('order_items'));
        $hasName = in_array('product_name', $cols, true);
        $hasDesc = in_array('product_description', $cols, true);
        $hasPrice = in_array('product_price', $cols, true);

        // If using MySQL, prefer snapshot columns (create them earlier in
        // the connection setup). If they are missing, ensureMySQLOrderSnapshotSchema
        // should have attempted to add them; re-check now and prefer snapshots
        // when available so historical snapshots are always stored.
        try {
            $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (Exception $_) {
            $driver = 'sqlite';
        }
        if (strcasecmp($driver, 'mysql') === 0) {
            // re-evaluate columns to prefer snapshot path
            $cols = array_map('strval', getTableColumns('order_items'));
            $hasName = in_array('product_name', $cols, true) || $hasName;
            $hasDesc = in_array('product_description', $cols, true) || $hasDesc;
            $hasPrice = in_array('product_price', $cols, true) || $hasPrice;
        }

        // If using MySQL, temporarily disable foreign key checks to allow
        // inserting historical snapshots even if the product FK references
        // a legacy table (e.g. products_old) or the product row has been
        // removed. We'll re-enable checks before committing.
        $driver = 'sqlite';
        try { $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME); } catch (Exception $_) {}
        $fkDisabled = false;
        if (strcasecmp($driver, 'mysql') === 0) {
            try {
                $db->exec('SET FOREIGN_KEY_CHECKS=0');
                $fkDisabled = true;
                error_log('createOrder: disabled FOREIGN_KEY_CHECKS for order snapshot insert');
            } catch (Exception $e) {
                error_log('createOrder: failed to disable FOREIGN_KEY_CHECKS: ' . $e->getMessage());
            }
        }

        if ($hasName || $hasDesc || $hasPrice) {
            // Build dynamic insert based on available snapshot columns
            $fields = ['order_id', 'product_id', 'quantity'];
            $params = [':order_id', ':product_id', ':quantity'];
            if ($hasName) { $fields[] = 'product_name'; $params[] = ':product_name'; }
            if ($hasDesc) { $fields[] = 'product_description'; $params[] = ':product_description'; }
            if ($hasPrice) { $fields[] = 'product_price'; $params[] = ':product_price'; }
            $sql = 'INSERT INTO order_items(' . implode(',', $fields) . ') VALUES(' . implode(',', $params) . ')';
            $insItem = $db->prepare($sql);
            foreach ($cart as $productId => $entry) {
                // Support snapshot entries stored in session cart
                if (is_array($entry) && isset($entry['quantity'])) {
                    $qty = (int)$entry['quantity'];
                    $pname = $entry['product_name'] ?? '';
                    $pdesc = $entry['product_description'] ?? '';
                    $pprice = isset($entry['product_price']) ? (float)$entry['product_price'] : null;
                } else {
                    $qty = (int)$entry;
                    $prod = getProductById((int)$productId);
                    $pname = $prod ? getProductDisplayName($prod) : '';
                    $pdesc = $prod ? getProductDescription($prod) : '';
                    $pprice = $prod ? getProductPrice($prod) : 0.0;
                }
                // If snapshot price was not provided, fall back to current product
                if ($pprice === null) {
                    $prod = getProductById((int)$productId);
                    $pprice = $prod ? ((float)($prod['price'] ?? 0.0)) : 0.0;
                }
                $bind = [':order_id' => $orderId, ':product_id' => (int)$productId, ':quantity' => (int)$qty];
                if ($hasName) $bind[':product_name'] = $pname;
                if ($hasDesc) $bind[':product_description'] = $pdesc;
                if ($hasPrice) $bind[':product_price'] = $pprice;
                $insItem->execute($bind);
            }
        } else {
            // Minimal legacy insert
            $insItem = $db->prepare('INSERT INTO order_items(order_id, product_id, quantity) VALUES(:order_id, :product_id, :quantity)');
            foreach ($cart as $productId => $qty) {
                $insItem->execute([
                    ':order_id' => $orderId,
                    ':product_id' => (int)$productId,
                    ':quantity' => (int)$qty
                ]);
            }
        }
        // Re-enable FK checks if we disabled them earlier
        if (!empty($fkDisabled)) {
            try {
                $db->exec('SET FOREIGN_KEY_CHECKS=1');
                error_log('createOrder: re-enabled FOREIGN_KEY_CHECKS after inserts');
            } catch (Exception $e) {
                error_log('createOrder: failed to re-enable FOREIGN_KEY_CHECKS: ' . $e->getMessage());
            }
        }
        $db->commit();
        return $orderId;
    } catch (Exception $e) {
        // Attempt to re-enable FK checks if we disabled them before rolling back
        if (!empty($fkDisabled)) {
            try { $db->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Exception $_) { }
        }
        $db->rollBack();
        throw $e;
    }
}

/**
 * Send an email using PHP's mail() function.  A default
 * From/Reply-To header is derived from COMPANY_EMAIL.  If
 * mail() is disabled in the environment the @ operator will
 * suppress warnings.  In a production system consider using
 * a proper SMTP library instead.
 *
 * @param string $to Recipient email address
 * @param string $subject Email subject
 * @param string $message Email body
 */
function sendEmail(string $to, string $subject, string $message, ?string $replyTo = null): bool
{
    /*
     * This helper attempts to send email using PHPMailer if it is available.
     * To enable SMTP delivery via PHPMailer, install the PHPMailer library
     * (e.g. via Composer) and define SMTP settings via environment
     * variables: SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS and SMTP_AUTH.
     * If PHPMailer is not installed or sending fails, the function falls
     * back to PHP's built‑in mail() function.
     */
    $from = getenv('COMPANY_EMAIL') ?: 'packinggenerals@gmail.com';
    // Try PHPMailer if the class exists
    if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        try {
            $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
            // Use SMTP if host specified, otherwise use PHP mail
            $smtpHost = getenv('SMTP_HOST');
            if ($smtpHost) {
                $mailer->isSMTP();
                $mailer->Host = $smtpHost;
                $mailer->Port = getenv('SMTP_PORT') ?: 25;
                // Enable SMTPAuth only if user/password provided
                $user = getenv('SMTP_USER');
                $pass = getenv('SMTP_PASS');
                if ($user && $pass) {
                    $mailer->SMTPAuth = true;
                    $mailer->Username = $user;
                    $mailer->Password = $pass;
                }
                $secure = getenv('SMTP_SECURE');
                if ($secure) {
                    $mailer->SMTPSecure = $secure;
                }
            }
            $mailer->CharSet = 'UTF-8';
            $mailer->Encoding = '8bit';
            $mailer->setFrom($from, 'Daytona Supply');
            $mailer->addAddress($to);
            // If a valid reply-to address was supplied, add it so replies go to the user
            if (!empty($replyTo) && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                $mailer->addReplyTo($replyTo);
            }
            $mailer->Subject = $subject;
            $mailer->Body = $message;
            // Attempt to send
            $mailer->send();
            return true;
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            error_log('PHPMailer error: ' . $e->getMessage());
            // Fall through to built‑in mail() fallback
        }
    }
    // Fallback to PHP mail()
    // Use the supplied reply-to if valid, otherwise keep Reply-To as the company address
    $effectiveReply = (!empty($replyTo) && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) ? $replyTo : $from;
    $headers = 'From: ' . $from . "\r\n"
             . 'Reply-To: ' . $effectiveReply . "\r\n"
             . 'MIME-Version: 1.0' . "\r\n"
             . 'Content-Type: text/plain; charset=UTF-8' . "\r\n"
             . 'Content-Transfer-Encoding: 8bit' . "\r\n"
             . 'X-Mailer: PHP/' . phpversion();
    $success = @mail($to, $subject, $message, $headers);
    if (!$success) {
        error_log('sendEmail: Failed sending to ' . $to . ' subject "' . $subject . '"');
        // Development fallback: when running locally (CLI) or when the
        // environment explicitly opts-in, write the email to disk so tests
        // and developers can inspect the message and the app can continue
        // as if the email was delivered. This avoids hard failures when a
        // local mail transport is not available.
        $appEnv = getenv('APP_ENV') ?: null;
        if (php_sapi_name() === 'cli' || $appEnv === 'development' || getenv('EMAIL_DEV_OUT')) {
            $outDir = __DIR__ . '/../data/emails';
            if (!is_dir($outDir)) {
                @mkdir($outDir, 0777, true);
            }
            try {
                $filename = $outDir . '/email_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.eml';
            } catch (Exception $e) {
                // Fallback to less secure random name if random_bytes fails
                $filename = $outDir . '/email_' . date('Ymd_His') . '_' . uniqid() . '.eml';
            }
            $contents = "To: $to\nSubject: $subject\n\n$message\n";
            @file_put_contents($filename, $contents);
            error_log('sendEmail: wrote email to ' . $filename . ' (dev fallback)');
            return true;
        }
    }
    return $success;
}

/**
 * Generate a random verification token for email verification.  A token
 * consists of hexadecimal characters derived from cryptographically
 * secure random bytes.  By default a 32‑character token is returned.
 *
 * @param int $length Length of the token in characters (must be even)
 * @return string
 */
function generateVerificationToken(int $length = 32): string
{
    // Ensure length is even because bin2hex outputs two hex chars per byte
    if ($length % 2 !== 0) {
        $length++;
    }
    return bin2hex(random_bytes((int)($length / 2)));
}

/**
 * Set a new verification token for a customer and mark them as not verified.
 * This helper is used when a new customer registers and needs to verify
 * their email address.  It stores the provided token and resets
 * is_verified to 0.
 *
 * @param int $customerId
 * @param string $token
 * @return void
 */
function setCustomerVerification(int $customerId, string $token): void
{
    $db = getDb();
    $stmt = $db->prepare('UPDATE customers SET verification_token = :token, is_verified = 0 WHERE id = :id');
    $stmt->execute([
        ':token' => $token,
        ':id' => $customerId
    ]);
    error_log('setCustomerVerification: stored token for customer id=' . $customerId . ' token=' . $token);
}

/**
 * Attempt to resend a verification email to the given customer email.
 * Enforces a throttle of 1 resend per hour per account.
 * Returns an array with keys: ok (bool) and message (string).
 */
function resendVerificationToCustomerByEmail(string $email): array
{
    $email = trim($email);
    if ($email === '') return ['ok' => false, 'message' => 'Please provide an email address.'];
    $customer = getCustomerByEmail($email);
    if (!$customer) {
        // Don't reveal whether the address exists to callers; but since
        // this function is intended for a user who already attempted to
        // login, be slightly informative.
        return ['ok' => false, 'message' => 'No account found for that email address.'];
    }
    if (!empty($customer['is_verified']) && (int)$customer['is_verified'] === 1) {
        return ['ok' => false, 'message' => 'This account is already verified. You can log in.'];
    }

    $db = getDb();
    $existingCols = array_map('strval', getTableColumns('customers'));
    $now = time();
    // If the verification_sent_at column exists, enforce the 1/hour cap
    if (in_array('verification_sent_at', $existingCols, true) && !empty($customer['verification_sent_at'])) {
        $last = strtotime($customer['verification_sent_at']);
        if ($last !== false && ($now - $last) < 3600) {
            $remaining = 3600 - ($now - $last);
            $mins = ceil($remaining / 60);
            return ['ok' => false, 'message' => "You can only resend once per hour. Please wait $mins minute(s) and try again."];
        }
    }

    // Generate a fresh token and store it
    $token = generateVerificationToken();
    setCustomerVerification((int)$customer['id'], $token);
    // Update verification_sent_at if the column exists; if not, ignore
    if (in_array('verification_sent_at', $existingCols, true)) {
        $upd = $db->prepare('UPDATE customers SET verification_sent_at = :sent WHERE id = :id');
        $upd->execute([':sent' => date('c'), ':id' => $customer['id']]);
    }

    // Compose verification email body (same format as signup)
    $verifyLink = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['REQUEST_URI'] ?? '/') . '/verify.php?token=' . urlencode($token);
    $body = "Hello " . ($customer['name'] ?? '') . ",\n\n";
    $body .= "Please verify your Daytona Supply account by clicking the link below:\n\n";
    $body .= $verifyLink . "\n\n";
    $body .= "If you did not sign up, please ignore this message.";

    $subject = 'Verify your Daytona Supply account';
    $sent = sendEmail($customer['email'], $subject, $body);
    if ($sent) {
        return ['ok' => true, 'message' => 'Verification email resent. Please check your inbox.'];
    }
    return ['ok' => false, 'message' => 'Failed to send verification email. Please try again later.'];
}

/**
 * Verify a customer using the provided token.  If the token matches a
 * customer who has not yet been verified, the customer's is_verified
 * flag is set to 1 and their verification_token is cleared.  The
 * updated customer record is returned.  If the token is invalid or
 * already used, null is returned.
 *
 * @param string $token
 * @return array|null
 */
function verifyCustomer(string $token): ?array
{
    $db = getDb();
    $db->beginTransaction();
    try {
        // Look up the customer by token and ensure they are not yet verified
        $sel = $db->prepare('SELECT * FROM customers WHERE verification_token = :token AND is_verified = 0');
        $sel->execute([':token' => $token]);
        $customer = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$customer) {
            $db->rollBack();
            return null;
        }
        // Update verification status
        $upd = $db->prepare('UPDATE customers SET is_verified = 1, verification_token = NULL WHERE id = :id');
        $upd->execute([':id' => $customer['id']]);
        $db->commit();
        // Refresh and return the updated customer
        return getCustomerById((int)$customer['id']);
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Retrieve a customer by their email address.  Returns null if no
 * customer with the given email exists.  The email comparison is
 * case-insensitive.
 *
 * @param string $email
 * @return array|null
 */
function getCustomerByEmail(string $email): ?array
{
    $db = getDb();
    $stmt = $db->prepare('SELECT * FROM customers WHERE LOWER(email) = LOWER(:email)');
    $stmt->execute([':email' => $email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Set a password reset token and its expiry time for a customer.  The
 * provided token will be hashed using password_hash() before being
 * stored.  The expiry should be an ISO8601 timestamp.  Any previous
 * reset token and expiry will be overwritten.
 *
 * @param int $customerId
 * @param string $token  The raw token to hash and store
 * @param string $expires ISO8601 timestamp when the token expires
 */
function setPasswordResetToken(int $customerId, string $token, string $expires): void
{
    $db = getDb();
    $hash = password_hash($token, PASSWORD_DEFAULT);
    $stmt = $db->prepare('UPDATE customers SET reset_token = :hash, reset_token_expires = :expires WHERE id = :id');
    $stmt->execute([
        ':hash' => $hash,
        ':expires' => $expires,
        ':id' => $customerId
    ]);
}

/**
 * Find a customer by password reset token.  This function verifies
 * that the provided raw token matches the stored hashed token and
 * checks that the expiry has not passed.  If the token is valid and
 * unexpired the matching customer record is returned; otherwise null
 * is returned.
 *
 * @param string $token
 * @return array|null
 */
function getCustomerByResetToken(string $token): ?array
{
    $db = getDb();
    // Fetch all customers with a non-null reset token
    $stmt = $db->query('SELECT * FROM customers WHERE reset_token IS NOT NULL');
    $now = new DateTime();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $expiresAt = isset($row['reset_token_expires']) ? new DateTime($row['reset_token_expires']) : null;
        // Ensure token has not expired
        if ($expiresAt && $expiresAt < $now) {
            continue;
        }
        $hash = $row['reset_token'];
        if ($hash && password_verify($token, $hash)) {
            return $row;
        }
    }
    return null;
}

/**
 * Clear any password reset token and expiry for a customer.  Call this
 * after a successful password reset or when invalidating tokens.
 *
 * @param int $customerId
 */
function clearPasswordResetToken(int $customerId): void
{
    $db = getDb();
    $stmt = $db->prepare('UPDATE customers SET reset_token = NULL, reset_token_expires = NULL WHERE id = :id');
    $stmt->execute([':id' => $customerId]);
}

/**
 * Authenticate a customer by email and password.
 *
 * @param string $email
 * @param string $password
 * @return array|null Returns the customer row on success or null on failure.
 */
function authenticateCustomer(string $email, string $password): ?array
{
    $db = getDb();
    $stmt = $db->prepare('SELECT * FROM customers WHERE email = :email');
    $stmt->execute([':email' => $email]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($customer && password_verify($password, $customer['password_hash'])) {
        return $customer;
    }
    return null;
}

/**
 * Create a new customer record.
 *
 * @param array $data Associative array containing keys: name, business_name,
 *                    phone, email, billing_line1, billing_line2, billing_city, billing_state, billing_postal_code,
 *                    shipping_line1, shipping_line2, shipping_city, shipping_state, shipping_postal_code, password.
 * @return int Newly created customer ID.
 * @throws Exception If email already exists or validation fails.
 */
function createCustomer(array $data): int
{
    $db = getDb();
    // Validate required fields
    if (empty($data['name']) || empty($data['email']) || empty($data['password'])) {
        throw new Exception('Name, email and password are required');
    }
    // Check for existing email
    $stmt = $db->prepare('SELECT id FROM customers WHERE email = :email');
    $stmt->execute([':email' => $data['email']]);
    $existing = $stmt->fetch();
    if ($existing) {
        // Debug output
        error_log('DEBUG: Email already registered: ' . $data['email'] . ' (ID: ' . $existing['id'] . ')');
        throw new Exception('Email already registered');
    } else {
        error_log('DEBUG: Email not found, proceeding to insert: ' . $data['email']);
    }
    error_log('DEBUG: Data to insert: ' . print_r($data, true));
    // Hash password and insert
    $hash = password_hash($data['password'], PASSWORD_DEFAULT);
    // Only use discrete address components if they were explicitly provided
    // in the $data array (e.g., from the account page). Map them to the
    // final database column names (billing_line1 etc.). We intentionally
    // avoid creating or storing legacy single-line address fields.
    $billing_line1 = array_key_exists('billing_street', $data) ? ($data['billing_street'] ?? '') : '';
    $billing_line2 = array_key_exists('billing_street2', $data) ? ($data['billing_street2'] ?? '') : '';
    $billing_city = array_key_exists('billing_city', $data) ? ($data['billing_city'] ?? '') : '';
    $billing_state = array_key_exists('billing_state', $data) ? ($data['billing_state'] ?? '') : '';
    $billing_postal_code = array_key_exists('billing_zip', $data) ? ($data['billing_zip'] ?? '') : '';

    $shipping_line1 = array_key_exists('shipping_street', $data) ? ($data['shipping_street'] ?? '') : '';
    $shipping_line2 = array_key_exists('shipping_street2', $data) ? ($data['shipping_street2'] ?? '') : '';
    $shipping_city = array_key_exists('shipping_city', $data) ? ($data['shipping_city'] ?? '') : '';
    $shipping_state = array_key_exists('shipping_state', $data) ? ($data['shipping_state'] ?? '') : '';
    $shipping_postal_code = array_key_exists('shipping_zip', $data) ? ($data['shipping_zip'] ?? '') : '';

    // Determine which address column naming the DB uses and insert accordingly.
    $existingCols = array_map('strval', getTableColumns('customers'));
    if (in_array('billing_line1', $existingCols, true) && in_array('shipping_line1', $existingCols, true)) {
        // New schema using billing_line1 / shipping_line1
        $ins = $db->prepare('INSERT INTO customers (name, business_name, phone, email, billing_line1, billing_line2, billing_city, billing_state, billing_postal_code, shipping_line1, shipping_line2, shipping_city, shipping_state, shipping_postal_code, password_hash) VALUES (:name, :business_name, :phone, :email, :billing_line1, :billing_line2, :billing_city, :billing_state, :billing_postal_code, :shipping_line1, :shipping_line2, :shipping_city, :shipping_state, :shipping_postal_code, :password_hash)');
        $ins->execute([
            ':name' => $data['name'],
            ':business_name' => $data['business_name'] ?? '',
            ':phone' => $data['phone'] ?? '',
            ':email' => $data['email'],
            ':billing_line1' => $billing_line1,
            ':billing_line2' => $billing_line2,
            ':billing_city' => $billing_city,
            ':billing_state' => $billing_state,
            ':billing_postal_code' => $billing_postal_code,
            ':shipping_line1' => $shipping_line1,
            ':shipping_line2' => $shipping_line2,
            ':shipping_city' => $shipping_city,
            ':shipping_state' => $shipping_state,
            ':shipping_postal_code' => $shipping_postal_code,
            ':password_hash' => $hash
        ]);
    } else {
        // Legacy schema using billing_street / shipping_street (SQLite fallback)
        error_log('createCustomer: falling back to legacy address columns for customers table');
        $ins = $db->prepare('INSERT INTO customers (name, business_name, phone, email, billing_street, billing_street2, billing_city, billing_state, billing_zip, shipping_street, shipping_street2, shipping_city, shipping_state, shipping_zip, password_hash) VALUES (:name, :business_name, :phone, :email, :billing_street, :billing_street2, :billing_city, :billing_state, :billing_zip, :shipping_street, :shipping_street2, :shipping_city, :shipping_state, :shipping_zip, :password_hash)');
        $ins->execute([
            ':name' => $data['name'],
            ':business_name' => $data['business_name'] ?? '',
            ':phone' => $data['phone'] ?? '',
            ':email' => $data['email'],
            ':billing_street' => $billing_line1,
            ':billing_street2' => $billing_line2,
            ':billing_city' => $billing_city,
            ':billing_state' => $billing_state,
            ':billing_zip' => $billing_postal_code,
            ':shipping_street' => $shipping_line1,
            ':shipping_street2' => $shipping_line2,
            ':shipping_city' => $shipping_city,
            ':shipping_state' => $shipping_state,
            ':shipping_zip' => $shipping_postal_code,
            ':password_hash' => $hash
        ]);
    }
    return (int)$db->lastInsertId();
}

/**
 * Retrieve a customer by ID.
 *
 * @param int $id
 * @return array|null
 */
function getCustomerById(int $id): ?array
{
    $db = getDb();
    $stmt = $db->prepare('SELECT * FROM customers WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    return $customer ?: null;
}

/**
 * Update customer information. Password will be updated only if provided.
 *
 * @param int $id
 * @param array $data
 */
// ...existing code... (duplicate older updateCustomer removed)

/**
 * Save or update a product. If $id is null, a new record is created.
 *
 * @param array $data
 * @param int|null $id
 */
function saveProduct(array $data, ?int $id = null): void
{
    $db = getDb();
    $driver = 'unknown';
    try { $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME); } catch (Exception $_) {}
    if ($id === null) {
        $stmt = $db->prepare('INSERT INTO products(name, description, price) VALUES(:name, :description, :price)');
        $stmt->execute([
            ':name' => $data['name'],
            ':description' => $data['description'] ?? '',
            ':price' => (float)$data['price']
        ]);
        $newId = (int)$db->lastInsertId();
        error_log('saveProduct: insert id=' . $newId . ' via driver=' . $driver);
    } else {
        $stmt = $db->prepare('UPDATE products SET name=:name, description=:description, price=:price WHERE id=:id');
        $stmt->execute([
            ':id' => $id,
            ':name' => $data['name'],
            ':description' => $data['description'] ?? '',
            ':price' => (float)$data['price']
        ]);
        $rc = (int)$stmt->rowCount();
        error_log('saveProduct: update id=' . $id . ' affected=' . $rc . ' via driver=' . $driver);
    }
    // Ensure subsequent reads reflect the new data immediately
    invalidateProductsCache();
}

/**
 * Delete a product by ID.
 *
 * @param int $id
 */
function deleteProduct(int $id): void
{
    $db = getDb();
    $stmt = $db->prepare('DELETE FROM products WHERE id = :id');
    $stmt->execute([':id' => $id]);
    // Invalidate product caches so UIs reflect the deletion
    invalidateProductsCache();
}

/**
 * Retrieve all customers.
 *
 * @return array
 */
/**
 * Retrieve all customers.  By default this returns all customer records
 * sorted by ID.  If $onlyUnverified is true, only customers who have
 * not verified their email (is_verified = 0) are returned.
 *
 * @param bool $onlyUnverified Whether to return only unverified customers
 * @return array
 */
function getAllCustomers(bool $onlyUnverified = false): array
{
    $db = getDb();
    if ($onlyUnverified) {
        $stmt = $db->query('SELECT * FROM customers WHERE is_verified = 0 ORDER BY id ASC');
    } else {
        $stmt = $db->query('SELECT * FROM customers ORDER BY id ASC');
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Retrieve all orders for a given customer.
 *
 * @param int $customerId
 * @return array
 */
function getOrdersByCustomer(int $customerId): array
{
    $db = getDb();
    $stmt = $db->prepare('SELECT * FROM orders WHERE customer_id = :customer_id ORDER BY created_at DESC');
    $stmt->execute([':customer_id' => $customerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Retrieve orders with optional filtering by archived status.  Orders are
 * returned in reverse chronological order of creation.  Each order row
 * includes the customer name and email for convenience.  To include
 * archived orders, set $includeArchived to true.  To show only archived
 * orders, set $onlyArchived to true.
 *
 * @param bool $includeArchived If true, include both active and archived orders.
 * @param bool $onlyArchived If true, return only archived orders.
 * @return array
 */
function getAllOrders(bool $includeArchived = false, bool $onlyArchived = false): array
{
    $db = getDb();
    $where = '';
    if ($onlyArchived) {
        $where = 'WHERE o.archived = 1';
    } elseif (!$includeArchived) {
        $where = 'WHERE o.archived = 0';
    }
    $sql = 'SELECT o.*, c.name AS customer_name, c.email AS customer_email FROM orders o ' .
           'JOIN customers c ON o.customer_id = c.id ' .
           $where . ' ORDER BY o.created_at DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Search products by term.  Returns products whose name or description contains the term.
 *
 * @param string $term
 * @return array
 */
// legacy search wrapper preserved below

/**
 * New wrapper to centralize product search behavior. Use this from pages
 * so search logic lives in one place and can be adjusted for different
 * DB drivers or performance optimizations without changing templates.
 *
 * @param string $term
 * @param PDO|null $db Optional PDO instance to use (helps testing)
 * @return array
 */
function getProductsBySearch(string $term, ?PDO $db = null): array
{
    $db = $db ?? getDb();
    // Normalize term length and trim
    $term = normalizeScalar($term, 150, '');
    if ($term === '') return [];

    // Escape wildcard chars and use a parameterized query. Use the
    // same ESCAPE character for portability across drivers.
    $escapeChar = "\\";
    $sql = 'SELECT * FROM products WHERE name LIKE :term ESCAPE ' . "'" . $escapeChar . "'" . ' OR description LIKE :term ESCAPE ' . "'" . $escapeChar . "'" . ' ORDER BY id ASC';
    $stmt = $db->prepare($sql);
    $stmt->execute([':term' => likeTerm($term)]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Keep the old name for backward compatibility
function searchProducts(string $term): array
{
    return getProductsBySearch($term);
}

/**
 * Update the status of an order.  Status should be a human‑readable
 * string such as "Pending", "Approved" or "Rejected".  Manager
 * actions in the portal call this function.
 *
 * @param int $orderId
 * @param string $status
 */
function updateOrderStatus(int $orderId, string $status, ?string $managerNote = null): void
{
    $db = getDb();
    // Update the order status
    $stmt = $db->prepare('UPDATE orders SET status = :status WHERE id = :id');
    $stmt->execute([
        ':status' => $status,
        ':id' => $orderId
    ]);
    // Send a notification email to the customer about the status change
    try {
        // Fetch order with customer info and PO number
        $query = $db->prepare('SELECT o.id, o.status, o.po_number, c.name AS customer_name, c.email AS customer_email FROM orders o JOIN customers c ON o.customer_id = c.id WHERE o.id = :id');
        $query->execute([':id' => $orderId]);
        $order = $query->fetch(PDO::FETCH_ASSOC);
        if ($order && !empty($order['customer_email'])) {
            $to = $order['customer_email'];
            // Include PO number in the subject when present for quick reference
            $poText = (!empty($order['po_number'])) ? ' (PO: ' . $order['po_number'] . ')' : '';
            $subj = 'Your Order #' . $order['id'] . ' has been updated' . $poText;

                 // Use simple ASCII dash to avoid mojibake if a client mishandles UTF-8
                 $msg = "Hello " . $order['customer_name'] . ",\n\n" .
                     "Your order #" . $order['id'] . (!empty($poText) ? ' - PO: ' . $order['po_number'] : '') . " has been " . strtolower($status) . ".\n";

            // If the manager left a note, place it directly under the status line
            if ($managerNote && trim($managerNote) !== '') {
                $msg .= "\nMessage from our team:\n" . trim($managerNote) . "\n";
            }

            // Continue with account login prompt and thank-you footer
            $msg .= "\nYou can log in to your account to view the details.\n\n";
            $msg .= "Thank you for shopping with us.";
            sendEmail($to, $subj, $msg);
        }
    } catch (Exception $e) {
        // Log but do not interrupt order status change on email failure
        error_log('updateOrderStatus email error: ' . $e->getMessage());
    }
}

/**
 * Retrieve all items belonging to a specific order.
 *
 * @param int $orderId
 * @return array
 */
function getOrderItems(int $orderId): array
{
    $db = getDb();
    $stmt = $db->prepare('SELECT * FROM order_items WHERE order_id = :order_id');
    $stmt->execute([':order_id' => $orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Archive or unarchive an order by setting the archived flag.  When an
 * order is archived it will not appear in the default order list but
 * remains in the database so that its ID stays consistent.  Passing
 * false for $archive will unarchive the order.
 *
 * @param int $orderId
 * @param bool $archive
 */
function archiveOrder(int $orderId, bool $archive = true): void
{
    $db = getDb();
    $stmt = $db->prepare('UPDATE orders SET archived = :archived WHERE id = :id');
    $stmt->execute([
        ':archived' => $archive ? 1 : 0,
        ':id' => $orderId
    ]);
}

/**
 * Delete a customer along with all of their orders and related order items.
 * This action will remove the customer record and any associated orders
 * and order_items.  Order and product ID sequences are not reset so
 * other orders retain their original IDs.  Use cautiously because this
 * operation cannot be undone.
 *
 * @param int $customerId
 */
function deleteCustomer(int $customerId): void
{
    $db = getDb();
    $db->beginTransaction();
    try {
        // Find all orders for this customer
        $stmtOrders = $db->prepare('SELECT id FROM orders WHERE customer_id = :cust');
        $stmtOrders->execute([':cust' => $customerId]);
        $orderIds = $stmtOrders->fetchAll(PDO::FETCH_COLUMN, 0);
        if (!empty($orderIds)) {
            // Prepare placeholders for IN clause
            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
            // Delete order items referencing these orders
            $delItems = $db->prepare('DELETE FROM order_items WHERE order_id IN (' . $placeholders . ')');
            $delItems->execute($orderIds);
            // Delete the orders themselves
            $delOrders = $db->prepare('DELETE FROM orders WHERE id IN (' . $placeholders . ')');
            $delOrders->execute($orderIds);
        }
        // Delete customer
        $delCust = $db->prepare('DELETE FROM customers WHERE id = :id');
        $delCust->execute([':id' => $customerId]);
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

// Do not close the PHP tag to prevent accidental output from trailing whitespace.
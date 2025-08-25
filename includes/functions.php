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
// This file can be created by administrators to override settings such as
// COMPANY_EMAIL, SMTP credentials, and other custom values without
// modifying the main codebase.  If the file does not exist it is
// ignored.
@include __DIR__ . '/config.local.php';

// Ensure application timezone is set to local (Daytona / Eastern Time).
// This prevents order times from being stored/displayed in UTC which
// previously resulted in a four-hour offset for the owner.
// Force application timezone to Eastern Time to avoid UTC/host mismatches.
// This ensures order timestamps display in ET consistently.
date_default_timezone_set('America/New_York');

/**
 * Ensure the COMPANY_EMAIL environment variable is set.  Many parts of
 * the application rely on getenv('COMPANY_EMAIL') to determine where
 * order notification emails should be sent.  If it is not defined
 * externally (e.g. via the web server configuration) we default
 * to packinggenerals@gmail.com.  Using putenv() here means the
 * environment variable is available to subsequent code and library
 * calls without requiring additional configuration.
 */
if (!getenv('COMPANY_EMAIL')) {
    putenv('COMPANY_EMAIL=packinggenerals@gmail.com');
}

/**
 * Retrieve all products from the database ordered by ID.
 *
 * @return array An array of associative arrays describing products.
 */
function getAllProducts(): array
{
    $db = getDb();
    $stmt = $db->query('SELECT * FROM products ORDER BY id ASC');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    return $prod ?: null;
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
function createOrder(int $customerId, array $cart, float $taxAmount = 0.0): int
{
    $db = getDb();
    $db->beginTransaction();
    try {
        // Calculate order total
        $total = 0.0;
        foreach ($cart as $productId => $qty) {
            $prod = getProductById((int)$productId);
            if ($prod) {
                $total += $prod['price'] * $qty;
            }
        }
        // Apply tax amount if provided
        if ($taxAmount && $taxAmount > 0) {
            $total += $taxAmount;
        }
        // Insert order
        $insOrder = $db->prepare('INSERT INTO orders(customer_id, status, total, created_at) VALUES(:customer_id, :status, :total, :created_at)');
        $insOrder->execute([
            ':customer_id' => $customerId,
            ':status' => 'Pending',
            ':total' => $total,
            ':created_at' => date('c')
        ]);
        $orderId = (int)$db->lastInsertId();
        // Insert order items
        $insItem = $db->prepare('INSERT INTO order_items(order_id, product_id, quantity) VALUES(:order_id, :product_id, :quantity)');
        foreach ($cart as $productId => $qty) {
            $insItem->execute([
                ':order_id' => $orderId,
                ':product_id' => (int)$productId,
                ':quantity' => (int)$qty
            ]);
        }
        $db->commit();
        return $orderId;
    } catch (Exception $e) {
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
function sendEmail(string $to, string $subject, string $message): bool
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
            $mailer->setFrom($from, 'Daytona Supply');
            $mailer->addAddress($to);
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
    $headers = 'From: ' . $from . "\r\n"
             . 'Reply-To: ' . $from . "\r\n"
             . 'X-Mailer: PHP/' . phpversion();
    $success = @mail($to, $subject, $message, $headers);
    if (!$success) {
        error_log('sendEmail: Failed sending to ' . $to . ' subject "' . $subject . '"');
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
 *                    phone, email, billing_address, shipping_address, password.
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
    // in the $data array (e.g., from the account page). Do not infer
    // billing_street/shipping_street from the legacy single-line values
    // to avoid silently overwriting customer inputs.
    $billing_street = array_key_exists('billing_street', $data) ? ($data['billing_street'] ?? '') : '';
    $billing_street2 = array_key_exists('billing_street2', $data) ? ($data['billing_street2'] ?? '') : '';
    $billing_city = array_key_exists('billing_city', $data) ? ($data['billing_city'] ?? '') : '';
    $billing_state = array_key_exists('billing_state', $data) ? ($data['billing_state'] ?? '') : '';
    $billing_zip = array_key_exists('billing_zip', $data) ? ($data['billing_zip'] ?? '') : '';

    $shipping_street = array_key_exists('shipping_street', $data) ? ($data['shipping_street'] ?? '') : '';
    $shipping_street2 = array_key_exists('shipping_street2', $data) ? ($data['shipping_street2'] ?? '') : '';
    $shipping_city = array_key_exists('shipping_city', $data) ? ($data['shipping_city'] ?? '') : '';
    $shipping_state = array_key_exists('shipping_state', $data) ? ($data['shipping_state'] ?? '') : '';
    $shipping_zip = array_key_exists('shipping_zip', $data) ? ($data['shipping_zip'] ?? '') : '';

    // Keep legacy billing_address/shipping_address populated from provided
    // legacy keys if present (signup form may still provide these), otherwise
    // build from explicit discrete parts only when those were provided.
    if (isset($data['billing_address'])) {
        $legacy_bill = $data['billing_address'];
    } else {
        $legacy_bill = trim($billing_street . "\n" . $billing_street2 . "\n" . trim($billing_city . ' ' . $billing_state . ' ' . $billing_zip));
    }
    if (isset($data['shipping_address'])) {
        $legacy_ship = $data['shipping_address'];
    } else {
        $legacy_ship = trim($shipping_street . "\n" . $shipping_street2 . "\n" . trim($shipping_city . ' ' . $shipping_state . ' ' . $shipping_zip));
    }

    $ins = $db->prepare('INSERT INTO customers (name, business_name, phone, email, billing_address, shipping_address, billing_street, billing_street2, billing_city, billing_state, billing_zip, shipping_street, shipping_street2, shipping_city, shipping_state, shipping_zip, password_hash) VALUES (:name, :business_name, :phone, :email, :billing_address, :shipping_address, :billing_street, :billing_street2, :billing_city, :billing_state, :billing_zip, :shipping_street, :shipping_street2, :shipping_city, :shipping_state, :shipping_zip, :password_hash)');
    $ins->execute([
        ':name' => $data['name'],
        ':business_name' => $data['business_name'] ?? '',
        ':phone' => $data['phone'] ?? '',
        ':email' => $data['email'],
        ':billing_address' => $legacy_bill,
        ':shipping_address' => $legacy_ship,
        ':billing_street' => $billing_street,
        ':billing_street2' => $billing_street2,
        ':billing_city' => $billing_city,
        ':billing_state' => $billing_state,
        ':billing_zip' => $billing_zip,
        ':shipping_street' => $shipping_street,
        ':shipping_street2' => $shipping_street2,
        ':shipping_city' => $shipping_city,
        ':shipping_state' => $shipping_state,
        ':shipping_zip' => $shipping_zip,
        ':password_hash' => $hash
    ]);
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
function updateCustomer(int $id, array $data): void
{
    $db = getDb();
    $fields = ['name', 'business_name', 'phone', 'email', 'billing_address', 'shipping_address', 'billing_street', 'billing_street2', 'billing_city', 'billing_state', 'billing_zip', 'shipping_street', 'shipping_street2', 'shipping_city', 'shipping_state', 'shipping_zip'];
    $sets = [];
    $params = [':id' => $id];
    foreach ($fields as $field) {
        if (isset($data[$field])) {
            $sets[] = "$field = :$field";
            $params[":$field"] = $data[$field];
        }
    }
    if (!empty($data['password'])) {
        $sets[] = 'password_hash = :password_hash';
        $params[':password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
    }
    if ($sets) {
        // If discrete address components are present, ensure legacy concatenated
        // fields reflect the same primary street value to avoid data loss.
        if (isset($params[':billing_street']) && (!isset($params[':billing_address']) || trim((string)$params[':billing_address']) === '')) {
            $params[':billing_address'] = trim($params[':billing_street'] . "\n" . ($params[':billing_street2'] ?? '') . "\n" . trim(($params[':billing_city'] ?? '') . ' ' . ($params[':billing_state'] ?? '') . ' ' . ($params[':billing_zip'] ?? '')));
            $sets[] = 'billing_address = :billing_address';
        }
        if (isset($params[':shipping_street']) && (!isset($params[':shipping_address']) || trim((string)$params[':shipping_address']) === '')) {
            $params[':shipping_address'] = trim($params[':shipping_street'] . "\n" . ($params[':shipping_street2'] ?? '') . "\n" . trim(($params[':shipping_city'] ?? '') . ' ' . ($params[':shipping_state'] ?? '') . ' ' . ($params[':shipping_zip'] ?? '')));
            $sets[] = 'shipping_address = :shipping_address';
        }

        $sql = 'UPDATE customers SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
    }
}

/**
 * Save or update a product. If $id is null, a new record is created.
 *
 * @param array $data
 * @param int|null $id
 */
function saveProduct(array $data, ?int $id = null): void
{
    $db = getDb();
    if ($id === null) {
        $stmt = $db->prepare('INSERT INTO products(name, description, price) VALUES(:name, :description, :price)');
        $stmt->execute([
            ':name' => $data['name'],
            ':description' => $data['description'] ?? '',
            ':price' => (float)$data['price']
        ]);
    } else {
        $stmt = $db->prepare('UPDATE products SET name=:name, description=:description, price=:price WHERE id=:id');
        $stmt->execute([
            ':id' => $id,
            ':name' => $data['name'],
            ':description' => $data['description'] ?? '',
            ':price' => (float)$data['price']
        ]);
    }
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
        // Fetch order with customer info
        $query = $db->prepare('SELECT o.id, o.status, c.name AS customer_name, c.email AS customer_email FROM orders o JOIN customers c ON o.customer_id = c.id WHERE o.id = :id');
        $query->execute([':id' => $orderId]);
        $order = $query->fetch(PDO::FETCH_ASSOC);
        if ($order && !empty($order['customer_email'])) {
            $to = $order['customer_email'];
            $subj = 'Your Order #' . $order['id'] . ' has been updated';
            $msg = "Hello " . $order['customer_name'] . ",\n\n" .
                   "Your order #" . $order['id'] . " has been " . strtolower($status) . ".\n\n" .
                   "You can log in to your account to view the details.\n\n";
            if ($managerNote && trim($managerNote) !== '') {
                $msg .= "Message from our team:\n" . trim($managerNote) . "\n\n";
            }
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
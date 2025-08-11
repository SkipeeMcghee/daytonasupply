<?php
// Common functions for Daytona Supply site.  These functions wrap
// database queries and other reusable logic.

require_once __DIR__ . '/db.php';

/**
 * Create a new customer record.
 *
 * @param array $data Associative array containing keys: name,
 *                    business_name, phone, email, billing_address,
 *                    shipping_address, password.
 * @return int Newly created customer ID.
 * @throws Exception If email already exists or validation fails.
 */
function createCustomer(array $data): int
{
    $db = getDb();
    // Ensure required fields
    if (empty($data['name']) || empty($data['email']) || empty($data['password'])) {
        throw new Exception('Name, email and password are required');
    }
    // Check if email exists
    $stmt = $db->prepare('SELECT id FROM customers WHERE email = :email');
    $stmt->execute([':email' => $data['email']]);
    if ($stmt->fetch()) {
        throw new Exception('Email already registered');
    }
    // Hash password using password_hash
    $hash = password_hash($data['password'], PASSWORD_DEFAULT);
    $ins = $db->prepare('INSERT INTO customers (name, business_name, phone, email, billing_address, shipping_address, password_hash) VALUES (:name, :business_name, :phone, :email, :billing_address, :shipping_address, :password_hash)');
    $ins->execute([
        ':name' => $data['name'],
        ':business_name' => $data['business_name'] ?? '',
        ':phone' => $data['phone'] ?? '',
        ':email' => $data['email'],
        ':billing_address' => $data['billing_address'] ?? '',
        ':shipping_address' => $data['shipping_address'] ?? '',
        ':password_hash' => $hash
    ]);
    return (int)$db->lastInsertId();
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
 * Update customer information.  Password will be updated only if
 * provided.
 *
 * @param int $id
 * @param array $data
 */
function updateCustomer(int $id, array $data): void
{
    $db = getDb();
    $fields = ['name', 'business_name', 'phone', 'email', 'billing_address', 'shipping_address'];
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
        $sql = 'UPDATE customers SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
    }
}

/**
 * Get all products.
 *
 * @return array
 */
function getAllProducts(): array
{
    $db = getDb();
    $stmt = $db->query('SELECT * FROM products ORDER BY id ASC');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get product by ID.
 *
 * @param int $id
 * @return array|null
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
 * Add a product or update existing product details.  If $id is null a
 * new record will be created.
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
 * Create a new order and associated order items.  Expects $cart
 * to be an associative array mapping product IDs to quantities.
 *
 * @param int $customerId
 * @param array $cart
 * @return int Order ID
 */
function createOrder(int $customerId, array $cart): int
{
    $db = getDb();
    $db->beginTransaction();
    try {
        // Calculate total
        $total = 0.0;
        foreach ($cart as $productId => $qty) {
            $prod = getProductById((int)$productId);
            if ($prod) {
                $total += $prod['price'] * $qty;
            }
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
 * Retrieve all orders.
 *
 * @return array
 */
function getAllOrders(): array
{
    $db = getDb();
    $stmt = $db->query('SELECT * FROM orders ORDER BY created_at DESC');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Retrieve items for a given order ID.
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
 * Update the status of an order and optionally send an email to the
 * customer notifying them of the change.  Valid statuses: Pending,
 * Approved, Rejected.
 *
 * @param int $orderId
 * @param string $status
 */
function updateOrderStatus(int $orderId, string $status): void
{
    $db = getDb();
    $stmt = $db->prepare('UPDATE orders SET status = :status WHERE id = :id');
    $stmt->execute([
        ':status' => $status,
        ':id' => $orderId
    ]);
    // Send email notification if order is approved
    if ($status === 'Approved' || $status === 'Rejected') {
        // Fetch order and customer
        $orderStmt = $db->prepare('SELECT * FROM orders WHERE id = :id');
        $orderStmt->execute([':id' => $orderId]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
        if ($order) {
            $cust = getCustomerById((int)$order['customer_id']);
            if ($cust) {
                $subject = 'Your Purchase Order #' . $orderId . ' has been ' . strtolower($status);
                $message = "Dear {$cust['name']},\n\n" .
                    "Your purchase order #{$orderId} has been {$status}.\n" .
                    "Total amount: $" . number_format($order['total'], 2) . "\n\n" .
                    "Thank you for shopping with Daytona Supply.";
                sendEmail($cust['email'], $subject, $message);
            }
        }
    }
}

/**
 * Retrieve all customers.
 *
 * @return array
 */
function getAllCustomers(): array
{
    $db = getDb();
    $stmt = $db->query('SELECT * FROM customers ORDER BY id ASC');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Send an email using PHP's mail() function.  This is a thin wrapper
 * that sets appropriate headers.  When sending to multiple recipients
 * separate addresses with a comma.
 *
 * Note: The mail() function may not work in all environments; on
 * shared hosting or dev machines it may be disabled.  In such cases
 * consider configuring an SMTP library (e.g. PHPMailer) instead.
 *
 * @param string $to
 * @param string $subject
 * @param string $message
 */
function sendEmail(string $to, string $subject, string $message): void
{
    $headers = 'From: ' . (getenv('COMPANY_EMAIL') ?: 'noreply@example.com') . "\r\n" .
               'Reply-To: ' . (getenv('COMPANY_EMAIL') ?: 'noreply@example.com') . "\r\n" .
               'X-Mailer: PHP/' . phpversion();
    // Use @ to suppress warnings if mail() is not configured
    @mail($to, $subject, $message, $headers);
}

?>
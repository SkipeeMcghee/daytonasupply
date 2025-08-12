<?php
// Common functions for Daytona Supply site.  These functions wrap
// database queries and other reusable logic.

require_once __DIR__ . '/db.php';

/**
 * Ensure the COMPANY_EMAIL environment variable is set.  Many parts of
 * the application rely on getenv('COMPANY_EMAIL') to determine where
 * order notification emails should be sent.  If it is not defined
 * externally (e.g. via the web server configuration) we default
 * to brianheise22@gmail.com.  Using putenv() here means the
 * environment variable is available to subsequent code and library
 * calls without requiring additional configuration.
 */
if (!getenv('COMPANY_EMAIL')) {
    putenv('COMPANY_EMAIL=brianheise22@gmail.com');
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
function createOrder(int $customerId, array $cart): int
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
function sendEmail(string $to, string $subject, string $message): void
{
    $from = getenv('COMPANY_EMAIL') ?: 'brianheise22@gmail.com';
    $headers = 'From: ' . $from . "\r\n" .
               'Reply-To: ' . $from . "\r\n" .
               'X-Mailer: PHP/' . phpversion();
    @mail($to, $subject, $message, $headers);
}

?>
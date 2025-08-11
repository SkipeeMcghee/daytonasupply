<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

$title = 'Checkout';

// Require cart not empty
$cart = $_SESSION['cart'] ?? [];
if (!$cart) {
    echo '<p>Your cart is empty. <a href="/catalogue.php">Go back to catalogue</a>.</p>';
    include __DIR__ . '/includes/footer.php';
    return;
}

// Require login
if (!isset($_SESSION['customer'])) {
    header('Location: /login.php');
    exit;
}

$customer = $_SESSION['customer'];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Create order
    $orderId = createOrder((int)$customer['id'], $cart);
    // Prepare email to company
    $itemsDesc = [];
    foreach ($cart as $pid => $qty) {
        $prod = getProductById((int)$pid);
        if ($prod) {
            $itemsDesc[] = $prod['name'] . ' x' . $qty . ' ($' . number_format($prod['price'] * $qty, 2) . ')';
        }
    }
    $body = "A new purchase order has been placed.\n\n" .
            "Order ID: {$orderId}\n" .
            "Customer: {$customer['name']} ({$customer['email']})\n\n" .
            "Items:\n" . implode("\n", $itemsDesc) . "\n\n" .
            "Total: $" . number_format(array_sum(array_map(function($pid, $qty) {
                $prod = getProductById((int)$pid);
                return $prod ? $prod['price'] * $qty : 0;
            }, array_keys($cart), $cart)), 2) . "\n\n" .
            "Please review and approve this order in the manager portal.";
    // Send email to company
    $companyEmail = getenv('COMPANY_EMAIL') ?: '';
    if ($companyEmail) {
        sendEmail($companyEmail, 'New Purchase Order #' . $orderId, $body);
    }
    // Clear cart
    $_SESSION['cart'] = [];
    $message = 'Thank you! Your order has been submitted and is awaiting approval.';
}

// Build order summary
$items = [];
$total = 0.0;
foreach ($cart as $pid => $qty) {
    $prod = getProductById((int)$pid);
    if ($prod) {
        $subtotal = $prod['price'] * $qty;
        $items[] = [
            'name' => $prod['name'],
            'price' => $prod['price'],
            'qty' => $qty,
            'subtotal' => $subtotal
        ];
        $total += $subtotal;
    }
}
?>

<h2>Checkout</h2>
<?php if ($message): ?>
    <div class="message"><?php echo htmlspecialchars($message); ?></div>
<?php else: ?>
    <table class="cart-table">
        <tr><th>Product</th><th>Price</th><th>Quantity</th><th>Subtotal</th></tr>
        <?php foreach ($items as $it): ?>
            <tr>
                <td><?php echo htmlspecialchars($it['name']); ?></td>
                <td>$<?php echo number_format($it['price'], 2); ?></td>
                <td><?php echo $it['qty']; ?></td>
                <td>$<?php echo number_format($it['subtotal'], 2); ?></td>
            </tr>
        <?php endforeach; ?>
        <tr><td colspan="3" style="text-align:right"><strong>Total:</strong></td><td><strong>$<?php echo number_format($total, 2); ?></strong></td></tr>
    </table>
    <form method="post" action="">
        <p><button type="submit">Place Order</button></p>
    </form>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

$title = 'Your Cart';
$message = '';

// Handle cart update form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_cart'])) {
    if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
        foreach ($_SESSION['cart'] as $pid => $qty) {
            $field = 'qty_' . $pid;
            if (isset($_POST[$field])) {
                $newQty = (int)$_POST[$field];
                if ($newQty <= 0) {
                    unset($_SESSION['cart'][$pid]);
                } else {
                    $_SESSION['cart'][$pid] = $newQty;
                }
            }
        }
        $message = 'Cart updated.';
    }
}

// Build cart details
$cartItems = [];
$total = 0.0;
if (!empty($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $pid => $qty) {
        $product = getProductById((int)$pid);
        if ($product) {
            $subtotal = $product['price'] * $qty;
            $cartItems[] = [
                'id' => (int)$pid,
                'name' => $product['name'],
                'price' => $product['price'],
                'quantity' => $qty,
                'subtotal' => $subtotal
            ];
            $total += $subtotal;
        }
    }
}
?>

<h2>Your Cart</h2>
<?php if ($message): ?>
    <div class="message"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>
<?php if (empty($cartItems)): ?>
    <p>Your cart is empty.  <a href="/catalogue.php">Browse products</a>.</p>
<?php else: ?>
    <form method="post" action="">
        <input type="hidden" name="update_cart" value="1">
        <table class="cart-table">
            <tr><th>Product</th><th>Price</th><th>Quantity</th><th>Subtotal</th></tr>
            <?php foreach ($cartItems as $item): ?>
                <tr>
                    <td><?php echo htmlspecialchars($item['name']); ?></td>
                    <td>$<?php echo number_format($item['price'], 2); ?></td>
                    <td><input type="number" name="qty_<?php echo $item['id']; ?>" value="<?php echo $item['quantity']; ?>" min="0" style="width:60px"></td>
                    <td>$<?php echo number_format($item['subtotal'], 2); ?></td>
                </tr>
            <?php endforeach; ?>
            <tr><td colspan="3" style="text-align:right"><strong>Total:</strong></td><td><strong>$<?php echo number_format($total, 2); ?></strong></td></tr>
        </table>
        <p><button type="submit">Update Cart</button></p>
    </form>
    <p><a href="/checkout.php" class="button">Proceed to Checkout</a></p>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
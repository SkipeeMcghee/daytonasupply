<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

$title = 'Your Cart';
$message = '';

// Handle adding items to cart when posted directly to this page.  This
// supports backward compatibility with forms that may still post to
// cart.php.  The Post/Redirect/Get pattern is used to prevent
// duplicate submissions if the user refreshes the page.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['product_id'])) {
    $pid = (int)$_POST['product_id'];
    $qty = (int)($_POST['quantity'] ?? 1);
    if ($qty < 1) {
        $qty = 1;
    }
    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }
    if (isset($_SESSION['cart'][$pid])) {
        $_SESSION['cart'][$pid] += $qty;
    } else {
        $_SESSION['cart'][$pid] = $qty;
    }
    // Redirect to the cart using a relative path so it works from subfolders
    header('Location: cart.php');
    exit;
}

// Immediate remove via remove_item is handled by update_cart flow: keep compatibility
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_item'])) {
    $rid = (int)$_POST['remove_item'];
    if (isset($_SESSION['cart'][$rid])) {
        // set quantity to 0 and allow the update handler below to process it
        $_POST['qty_' . $rid] = 0;
        $_POST['update_cart'] = 1;
    }
}

// Handle cart update form (quantity adjustments/removals)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_cart'])) {
    if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
        foreach (array_keys($_SESSION['cart']) as $pid) {
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

// Build cart details for display
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
<section class="page-hero">
    <h2>Your Cart</h2>
</section>
<?php if ($message): ?>
    <div class="message"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>
<?php if (empty($cartItems)): ?>
    <p>Your cart is empty.  <a href="catalogue.php">Browse products</a>.</p>
<?php else: ?>
    <form method="post" action="">
        <input type="hidden" name="update_cart" value="1">
        <table class="cart-table">
            <tr>
                <th>SKU</th>
                <th>Description</th>
                <th>Quantity</th>
                <th>Rate</th>
                <th>Price</th>
                <th></th>
            </tr>
            <?php foreach ($cartItems as $item): ?>
                <?php $prod = getProductById((int)$item['id']);
                      // Use the product 'name' field as the visible SKU/code.
                      $sku = $prod ? htmlspecialchars($prod['name']) : $item['id'];
                      $description = $prod ? htmlspecialchars($prod['description'] ?? $prod['name']) : htmlspecialchars($item['name']); ?>
                <tr>
                    <td><?php echo $sku; ?></td>
                    <td><?php echo $description; ?></td>
                    <td><input type="number" name="qty_<?php echo $item['id']; ?>" value="<?php echo $item['quantity']; ?>" min="0" style="width:60px"></td>
                    <td>$<?php echo number_format($item['price'], 2); ?></td>
                    <td>$<?php echo number_format($item['subtotal'], 2); ?></td>
                        <td style="text-align:center;">
                            <button type="button" class="cart-remove-btn" title="Remove item" data-item-id="<?php echo $item['id']; ?>">×</button>
                        </td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td colspan="4" style="text-align:right"><strong>Total:</strong></td>
                <td><strong>$<?php echo number_format($total, 2); ?></strong></td>
            </tr>
        </table>
    <p><button type="submit" class="proceed-btn muted-btn">Update Cart</button></p>
    </form>
    <script>
    // Replace remove buttons behaviour: set the qty input to 0 and submit the form
    document.addEventListener('DOMContentLoaded', function() {
        var buttons = document.querySelectorAll('.cart-remove-btn[data-item-id]');
        buttons.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var id = btn.getAttribute('data-item-id');
                var qtyField = document.querySelector('input[name="qty_' + id + '"]');
                if (qtyField) {
                    qtyField.value = 0;
                    // submit the enclosing form
                    var form = document.querySelector('form[action=""]');
                    if (form) form.submit();
                }
            });
        });
    });
    </script>
    <p><a href="checkout.php" class="proceed-btn">Proceed to Checkout</a></p>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
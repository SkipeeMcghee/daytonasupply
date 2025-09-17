<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

$title = 'Checkout';

// Require cart not empty
$cart = $_SESSION['cart'] ?? [];
if (!$cart) {
    // Use relative link for catalogue so it works when the site is served from a subfolder
    echo '<p>Your cart is empty. <a href="catalogue.php">Go back to catalogue</a>.</p>';
    include __DIR__ . '/includes/footer.php';
    return;
}

// Require login
if (!isset($_SESSION['customer'])) {
    header('Location: login.php');
    exit;
}

$customer = $_SESSION['customer'];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Apply optional sales tax (6.5%)
    $applyTax = isset($_POST['apply_tax']) && ($_POST['apply_tax'] === '1' || $_POST['apply_tax'] === 'on');
    $cartTotal = 0.0;
    $itemsDesc = [];
    foreach ($cart as $pid => $qty) {
        $prod = getProductById((int)$pid);
        if ($prod) {
            $itemsDesc[] = $prod['name'] . ' x' . $qty . ' ($' . number_format($prod['price'] * $qty, 2) . ')';
            $cartTotal += $prod['price'] * $qty;
        }
    }
    $taxAmount = $applyTax ? round($cartTotal * 0.065, 2) : 0.0;
    // Collect optional PO number
    $poNumber = isset($_POST['po_number']) ? trim((string)$_POST['po_number']) : null;
    // Create order (include tax)
    try {
        $orderId = createOrder((int)$customer['id'], $cart, $taxAmount, $poNumber);
    } catch (Exception $e) {
        // Don't expose raw DB errors to users; log and show friendly message
        error_log('checkout createOrder error: ' . $e->getMessage());
        $message = 'An error occurred while placing your order. Please try again or contact support.';
        $orderId = null;
    }
    // Prepare email to company
    $body = "A new purchase order has been placed.\n\n" .
            ( !empty($orderId) ? "Order ID: {$orderId}\n" : "Order ID: (not created)\n" ) .
            "Customer: {$customer['name']} ({$customer['email']})\n" .
            ( !empty($poNumber) ? "PO Number: {$poNumber}\n" : "" ) .
            "\n" .
            "Items:\n" . implode("\n", $itemsDesc) . "\n\n" .
            "Subtotal: $" . number_format($cartTotal, 2) . "\n" .
            ($taxAmount > 0 ? ("Tax: $" . number_format($taxAmount, 2) . "\n") : "") .
            "Total: $" . number_format($cartTotal + $taxAmount, 2) . "\n\n" .
            "Please review and approve this order in the manager portal.";
    // Only send email and clear cart if order creation succeeded
    if (!empty($orderId)) {
        // Send email to company
        $companyEmail = getenv('COMPANY_EMAIL') ?: 'packinggenerals@gmail.com';
        if ($companyEmail) {
            $subject = 'New Purchase Order #' . $orderId;
            if (!empty($poNumber)) $subject .= ' (PO: ' . $poNumber . ')';
            sendEmail($companyEmail, $subject, $body);
        }
        // Clear cart
        $_SESSION['cart'] = [];
        $message = 'Thank you! Your order has been submitted and is awaiting approval. You will receive an email reply shortly.';
    } else {
        // No order ID — report a friendly failure
        $message = 'An error occurred while placing your order. Please try again or contact support.';
    }
}

// Build order summary
$items = [];
$total = 0.0;
foreach ($cart as $pid => $qty) {
    $prod = getProductById((int)$pid);
    if ($prod) {
        $subtotal = $prod['price'] * $qty;
        $items[] = [
            'id' => (int)$pid,
            'name' => $prod['name'],
            'description' => $prod['description'] ?? '',
            'price' => $prod['price'],
            'qty' => $qty,
            'subtotal' => $subtotal
        ];
        $total += $subtotal;
    }
}
?>

<section class="page-hero">
    <h2>Checkout</h2>
</section>
<?php if ($message): ?>
    <div class="message"><?php echo htmlspecialchars($message); ?></div>
<?php else: ?>
    <table class="cart-table">
        <tr>
            <th>SKU</th>
            <th>Description</th>
            <th class="numeric">Quantity</th>
            <th class="numeric">Rate</th>
            <th class="numeric">Price</th>
        </tr>
        <?php foreach ($items as $it): ?>
            <?php $prod = getProductById((int)$it['id']);
                  // The product 'name' field is used as the visible SKU/code.
                  $sku = $prod ? htmlspecialchars($prod['name']) : htmlspecialchars($it['name']);
                  $description = $prod ? htmlspecialchars($prod['description'] ?? $prod['name']) : htmlspecialchars($it['name']); ?>
            <tr>
                <td><?php echo $sku; ?></td>
                <td><?php echo $description; ?></td>
                <td class="numeric"><?php echo $it['qty']; ?></td>
                <td class="numeric">$<?php echo number_format($it['price'], 2); ?></td>
                <td class="numeric">$<?php echo number_format($it['subtotal'], 2); ?></td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <td colspan="4" style="text-align:right"><strong>Subtotal:</strong></td>
            <td class="numeric" id="checkout-subtotal"><strong>$<?php echo number_format($total, 2); ?></strong></td>
        </tr>
        <tr id="checkout-tax-row" style="display:none;">
            <td colspan="4" style="text-align:right"><strong>Sales Tax (6.5%):</strong></td>
            <td class="numeric" id="checkout-tax-amount">$0.00</td>
        </tr>
        <tr>
            <td colspan="4" style="text-align:right"><strong>Total:</strong></td>
            <td class="numeric" id="checkout-total"><strong>$<?php echo number_format($total, 2); ?></strong></td>
        </tr>
    </table>
    <form method="post" action="" id="checkout-form">
    <p><label><input type="checkbox" name="apply_tax" id="apply_tax" value="1"> Apply sales tax (6.5%)</label></p>
    <p><label>PO Number (optional): <input type="text" name="po_number" value="<?php echo htmlspecialchars($_POST['po_number'] ?? ''); ?>" maxlength="255"></label></p>
        <p class="lead" id="checkout-lead">Once you click on PLACE ORDER, the request is received at our Order Desk for review and fulfillment.  You will receive a confirmation Email when your order has been accepted. Sales Tax applies unless you have a valid Sales Tax Exemption Form on file with us.</p>
        <p><button type="submit" class="proceed-btn">Place Order</button></p>
    </form>
    <script>
    (function(){
        var taxCheckbox = document.getElementById('apply_tax');
        var subtotalEl = document.getElementById('checkout-subtotal');
        var taxRow = document.getElementById('checkout-tax-row');
        var taxAmtEl = document.getElementById('checkout-tax-amount');
        var totalEl = document.getElementById('checkout-total');
        function parseMoney(s){ return parseFloat(s.replace(/[^0-9.-]+/g,'')) || 0; }
        function update() {
            var sub = parseMoney(subtotalEl.textContent || subtotalEl.innerText || '0');
            if (taxCheckbox && taxCheckbox.checked) {
                var tax = Math.round(sub * 0.065 * 100) / 100;
                taxAmtEl.textContent = '$' + tax.toFixed(2);
                taxRow.style.display = '';
                totalEl.textContent = '$' + ( (Math.round((sub + tax) * 100))/100 ).toFixed(2);
            } else {
                taxRow.style.display = 'none';
                taxAmtEl.textContent = '$0.00';
                totalEl.textContent = '$' + sub.toFixed(2);
            }
        }
        if (taxCheckbox) {
            taxCheckbox.addEventListener('change', update);
            // run once on load to set initial state
            update();
        }
        // After successful submission the server sets $message; hide lead when message present
        <?php if ($message): ?>
            var lead = document.getElementById('checkout-lead'); if (lead) lead.style.display = 'none';
        <?php endif; ?>
    })();
    </script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

$title = 'Your Cart';
$message = '';

// DEBUG: When running locally, emit a HTML comment with the current session id
// and the cart contents to help diagnose session persistence issues.
if ((php_sapi_name() === 'cli') || ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1' || ($_SERVER['REMOTE_ADDR'] ?? '') === '::1') {
    // local debug was here; removed
}

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
    // Preserve snapshot-shaped entries if present
    if (isset($_SESSION['cart'][$pid])) {
        if (is_array($_SESSION['cart'][$pid]) && isset($_SESSION['cart'][$pid]['quantity'])) {
            $_SESSION['cart'][$pid]['quantity'] = (int)$_SESSION['cart'][$pid]['quantity'] + $qty;
        } elseif (is_int($_SESSION['cart'][$pid]) || is_numeric($_SESSION['cart'][$pid])) {
            $_SESSION['cart'][$pid] = (int)$_SESSION['cart'][$pid] + $qty;
        } else {
            // unexpected shape, replace with numeric fallback
            $_SESSION['cart'][$pid] = $qty;
        }
    } else {
        // Build a snapshot object when product info is available
        $prod = getProductById($pid);
        if ($prod) {
            $_SESSION['cart'][$pid] = [
                'product_id' => $pid,
                'quantity' => $qty,
                'product_name' => getProductDisplayName($prod),
                'product_description' => getProductDescription($prod) ?: getProductDisplayName($prod),
                'product_price' => getProductPrice($prod)
            ];
        } else {
            // fallback to numeric qty if product not found
            $_SESSION['cart'][$pid] = $qty;
        }
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
                    // If this cart entry is a snapshot object, update its quantity subkey
                    if (is_array($_SESSION['cart'][$pid]) && isset($_SESSION['cart'][$pid]['quantity'])) {
                        $_SESSION['cart'][$pid]['quantity'] = $newQty;
                    } else {
                        // legacy numeric entry
                        $_SESSION['cart'][$pid] = $newQty;
                    }
                }
            }
        }
$message = 'Cart updated.';
    }
}

// After handling POST updates, ensure any on-disk or cookie snapshots reflect
// the authoritative session state. This prevents stale snapshots from being
// reloaded on subsequent requests and restores deleted items.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $cartDir = __DIR__ . '/data/carts';
        if (!is_dir($cartDir)) @mkdir($cartDir, 0755, true);
        $sid = session_id();
        // Build a normalized cart payload (empty array if no items)
        $payload = (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) ? $_SESSION['cart'] : [];
        // Write session-keyed snapshot
        if ($sid) {
            $sessFile = $cartDir . '/sess_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $sid) . '.json';
            if (!empty($payload)) {
                @file_put_contents($sessFile, json_encode($payload), LOCK_EX);
            } else {
                // remove stale snapshot when cart is emptied
                if (is_file($sessFile)) @unlink($sessFile);
            }
        }
        // Update compact cookie and cookie-key snapshot
        if (!empty($payload)) {
            // compact cookie (base64 json)
            setcookie('dg_cart', base64_encode(json_encode($payload)), 0, '/');
            // ensure a stable dg_cart_key exists
            $cartKey = $_COOKIE['dg_cart_key'] ?? bin2hex(random_bytes(8));
            setcookie('dg_cart_key', $cartKey, 0, '/');
            $cartFile = $cartDir . '/cart_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $cartKey) . '.json';
            @file_put_contents($cartFile, json_encode($payload), LOCK_EX);
        } else {
            // clear cookies when cart is emptied
            setcookie('dg_cart', '', time() - 3600, '/');
            setcookie('dg_cart_key', '', time() - 3600, '/');
        }
    } catch (Exception $_) { /* ignore snapshot write failures */ }
}

$total = 0.0;

// If the session cart is empty and this is a GET request, attempt to load a
// file-backed snapshot. Keep this read-only and skip during POST handling so
// that form submissions remain authoritative.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($_SESSION['cart'])) {
    try {
        $sid = session_id();
        // DEBUG: log session id and snapshot existence for diagnosis
        try {
            $dbgDir = __DIR__ . '/data/logs'; if (!is_dir($dbgDir)) @mkdir($dbgDir, 0755, true);
            $snap = __DIR__ . '/data/carts/sess_' . $sid . '.json';
            $exists = is_readable($snap) ? 'yes' : 'no';
            @file_put_contents($dbgDir . '/cart_session_debug.log', '['.date('c').'] cart.php GET SID=' . $sid . ' snapshot_exists=' . $exists . "\n", FILE_APPEND | LOCK_EX);
        } catch (Exception $_) { /* ignore */ }
        // Try compact cookie fallback first (dg_cart)
        $dgCart = $_COOKIE['dg_cart'] ?? null;
        if ($dgCart) {
            $decoded = @base64_decode($dgCart, true);
            $data = $decoded ? json_decode($decoded, true) : null;
            if (is_array($data) && !empty($data)) {
                $_SESSION['cart'] = $data;
            }
        }
        // Next, try cookie-backed snapshot key (dg_cart_key)
        $cartKey = $_COOKIE['dg_cart_key'] ?? null;
        if (empty($_SESSION['cart']) && $cartKey) {
            $snap = __DIR__ . '/data/carts/cart_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $cartKey) . '.json';
            if (is_readable($snap)) {
                $data = json_decode(@file_get_contents($snap), true);
                if (is_array($data) && !empty($data)) {
                    $_SESSION['cart'] = $data;
                }
            }
        }
        // Legacy: session-id keyed snapshot
        if (empty($_SESSION['cart'])) {
            $sid = session_id();
            if ($sid) {
                $snap = __DIR__ . '/data/carts/sess_' . $sid . '.json';
                if (is_readable($snap)) {
                    $data = json_decode(@file_get_contents($snap), true);
                    if (is_array($data) && !empty($data)) {
                        $_SESSION['cart'] = $data;
                    }
                }
            }
        }
    } catch (Exception $e) { /* ignore fallback errors */ }
}

if (!empty($_SESSION['cart'])) {
    // Normalize legacy numeric-only cart entries into snapshot objects so
    // rendering can consistently use product_name/description/product_price.
    foreach ($_SESSION['cart'] as $k => $v) {
        if (!is_array($v)) {
            $pid = (int)$k;
            $qty = (int)$v;
            $prod = getProductById($pid);
            if ($prod) {
                $_SESSION['cart'][$k] = [
                    'product_id' => $pid,
                    'quantity' => $qty,
                    'product_name' => $prod['name'],
                    'product_description' => $prod['description'] ?? $prod['name'],
                    'product_price' => isset($prod['price']) ? (float)$prod['price'] : 0.0
                ];
            } else {
                // leave numeric fallback if product missing
                $_SESSION['cart'][$k] = $qty;
            }
        }
    }
    // DEBUG: write full $_SESSION['cart'] to a log for diagnosis (GET only)
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        try {
            $dbgDir = __DIR__ . '/data/logs'; if (!is_dir($dbgDir)) @mkdir($dbgDir, 0755, true);
            @file_put_contents($dbgDir . '/cart_session_full.log', '['.date('c').'] SID=' . session_id() . ' CART=' . json_encode($_SESSION['cart']) . "\n", FILE_APPEND | LOCK_EX);
        } catch (Exception $_) { /* ignore */ }
    }
    foreach ($_SESSION['cart'] as $pid => $entry) {
        // Support both legacy numeric-qty entries and the new snapshot object
        if (is_array($entry) && isset($entry['quantity'])) {
            $qty = (int)$entry['quantity'];
            $name = $entry['product_name'] ?? '';
            $desc = $entry['product_description'] ?? '';
            $price = isset($entry['product_price']) ? (float)$entry['product_price'] : 0.0;
            // If snapshot fields are blank, try resolving current product data
            if ($name === '' || $price === 0.0) {
                $prod = getProductById((int)$pid);
                if ($prod) {
                    $name = getProductDisplayName($prod);
                    $desc = getProductDescription($prod);
                    if ($price === 0.0) $price = getProductPrice($prod);
                }
            }
            $subtotal = $price * $qty;
            $cartItems[] = [
                'id' => (int)$pid,
                // prefer the snapshot product_name for SKU display
                'name' => $name ?: ('Product #' . (int)$pid),
                'price' => $price,
                'quantity' => $qty,
                'subtotal' => $subtotal,
                'description' => $desc
            ];
            $total += $subtotal;
        } else {
            // Legacy numeric qty entry
            $qty = (int)$entry;
            $prod = getProductById((int)$pid);
            if ($prod) {
                $price = getProductPrice($prod);
                $subtotal = $price * $qty;
                $cartItems[] = [
                    'id' => (int)$pid,
                    'name' => getProductDisplayName($prod),
                    'price' => $price,
                    'quantity' => $qty,
                    'subtotal' => $subtotal,
                    'description' => getProductDescription($prod)
                ];
                $total += $subtotal;
            } else {
                error_log('cart.php: product not found for id=' . (int)$pid . ' (showing placeholder)');
                $cartItems[] = [
                    'id' => (int)$pid,
                    'name' => 'Product #' . (int)$pid,
                    'price' => 0.0,
                    'quantity' => $qty,
                    'subtotal' => 0.0,
                    'description' => ''
                ];
            }
        }
    }
}
?>
<div class="container">
  <div class="form-card">
    <h2>Your Cart</h2>
<?php if ($message): ?>
    <div class="message"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>
<?php if (empty($cartItems)): ?>
    <p>Your cart is empty.  <a href="catalogue.php" class="proceed-btn btn-catalog">Browse products</a>.</p>
<?php else: ?>
    <form method="post" action="">
        <input type="hidden" name="update_cart" value="1">
        <table class="cart-table">
            <tr>
                <th>SKU</th>
                <th>Description</th>
                <th>Quantity</th>
                <th class="numeric">Rate</th>
                <th class="numeric">Price</th>
                <th></th>
            </tr>
            <?php foreach ($cartItems as $item): ?>
                <?php
                    // Prefer snapshot values from the cart item when available.
                    $sku = isset($item['name']) ? htmlspecialchars($item['name']) : '';
                    $description = isset($item['description']) ? htmlspecialchars($item['description']) : '';
                    // If snapshot lacks fields, try to resolve from the live product record.
                    if ($sku === '' || $description === '') {
                        $prod = getProductById((int)$item['id']);
                        if ($prod) {
                            if ($sku === '') $sku = htmlspecialchars($prod['name']);
                            if ($description === '') $description = htmlspecialchars($prod['description'] ?? $prod['name']);
                        }
                    }
                    // Final fallback: show numeric id if nothing else available
                    if ($sku === '') $sku = (int)$item['id'];
                ?>
                <tr>
                    <td><?php echo $sku; ?></td>
                    <td><?php echo $description; ?></td>
                    <td><input type="number" name="qty_<?php echo $item['id']; ?>" value="<?php echo $item['quantity']; ?>" min="0" style="width:60px"></td>
                    <td class="numeric">$<?php echo number_format($item['price'], 2); ?></td>
                    <td class="numeric">$<?php echo number_format($item['subtotal'], 2); ?></td>
                        <td style="text-align:center;">
                            <button type="button" class="cart-remove-btn" title="Remove item" data-item-id="<?php echo $item['id']; ?>">×</button>
                        </td>
                </tr>
            <?php endforeach; ?>
            <tr class="cart-total-row">
                <td class="cart-total-label" colspan="4"></td>
                <td class="cart-total-amount numeric"><span class="total-label">Total:</span> <strong>$<?php echo number_format($total, 2); ?></strong></td>
                <td class="cart-total-spacer"></td>
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
        
        // Ensure totals row spans the correct number of columns at <=600px
        function adjustCartTotalsColspan(){
            var mobile = window.matchMedia && window.matchMedia('(max-width: 735px)').matches;
            document.querySelectorAll('.cart-table .cart-total-row').forEach(function(row){
                var firstCell = row.querySelector('td');
                if (firstCell && firstCell.colSpan) {
                    // For cart: desktop 6 columns -> label spans 4; mobile hides SKU -> 5 visible -> label spans 3
                    firstCell.colSpan = mobile ? 3 : 4;
                }
                // amount lives in the Price column only; last cell remains a spacer
            });
        }
        adjustCartTotalsColspan();
        window.addEventListener('resize', adjustCartTotalsColspan);
    });
    </script>
    <?php if (isset($_SESSION['customer'])): ?>
        <p><a href="checkout.php" class="proceed-btn btn-checkout">Proceed to Checkout</a></p>
    <?php else: ?>
        <p><a href="login.php?next=checkout.php" class="proceed-btn btn-checkout">Proceed to Checkout</a></p>
    <?php endif; ?>
<?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
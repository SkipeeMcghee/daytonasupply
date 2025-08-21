<?php
// Customer account page.  Shows a summary of the customer's details and
// orders, and allows the customer to update certain fields (except
// email).  If the user is not logged in they are redirected to the
// login page.

session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

if (!isset($_SESSION['customer'])) {
    header('Location: login.php');
    exit;
}

$customer = $_SESSION['customer'];

// Handle updates
// Messages and errors to display
$messages = [];
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id   = (int)$customer['id'];
    // Normalize and cap lengths before updating
    $name = normalizeScalar($_POST['name'] ?? $customer['name'], 128, $customer['name']);
    $biz  = normalizeScalar($_POST['business_name'] ?? $customer['business_name'], 128, $customer['business_name']);
    $phone= normalizeScalar($_POST['phone'] ?? $customer['phone'], 32, $customer['phone']);
    $bill = normalizeScalar($_POST['billing_address'] ?? $customer['billing_address'], 255, $customer['billing_address']);
    $ship = normalizeScalar($_POST['shipping_address'] ?? $customer['shipping_address'], 255, $customer['shipping_address']);
    $newPass = (string)($_POST['password'] ?? '');
    $currentPass = (string)($_POST['current_password'] ?? '');
    // If the user checked the same_as_billing box, copy billing to shipping
    $sameBillingFlag = isset($_POST['same_as_billing']);
    if ($sameBillingFlag) {
        $ship = $bill;
    }
    // Build data for update
    $data = [
        'name' => $name,
        'business_name' => $biz,
        'phone' => $phone,
        'billing_address' => $bill,
        'shipping_address' => $ship
    ];
    // Handle password change
    if ($newPass !== '') {
        if ($currentPass === '') {
            $errors[] = 'Please enter your current password to change it.';
        } elseif (!authenticateCustomer($customer['email'], $currentPass)) {
            $errors[] = 'Current password is incorrect.';
        } else {
            $data['password'] = $newPass;
        }
    }
    if (empty($errors)) {
        updateCustomer($id, $data);
        // Refresh session data
        $_SESSION['customer'] = getCustomerById($id);
        $customer = $_SESSION['customer'];
        $messages[] = 'Your details have been updated.';
    }
}

$orders = getOrdersByCustomer((int)$customer['id']);
$title = 'My Account';
include __DIR__ . '/includes/header.php';
?>
<h1>My Account</h1>
<?php foreach ($messages as $msg): ?>
    <p class="success"><?= htmlspecialchars($msg) ?></p>
<?php endforeach; ?>
<?php if (!empty($errors)): ?>
    <ul class="error">
        <?php foreach ($errors as $err): ?>
            <li><?= htmlspecialchars($err) ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<h2>Your Details</h2>
<form method="post" action="account.php">
    <p>Name: <input type="text" name="name" value="<?= htmlspecialchars($customer['name']) ?>" required autocomplete="off"></p>
    <p>Business Name: <input type="text" name="business_name" value="<?= htmlspecialchars($customer['business_name']) ?>" autocomplete="off"></p>
    <p>Phone: <input type="text" name="phone" value="<?= htmlspecialchars($customer['phone']) ?>" autocomplete="off"></p>
    <p>Email: <input type="email" value="<?= htmlspecialchars($customer['email']) ?>" disabled autocomplete="off"></p>
    <p>Billing Address: <input type="text" name="billing_address" value="<?= htmlspecialchars($customer['billing_address']) ?>" autocomplete="off"></p>
    <?php
    $sameBillingChecked = (trim($customer['shipping_address']) === trim($customer['billing_address']));
    ?>
    <p>Shipping Address: <input type="text" name="shipping_address" id="account_shipping" value="<?= htmlspecialchars($customer['shipping_address']) ?>" autocomplete="off"></p>
    <p><label><input type="checkbox" name="same_as_billing" id="account_same_billing" value="1" <?= $sameBillingChecked ? 'checked' : '' ?>> Same as billing</label></p>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var checkbox = document.getElementById('account_same_billing');
        var bill = document.querySelector('input[name="billing_address"]');
        var ship = document.getElementById('account_shipping');
        function sync() {
            if (checkbox.checked) {
                ship.value = bill.value;
                ship.disabled = true;
            } else {
                ship.disabled = false;
            }
        }
        checkbox.addEventListener('change', sync);
        bill.addEventListener('input', function() {
            if (checkbox.checked) {
                ship.value = bill.value;
            }
        });
        sync();
    });
    </script>
    <hr>
    <section style="margin-top:2em;">
        <h3>Change your password</h3>
        <p>Current Password: <input type="password" name="current_password" autocomplete="new-password"></p>
        <p>New Password (leave blank to keep current): <input type="password" name="password" autocomplete="new-password"></p>
    </section>
    <p><button type="submit">Save Changes</button></p>
</form>

<h2>Your Orders</h2>
<?php if (!empty($orders)): ?>
    <?php foreach ($orders as $order): ?>
        <?php $items = getOrderItems((int)$order['id']); $orderTotal = 0.0; ?>
        <div class="order-group">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                <div><strong>Order #<?= $order['id']; ?></strong> — <?= htmlspecialchars(date('Y-m-d H:i', strtotime($order['created_at']))); ?></div>
                <div><span class="order-toggle" data-order="<?= $order['id']; ?>">Collapse</span></div>
            </div>
            <table class="account-table order-items" data-order="<?= $order['id']; ?>">
                <tr>
                    <th>Order ID</th>
                    <th>Date</th>
                    <th>SKU</th>
                    <th>Description</th>
                    <th class="numeric">Quantity</th>
                    <th class="numeric">Rate</th>
                    <th class="numeric">Price</th>
                    <th>Status</th>
                </tr>
                <?php if (!empty($items)): ?>
                    <?php $firstItem = true; foreach ($items as $item): ?>
                        <?php
                            $prod = getProductById((int)$item['product_id']);
                            $sku = $prod ? htmlspecialchars($prod['name']) : $item['product_id'];
                            $desc = $prod ? htmlspecialchars($prod['description'] ?? $prod['name']) : 'Unknown product';
                            $qty = (int)$item['quantity'];
                            $rate = $prod ? (float)$prod['price'] : 0.0;
                            $price = $rate * $qty;
                            $orderTotal += $price;
                        ?>
                        <tr>
                            <?php if ($firstItem): ?>
                                <td><?= $order['id']; ?></td>
                                <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($order['created_at']))); ?></td>
                            <?php else: ?>
                                <td></td>
                                <td></td>
                            <?php endif; ?>
                            <td><?= htmlspecialchars($sku); ?></td>
                            <td><?= $desc; ?></td>
                            <td class="numeric"><?= $qty; ?></td>
                            <td class="numeric">$<?= number_format($rate, 2); ?></td>
                            <td class="numeric">$<?= number_format($price, 2); ?></td>
                            <td></td>
                        </tr>
                        <?php $firstItem = false; ?>
                    <?php endforeach; ?>
                    <tr class="order-total-row">
                        <td colspan="6" style="text-align:right"><strong>Total:</strong></td>
                        <td class="numeric"><strong>$<?= number_format($orderTotal, 2); ?></strong></td>
                        <td><?= htmlspecialchars($order['status']); ?></td>
                    </tr>
                <?php else: ?>
                    <tr><td colspan="8">No items for order #<?= $order['id']; ?></td></tr>
                <?php endif; ?>
            </table>
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <p>You have not placed any orders.</p>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
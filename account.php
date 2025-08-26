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
    // Accept split address inputs. If the POST key exists we treat an empty
    // string as intentional (allowing the user to clear a previously set value).
    if (array_key_exists('billing_street', $_POST)) {
        $bill_street = normalizeScalar($_POST['billing_street'], 128, '');
    } else {
    // Prefer the discrete DB column billing_line1, then billing_street
    $bill_street = normalizeScalar($customer['billing_line1'] ?? $customer['billing_street'] ?? '', 128, $customer['billing_line1'] ?? $customer['billing_street'] ?? '');
    }
    if (array_key_exists('billing_street2', $_POST)) {
        $bill_street2 = normalizeScalar($_POST['billing_street2'], 128, '');
    } else {
        $bill_street2 = normalizeScalar($customer['billing_line2'] ?? $customer['billing_street2'] ?? '', 128, $customer['billing_line2'] ?? $customer['billing_street2'] ?? '');
    }
    if (array_key_exists('billing_city', $_POST)) {
        $bill_city = normalizeScalar($_POST['billing_city'], 64, '');
    } else {
        $bill_city = normalizeScalar($customer['billing_city'] ?? '', 64, $customer['billing_city'] ?? '');
    }
    if (array_key_exists('billing_state', $_POST)) {
        $bill_state = normalizeScalar($_POST['billing_state'], 64, '');
    } else {
        $bill_state = normalizeScalar($customer['billing_state'] ?? '', 64, $customer['billing_state'] ?? '');
    }
    if (array_key_exists('billing_zip', $_POST)) {
        $bill_zip = normalizeScalar($_POST['billing_zip'], 16, '');
    } else {
        $bill_zip = normalizeScalar($customer['billing_zip'] ?? '', 16, $customer['billing_zip'] ?? '');
    }
    $bill = $bill_street;
    if ($bill_street2 !== '') $bill .= "\n" . $bill_street2;
    if ($bill_city || $bill_state || $bill_zip) $bill .= "\n" . trim("$bill_city $bill_state $bill_zip");

    if (array_key_exists('shipping_street', $_POST)) {
        $ship_street = normalizeScalar($_POST['shipping_street'], 128, '');
    } else {
    // Prefer discrete DB column shipping_line1
    $ship_street = normalizeScalar($customer['shipping_line1'] ?? $customer['shipping_street'] ?? '', 128, $customer['shipping_line1'] ?? $customer['shipping_street'] ?? '');
    }
    if (array_key_exists('shipping_street2', $_POST)) {
        $ship_street2 = normalizeScalar($_POST['shipping_street2'], 128, '');
    } else {
        $ship_street2 = normalizeScalar($customer['shipping_line2'] ?? $customer['shipping_street2'] ?? '', 128, $customer['shipping_line2'] ?? $customer['shipping_street2'] ?? '');
    }
    if (array_key_exists('shipping_city', $_POST)) {
        $ship_city = normalizeScalar($_POST['shipping_city'], 64, '');
    } else {
        $ship_city = normalizeScalar($customer['shipping_city'] ?? '', 64, $customer['shipping_city'] ?? '');
    }
    if (array_key_exists('shipping_state', $_POST)) {
        $ship_state = normalizeScalar($_POST['shipping_state'], 64, '');
    } else {
        $ship_state = normalizeScalar($customer['shipping_state'] ?? '', 64, $customer['shipping_state'] ?? '');
    }
    if (array_key_exists('shipping_zip', $_POST)) {
        $ship_zip = normalizeScalar($_POST['shipping_zip'], 16, '');
    } else {
        $ship_zip = normalizeScalar($customer['shipping_zip'] ?? '', 16, $customer['shipping_zip'] ?? '');
    }
    // Do not build a legacy concatenated shipping string. Use discrete components.
    // If the user checked the same_as_billing box, copy the discrete billing
    // components into the shipping fields so they are saved into the
    // canonical columns (shipping_line1 / shipping_line2 / shipping_postal_code).
    $sameBillingFlag = isset($_POST['same_as_billing']);
    if ($sameBillingFlag) {
        $ship_street = $bill_street;
        $ship_street2 = $bill_street2;
        $ship_city = $bill_city;
        $ship_state = $bill_state;
        $ship_zip = $bill_zip;
    }
    $newPass = (string)($_POST['password'] ?? '');
    $currentPass = (string)($_POST['current_password'] ?? '');
    // Build data for update. Avoid sending the legacy concatenated
    // billing_address/shipping_address when the database already uses
    // discrete address columns (billing_line1 / billing_street etc.).
    // This prevents the site from repeatedly storing a concatenated
    // address and then having migration code propagate that back into
    // the discrete columns.
    $data = [
        'name' => $name,
        'business_name' => $biz,
        'phone' => $phone,
        'billing_street' => $bill_street,
        'billing_street2' => $bill_street2,
        'billing_city' => $bill_city,
        'billing_state' => $bill_state,
        'billing_zip' => $bill_zip,
        'shipping_street' => $ship_street,
        'shipping_street2' => $ship_street2,
        'shipping_city' => $ship_city,
        'shipping_state' => $ship_state,
        'shipping_zip' => $ship_zip
    ];
    // We intentionally do not populate legacy concatenated fields here.
    // The discrete columns (billing_line1, billing_line2, billing_postal_code,
    // shipping_line1, shipping_line2, shipping_postal_code) are authoritative.
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
        $res = updateCustomer($id, $data);
        if ($res === false) {
            $errors[] = 'Unable to update your details due to a server error. The admin has been notified.';
            error_log('account.php: updateCustomer failed for id=' . $id . ' data=' . print_r($data, true));
        } elseif ($res === 0) {
            $messages[] = 'No changes were detected.';
        } else {
            // Refresh session data
            $_SESSION['customer'] = getCustomerById($id);
            $customer = $_SESSION['customer'];
            $messages[] = 'Your details have been updated.';
        }
    }
}

$orders = getOrdersByCustomer((int)$customer['id']);
// Ensure orders are displayed newest first (defensive sort in case underlying
// data source ordering changes). Sort by created_at descending.
usort($orders, function($a, $b) {
    $ta = strtotime($a['created_at'] ?? '');
    $tb = strtotime($b['created_at'] ?? '');
    return $tb <=> $ta; // newest first
});
$title = 'My Account';
include __DIR__ . '/includes/header.php';
?>
<section class="page-hero">
    <h1>My Account</h1>
    <p class="lead">If you have no changes to make, go directly to the CATALOG</p>
</section>
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
<form method="post" action="account.php" class="vertical-form">
    <p>Name:<br> <input type="text" name="name" value="<?= htmlspecialchars($customer['name']) ?>" required autocomplete="off"></p>
    <p>Business Name:<br> <input type="text" name="business_name" value="<?= htmlspecialchars($customer['business_name']) ?>" autocomplete="off"></p>
    <p>Phone:<br> <input type="text" name="phone" value="<?= htmlspecialchars($customer['phone']) ?>" autocomplete="off"></p>
    <p>Email:<br> <input type="email" value="<?= htmlspecialchars($customer['email']) ?>" disabled autocomplete="off"></p>
    <fieldset>
        <legend>Billing Address</legend>
    <p>Street Address:<br><input type="text" name="billing_street" value="<?= htmlspecialchars($customer['billing_line1'] ?? $customer['billing_street'] ?? '') ?>" autocomplete="off"></p>
    <p>Street Address 2:<br><input type="text" name="billing_street2" value="<?= htmlspecialchars($customer['billing_street2'] ?? '') ?>" autocomplete="off"></p>
        <p>City:<br><input type="text" name="billing_city" value="<?= htmlspecialchars($customer['billing_city'] ?? '') ?>" autocomplete="off"></p>
        <p>State:<br><input type="text" name="billing_state" value="<?= htmlspecialchars($customer['billing_state'] ?? '') ?>" autocomplete="off"></p>
    <p>Zip:<br><input type="text" name="billing_zip" value="<?= htmlspecialchars($customer['billing_postal_code'] ?? $customer['billing_zip'] ?? '') ?>" autocomplete="off"></p>
    </fieldset>
    <?php $sameBillingChecked = (trim(($customer['shipping_line1'] ?? $customer['shipping_street'] ?? '')) === trim(($customer['billing_line1'] ?? $customer['billing_street'] ?? ''))); ?>
    <fieldset>
        <legend>Shipping Address</legend>
        <p><label><input type="checkbox" name="same_as_billing" id="account_same_billing" value="1" <?= $sameBillingChecked ? 'checked' : '' ?>> Same as billing</label></p>
        <div id="account_shipping_fields">
            <p>Street Address:<br><input type="text" name="shipping_street" value="<?= htmlspecialchars($customer['shipping_line1'] ?? $customer['shipping_street'] ?? '') ?>" autocomplete="off"></p>
            <p>Street Address 2:<br><input type="text" name="shipping_street2" value="<?= htmlspecialchars($customer['shipping_street2'] ?? '') ?>" autocomplete="off"></p>
            <p>City:<br><input type="text" name="shipping_city" value="<?= htmlspecialchars($customer['shipping_city'] ?? '') ?>" autocomplete="off"></p>
            <p>State:<br><input type="text" name="shipping_state" value="<?= htmlspecialchars($customer['shipping_state'] ?? '') ?>" autocomplete="off"></p>
            <p>Zip:<br><input type="text" name="shipping_zip" value="<?= htmlspecialchars($customer['shipping_postal_code'] ?? $customer['shipping_zip'] ?? '') ?>" autocomplete="off"></p>
        </div>
    </fieldset>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var checkbox = document.getElementById('account_same_billing');
        var billing = {
            street: document.querySelector('input[name="billing_street"]'),
            street2: document.querySelector('input[name="billing_street2"]'),
            city: document.querySelector('input[name="billing_city"]'),
            state: document.querySelector('input[name="billing_state"]'),
            zip: document.querySelector('input[name="billing_zip"]')
        };
        var shipping = {
            street: document.querySelector('input[name="shipping_street"]'),
            street2: document.querySelector('input[name="shipping_street2"]'),
            city: document.querySelector('input[name="shipping_city"]'),
            state: document.querySelector('input[name="shipping_state"]'),
            zip: document.querySelector('input[name="shipping_zip"]')
        };
        function setShipping(disable) {
            if (disable) {
                shipping.street.value = billing.street.value || '';
                shipping.street2.value = billing.street2.value || '';
                shipping.city.value = billing.city.value || '';
                shipping.state.value = billing.state.value || '';
                shipping.zip.value = billing.zip.value || '';
                Object.values(shipping).forEach(function(f) { f.disabled = true; });
            } else {
                Object.values(shipping).forEach(function(f) { f.disabled = false; });
            }
        }
        checkbox.addEventListener('change', function() { setShipping(checkbox.checked); });
        Object.values(billing).forEach(function(f) { f.addEventListener('input', function() { if (checkbox.checked) setShipping(true); }); });
        setShipping(checkbox.checked);
    });
    </script>
    <hr>
    <section style="margin-top:2em;">
        <h3>Change your password</h3>
        <p>Current Password: <input type="password" name="current_password" autocomplete="new-password"></p>
        <p>New Password (leave blank to keep current): <input type="password" name="password" autocomplete="new-password"></p>
    </section>
    <p><button type="submit" class="proceed-btn">Save Changes</button></p>
</form>

<h2>Your Orders</h2>
<?php if (!empty($orders)): ?>
    <?php foreach ($orders as $order): ?>
        <?php $items = getOrderItems((int)$order['id']); $orderTotal = 0.0; ?>
        <div class="order-group">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                <div><strong>Order #<?= $order['id']; ?></strong> — <?= htmlspecialchars(date('n/j/Y g:i A', strtotime($order['created_at']))); ?></div>
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
                                <td><?= htmlspecialchars(date('n/j/Y g:i A', strtotime($order['created_at']))); ?></td>
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
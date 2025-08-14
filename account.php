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
    $name = trim($_POST['name'] ?? $customer['name']);
    $biz  = trim($_POST['business_name'] ?? $customer['business_name']);
    $phone= trim($_POST['phone'] ?? $customer['phone']);
    $bill = trim($_POST['billing_address'] ?? $customer['billing_address']);
    $ship = trim($_POST['shipping_address'] ?? $customer['shipping_address']);
    $newPass = $_POST['password'] ?? '';
    $currentPass = $_POST['current_password'] ?? '';
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
    <p>Name: <input type="text" name="name" value="<?= htmlspecialchars($customer['name']) ?>" required></p>
    <p>Business Name: <input type="text" name="business_name" value="<?= htmlspecialchars($customer['business_name']) ?>"></p>
    <p>Phone: <input type="text" name="phone" value="<?= htmlspecialchars($customer['phone']) ?>"></p>
    <p>Email: <input type="email" value="<?= htmlspecialchars($customer['email']) ?>" disabled></p>
    <p>Billing Address: <input type="text" name="billing_address" value="<?= htmlspecialchars($customer['billing_address']) ?>"></p>
    <?php
    // Determine whether the customer currently has the same shipping and billing
    $sameBillingChecked = (trim($customer['shipping_address']) === trim($customer['billing_address']));
    ?>
    <p>Shipping Address: <input type="text" name="shipping_address" id="account_shipping" value="<?= htmlspecialchars($customer['shipping_address']) ?>"></p>
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
        // initialize on page load
        sync();
    });
    </script>
    <p>Current Password: <input type="password" name="current_password"></p>
    <p>New Password (leave blank to keep current): <input type="password" name="password"></p>
    <p><button type="submit">Save Changes</button></p>
</form>

<h2>Your Orders</h2>
<?php if (!empty($orders)): ?>
    <table class="account-table">
        <tr><th>Order ID</th><th>Date</th><th>Items</th><th>Total</th><th>Status</th></tr>
        <?php foreach ($orders as $order): ?>
            <?php
                $items = getOrderItems((int)$order['id']);
                $desc = [];
                foreach ($items as $item) {
                    $prod = getProductById((int)$item['product_id']);
                    if ($prod) {
                        $desc[] = htmlspecialchars($prod['name']) . ' x' . (int)$item['quantity'];
                    }
                }
            ?>
            <tr>
                <td><?= $order['id']; ?></td>
                <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime($order['created_at']))); ?></td>
                <td><?= implode(', ', $desc); ?></td>
                <td>$<?= number_format($order['total'], 2); ?></td>
                <td><?= htmlspecialchars($order['status']); ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php else: ?>
    <p>You have not placed any orders.</p>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
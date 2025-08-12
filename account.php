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
$messages = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id   = (int)$customer['id'];
    $name = trim($_POST['name'] ?? $customer['name']);
    $biz  = trim($_POST['business_name'] ?? $customer['business_name']);
    $phone= trim($_POST['phone'] ?? $customer['phone']);
    $bill = trim($_POST['billing_address'] ?? $customer['billing_address']);
    $ship = trim($_POST['shipping_address'] ?? $customer['shipping_address']);
    $pass = $_POST['password'] ?? '';
    $data = [
        'name' => $name,
        'business_name' => $biz,
        'phone' => $phone,
        'billing_address' => $bill,
        'shipping_address' => $ship
    ];
    if ($pass !== '') {
        $data['password'] = $pass;
    }
    updateCustomer($id, $data);
    // Refresh session data
    $_SESSION['customer'] = getCustomerById($id);
    $customer = $_SESSION['customer'];
    $messages[] = 'Your details have been updated.';
}

$orders = getOrdersByCustomer((int)$customer['id']);
$title = 'My Account';
include __DIR__ . '/includes/header.php';
?>
<h1>My Account</h1>
<?php foreach ($messages as $msg): ?>
    <p class="success"><?= htmlspecialchars($msg) ?></p>
<?php endforeach; ?>
<h2>Your Details</h2>
<form method="post" action="account.php">
    <p>Name: <input type="text" name="name" value="<?= htmlspecialchars($customer['name']) ?>" required></p>
    <p>Business Name: <input type="text" name="business_name" value="<?= htmlspecialchars($customer['business_name']) ?>"></p>
    <p>Phone: <input type="text" name="phone" value="<?= htmlspecialchars($customer['phone']) ?>"></p>
    <p>Email: <input type="email" value="<?= htmlspecialchars($customer['email']) ?>" disabled></p>
    <p>Billing Address: <input type="text" name="billing_address" value="<?= htmlspecialchars($customer['billing_address']) ?>"></p>
    <p>Shipping Address: <input type="text" name="shipping_address" value="<?= htmlspecialchars($customer['shipping_address']) ?>"></p>
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
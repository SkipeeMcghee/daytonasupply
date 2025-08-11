<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

// Require login
if (!isset($_SESSION['customer'])) {
    header('Location: /login.php');
    exit;
}

$customer = $_SESSION['customer'];
$title = 'My Account';

// Handle profile update
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    $updatedData = [
        'name' => trim($_POST['name'] ?? ''),
        'business_name' => trim($_POST['business_name'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'billing_address' => trim($_POST['billing_address'] ?? ''),
        'shipping_address' => trim($_POST['shipping_address'] ?? ''),
        'password' => $_POST['password'] ?? ''
    ];
    updateCustomer((int)$customer['id'], $updatedData);
    // Refresh session customer data
    $customer = getCustomerById((int)$customer['id']);
    $_SESSION['customer'] = $customer;
    $message = 'Account details updated successfully.';
}

// Retrieve orders
$orders = getOrdersByCustomer((int)$customer['id']);
?>

<h2>My Account</h2>
<?php if ($message): ?>
    <div class="message"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>
<h3>Profile Details</h3>
<form method="post" action="">
    <input type="hidden" name="update" value="1">
    <p>Name: <input type="text" name="name" value="<?php echo htmlspecialchars($customer['name']); ?>" required></p>
    <p>Business Name: <input type="text" name="business_name" value="<?php echo htmlspecialchars($customer['business_name']); ?>"></p>
    <p>Phone: <input type="text" name="phone" value="<?php echo htmlspecialchars($customer['phone']); ?>"></p>
    <p>Email: <input type="email" name="email" value="<?php echo htmlspecialchars($customer['email']); ?>" required></p>
    <p>Billing Address: <input type="text" name="billing_address" value="<?php echo htmlspecialchars($customer['billing_address']); ?>"></p>
    <p>Shipping Address: <input type="text" name="shipping_address" value="<?php echo htmlspecialchars($customer['shipping_address']); ?>"></p>
    <p>New Password (leave blank to keep unchanged): <input type="password" name="password"></p>
    <p><button type="submit">Save Changes</button></p>
</form>

<h3>Your Orders</h3>
<?php if (!$orders): ?>
    <p>You have not placed any orders yet.</p>
<?php else: ?>
    <table class="orders">
        <tr><th>Order ID</th><th>Date</th><th>Status</th><th>Total</th><th>Items</th></tr>
        <?php foreach ($orders as $order): ?>
            <tr>
                <td><?php echo $order['id']; ?></td>
                <td><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($order['created_at']))); ?></td>
                <td><?php echo htmlspecialchars($order['status']); ?></td>
                <td>$<?php echo number_format($order['total'], 2); ?></td>
                <td>
                    <?php
                    $items = getOrderItems((int)$order['id']);
                    $descArr = [];
                    foreach ($items as $it) {
                        $prod = getProductById((int)$it['product_id']);
                        if ($prod) {
                            $descArr[] = htmlspecialchars($prod['name']) . ' x' . (int)$it['quantity'];
                        }
                    }
                    echo implode(', ', $descArr);
                    ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
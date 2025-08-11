<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

// Set page title
$title = 'Sign Up';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect form data
    $name = trim($_POST['name'] ?? '');
    $businessName = trim($_POST['business_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $billing = trim($_POST['billing_address'] ?? '');
    $shipping = trim($_POST['shipping_address'] ?? '');
    $password = $_POST['password'] ?? '';
    try {
        $custId = createCustomer([
            'name' => $name,
            'business_name' => $businessName,
            'phone' => $phone,
            'email' => $email,
            'billing_address' => $billing,
            'shipping_address' => $shipping,
            'password' => $password
        ]);
        // Fetch customer and store in session
        $customer = getCustomerById($custId);
        $_SESSION['customer'] = $customer;
        // Redirect to account page
        header('Location: /account.php');
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>

<h2>Create an Account</h2>
<?php if ($error): ?>
    <div class="error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<form method="post" action="">
    <p>Name: <input type="text" name="name" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" required></p>
    <p>Business Name: <input type="text" name="business_name" value="<?php echo htmlspecialchars($_POST['business_name'] ?? ''); ?>"></p>
    <p>Phone: <input type="text" name="phone" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"></p>
    <p>Email: <input type="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required></p>
    <p>Billing Address: <input type="text" name="billing_address" value="<?php echo htmlspecialchars($_POST['billing_address'] ?? ''); ?>"></p>
    <p>Shipping Address: <input type="text" name="shipping_address" value="<?php echo htmlspecialchars($_POST['shipping_address'] ?? ''); ?>"></p>
    <p>Password: <input type="password" name="password" required></p>
    <p><button type="submit">Sign Up</button></p>
</form>

<?php include __DIR__ . '/includes/footer.php'; ?>
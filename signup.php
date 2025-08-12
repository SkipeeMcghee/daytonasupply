<?php
// Customer sign‑up page.  Presents a form for creating a new customer
// account and handles registration logic.  On successful sign‑up the
// user is automatically logged in and redirected to their account page.

session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$title = 'Sign Up';

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Gather and sanitize input
    $name    = trim($_POST['name'] ?? '');
    $biz     = trim($_POST['business_name'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $bill    = trim($_POST['billing_address'] ?? '');
    $ship    = trim($_POST['shipping_address'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    // Basic validation
    if ($name === '' || $email === '' || $password === '') {
        $errors[] = 'Name, email and password are required.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email address.';
    }
    // Attempt to create the customer
    if (empty($errors)) {
        try {
            $customerId = createCustomer([
                'name' => $name,
                'business_name' => $biz,
                'phone' => $phone,
                'email' => $email,
                'billing_address' => $bill,
                'shipping_address' => $ship,
                'password' => $password
            ]);
            // Log them in
            $_SESSION['customer'] = getCustomerById($customerId);
            header('Location: account.php');
            exit;
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }
}

include __DIR__ . '/includes/header.php';
?>
<h1>Sign Up</h1>
<?php if (!empty($errors)): ?>
    <ul class="error">
        <?php foreach ($errors as $err): ?>
            <li><?= htmlspecialchars($err) ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
<form method="post" action="signup.php">
    <p>Name: <input type="text" name="name" required value="<?= isset($name) ? htmlspecialchars($name) : '' ?>"></p>
    <p>Business Name: <input type="text" name="business_name" value="<?= isset($biz) ? htmlspecialchars($biz) : '' ?>"></p>
    <p>Phone: <input type="text" name="phone" value="<?= isset($phone) ? htmlspecialchars($phone) : '' ?>"></p>
    <p>Email: <input type="email" name="email" required value="<?= isset($email) ? htmlspecialchars($email) : '' ?>"></p>
    <p>Billing Address: <input type="text" name="billing_address" value="<?= isset($bill) ? htmlspecialchars($bill) : '' ?>"></p>
    <p>Shipping Address: <input type="text" name="shipping_address" value="<?= isset($ship) ? htmlspecialchars($ship) : '' ?>"></p>
    <p>Password: <input type="password" name="password" required></p>
    <p>Confirm Password: <input type="password" name="confirm" required></p>
    <p><button type="submit">Create Account</button></p>
</form>
<?php include __DIR__ . '/includes/footer.php'; ?>
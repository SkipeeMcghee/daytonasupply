<?php
// Customer sign‑up page.  Presents a form for creating a new customer
// account and handles registration logic.  On successful sign‑up the
// user is automatically logged in and redirected to their account page.

session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$title = 'Sign Up';

// Holds validation errors
$errors = [];
// Success message when registration completes
$successMessage = '';
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
            // Generate a verification token and associate it with the new customer
            $token = generateVerificationToken();
            setCustomerVerification($customerId, $token);
            // Construct verification URL.  Use current host and directory to build a link
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            // dirname on PHP_SELF gives the current directory (may be subfolder)
            $dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
            // Build relative path to verify.php
            $verifyPath = ($dir === '' ? '' : $dir . '/') . 'verify.php?token=' . urlencode($token);
            $verificationUrl = $scheme . '://' . $host . $verifyPath;
            // Send verification email
            $body = "Hello " . $name . ",\n\n" .
                    "Thank you for registering an account with Daytona Supply.\n" .
                    "Please verify your email address by clicking the link below:\n\n" .
                    $verificationUrl . "\n\n" .
                    "If you did not sign up for an account, please ignore this message.";
            sendEmail($email, 'Verify your Daytona Supply account', $body);
            // Show success message instead of logging the user in
            $successMessage = 'Registration successful! Please check your email to verify your account.';
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }
}

include __DIR__ . '/includes/header.php';
?>
<h1>Sign Up</h1>
<?php if (!empty($successMessage)): ?>
    <p class="message" style="color:green; font-weight:bold;"><?= htmlspecialchars($successMessage) ?></p>
<?php else: ?>
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
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
<?php
// Login page for customers. Handles authentication and redirects to account page.
session_start();
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

// If customer already logged in, send them straight to their account
if (isset($_SESSION['customer'])) {
    header('Location: account.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Normalize inputs to a reasonable length to prevent abuse
    $email = normalizeScalar($_POST['email'] ?? '', 254, '');
    $password = (string)($_POST['password'] ?? '');
    $customer = authenticateCustomer($email, $password);
    if ($customer) {
        // Check if the account has been verified
        if (!empty($customer['is_verified']) && (int)$customer['is_verified'] === 1) {
            // Store customer data in session and redirect to account page
            $_SESSION['customer'] = $customer;
            header('Location: account.php');
            exit;
        } else {
            $error = 'Please verify your email address before logging in.';
        }
    } else {
        $error = 'Invalid credentials';
    }
}

// Render the login form
$title = 'Login';
include __DIR__ . '/includes/header.php';
?>
<section class="page-hero">
    <h1>Login</h1>
    <p class="lead">Enter your Email address and Password into the fields below, then click the LOGIN button.</p>
</section>
<?php if (!empty($error)): ?>
    <p style="color:red"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>
<form method="post" action="login.php" class="vertical-form">
    <p>Email:<br><input type="email" name="email" required></p>
    <p>Password:<br><input type="password" name="password" required></p>
    <p><button type="submit">LOGIN</button></p>
</form>
<p><a href="forgot_password.php">Forgot your password?</a></p>
<p>Don't have an account? <a href="signup.php">Sign up</a></p>
<?php include __DIR__ . '/includes/footer.php'; ?>
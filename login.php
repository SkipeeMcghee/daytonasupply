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
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $customer = authenticateCustomer($email, $password);
    if ($customer) {
        // Store customer data in session and redirect to account page
        $_SESSION['customer'] = $customer;
        header('Location: account.php');
        exit;
    } else {
        $error = 'Invalid credentials';
    }
}

// Render the login form
$title = 'Login';
include __DIR__ . '/includes/header.php';
?>
<h1>Login</h1>
<?php if (!empty($error)): ?>
    <p style="color:red"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>
<form method="post" action="login.php">
    <label>Email: <input type="email" name="email" required></label><br>
    <label>Password: <input type="password" name="password" required></label><br>
    <button type="submit">Login</button>
</form>
<p>Don't have an account? <a href="signup.php">Sign up</a></p>
<?php include __DIR__ . '/includes/footer.php'; ?>
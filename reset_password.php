<?php
// Password reset handler.  Users arrive at this page by clicking a link
// in the password reset email.  The link includes a token that is
// validated and used to identify the customer.  If the token is valid
// and has not expired, the user can choose a new password.  After
// resetting the password the token is cleared and the user is
// prompted to log in.

session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$title = 'Reset Password';
$token = $_GET['token'] ?? '';
$customer = null;
$errors = [];
$message = '';

if ($token) {
    $customer = getCustomerByResetToken($token);
    if (!$customer) {
        $errors[] = 'The password reset link is invalid or has expired.';
    }
} else {
    $errors[] = 'Invalid password reset link.';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    $newPass = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';
    if ($newPass === '' || $confirm === '') {
        $errors[] = 'Please enter and confirm your new password.';
    } elseif ($newPass !== $confirm) {
        $errors[] = 'The passwords do not match.';
    } else {
        // Update the customer's password and clear the reset token
        $id = (int)$customer['id'];
        updateCustomer($id, ['password' => $newPass]);
        clearPasswordResetToken($id);
        $message = 'Your password has been reset successfully. You can now log in with your new password.';
        // Optionally, log the user in automatically
        // $_SESSION['customer'] = getCustomerById($id);
        // header('Location: account.php'); exit;
    }
}

include __DIR__ . '/includes/header.php';
?>
<h1>Reset Password</h1>
<?php if (!empty($errors)): ?>
    <ul class="error">
    <?php foreach ($errors as $err): ?>
        <li><?= htmlspecialchars($err) ?></li>
    <?php endforeach; ?>
    </ul>
<?php endif; ?>
<?php if ($message): ?>
    <p class="success"><?= htmlspecialchars($message) ?></p>
    <p><a href="login.php">Return to login</a></p>
<?php elseif ($customer): ?>
    <form method="post" action="reset_password.php?token=<?= urlencode($token) ?>">
        <p>New Password: <input type="password" name="password" required></p>
        <p>Confirm Password: <input type="password" name="confirm" required></p>
        <p><button type="submit">Reset Password</button></p>
    </form>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
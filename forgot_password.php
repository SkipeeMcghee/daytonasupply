<?php
// Password reset request page.  Provides a form for users to request
// a password reset link by entering their registered email address.
// If the email exists, a reset token will be generated and emailed
// to the user.  The response does not disclose whether the email
// exists for security purposes.

session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$title = 'Forgot Password';
$message = '';
// If the form has been submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $customer = getCustomerByEmail($email);
        if ($customer) {
            // Generate a reset token and expiry (1 hour from now)
            $token = generateVerificationToken();
            $expires = (new DateTime('+1 hour'))->format(DATE_ATOM);
            setPasswordResetToken((int)$customer['id'], $token, $expires);
            // Build reset URL similar to verification URL
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            $dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
            if ($dir === '' || $dir === '.') {
                $path = '/reset_password.php?token=' . urlencode($token);
            } else {
                $path = $dir . '/reset_password.php?token=' . urlencode($token);
            }
            $resetUrl = $scheme . '://' . $host . $path;
            // Compose reset email
            $body = "Hello " . $customer['name'] . ",\n\n" .
                    "We received a request to reset your password at Daytona Supply.\n" .
                    "Please click the link below to set a new password. This link will expire in one hour.\n\n" .
                    $resetUrl . "\n\n" .
                    "If you did not request a password reset, you can ignore this email.";
            sendEmail($customer['email'], 'Password Reset Request', $body);
        }
        // Display generic message regardless of whether the email exists
        $message = 'If the email you provided is registered with us, a password reset link has been sent.';
    } else {
        $message = 'Please enter a valid email address.';
    }
}

include __DIR__ . '/includes/header.php';
?>
<h1>Forgot Password</h1>
<?php if ($message): ?>
    <p><?= htmlspecialchars($message) ?></p>
<?php else: ?>
    <p>Enter your registered email address and we'll send you a link to reset your password.</p>
    <form method="post" action="forgot_password.php">
        <p>Email: <input type="email" name="email" required></p>
        <p><button type="submit">Send Reset Link</button></p>
    </form>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
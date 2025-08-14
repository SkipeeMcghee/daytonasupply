<?php
// Account email verification handler.  Users arrive at this page by
// clicking the verification link sent to them during registration.  If
// the token is valid and the account has not been verified yet, the
// user is automatically marked as verified and logged in, then
// redirected to their account page.  Otherwise an appropriate
// message is displayed.

session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$token = $_GET['token'] ?? '';
$title = 'Email Verification';
// Attempt verification if a token was provided
$message = '';
if ($token !== '') {
    $customer = verifyCustomer($token);
    if ($customer) {
        // Log the customer in and redirect to account page
        $_SESSION['customer'] = $customer;
        header('Location: account.php');
        exit;
    } else {
        $message = 'The verification link is invalid or has already been used.';
    }
} else {
    $message = 'Invalid verification link.';
}

include __DIR__ . '/includes/header.php';
?>
<h1>Email Verification</h1>
<p><?= htmlspecialchars($message) ?></p>
<?php include __DIR__ . '/includes/footer.php'; ?>

<?php
// Simple contact form that requires a logged-in, verified customer.
session_start();
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

$title = 'Contact Us';
// Basic CSRF token helper
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

$errors = [];
$success = false;

// Check if user is logged in
$customer = $_SESSION['customer'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $errors[] = 'Invalid request (CSRF token mismatch).';
    }

    if (!$customer) {
        $errors[] = 'You must be logged in to contact us.';
    } else {
        // Refresh customer record from DB to get latest is_verified flag
        $cust = getCustomerById((int)$customer['id']);
        if (empty($cust) || empty($cust['is_verified']) || (int)$cust['is_verified'] !== 1) {
            $errors[] = 'Your account must be verified before contacting us. Please verify your email.';
        }
    }

    $subject = trim((string)($_POST['subject'] ?? ''));
    $message = trim((string)($_POST['message'] ?? ''));
    if ($subject === '') $errors[] = 'Please provide a subject.';
    if ($message === '') $errors[] = 'Please provide a message.';

    if (empty($errors)) {
        $company = getenv('COMPANY_EMAIL') ?: 'webmaster@localhost';
        $from = $cust['email'] ?? ($customer['email'] ?? '');
        $body = "Contact form submission from: " . ($cust['name'] ?? ($customer['name'] ?? 'Unknown')) . "\n";
        $body .= "Email: " . $from . "\n\n";
        $body .= "Subject: " . $subject . "\n\n";
        $body .= $message . "\n\n--\nThis message was sent from the site contact form.";

        $sent = sendEmail($company, '[Contact] ' . $subject, $body);
        if ($sent) {
            $success = true;
            // rotate CSRF token to avoid resubmit
            $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
        } else {
            $errors[] = 'Unable to send message at this time. Please try again later.';
        }
    }
}

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero centered">
    <h1>Contact Us</h1>
    <p class="lead">Use this form to reach our support team. You must have a verified account to send messages through this form.</p>
</section>
<?php if ($success): ?>
    <div class="login-page compact">
            <div class="login-card">
                <p class="success" style="color:green">Thank you — your message has been sent. We'll respond via email.</p>
            </div>
        </div>
<?php else: ?>
    <div class="login-page compact">
            <div class="login-card form-card simple-form">
                <?php if (!empty($errors)): ?>
                        <ul class="error">
                                <?php foreach ($errors as $e): ?>
                                        <li><?php echo htmlspecialchars($e); ?></li>
                                <?php endforeach; ?>
                        </ul>
                <?php endif; ?>

                <?php if (empty($customer)): ?>
                        <p>You must be <a href="login.php">logged in</a> with a verified account to contact us.</p>
                <?php endif; ?>

                <form method="post" action="contact.php" class="contact-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div class="form-grid">
                            <div class="form-row">
                                <label>Subject
                                    <input type="text" name="subject" value="<?php echo htmlspecialchars($_POST['subject'] ?? ''); ?>" required>
                                </label>
                            </div>
                            <div class="form-row">
                                <label>Message
                                    <textarea name="message" class="autosize" rows="6" required><?php echo htmlspecialchars($_POST['message'] ?? ''); ?></textarea>
                                </label>
                            </div>
                            <div class="form-row">
                                <button type="submit" class="btn-primary">Send Message</button>
                            </div>
                        </div>
                </form>

            </div>
        </div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>

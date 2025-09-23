<?php
// Login page for customers. Handles authentication and redirects to account page.
session_start();
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

// If customer already logged in, send them straight to their account
// If already authenticated, redirect to 'next' if provided and safe, otherwise account page
if (isset($_SESSION['customer'])) {
    $next = normalizeScalar($_GET['next'] ?? '', 512, '');
    if ($next && strpos($next, '/') !== 0 && strpos($next, 'http') === false) {
        // ensure next is a relative path without host to avoid open redirects
        header('Location: ' . $next);
        exit;
    }
    header('Location: account.php');
    exit;
}

$error = '';
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
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
                // Redirect to a safe 'next' parameter if provided
                $next = normalizeScalar($_GET['next'] ?? $_POST['next'] ?? '', 512, '');
                if ($next && strpos($next, '/') !== 0 && strpos($next, 'http') === false) {
                    header('Location: ' . $next);
                    exit;
                }
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
<main class="login-page" id="content" role="main">
    <section class="login-card" aria-labelledby="login-heading">
        <h1 id="login-heading">Sign in to your account</h1>
        <p class="login-sub">Access your account to order, view invoices, and manage your catalog.</p>

        <?php if (!empty($flash)): ?>
                <p style="color:<?= $flash['type'] === 'success' ? 'green' : 'red' ?>"><?= htmlspecialchars($flash['msg']) ?></p>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
                <p style="color:red"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        <?php if (!empty($error) && $error === 'Please verify your email address before logging in.'): ?>
                <form method="post" action="resend_verification.php" style="display:block; margin-bottom:12px;">
                        <input type="hidden" name="email" value="<?= htmlspecialchars($_SESSION['flash_email'] ?? ($_POST['email'] ?? '')) ?>">
                        <button type="submit" class="muted-btn action-btn small">Resend verification email</button>
                </form>
        <?php endif; ?>

        <form method="post" class="vertical-form" action="login.php">
            <div class="form-row">
                <label for="login_email">Email</label>
                <div class="field"><input id="login_email" type="email" name="email" required autocomplete="email"></div>
            </div>
            <div class="form-row">
                <label for="login_password">Password</label>
                <div class="field"><input id="login_password" type="password" name="password" required autocomplete="current-password"></div>
            </div>
            <button type="submit" class="btn-primary">Sign in</button>

            <div class="alt-actions"></div>
        </form>

        <div class="login-helpers" role="group" aria-label="login actions">
            <a class="forgot-link" href="forgot_password.php">Forgot your password?</a>

            <div class="signup-helper">
                <span>Don't have an account?</span>
                <form method="post" action="signup.php" style="display:inline; margin:0;">
                    <input type="hidden" name="from_login" value="1">
                    <button type="submit" class="signup-trigger">Sign up</button>
                </form>
            </div>
        </div>
    </section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
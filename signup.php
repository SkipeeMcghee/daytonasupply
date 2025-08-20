<?php
// Customer sign‑up page.  Presents a form for creating a new customer
// account and handles registration logic.  On successful sign‑up the
// user is automatically logged in and redirected to their account page.

session_start();
$successMessage = '';
$showForm = true;
if (isset($_SESSION['signup_success'])) {
    $successMessage = $_SESSION['signup_success'];
    unset($_SESSION['signup_success']);
    $showForm = false;
}
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$title = 'Sign Up';

// Holds validation errors
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
if ($showForm && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Gather and sanitize input
    $name    = trim($_POST['name'] ?? '');
    $biz     = trim($_POST['business_name'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $bill    = trim($_POST['billing_address'] ?? '');
    $ship    = trim($_POST['shipping_address'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    // Google reCAPTCHA v3 verification
    $recaptcha_response = $_POST['g-recaptcha-response'] ?? '';
    $recaptcha_secret = '6Lfsm6wrAAAAAO-nZyV1DSDNVtsk5fzm3SALvNam';
    $recaptcha_url = 'https://www.google.com/recaptcha/api/siteverify';
    $recaptcha = false;
    if ($recaptcha_response) {
        // Debug: Log the outgoing URL and response
        $recaptcha_debug = [];
        $recaptcha_debug['request_url'] = $recaptcha_url . '?secret=' . $recaptcha_secret . '&response=' . $recaptcha_response;
        $verify = @file_get_contents($recaptcha_debug['request_url']);
        $recaptcha_debug['raw_response'] = $verify;
        $captcha_success = json_decode($verify);
        $recaptcha_debug['decoded_response'] = $captcha_success;
        if ($captcha_success && $captcha_success->success && $captcha_success->score >= 0.5) {
            $recaptcha = true;
        } else {
            $errors[] = 'Captcha verification failed. Please try again.';
            $errors[] = 'reCAPTCHA debug info: ' . print_r($recaptcha_debug, true);
        }
    } else {
    $errors[] = 'Captcha verification required.';
    $errors[] = 'reCAPTCHA debug info: No response token received.';
    }
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
    // If the user indicated shipping address is the same as billing, copy it
    $sameBillingFlag = isset($_POST['same_as_billing']);
    if ($sameBillingFlag) {
        $ship = $bill;
    }
    // Attempt to create the customer
    if ($recaptcha && empty($errors)) {
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
            $dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
            if ($dir === '' || $dir === '.') {
                $verifyPath = '/verify.php?token=' . urlencode($token);
            } else {
                $verifyPath = $dir . '/verify.php?token=' . urlencode($token);
            }
            $verificationUrl = $scheme . '://' . $host . $verifyPath;
            $body = "Hello " . $name . ",\n\n" .
                    "Thank you for registering an account with Daytona Supply.\n" .
                    "Please verify your email address by clicking the link below:\n\n" .
                    $verificationUrl . "\n\n" .
                    "If you did not sign up for an account, please ignore this message.";
            sendEmail($email, 'Verify your Daytona Supply account', $body);
            $successMessage = 'Registration successful! Please check your email to verify your account.';
            // Store success message in session and redirect to avoid resubmission
            $_SESSION['signup_success'] = $successMessage;
            header('Location: signup.php');
            exit;
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }
}
}
include __DIR__ . '/includes/header.php';
?>
<h1>Sign Up</h1>
<?php if (!empty($successMessage)): ?>
    <div class="message" style="background:#d4edda;color:#155724;padding:16px;border-radius:6px;font-weight:bold;margin-bottom:24px;border:1px solid #c3e6cb;">
        <?= htmlspecialchars($successMessage) ?>
    </div>
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
        <p>Shipping Address: <input type="text" name="shipping_address" id="signup_shipping" value="<?= isset($ship) ? htmlspecialchars($ship) : '' ?>"></p>
        <p><label><input type="checkbox" name="same_as_billing" id="signup_same_billing" value="1" <?= isset($_POST['same_as_billing']) ? 'checked' : '' ?>> Same as billing</label></p>
        <script>
        // Client‑side helper to mirror billing to shipping when the user
        // indicates they are the same.  When checked, the shipping input
        // value is kept in sync with the billing input and is disabled to
        // prevent editing.  This improves usability but the server also
        // copies the billing address on submission.
        document.addEventListener('DOMContentLoaded', function() {
            var checkbox = document.getElementById('signup_same_billing');
            var bill = document.querySelector('input[name="billing_address"]');
            var ship = document.getElementById('signup_shipping');
            function sync() {
                if (checkbox.checked) {
                    ship.value = bill.value;
                    ship.disabled = true;
                } else {
                    ship.disabled = false;
                }
            }
            checkbox.addEventListener('change', sync);
            bill.addEventListener('input', function() {
                if (checkbox.checked) {
                    ship.value = bill.value;
                }
            });
            // initialize on load
            sync();
        });
        </script>
        <p>Password: <input type="password" name="password" required></p>
        <p>Confirm Password: <input type="password" name="confirm" required></p>
        <input type="hidden" id="g-recaptcha-response" name="g-recaptcha-response">
        <p><button type="submit">Create Account</button></p>
        <div class="g-recaptcha-badge" style="margin-top:10px;">
            <small>This site is protected by reCAPTCHA and the Google <a href="https://policies.google.com/privacy">Privacy Policy</a> and <a href="https://policies.google.com/terms">Terms of Service</a> apply.</small>
        </div>
    </form>
    <script src="https://www.google.com/recaptcha/api.js?render=6Lfsm6wrAAAAAFuElD_SuX0RdtxWv3myo3t5AvqT"></script>
        
    <script>
    grecaptcha.ready(function() {
        document.querySelector('form[action="signup.php"]').addEventListener('submit', function(e) {
            e.preventDefault();
            grecaptcha.execute('6Lfsm6wrAAAAAFuElD_SuX0RdtxWv3myo3t5AvqT', {action: 'signup'}).then(function(token) {
                document.getElementById('g-recaptcha-response').value = token;
                e.target.submit();
            });
        });
    });
    </script>
    <script src="https://www.google.com/recaptcha/api.js?render=6Lfsm6wrAAAAAFuElD_SuX0RdtxWv3myo3t5AvqT"></script>
    <script>
    grecaptcha.ready(function() {
        document.querySelector('form[action="signup.php"]').addEventListener('submit', function(e) {
            e.preventDefault();
            grecaptcha.execute('6Lfsm6wrAAAAAFuElD_SuX0RdtxWv3myo3t5AvqT', {action: 'signup'}).then(function(token) {
                document.getElementById('g-recaptcha-response').value = token;
                e.target.submit();
            });
        });
    });
    </script>
    </form>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
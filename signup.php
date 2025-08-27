<?php
session_start();
$successMessage = '';
$showForm = true;
$show_resend_top = false;
// Keep a prefill email for the resend button if needed
$prefill_resend_email = '';
if (isset($_SESSION['signup_success'])) {
    $successMessage = $_SESSION['signup_success'];
    unset($_SESSION['signup_success']);
    $showForm = false;
}
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$title = 'Sign Up';
$errors = [];
// Ensure a per-session randomized honeypot field name to prevent attackers
// from trivially adding a known field name in their client HTML to bypass.
if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['hp_name'])) {
    try {
        $_SESSION['hp_name'] = 'hp_' . bin2hex(random_bytes(6));
    } catch (Exception $e) {
        $_SESSION['hp_name'] = 'hp_' . bin2hex(substr(md5(uniqid('', true)), 0, 6));
    }
    // Record the form render time to enforce a minimum human interaction delay
    $_SESSION['signup_form_ts'] = time();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $showForm) {
    // Per-IP rate limiting: allow 3 signup attempts per rolling hour
    try {
        $db = getDb();
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $oneHourAgo = date('c', time() - 3600);
        $stmt = $db->prepare('SELECT COUNT(*) FROM signup_attempts WHERE ip = :ip AND attempted_at >= :since');
        $stmt->execute([':ip' => $ip, ':since' => $oneHourAgo]);
        $recent = (int)$stmt->fetchColumn();
        if ($recent >= 3) {
            $errors[] = 'Too many signup attempts from your IP address. Please try again later.';
        }
    } catch (Exception $e) {
        // If the DB check fails for any reason, log and continue (do not block signups)
        error_log('signup rate limit check failed: ' . $e->getMessage());
    }
    $name    = normalizeScalar($_POST['name'] ?? '', 128, '');
    $biz     = normalizeScalar($_POST['business_name'] ?? '', 128, '');
    $phone   = normalizeScalar($_POST['phone'] ?? '', 32, '');
    // Read honeypot using the per-session randomized name when available.
    $hpName = $_SESSION['hp_name'] ?? 'hp_field';
    $honeypot = normalizeScalar($_POST[$hpName] ?? $_POST['hp_field'] ?? '', 128, '');
    $email   = normalizeScalar($_POST['email'] ?? '', 254, '');
    // billing discrete components
    $bill_street = normalizeScalar($_POST['billing_street'] ?? '', 128, '');
    $bill_street2 = normalizeScalar($_POST['billing_street2'] ?? '', 128, '');
    $bill_city = normalizeScalar($_POST['billing_city'] ?? '', 64, '');
    $bill_state = normalizeScalar($_POST['billing_state'] ?? '', 64, '');
    $bill_zip = normalizeScalar($_POST['billing_zip'] ?? '', 16, '');
    // shipping discrete components
    $ship_street = normalizeScalar($_POST['shipping_street'] ?? '', 128, '');
    $ship_street2 = normalizeScalar($_POST['shipping_street2'] ?? '', 128, '');
    $ship_city = normalizeScalar($_POST['shipping_city'] ?? '', 64, '');
    $ship_state = normalizeScalar($_POST['shipping_state'] ?? '', 64, '');
    $ship_zip = normalizeScalar($_POST['shipping_zip'] ?? '', 16, '');
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm'] ?? '');

    $recaptcha_response = $_POST['g-recaptcha-response'] ?? '';
    $recaptcha_secret = '6Lfsm6wrAAAAAO-nZyV1DSDNVtsk5fzm3SALvNam';
    $recaptcha = false;
    if ($recaptcha_response) {
        $verify = @file_get_contents('https://www.google.com/recaptcha/api/siteverify?secret=' . $recaptcha_secret . '&response=' . urlencode($recaptcha_response));
        $captcha_success = json_decode($verify);
        if ($captcha_success && $captcha_success->success && ($captcha_success->score ?? 0) >= 0.5) {
            $recaptcha = true;
        } else {
            $errors[] = 'Captcha verification failed. Please try again.';
        }
    } else {
        $errors[] = 'Captcha verification required.';
    }
    if ($name === '' || $email === '' || $password === '') {
        $errors[] = 'Name, email and password are required.';
    }
    // Honeypot should be empty; if filled, treat as bot submission
    if ($honeypot !== '') {
        $errors[] = 'Bot detected.';
    }
    // Minimum time check: require the form to have been open for at least 3 seconds
    if (!empty($_SESSION['signup_form_ts'])) {
        $elapsed = time() - (int)$_SESSION['signup_form_ts'];
        if ($elapsed < 3) {
            $errors[] = 'Form submitted too quickly. Please take a moment and try again.';
        }
    }
    // Require business name and phone and billing fields
    if ($biz === '') $errors[] = 'Business name is required.';
    if ($phone === '') $errors[] = 'Phone number is required.';
    if ($bill_street === '' || $bill_city === '' || $bill_state === '' || $bill_zip === '') {
        $errors[] = 'Billing address (street, city, state, zip) is required.';
    }
    if ($password !== $confirm) $errors[] = 'Passwords do not match.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';
    $sameBillingFlag = isset($_POST['same_as_billing']);
    if ($sameBillingFlag) {
        $ship_street = $bill_street;
        $ship_street2 = $bill_street2;
        $ship_city = $bill_city;
        $ship_state = $bill_state;
        $ship_zip = $bill_zip;
    }
    // If shipping is not same as billing, require shipping fields
    if (!$sameBillingFlag) {
        if ($ship_street === '' || $ship_city === '' || $ship_state === '' || $ship_zip === '') {
            $errors[] = 'Shipping address (street, city, state, zip) is required.';
        }
    }
    // Normalize phone to digits and require exactly 10 digits for US numbers
    $phone_digits = preg_replace('/\D+/', '', $phone);
    if (strlen($phone_digits) !== 10) {
        $errors[] = 'Phone number must be a 10-digit US number.';
    } else {
        // store normalized phone (optional)
        $phone = $phone_digits;
    }
    // Validate US ZIP: 5 digits or 5-4 format
    $zip_to_check = $bill_zip;
    if (!preg_match('/^\d{5}(-\d{4})?$/', $zip_to_check)) {
        $errors[] = 'Billing ZIP code must be a US ZIP (12345 or 12345-6789).';
    }
    // If shipping distinct, validate shipping zip as US ZIP
    if (!$sameBillingFlag) {
        if (!preg_match('/^\d{5}(-\d{4})?$/', $ship_zip)) {
            $errors[] = 'Shipping ZIP code must be a US ZIP (12345 or 12345-6789).';
        }
    }
    if ($recaptcha && empty($errors)) {
    try {
            // Record this signup attempt (successful validation path reaches DB insert below)
            try {
                if (!isset($db)) $db = getDb();
                $ins = $db->prepare('INSERT INTO signup_attempts(ip, attempted_at) VALUES(:ip, :at)');
                $ins->execute([':ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown', ':at' => date('c')]);
            } catch (Exception $_e) {
                error_log('failed to record signup_attempt: ' . $_e->getMessage());
            }
            $customerId = createCustomer([
                'name' => $name,
                'business_name' => $biz,
                'phone' => $phone,
                'email' => $email,
                // Pass discrete address components
                'billing_street' => $bill_street,
                'billing_street2' => $bill_street2,
                'billing_city' => $bill_city,
                'billing_state' => $bill_state,
                'billing_zip' => $bill_zip,
                'shipping_street' => $ship_street,
                'shipping_street2' => $ship_street2,
                'shipping_city' => $ship_city,
                'shipping_state' => $ship_state,
                'shipping_zip' => $ship_zip,
                'password' => $password
            ]);
            $token = generateVerificationToken();
            setCustomerVerification($customerId, $token);
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
            $sent = sendEmail($email, 'Verify your Daytona Supply account', $body);
            if ($sent) {
                error_log('signup: verification email sent to ' . $email . ' (customer id: ' . $customerId . ')');
            } else {
                // Log token and recipient to help diagnose delivery issues.
                error_log('signup: FAILED to send verification email to ' . $email . ' (customer id: ' . $customerId . ') token=' . $token . ' body=' . substr($body, 0, 200));
            }
            $_SESSION['signup_success'] = 'Registration successful! Please check your email to verify your account.';
            header('Location: signup.php');
            exit;
        } catch (Exception $e) {
            // On exception (e.g., duplicate email), still record the attempt to avoid retries
            try {
                if (!isset($db)) $db = getDb();
                $ins = $db->prepare('INSERT INTO signup_attempts(ip, attempted_at) VALUES(:ip, :at)');
                $ins->execute([':ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown', ':at' => date('c')]);
            } catch (Exception $_e) {
                // swallow
            }
            $errors[] = $e->getMessage();
            // If the error is that the email already exists and the account
            // is not verified yet, show a resend verification button at the
            // top of the signup page so the user can request a new link.
            if (stripos($e->getMessage(), 'Email already registered') !== false) {
                $prefill_resend_email = $email;
                $_SESSION['flash_email'] = $email; // used by resend form prefill
                // Check if account exists and is unverified
                $existing = getCustomerByEmail($email);
                if ($existing && (empty($existing['is_verified']) || (int)$existing['is_verified'] === 0)) {
                    $show_resend_top = true;
                }
            }
        }
    }
}
include __DIR__ . '/includes/header.php';
?>
<h1>Sign Up</h1>
<?php if (!empty($show_resend_top)): ?>
    <div style="margin:0.5em 0;padding:0.5em;border-radius:6px;background:#fff;box-shadow:0 6px 18px rgba(0,0,0,0.04);">
        <p style="margin:0 0 0.4em 0;color:#222;">It looks like an account already exists for <strong><?= htmlspecialchars($prefill_resend_email) ?></strong> but it hasn't been verified yet.</p>
        <form method="post" action="resend_verification.php" style="margin:0;">
            <input type="hidden" name="email" value="<?= htmlspecialchars($prefill_resend_email) ?>">
            <button type="submit" class="muted-btn action-btn small">Resend verification email</button>
        </form>
    </div>
<?php endif; ?>
<?php if (!empty($successMessage)): ?>
    <div class="message"><?= htmlspecialchars($successMessage) ?></div>
<?php else: ?>
    <p class="lead">For new accounts, please complete the boxes, then click on the Create Account button below.</p>
    <?php if (!empty($errors)): ?>
        <ul class="error"><?php foreach ($errors as $err): ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <form id="signup_form" method="post" action="signup.php" class="vertical-form">
        <!-- Honeypot field to trap bots; name randomized per session to avoid simple bypass -->
        <div style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;">
            <?php $hpName = $_SESSION['hp_name'] ?? 'hp_field'; ?>
            <label for="<?= htmlspecialchars($hpName) ?>">Leave this field empty</label>
            <input type="text" name="<?= htmlspecialchars($hpName) ?>" id="<?= htmlspecialchars($hpName) ?>" autocomplete="off" tabindex="-1" value="">
            <!-- Fallback timestamp in case session data is lost for server-side minimum time check -->
            <input type="hidden" name="signup_ts" value="<?= time() ?>">
        </div>
        <p>Name:<br><input type="text" name="name" required value="<?= isset($name) ? htmlspecialchars($name) : '' ?>"></p>
        <p>Business Name:<br><input type="text" name="business_name" required value="<?= isset($biz) ? htmlspecialchars($biz) : '' ?>"></p>
        <p>Phone:<br><input type="text" name="phone" required value="<?= isset($phone) ? htmlspecialchars($phone) : '' ?>"></p>
        <p>Email:<br><input type="email" name="email" required value="<?= isset($email) ? htmlspecialchars($email) : '' ?>"></p>
        <fieldset>
            <legend>Billing Address</legend>
            <p>Street Address:<br><input type="text" name="billing_street" required value="<?= isset($bill_street) ? htmlspecialchars($bill_street) : '' ?>" autocomplete="off"></p>
            <p>Street Address 2:<br><input type="text" name="billing_street2" required value="<?= isset($bill_street2) ? htmlspecialchars($bill_street2) : '' ?>" autocomplete="off"></p>
            <p>City:<br><input type="text" name="billing_city" required value="<?= isset($bill_city) ? htmlspecialchars($bill_city) : '' ?>" autocomplete="off"></p>
            <p>State:<br><input type="text" name="billing_state" required value="<?= isset($bill_state) ? htmlspecialchars($bill_state) : '' ?>" autocomplete="off"></p>
            <p>Zip:<br><input type="text" name="billing_zip" required value="<?= isset($bill_zip) ? htmlspecialchars($bill_zip) : '' ?>" autocomplete="off"></p>
        </fieldset>
        <fieldset>
            <legend>Shipping Address</legend>
            <p><label><input type="checkbox" name="same_as_billing" id="signup_same_billing" value="1" <?= isset($_POST['same_as_billing']) ? 'checked' : '' ?>> Same as billing</label></p>
            <div id="signup_shipping_fields">
                <p>Street Address:<br><input type="text" name="shipping_street" required value="<?= isset($ship_street) ? htmlspecialchars($ship_street) : '' ?>" autocomplete="off"></p>
                <p>Street Address 2:<br><input type="text" name="shipping_street2" required value="<?= isset($ship_street2) ? htmlspecialchars($ship_street2) : '' ?>" autocomplete="off"></p>
                <p>City:<br><input type="text" name="shipping_city" required value="<?= isset($ship_city) ? htmlspecialchars($ship_city) : '' ?>" autocomplete="off"></p>
                <p>State:<br><input type="text" name="shipping_state" required value="<?= isset($ship_state) ? htmlspecialchars($ship_state) : '' ?>" autocomplete="off"></p>
                <p>Zip:<br><input type="text" name="shipping_zip" required value="<?= isset($ship_zip) ? htmlspecialchars($ship_zip) : '' ?>" autocomplete="off"></p>
            </div>
        </fieldset>
        <p>Password:<br><input type="password" name="password" required></p>
        <p>Confirm Password:<br><input type="password" name="confirm" required></p>
        <input type="hidden" id="g-recaptcha-response" name="g-recaptcha-response">
    <p><button type="submit" class="proceed-btn">Create Account</button></p>
    </form>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var checkbox = document.getElementById('signup_same_billing');
        var billing = {
            street: document.querySelector('input[name="billing_street"]'),
            street2: document.querySelector('input[name="billing_street2"]'),
            city: document.querySelector('input[name="billing_city"]'),
            state: document.querySelector('input[name="billing_state"]'),
            zip: document.querySelector('input[name="billing_zip"]')
        };
        var shipping = {
            street: document.querySelector('input[name="shipping_street"]'),
            street2: document.querySelector('input[name="shipping_street2"]'),
            city: document.querySelector('input[name="shipping_city"]'),
            state: document.querySelector('input[name="shipping_state"]'),
            zip: document.querySelector('input[name="shipping_zip"]')
        };
        function setShipping(disable) {
            if (disable) {
                shipping.street.value = billing.street.value || '';
                shipping.street2.value = billing.street2.value || '';
                shipping.city.value = billing.city.value || '';
                shipping.state.value = billing.state.value || '';
                shipping.zip.value = billing.zip.value || '';
                Object.values(shipping).forEach(function(f) { f.disabled = true; });
            } else {
                Object.values(shipping).forEach(function(f) { f.disabled = false; });
            }
        }
        if (checkbox) {
            checkbox.addEventListener('change', function() { setShipping(checkbox.checked); });
        }
        Object.values(billing).forEach(function(f) { if (f) f.addEventListener('input', function() { if (checkbox && checkbox.checked) setShipping(true); }); });
        setShipping(checkbox && checkbox.checked);
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
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
 
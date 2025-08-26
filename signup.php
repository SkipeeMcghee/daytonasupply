<?php
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
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $showForm) {
    $name    = normalizeScalar($_POST['name'] ?? '', 128, '');
    $biz     = normalizeScalar($_POST['business_name'] ?? '', 128, '');
    $phone   = normalizeScalar($_POST['phone'] ?? '', 32, '');
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
    if ($recaptcha && empty($errors)) {
        try {
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
            $errors[] = $e->getMessage();
        }
    }
}
include __DIR__ . '/includes/header.php';
?>
<h1>Sign Up</h1>
<?php if (!empty($successMessage)): ?>
    <div class="message"><?= htmlspecialchars($successMessage) ?></div>
<?php else: ?>
    <p class="lead">For new accounts, please complete the boxes, then click on the Create Account button below.</p>
    <?php if (!empty($errors)): ?>
        <ul class="error"><?php foreach ($errors as $err): ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <form id="signup_form" method="post" action="signup.php" class="vertical-form">
        <p>Name:<br><input type="text" name="name" required value="<?= isset($name) ? htmlspecialchars($name) : '' ?>"></p>
        <p>Business Name:<br><input type="text" name="business_name" value="<?= isset($biz) ? htmlspecialchars($biz) : '' ?>"></p>
        <p>Phone:<br><input type="text" name="phone" value="<?= isset($phone) ? htmlspecialchars($phone) : '' ?>"></p>
        <p>Email:<br><input type="email" name="email" required value="<?= isset($email) ? htmlspecialchars($email) : '' ?>"></p>
        <fieldset>
            <legend>Billing Address</legend>
            <p>Street Address:<br><input type="text" name="billing_street" value="<?= isset($bill_street) ? htmlspecialchars($bill_street) : '' ?>" autocomplete="off"></p>
            <p>Street Address 2:<br><input type="text" name="billing_street2" value="<?= isset($bill_street2) ? htmlspecialchars($bill_street2) : '' ?>" autocomplete="off"></p>
            <p>City:<br><input type="text" name="billing_city" value="<?= isset($bill_city) ? htmlspecialchars($bill_city) : '' ?>" autocomplete="off"></p>
            <p>State:<br><input type="text" name="billing_state" value="<?= isset($bill_state) ? htmlspecialchars($bill_state) : '' ?>" autocomplete="off"></p>
            <p>Zip:<br><input type="text" name="billing_zip" value="<?= isset($bill_zip) ? htmlspecialchars($bill_zip) : '' ?>" autocomplete="off"></p>
        </fieldset>
        <fieldset>
            <legend>Shipping Address</legend>
            <p><label><input type="checkbox" name="same_as_billing" id="signup_same_billing" value="1" <?= isset($_POST['same_as_billing']) ? 'checked' : '' ?>> Same as billing</label></p>
            <div id="signup_shipping_fields">
                <p>Street Address:<br><input type="text" name="shipping_street" value="<?= isset($ship_street) ? htmlspecialchars($ship_street) : '' ?>" autocomplete="off"></p>
                <p>Street Address 2:<br><input type="text" name="shipping_street2" value="<?= isset($ship_street2) ? htmlspecialchars($ship_street2) : '' ?>" autocomplete="off"></p>
                <p>City:<br><input type="text" name="shipping_city" value="<?= isset($ship_city) ? htmlspecialchars($ship_city) : '' ?>" autocomplete="off"></p>
                <p>State:<br><input type="text" name="shipping_state" value="<?= isset($ship_state) ? htmlspecialchars($ship_state) : '' ?>" autocomplete="off"></p>
                <p>Zip:<br><input type="text" name="shipping_zip" value="<?= isset($ship_zip) ? htmlspecialchars($ship_zip) : '' ?>" autocomplete="off"></p>
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
 
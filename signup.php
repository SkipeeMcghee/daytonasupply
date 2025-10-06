<?php
session_start();
$successMessage = '';
$showForm = true;
$show_resend_top = false;
// Only allow signup when arriving from the login page or when a server-side flag is set.
// This prevents users from navigating directly to signup.php except via the login "Sign up" button.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['from_login'])) {
    // Set a short-lived session flag permitting access to the signup form.
    // This POST is only a navigation signal from the login page; avoid
    // treating it as a form submission. Redirect to the signup page (GET)
    // so validation logic below doesn't run on this navigation POST.
    $_SESSION['allow_signup'] = time();
    header('Location: signup.php');
    exit;
}
// If no session allow flag present or it's older than 5 minutes, redirect back to login.
if (empty($_SESSION['allow_signup']) || (time() - (int)$_SESSION['allow_signup']) > 300) {
    // If we already completed signup and were redirected back, allow showing the success message.
    if (empty($_SESSION['signup_success'])) {
        header('Location: login.php');
        exit;
    }
}
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
$fieldErrors = [];

function add_field_error($field, $msg) {
    global $errors, $fieldErrors;
    $errors[] = $msg;
    if (!isset($fieldErrors[$field])) $fieldErrors[$field] = [];
    $fieldErrors[$field][] = $msg;
}
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
                add_field_error('general', 'Too many signup attempts from your IP address. Please try again later.');
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
            add_field_error('captcha', 'Captcha verification failed. Please try again.');
        }
    } else {
        add_field_error('captcha', 'Captcha verification required.');
    }
    if ($name === '') add_field_error('name', 'Name is required.');
    if ($email === '') add_field_error('email', 'Email is required.');
    if ($password === '') add_field_error('password', 'Password is required.');
    // Honeypot should be empty; if filled, treat as bot submission
    if ($honeypot !== '') {
        add_field_error('general', 'Bot detected.');
    }
    // Minimum time check: require the form to have been open for at least 3 seconds
    if (!empty($_SESSION['signup_form_ts'])) {
        $elapsed = time() - (int)$_SESSION['signup_form_ts'];
        if ($elapsed < 3) {
            add_field_error('general', 'Form submitted too quickly. Please take a moment and try again.');
        }
    }
    // Require business name and phone and billing fields
    if ($biz === '') add_field_error('business_name', 'Business name is required.');
    if ($phone === '') add_field_error('phone', 'Phone number is required.');
    if ($bill_street === '' || $bill_city === '' || $bill_state === '' || $bill_zip === '') {
        add_field_error('billing', 'Billing address (street, city, state, zip) is required.');
    }
    if ($password !== $confirm) add_field_error('confirm', 'Passwords do not match.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) add_field_error('email', 'Invalid email address.');
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
            add_field_error('shipping', 'Shipping address (street, city, state, zip) is required.');
        }
    }
    // Normalize phone to digits and require exactly 10 digits for US numbers
    $phone_digits = preg_replace('/\D+/', '', $phone);
    if (strlen($phone_digits) !== 10) {
        add_field_error('phone', 'Phone number must be a 10-digit US number.');
    } else {
        // store normalized phone (optional)
        $phone = $phone_digits;
    }
    // Validate US ZIP: 5 digits or 5-4 format
    $zip_to_check = $bill_zip;
    if (!preg_match('/^\d{5}(-\d{4})?$/', $zip_to_check)) {
        add_field_error('billing_zip', 'Billing ZIP code must be a US ZIP (12345 or 12345-6789).');
    }
    // If shipping distinct, validate shipping zip as US ZIP
    if (!$sameBillingFlag) {
        if (!preg_match('/^\d{5}(-\d{4})?$/', $ship_zip)) {
            add_field_error('shipping_zip', 'Shipping ZIP code must be a US ZIP (12345 or 12345-6789).');
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
            add_field_error('general', $e->getMessage());
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
    <section class="login-card" <?php if (empty($successMessage)): ?>aria-labelledby="signup-heading"<?php endif; ?>>
        <?php if (empty($successMessage)): ?>
                <h1 id="signup-heading">Create a Daytona Supply account</h1>
                <p class="login-sub">Create an account to place orders, track shipments, and manage your billing.</p>
        <?php endif; ?>

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
        <!-- Shared datalist for US state abbreviations -->
        <datalist id="us_states">
            <option value="AL"></option>
            <option value="AK"></option>
            <option value="AZ"></option>
            <option value="AR"></option>
            <option value="CA"></option>
            <option value="CO"></option>
            <option value="CT"></option>
            <option value="DE"></option>
            <option value="FL"></option>
            <option value="GA"></option>
            <option value="HI"></option>
            <option value="ID"></option>
            <option value="IL"></option>
            <option value="IN"></option>
            <option value="IA"></option>
            <option value="KS"></option>
            <option value="KY"></option>
            <option value="LA"></option>
            <option value="ME"></option>
            <option value="MD"></option>
            <option value="MA"></option>
            <option value="MI"></option>
            <option value="MN"></option>
            <option value="MS"></option>
            <option value="MO"></option>
            <option value="MT"></option>
            <option value="NE"></option>
            <option value="NV"></option>
            <option value="NH"></option>
            <option value="NJ"></option>
            <option value="NM"></option>
            <option value="NY"></option>
            <option value="NC"></option>
            <option value="ND"></option>
            <option value="OH"></option>
            <option value="OK"></option>
            <option value="OR"></option>
            <option value="PA"></option>
            <option value="RI"></option>
            <option value="SC"></option>
            <option value="SD"></option>
            <option value="TN"></option>
            <option value="TX"></option>
            <option value="UT"></option>
            <option value="VT"></option>
            <option value="VA"></option>
            <option value="WA"></option>
            <option value="WV"></option>
            <option value="WI"></option>
            <option value="WY"></option>
            <option value="DC"></option>
        </datalist>
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
            <div class="form-row inline">
                <label for="billing_street">Street Address</label>
                <div class="field">
                    <textarea id="billing_street" name="billing_street" class="autosize" required autocomplete="off"><?= isset($bill_street) ? htmlspecialchars($bill_street) : '' ?></textarea>
                </div>
            </div>
            <div class="form-row inline">
                <label for="billing_street2">Street Address 2</label>
                <div class="field"><input type="text" id="billing_street2" name="billing_street2" value="<?= isset($bill_street2) ? htmlspecialchars($bill_street2) : '' ?>" autocomplete="off"></div>
            </div>
            <div class="form-row">
                <div class="compact-inline">
                    <div>
                        <label for="billing_city">City</label>
                        <input type="text" id="billing_city" name="billing_city" required value="<?= isset($bill_city) ? htmlspecialchars($bill_city) : '' ?>" autocomplete="off">
                    </div>
                    <div>
                        <label for="billing_state">State</label>
                        <input list="us_states" type="text" id="billing_state" name="billing_state" required value="<?= isset($bill_state) ? htmlspecialchars($bill_state) : '' ?>" autocomplete="off" placeholder="e.g., FL">
                    </div>
                    <div>
                        <label for="billing_zip">Zip</label>
                        <input type="text" id="billing_zip" name="billing_zip" required value="<?= isset($bill_zip) ? htmlspecialchars($bill_zip) : '' ?>" autocomplete="off">
                    </div>
                </div>
            </div>
        </fieldset>
        <fieldset>
            <legend>Shipping Address</legend>
            <p><label><input type="checkbox" name="same_as_billing" id="signup_same_billing" value="1" <?= isset($_POST['same_as_billing']) ? 'checked' : '' ?>> Same as billing</label></p>
            <div id="signup_shipping_fields">
                <div class="form-row inline">
                    <label for="shipping_street">Street Address</label>
                    <div class="field">
                        <textarea id="shipping_street" name="shipping_street" class="autosize" required autocomplete="off"><?= isset($ship_street) ? htmlspecialchars($ship_street) : '' ?></textarea>
                    </div>
                </div>
                <div class="form-row inline">
                    <label for="shipping_street2">Street Address 2</label>
                    <div class="field"><input type="text" id="shipping_street2" name="shipping_street2" value="<?= isset($ship_street2) ? htmlspecialchars($ship_street2) : '' ?>" autocomplete="off"></div>
                </div>
                <div class="form-row">
                    <div class="compact-inline">
                        <div>
                            <label for="shipping_city">City</label>
                            <input type="text" id="shipping_city" name="shipping_city" required value="<?= isset($ship_city) ? htmlspecialchars($ship_city) : '' ?>" autocomplete="off">
                        </div>
                        <div>
                            <label for="shipping_state">State</label>
                            <input list="us_states" type="text" id="shipping_state" name="shipping_state" required value="<?= isset($ship_state) ? htmlspecialchars($ship_state) : '' ?>" autocomplete="off" placeholder="e.g., FL">
                        </div>
                        <div>
                            <label for="shipping_zip">Zip</label>
                            <input type="text" id="shipping_zip" name="shipping_zip" required value="<?= isset($ship_zip) ? htmlspecialchars($ship_zip) : '' ?>" autocomplete="off">
                        </div>
                    </div>
                </div>
            </div>
        </fieldset>
        <p>Password:<br><input type="password" name="password" id="signup_password" required></p>
        <p>Confirm Password:<br><input type="password" name="confirm" id="signup_confirm" required></p>
        <!-- Password rule hints — hidden by default, shown only when a rule is violated -->
        <ul id="pw_rules" class="pw-rules" aria-live="polite">
            <li class="pw-rule" data-rule="length">Must be at least 8 characters</li>
            <li class="pw-rule" data-rule="uppercase">Must contain an uppercase letter (A-Z)</li>
            <li class="pw-rule" data-rule="lowercase">Must contain a lowercase letter (a-z)</li>
            <li class="pw-rule" data-rule="digit">Must contain a digit (0-9)</li>
            <li class="pw-rule" data-rule="special">Must contain a special character (e.g., !@#$%)</li>
            <li class="pw-rule" data-rule="nospaces">Must not contain spaces</li>
        </ul>
        <input type="hidden" id="g-recaptcha-response" name="g-recaptcha-response">
    <p><button type="submit" class="btn-primary">Create Account</button></p>
    </form>
    </section>
    <!-- Removed stray closing </main>; footer will close the single main opened in header -->
    <script>
    // Ensure the "Same as Billing" functionality works with textarea + input elements
    document.addEventListener('DOMContentLoaded', function() {
        var checkbox = document.getElementById('signup_same_billing');
        function byId(id){ return document.getElementById(id); }
        var billing = {
            street: byId('billing_street'),     // textarea
            street2: byId('billing_street2'),   // input
            city: byId('billing_city'),
            state: byId('billing_state'),
            zip: byId('billing_zip')
        };
        var shipping = {
            street: byId('shipping_street'),    // textarea
            street2: byId('shipping_street2'),  // input
            city: byId('shipping_city'),
            state: byId('shipping_state'),
            zip: byId('shipping_zip')
        };
        function fields(obj){ return Object.keys(obj).map(function(k){ return obj[k]; }).filter(Boolean); }
        function setShipping(disable) {
            if (disable) {
                // Copy current billing values over
                if (shipping.street && billing.street) shipping.street.value = billing.street.value || '';
                if (shipping.street2 && billing.street2) shipping.street2.value = billing.street2.value || '';
                if (shipping.city && billing.city) shipping.city.value = billing.city.value || '';
                if (shipping.state && billing.state) shipping.state.value = billing.state.value || '';
                if (shipping.zip && billing.zip) shipping.zip.value = billing.zip.value || '';
                fields(shipping).forEach(function(f){ f.disabled = true; f.setAttribute('aria-disabled','true'); });
            } else {
                fields(shipping).forEach(function(f){ f.disabled = false; f.removeAttribute('aria-disabled'); });
            }
        }
        if (checkbox) {
            checkbox.addEventListener('change', function(){ setShipping(checkbox.checked); });
        }
        // If billing changes while same-as-billing is active, keep shipping in sync
        fields(billing).forEach(function(f){ if (!f) return; f.addEventListener('input', function(){ if (checkbox && checkbox.checked) setShipping(true); }); });
        // Initialize once
        setShipping(checkbox && checkbox.checked);
    });
    </script>
    <script>
    // Password rule validation: hide rules until the user interacts
    // or when the server already reported password/confirm errors.
    (function(){
        var pw = document.getElementById('signup_password');
        var confirm = document.getElementById('signup_confirm');
        var rulesEl = document.getElementById('pw_rules');
        if (!pw || !rulesEl) return;
        // If the server returned password/confirm errors, show rules immediately so users see guidance
        var serverShowPwRules = <?php echo (!empty($fieldErrors['password']) || !empty($fieldErrors['confirm'])) ? 'true' : 'false'; ?>;
        var rules = Array.prototype.slice.call(rulesEl.querySelectorAll('.pw-rule'));
        var touched = false; // becomes true after user focuses or types in password fields
        function testPassword(value) {
            var results = {
                length: value.length >= 8,
                uppercase: /[A-Z]/.test(value),
                lowercase: /[a-z]/.test(value),
                digit: /[0-9]/.test(value),
                special: /[!@#\$%\^&\*\(\)_\+\-=`\[\]\\\{\};:'"\\|,.<>\/?]/.test(value),
                nospaces: !/\s/.test(value)
            };
            return results;
        }
        function updateRules() {
            var val = pw.value || '';
            var r = testPassword(val);
            var anyFail = false;
            rules.forEach(function(el){
                var rule = el.getAttribute('data-rule');
                if (!r[rule]) { el.classList.add('fail'); anyFail = true; }
                else { el.classList.remove('fail'); }
            });
            // show rule box only when user interacted (touched) and there's a failure/typing,
            // or when the server has already indicated password/confirm errors.
            if (serverShowPwRules) {
                rulesEl.classList.add('show');
            } else if (touched && (anyFail || val.length > 0)) {
                rulesEl.classList.add('show');
            } else {
                rulesEl.classList.remove('show');
            }
            // confirm mismatch handling (show as a failing rule visually by toggling a class)
            if (confirm) {
                var mismatch = (val.length>0 && confirm.value.length>0 && val !== confirm.value);
                var exists = rulesEl.querySelector('[data-rule="mismatch"]');
                if (!exists) {
                    var li = document.createElement('li'); li.className='pw-rule'; li.setAttribute('data-rule','mismatch'); li.textContent='Passwords do not match'; rulesEl.appendChild(li); rules.push(li); exists = li;
                }
                if (mismatch) { exists.classList.add('fail'); rulesEl.classList.add('show'); } else { exists.classList.remove('fail'); }
            }
        }
        // mark touched on focus or first input so rules don't appear from autofill or initial state
        function touch() { if (!touched) { touched = true; updateRules(); } }
        pw.addEventListener('input', function(e){ touch(); updateRules(); });
        pw.addEventListener('focus', touch);
        if (confirm) { confirm.addEventListener('input', function(e){ touch(); updateRules(); }); confirm.addEventListener('focus', touch); }
        // initialize once (but only show if server signaled errors)
        updateRules();
    })();
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
 
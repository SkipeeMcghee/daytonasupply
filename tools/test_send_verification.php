<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

try {
    $db = getDb();
    $testEmail = 'test+' . time() . '@example.local';
    echo "Creating test customer with email: $testEmail\n";
    $id = createCustomer([
        'name' => 'CLI Test User',
        'business_name' => 'CLI Test',
        'phone' => '555-0000',
        'email' => $testEmail,
        'billing_street' => '1 CLI Way',
        'billing_street2' => '',
        'billing_city' => 'Testville',
        'billing_state' => 'TS',
        'billing_zip' => '00000',
        'shipping_street' => '1 CLI Way',
        'shipping_street2' => '',
        'shipping_city' => 'Testville',
        'shipping_state' => 'TS',
        'shipping_zip' => '00000',
        'password' => 'testing123'
    ]);
    echo "Created customer id: $id\n";
    $token = generateVerificationToken();
    setCustomerVerification($id, $token);
    echo "Stored token: $token\n";
    $scheme = 'http';
    $host = 'localhost:8000';
    $verifyPath = '/verify.php?token=' . urlencode($token);
    $verificationUrl = $scheme . '://' . $host . $verifyPath;
    $body = "Hello CLI Test User,\n\nPlease verify: " . $verificationUrl;
    echo "Attempting sendEmail...\n";
    $sent = sendEmail($testEmail, 'Verify your Daytona Supply account (CLI test)', $body);
    echo 'sendEmail returned: ' . ($sent ? 'true' : 'false') . "\n";
    $cust = getCustomerById($id);
    echo "Customer record verification_token (db): " . var_export($cust['verification_token'] ?? null, true) . "\n";
} catch (Exception $e) {
    echo 'Exception: ' . $e->getMessage() . "\n";
}

?>

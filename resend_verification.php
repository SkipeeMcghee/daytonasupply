<?php
session_start();
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

$email = normalizeScalar($_POST['email'] ?? '', 254, '');
if ($email === '') {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Please enter your email address.'];
    header('Location: login.php');
    exit;
}
$res = resendVerificationToCustomerByEmail($email);
if ($res['ok']) {
    $_SESSION['flash'] = ['type' => 'success', 'msg' => $res['message']];
} else {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => $res['message']];
}
// Keep email so the user can try logging again easily
$_SESSION['flash_email'] = $email;
header('Location: login.php');
exit;

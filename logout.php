<?php
// Logout script.  Clears the session and redirects the user to the home page.

session_start();
// Remove all session variables
$_SESSION = [];
// Destroy the session cookie if present
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}
session_destroy();

header('Location: index.php');
exit;
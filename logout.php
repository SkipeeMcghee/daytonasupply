<?php
// Log the user out by destroying the session and redirecting to the
// homepage.

session_start();
// Unset all session variables
$_SESSION = [];
// Destroy the session
session_destroy();
// Redirect to the homepage
header('Location: /index.php');
exit;
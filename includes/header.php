<?php
// Shared header for all pages.  Includes HTML doctype, head section
// with styles and a navigation bar.  Starts the <main> element.

// Ensure a session is started so cart and user data can persist.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If the site is being served over HTTPS, instruct modern browsers
// to automatically upgrade insecure (http://) subresources to HTTPS.
// This helps mitigate mixed-content warnings without changing runtime URLs.
if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
    // Send the CSP header early before any output is emitted.
    header('Content-Security-Policy: upgrade-insecure-requests');
}

// Determine the number of items in the cart (stored in session)
$cartCount = 0;
if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $qty) {
        $cartCount += (int)$qty;
    }
}
// Check if user is logged in
$loggedIn = isset($_SESSION['customer']);
// Check if admin logged in
$adminLoggedIn = isset($_SESSION['admin']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($title) ? htmlspecialchars($title) : 'Daytona Supply'; ?></title>
    <!-- Use a relative path for the stylesheet. Append file modification time to bust client caches when the file changes -->
    <?php $cssPath = __DIR__ . '/../assets/styles.css'; ?>
    <link rel="stylesheet" href="assets/styles.css?v=<?php echo file_exists($cssPath) ? filemtime($cssPath) : time(); ?>">
</head>
<body>
<header>
    <nav>
        <ul class="nav">
            <!-- Use relative links so navigation works regardless of the base directory -->
            <li><a href="index.php">Home</a></li>
            <li><a href="catalogue.php">Catalogue</a></li>
            <li><a href="cart.php">Cart (<?php echo $cartCount; ?>)</a></li>
            <?php if ($loggedIn): ?>
                <li><a href="account.php">My Account</a></li>
                <li><a href="logout.php">Logout</a></li>
            <?php else: ?>
                <li><a href="signup.php">Sign Up</a></li>
                <li><a href="login.php">Login</a></li>
            <?php endif; ?>
            <?php if ($adminLoggedIn): ?>
                <li><a href="managerportal.php">Manager Portal</a></li>
            <?php endif; ?>
        </ul>
    </nav>
    <h1 class="site-title">Daytona Supply</h1>
</header>
<main>
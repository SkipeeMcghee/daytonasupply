<?php
// Manager portal login and dashboard.  This file handles authentication before any HTML
// is sent to the browser.  On successful login, admins are redirected to the portal.

session_start();
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

// If the admin isn't logged in yet, handle login
if (!isset($_SESSION['admin'])) {
    $loginError = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $password = trim($_POST['password'] ?? '');
        // Retrieve the admin password hash from database
        $stmt = getDb()->query('SELECT password_hash FROM admin LIMIT 1');
        $hash = $stmt->fetchColumn();
        if ($hash && password_verify($password, $hash)) {
            // Set admin session and redirect to the portal page
            $_SESSION['admin'] = true;
            header('Location: managerportal.php');
            exit;
        }
        // Incorrect password; store error for display
        $loginError = 'Incorrect password.';
    }
    // Show the login form
    $title = 'Office Manager Login';
    include __DIR__ . '/includes/header.php';
    ?>
    <h1>Office Manager Login</h1>
    <?php if (!empty($loginError)): ?>
        <p style="color:red"><?= htmlspecialchars($loginError) ?></p>
    <?php endif; ?>
    <form method="post" action="managerportal.php">
        <label>Password: <input type="password" name="password" required></label>
        <button type="submit">Login</button>
    </form>
    <?php
    include __DIR__ . '/includes/footer.php';
    return;
}

// At this point the admin is authenticated. Set page title and include header.
$title = 'Manager Portal';
include __DIR__ . '/includes/header.php';

//
// Existing manager portal content goes below.  Paste the rest of your original
// managerportal.php code here to display orders, customers, and products.
// Make sure that any redirects (using header()) occur before the footer
// is included.
//

include __DIR__ . '/includes/footer.php';
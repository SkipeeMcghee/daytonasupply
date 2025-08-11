<?php
// Landing page for Daytona Supply.  Provides a brief introduction and
// directs visitors to sign up or browse the catalogue.
require_once __DIR__ . '/includes/header.php';
$title = 'Welcome';
?>
<section class="landing">
    <h2>Welcome to Daytona Supply</h2>
    <p>Your trusted supplier for quality parts and supplies.  Explore our
        catalogue and place purchase orders online.  If you are new
        here, please <a href="/signup.php">create an account</a> to get started.</p>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
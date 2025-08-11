<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

$title = 'Login';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $customer = authenticateCustomer($email, $password);
    if ($customer) {
        $_SESSION['customer'] = $customer;
        // Determine redirect: if cart has items go to checkout else account
        if (!empty($_SESSION['cart'])) {
            header('Location: /cart.php');
        } else {
            header('Location: /account.php');
        }
        exit;
    } else {
        $error = 'Invalid email or password';
    }
}
?>

<h2>Login</h2>
<?php if ($error): ?>
    <div class="error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<form method="post" action="">
    <p>Email: <input type="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required></p>
    <p>Password: <input type="password" name="password" required></p>
    <p><button type="submit">Login</button></p>
</form>

<?php include __DIR__ . '/includes/footer.php'; ?>
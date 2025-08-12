<?php
// Product catalogue page with search capability.

// Start a session so cart and user data persist across requests.
session_start();
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

$title = 'Product Catalogue';

// If the form to add a product was submitted, update the session cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['product_id'], $_POST['quantity'])) {
    $pid = (int)$_POST['product_id'];
    $qty = (int)$_POST['quantity'];
    if ($qty < 1) {
        $qty = 1;
    }
    // Initialise cart structure if absent
    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }
    // Increment quantity if product already in cart
    if (isset($_SESSION['cart'][$pid])) {
        $_SESSION['cart'][$pid] += $qty;
    } else {
        $_SESSION['cart'][$pid] = $qty;
    }
    // Redirect back to catalogue to implement the Post/Redirect/Get pattern and
    // keep the user on this page.  Preserve the search term if one was
    // provided on the form or in the query string.
    $searchParam = '';
    if (!empty($_POST['search'])) {
        $searchParam = '?search=' . urlencode($_POST['search']);
    } elseif (!empty($_GET['search'])) {
        $searchParam = '?search=' . urlencode($_GET['search']);
    }
    header('Location: catalogue.php' . $searchParam);
    exit;
}

// Retrieve search term from the query string
$search = trim($_GET['search'] ?? '');

// Determine which products to show
if ($search !== '') {
    // Fall back to a manual query if a dedicated search function is not available
    $db = getDb();
    $stmt = $db->prepare('SELECT * FROM products WHERE name LIKE :term OR description LIKE :term ORDER BY id ASC');
    $stmt->execute([':term' => '%' . $search . '%']);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $products = getAllProducts();
}

include __DIR__ . '/includes/header.php';
?>
<h1>Product Catalogue</h1>
<form method="get" action="catalogue.php" style="margin-bottom:1em;">
    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search products...">
    <button type="submit">Search</button>
    <?php if ($search !== ''): ?>
        <a href="catalogue.php">Clear</a>
    <?php endif; ?>
</form>
<?php if (empty($products)): ?>
    <p>No products found.</p>
<?php else: ?>
<table>
    <tr>
        <th>Name</th>
        <th>Description</th>
        <th>Price</th>
        <th>Add to Cart</th>
    </tr>
    <?php foreach ($products as $p): ?>
        <tr>
            <td><?= htmlspecialchars($p['name']) ?></td>
            <td><?= htmlspecialchars($p['description']) ?></td>
            <td>$<?= number_format($p['price'], 2) ?></td>
            <td>
                <form method="post" action="catalogue.php" style="margin:0;">
                    <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                    <input type="number" name="quantity" value="1" min="1" style="width:3em;">
                    <?php if ($search !== ''): ?>
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                    <?php endif; ?>
                    <button type="submit">Add</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
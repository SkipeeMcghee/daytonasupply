<?php
// Product catalogue page with search capability.
session_start();
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

$title = 'Product Catalogue';

// Retrieve search term from the query string
$search = trim($_GET['search'] ?? '');

// Determine which products to show
if ($search !== '') {
    // Use searchProducts() if available; fall back to manual query otherwise
    if (function_exists('searchProducts')) {
        $products = searchProducts($search);
    } else {
        $db = getDb();
        $stmt = $db->prepare('SELECT * FROM products WHERE name LIKE :term OR description LIKE :term ORDER BY id ASC');
        $stmt->execute([':term' => '%' . $search . '%']);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
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
                <form method="post" action="cart.php" style="margin:0;">
                    <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                    <input type="number" name="quantity" value="1" min="1" style="width:3em;">
                    <button type="submit">Add</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
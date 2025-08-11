<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

$title = 'Catalogue';
$message = '';

// Handle add to cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['product_id'])) {
    $productId = (int)($_POST['product_id']);
    $qty = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;
    if ($qty < 1) {
        $qty = 1;
    }
    // Validate product exists
    $product = getProductById($productId);
    if ($product) {
        if (!isset($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }
        if (!isset($_SESSION['cart'][$productId])) {
            $_SESSION['cart'][$productId] = 0;
        }
        $_SESSION['cart'][$productId] += $qty;
        $message = 'Added ' . htmlspecialchars($product['name']) . ' to cart.';
    } else {
        $message = 'Invalid product selected.';
    }
}

$products = getAllProducts();
?>

<h2>Product Catalogue</h2>
<?php if ($message): ?>
    <div class="message"><?php echo $message; ?></div>
<?php endif; ?>
<div class="products">
    <?php foreach ($products as $product): ?>
        <div class="product-item">
            <h3><?php echo htmlspecialchars($product['name']); ?></h3>
            <p><?php echo htmlspecialchars($product['description']); ?></p>
            <p class="price">$<?php echo number_format($product['price'], 2); ?></p>
            <form method="post" action="">
                <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                <label>Qty: <input type="number" name="quantity" value="1" min="1" style="width:60px"></label>
                <button type="submit">Add to Cart</button>
            </form>
        </div>
    <?php endforeach; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
<?php
// Product detail page showing a larger image, name, description, and price.

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

$title = 'Product Details';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$prod = $id > 0 ? getProductById($id) : null;
if (!$prod) {
    http_response_code(404);
}

include __DIR__ . '/includes/header.php';
?>
<div class="container"><div class="form-card">
<?php if (!$prod): ?>
    <h1>Product not found</h1>
    <p>Sorry, we couldn't find that product. <a href="catalogue.php" class="proceed-btn btn-catalog">Back to Catalog</a></p>
<?php else: ?>
    <?php
        $name = (string)($prod['name'] ?? '');
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
        $slug = trim(preg_replace('/-+/', '-', $slug), '-');
        if ($slug === '') $slug = 'product';
        $base = '/assets/uploads/products/' . $slug;
        $exts = ['jpg','jpeg','png','webp','gif'];
        $imgUrl = '';
        foreach ($exts as $e) {
            $path = __DIR__ . '/assets/uploads/products/' . $slug . '.' . $e;
            if (is_file($path)) { $imgUrl = $base . '.' . $e; break; }
        }
        $placeholder = '/assets/DaytonaSupplyDSlogo.png';
        if (!is_file(__DIR__ . '/assets/DaytonaSupplyDSlogo.png')) { $placeholder = '/assets/images/DaytonaSupplyDSlogo.png'; }
        $hero = $imgUrl ?: $placeholder;
    ?>
    <section class="page-hero centered">
        <h1><?= htmlspecialchars($prod['name']) ?></h1>
    </section>
    <div style="display:grid;grid-template-columns:1fr;gap:16px;align-items:start;">
        <div style="text-align:center;">
            <img src="<?= htmlspecialchars($hero) ?>" alt="<?= htmlspecialchars($prod['name']) ?>" style="max-width:420px;width:100%;height:auto;border-radius:12px;border:1px solid rgba(11,34,56,0.06);background:#fff;box-shadow:0 10px 30px rgba(11,34,56,0.06);">
        </div>
        <div>
            <p class="lead" style="margin-top:0;"><?= htmlspecialchars($prod['description'] ?? $prod['name']) ?></p>
            <p style="font-size:1.2rem;font-weight:800;margin:10px 0;">$<?= number_format((float)($prod['price'] ?? 0), 2) ?></p>
            <p>
                <a href="catalogue.php" class="proceed-btn btn-catalog">Back to Catalog</a>
            </p>
        </div>
    </div>
<?php endif; ?>
</div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>

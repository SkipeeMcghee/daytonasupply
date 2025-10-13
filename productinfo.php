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

// AJAX favorite toggle handling (mirrors catalogue.php behavior)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['favorite_product_id'])) {
    $fid = (int)$_POST['favorite_product_id'];
    $favorited = false;
    if (!empty($_SESSION['customer']) && !empty($_SESSION['customer']['id'])) {
        $favorited = toggleFavoriteForCustomerProduct((int)$_SESSION['customer']['id'], $fid);
    }
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'favorited' => $favorited, 'productId' => $fid, 'requiresLogin' => empty($_SESSION['customer']['id'])]);
        exit;
    }
    // non-AJAX fallback: redirect back to this product page
    $back = 'productinfo.php?id=' . $id;
    header('Location: ' . $back);
    exit;
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
    <div style="display:grid;grid-template-columns:1fr;gap:16px;align-items:start;">
        <div style="text-align:center;">
            <img src="<?= htmlspecialchars($hero) ?>" alt="<?= htmlspecialchars($prod['name']) ?>" style="max-width:420px;width:100%;height:auto;border-radius:12px;border:1px solid rgba(11,34,56,0.06);background:#fff;box-shadow:0 10px 30px rgba(11,34,56,0.06);">
        </div>
        <div>
            <?php
                $desc = (string)($prod['description'] ?? $prod['name']);
                $dispName = getProductDisplayName($prod);
                $price = (float)($prod['price'] ?? 0);
                $skuName = $dispName; // favorites keyed by display name
                $isFav = false;
                $onSale = !empty($prod['deal']);
                if (!empty($_SESSION['customer']['id']) && $skuName !== '') {
                    $favs = getFavoriteSkusByCustomerId((int)$_SESSION['customer']['id']);
                    if ($favs) { $isFav = in_array($skuName, $favs, true); }
                }
            ?>
            <!-- Bold description -->
            <div style="font-weight:800; font-size:1.05rem; line-height:1.3; margin-top:0; margin-bottom:6px;">
                <?= htmlspecialchars($desc) ?>
            </div>
            <!-- Name below, not bold -->
            <div style="font-weight:400; opacity:0.95; font-size:1rem; margin-bottom:10px;">
                <?= htmlspecialchars($dispName) ?>
            </div>
            <?php $detailLoggedIn = !empty($_SESSION['customer']['id']); ?>
            <?php if ($detailLoggedIn): ?>
                <p style="font-size:1.2rem;font-weight:800;margin:10px 0;display:flex;align-items:center;gap:8px;">
                    <span>$<?= number_format($price, 2) ?></span>
                    <?php if ($onSale): ?>
                        <span style="display:inline-flex;align-items:center;background:#16a34a;color:#fff;border-radius:999px;padding:4px 8px;font-weight:800;font-size:12px;line-height:1;">On Sale!</span>
                    <?php endif; ?>
                </p>
                <div style="display:flex; flex-wrap:wrap; align-items:center; gap:10px;">
                    <form method="post" action="catalogue.php" class="cart-add" style="margin:0; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        <input type="hidden" name="product_id" value="<?= (int)$id ?>">
                        <input type="hidden" name="product_name" value="<?= htmlspecialchars($dispName) ?>">
                        <input type="hidden" name="product_description" value="<?= htmlspecialchars($desc) ?>">
                        <input type="hidden" name="product_price" value="<?= htmlspecialchars(number_format($price, 2, '.', '')) ?>">
                        <span class="qty-wrap" style="display:inline-flex;gap:6px;align-items:center;">
                            <label for="pi_qty_<?= (int)$id ?>" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;">Quantity</label>
                            <input id="pi_qty_<?= (int)$id ?>" type="number" name="quantity" value="1" min="1" step="1" style="width:4.5em;">
                            <select class="qty-preset qty-compact" aria-label="Quick quantity" style="padding:6px 8px;border:1px solid #d7e1ea;border-radius:6px;">
                                <option value="1">1</option>
                                <option value="5">5</option>
                                <option value="10">10</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="75">75</option>
                                <option value="100">100</option>
                            </select>
                        </span>
                        <button type="submit" class="shop-btn" style="background:#0b5ed7;color:#fff;border:none;border-radius:8px;padding:8px 12px;font-weight:800;line-height:1;white-space:nowrap;">Add to Cart</button>
                    </form>
                    <button class="fav-toggle <?= $isFav ? 'fav-on' : '' ?>" data-product-id="<?= (int)$id ?>" aria-pressed="<?= $isFav ? 'true' : 'false' ?>" title="<?= $isFav ? 'Remove from favorites' : 'Add to favorites' ?>" style="background:transparent;border:0;padding:6px;cursor:pointer;">
                        <svg class="fav-icon" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M12 .587l3.668 7.431 8.2 1.192-5.934 5.787 1.402 8.169L12 18.897l-7.336 3.866 1.402-8.169L.132 9.21l8.2-1.192z" />
                        </svg>
                    </button>
                </div>
            <?php else: ?>
                <p style="font-size:.95rem;font-weight:600;margin:16px 0 6px;">Price available to registered customers.</p>
                <p style="margin:4px 0 18px;">
                    <a href="login.php?next=<?= urlencode('productinfo.php?id=' . (int)$id) ?>" class="login-required" style="font-size:.9rem;font-weight:600;color:#0b5ed7;text-decoration:none;">Log in</a>
                </p>
            <?php endif; ?>
            <p style="margin-top:14px;">
                <a href="catalogue.php" class="proceed-btn btn-catalog">Back to Catalog</a>
            </p>

    </div>
    <style>
    /* Compact preset: hide displayed number in closed state, keep dropdown options readable */
    .qty-preset.qty-compact {
        width: 34px; min-width: 34px;
        padding-left: 0.5rem; padding-right: 0.5rem;
        text-indent: 9999px; white-space: nowrap; overflow: hidden;
        appearance: auto; /* keep native caret */
    }
    .qty-preset.qty-compact:focus { outline: 2px solid #0b5ed7; outline-offset: 2px; }
    </style>
    <script>
    // Sync preset dropdown to quantity input; same behavior as catalogue
    document.addEventListener('DOMContentLoaded', function(){
        document.querySelectorAll('form.cart-add').forEach(function(form){
            var num = form.querySelector('input[name="quantity"]');
            var sel = form.querySelector('select.qty-preset');
            if (!num || !sel) return;
            sel.addEventListener('change', function(){
                var v = parseInt(sel.value, 10);
                if (!isNaN(v) && v > 0) { num.value = String(v); }
            });
        });
    });
    </script>
<?php endif; ?>
</div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>

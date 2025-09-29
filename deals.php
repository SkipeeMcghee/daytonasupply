<?php
// Deals page: prominently display products flagged as deal=1
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$title = 'Deals';
$metaDescription = 'Current deals and specials from Daytona Supply.';

// Load products and filter to deals
$all = getAllProducts();
$deals = array_values(array_filter($all, function($p){ return !empty($p['deal']); }));

// Load category filters/groups and prepare category image resolution
$skuData = @include __DIR__ . '/includes/sku_filters.php';
$skuFilters = is_array($skuData) && isset($skuData['filters']) ? $skuData['filters'] : [];
// Base image map to prefer for well-known categories
$imgMap = [
  'CORRUGATED BOXES' => 'assets/images/boxes.png',
  'TAPE' => 'assets/images/tape.png',
  'PACKAGING SUPPLIES' => 'assets/images/stretchfilm.png',
  'PAPER PRODUCTS' => 'assets/images/paper.png',
  'BUBBLE PRODUCTS' => 'assets/images/bubble.png',
  'FOAM' => 'assets/images/foam.png',
];
$placeholder = 'assets/images/DaytonaSupplyDSlogo.png';

function resolveCategoryForProduct(array $p, array $skuFilters): ?string {
  $name = strtoupper((string)($p['name'] ?? ''));
  if ($name === '') return null;
  foreach ($skuFilters as $label => $codes) {
    foreach ((array)$codes as $code) {
      $c = strtoupper($code);
      if ($c !== '' && strpos($name, $c) === 0) {
        return (string)$label;
      }
    }
  }
  return null;
}

function resolveImageForProduct(array $p, array $skuFilters, array $imgMap, string $placeholder): string {
  // 1) Try a product-specific image by name variations
  $name = (string)($p['name'] ?? '');
  $baseDir = __DIR__ . '/assets/images/';
  $webBase = 'assets/images/';
  if ($name !== '') {
    $candidates = [];
    $noSpaces = str_replace(' ', '', $name);
    $alnum = preg_replace('/[^A-Za-z0-9]/', '', $name);
    $candidates[] = $noSpaces . '.png';
    if ($alnum !== $noSpaces) $candidates[] = $alnum . '.png';
    $candidates[] = strtolower($noSpaces) . '.png';
    $candidates[] = strtolower($alnum) . '.png';
    foreach ($candidates as $f) {
      if ($f && is_readable($baseDir . $f)) return $webBase . $f;
    }
  }
  // 2) Fallback to parent category image based on SKU prefix mapping
  $cat = resolveCategoryForProduct($p, $skuFilters);
  if ($cat) {
    if (isset($imgMap[$cat])) return $imgMap[$cat];
    // Attempt deterministic filename from category label
    $noSpaces = str_replace(' ', '', $cat);
    $alnum = preg_replace('/[^A-Za-z0-9]/', '', $cat);
    $catCandidates = [$noSpaces . '.png'];
    if ($alnum !== $noSpaces) $catCandidates[] = $alnum . '.png';
    $catCandidates[] = strtolower($noSpaces) . '.png';
    $catCandidates[] = strtolower($alnum) . '.png';
    foreach ($catCandidates as $f) {
      if ($f && is_readable($baseDir . $f)) return $webBase . $f;
    }
  }
  // 3) Final fallback
  return $placeholder;
}

require __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div class="form-card">
    <h1 style="margin-bottom:16px;">Deals</h1>
    <?php if (empty($deals)): ?>
      <p>No active deals right now. Please check back soon.</p>
    <?php else: ?>
  <div class="categories-grid deals-grid">
        <?php foreach ($deals as $p): $pid=(int)$p['id']; $name=getProductDisplayName($p); $desc=getProductDescription($p); $price=getProductPrice($p); $img=resolveImageForProduct($p, $skuFilters, $imgMap, $placeholder); ?>
          <div class="category-card" style="position:relative;">
            <div class="cat-img-wrap" style="position:relative; overflow:hidden; border-radius:12px;">
              <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($name) ?>" style="width:100%; height:220px; object-fit:cover; display:block;">
              <div class="cat-body" style="position:absolute; left:0; right:0; bottom:0; background:linear-gradient(180deg, rgba(0,0,0,0.0), rgba(0,0,0,0.55)); color:#fff; padding:10px;">
                <!-- Description first (bold, left-aligned) -->
                <div style="font-weight:700; font-size:0.98rem; line-height:1.25; margin-bottom:4px; text-align:left; max-height:2.7em; overflow:hidden; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;"><?= htmlspecialchars($desc) ?></div>
                <!-- SKU centered, not bold -->
                <div style="opacity:0.95; font-weight:400; font-size:0.95rem; margin-bottom:6px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; text-align:center;"><?= htmlspecialchars($name) ?></div>
                <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
                  <div style="font-weight:800; font-size:1.0rem;">$<?= number_format($price,2) ?></div>
                  <form class="cart-add" method="post" action="catalogue.php" style="margin:0;">
                    <input type="hidden" name="product_id" value="<?= $pid ?>">
                    <input type="hidden" name="product_name" value="<?= htmlspecialchars($name, ENT_QUOTES) ?>">
                    <input type="hidden" name="product_description" value="<?= htmlspecialchars($desc, ENT_QUOTES) ?>">
                    <input type="hidden" name="product_price" value="<?= number_format($price,2,'.','') ?>">
                    <input type="hidden" name="quantity" value="1">
                    <button type="submit" class="shop-btn deals-add" style="background:#0b5ed7;color:#fff;border:none;border-radius:8px;padding:8px 12px;font-weight:800;line-height:1;white-space:nowrap;">Add to Cart</button>
                  </form>
                </div>
              </div>
              <div style="position:absolute;top:8px;left:8px;background:#dc3545;color:#fff;padding:6px 10px;border-radius:999px;font-weight:800;">Deal</div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

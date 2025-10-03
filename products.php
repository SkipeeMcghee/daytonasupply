<?php
// Products landing page with full category grid and subcategories for Corrugated Boxes
$title = 'Products — Shop by Category';
require_once __DIR__ . '/includes/header.php';

// Load SKU filters and groups
$skuData = @include __DIR__ . '/includes/sku_filters.php';
$skuFilters = is_array($skuData) && isset($skuData['filters']) ? $skuData['filters'] : [];
$skuGroups = is_array($skuData) && isset($skuData['groups']) ? $skuData['groups'] : [];

// Map known homepage categories to their images; use DS logo as placeholder for others
$imgMap = [
    'CORRUGATED BOXES' => 'assets/images/boxes.png',
    'TAPE' => 'assets/images/tape.png',
    'PACKAGING SUPPLIES' => 'assets/images/stretchfilm.png',
    'PAPER PRODUCTS' => 'assets/images/paper.png',
    'BUBBLE PRODUCTS' => 'assets/images/bubble.png',
    'FOAM' => 'assets/images/foam.png',
];
$placeholder = 'assets/images/DaytonaSupplyDSlogo.png';
?>
    <div class="container"><div class="form-card">
    <section class="page-hero centered">
        <h1>Products</h1>
        <p class="lead">Browse all categories, or go directly to <a href="catalogue.php" class="proceed-btn btn-catalog">All Products</a></p>
    </section>

    <section class="categories" aria-label="Shop by category">
        <div id="allCategories">
            <?php foreach ($skuGroups as $groupLabel => $labels): ?>
                <div class="group-block">
                    <h2 class="group-title"><?php echo htmlspecialchars($groupLabel); ?></h2>
                    <div class="grid categories-grid">
                        <?php foreach ($labels as $label): if (!isset($skuFilters[$label])) continue; ?>
                            <?php
                                $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $label));
                                // Resolve image for this category
                                if (isset($imgMap[$label])) {
                                    $img = $imgMap[$label];
                                } else {
                                    $baseDir = __DIR__ . '/assets/images/';
                                    $webBase = 'assets/images/';
                                    $candidates = [];
                                    $noSpaces = str_replace(' ', '', $label);
                                    $candidates[] = $noSpaces . '.png';
                                    $alnum = preg_replace('/[^A-Za-z0-9]/', '', $label);
                                    if ($alnum !== $noSpaces) $candidates[] = $alnum . '.png';
                                    $candidates[] = strtolower($noSpaces) . '.png';
                                    $candidates[] = strtolower($alnum) . '.png';
                                    $img = $placeholder;
                                    foreach ($candidates as $file) {
                                        if (is_readable($baseDir . $file)) { $img = $webBase . $file; break; }
                                    }
                                }
                                $url = 'catalogue.php?sku=' . urlencode($label);
                                // All categories now have subcategories panels
                                $hasSub = true;
                                $targetId = (strtoupper($label) === 'CORRUGATED BOXES') ? 'subcats-corrugated' : ('subcats-' . $slug);
                            ?>
                            <a class="category-card" href="<?php echo htmlspecialchars($url); ?>" data-has-subcats="1" data-subcats-target="<?php echo htmlspecialchars($targetId); ?>">
                                <img src="<?php echo htmlspecialchars($img); ?>" alt="<?php echo htmlspecialchars($label); ?>" loading="lazy">
                                <div class="cat-body">
                                    <h3><?php echo htmlspecialchars($label); ?></h3>
                                    <button class="shop-btn">Shop<br>Category</button>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Subcategory panel for Corrugated Boxes (hidden by default) -->
        <?php 
            $corrLabel = 'CORRUGATED BOXES';
            $allCorrUrl = 'catalogue.php?sku=' . urlencode($corrLabel);
            $cubeUrl = $allCorrUrl . '&sub=cube';
            $corrSaleUrl = $allCorrUrl . '&show=onsale';
            $corrImg = $imgMap[$corrLabel] ?? $placeholder;
            // Resolve images for subcategories with same logic (no spaces/alnum/lowercase)
            $baseDir = __DIR__ . '/assets/images/';
            $webBase = 'assets/images/';
            // Cube Boxes image
            $cubeLabel = 'Cube Boxes';
            $cubeCandidates = [];
            $cubeNoSpaces = str_replace(' ', '', $cubeLabel);
            $cubeCandidates[] = $cubeNoSpaces . '.png';
            $cubeAlnum = preg_replace('/[^A-Za-z0-9]/', '', $cubeLabel);
            if ($cubeAlnum !== $cubeNoSpaces) $cubeCandidates[] = $cubeAlnum . '.png';
            $cubeCandidates[] = strtolower($cubeNoSpaces) . '.png';
            $cubeCandidates[] = strtolower($cubeAlnum) . '.png';
            $cubeImg = $corrImg; // fallback to corrugated image if none found
            foreach ($cubeCandidates as $f) { if (is_readable($baseDir . $f)) { $cubeImg = $webBase . $f; break; } }
            // All Corrugated Boxes image (likely falls back)
            $allCorrLabelText = 'All Corrugated Boxes';
            $allCandidates = [];
            $allNoSpaces = str_replace(' ', '', $allCorrLabelText);
            $allCandidates[] = $allNoSpaces . '.png';
            $allAlnum = preg_replace('/[^A-Za-z0-9]/', '', $allCorrLabelText);
            if ($allAlnum !== $allNoSpaces) $allCandidates[] = $allAlnum . '.png';
            $allCandidates[] = strtolower($allNoSpaces) . '.png';
            $allCandidates[] = strtolower($allAlnum) . '.png';
            $allCorrImg = $corrImg; // fallback to corrugated image if none found
            foreach ($allCandidates as $f) { if (is_readable($baseDir . $f)) { $allCorrImg = $webBase . $f; break; } }
        ?>
        <div class="subcategory-panel" id="subcats-corrugated" hidden>
            <div class="subcats-head">
                <button type="button" class="back-to-cats" aria-label="Back to all categories">← All Categories</button>
                <h2 class="subcats-title">Corrugated Boxes</h2>
            </div>
            <div class="grid categories-grid">
                <a class="category-card" href="<?php echo htmlspecialchars($allCorrUrl); ?>">
                    <img src="<?php echo htmlspecialchars($allCorrImg); ?>" alt="All Corrugated Boxes" loading="lazy">
                    <div class="cat-body">
                        <h3>All Corrugated Boxes</h3>
                        <button class="shop-btn">Shop<br>Subcategory</button>
                    </div>
                </a>
                <a class="category-card" href="<?php echo htmlspecialchars($cubeUrl); ?>">
                    <img src="<?php echo htmlspecialchars($cubeImg); ?>" alt="Cube Boxes" loading="lazy">
                    <div class="cat-body">
                        <h3>Cube Corrugated Boxes</h3>
                        <button class="shop-btn">Shop<br>Subcategory</button>
                    </div>
                </a>
                <a class="category-card" href="<?php echo htmlspecialchars($corrSaleUrl); ?>">
                    <img src="<?php echo htmlspecialchars($corrImg); ?>" alt="Corrugated Boxes Deals" loading="lazy">
                    <div class="cat-body">
                        <h3>Corrugated Boxes Deals</h3>
                        <button class="shop-btn">Shop<br>Subcategory</button>
                    </div>
                </a>
            </div>
        </div>

        <?php
        // Generate subcategory panels for all other categories: "All X" and "On Sale"
        foreach ($skuGroups as $groupLabel => $labels) {
            foreach ($labels as $label) {
                if (!isset($skuFilters[$label])) continue;
                if (strtoupper($label) === 'CORRUGATED BOXES') continue; // corrugated handled above
                $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $label));
                $baseDir = __DIR__ . '/assets/images/';
                $webBase = 'assets/images/';
                // Use category image or DS logo
                if (isset($imgMap[$label])) { $catImg = $imgMap[$label]; }
                else {
                    $candidates = [];
                    $noSpaces = str_replace(' ', '', $label);
                    $candidates[] = $noSpaces . '.png';
                    $alnum = preg_replace('/[^A-Za-z0-9]/', '', $label);
                    if ($alnum !== $noSpaces) $candidates[] = $alnum . '.png';
                    $candidates[] = strtolower($noSpaces) . '.png';
                    $candidates[] = strtolower($alnum) . '.png';
                    $catImg = $placeholder;
                    foreach ($candidates as $file) { if (is_readable($baseDir . $file)) { $catImg = $webBase . $file; break; } }
                }
                $allUrl = 'catalogue.php?sku=' . urlencode($label);
                $saleUrl = $allUrl . '&show=onsale';
                $allHeading = 'All ' . ucwords(strtolower($label));
                $dealsHeading = ucwords(strtolower($label)) . ' Deals';
                ?>
                <div class="subcategory-panel" id="subcats-<?php echo htmlspecialchars($slug); ?>" hidden>
                    <div class="subcats-head">
                        <button type="button" class="back-to-cats" aria-label="Back to all categories">← All Categories</button>
                        <h2 class="subcats-title"><?php echo htmlspecialchars($label); ?></h2>
                    </div>
                    <div class="grid categories-grid">
                        <a class="category-card" href="<?php echo htmlspecialchars($allUrl); ?>">
                            <img src="<?php echo htmlspecialchars($catImg); ?>" alt="<?php echo htmlspecialchars($allHeading); ?>" loading="lazy">
                            <div class="cat-body">
                                <h3><?php echo htmlspecialchars($allHeading); ?></h3>
                                <button class="shop-btn">Shop<br>Subcategory</button>
                            </div>
                        </a>
                        <a class="category-card" href="<?php echo htmlspecialchars($saleUrl); ?>">
                            <img src="<?php echo htmlspecialchars($catImg); ?>" alt="<?php echo htmlspecialchars($dealsHeading); ?>" loading="lazy">
                            <div class="cat-body">
                                <h3><?php echo htmlspecialchars($dealsHeading); ?></h3>
                                <button class="shop-btn">Shop<br>Subcategory</button>
                            </div>
                        </a>
                    </div>
                </div>
                <?php
            }
        }
        ?>
    </section>

    <!-- Back to top -->
    <div id="backToTopWrap" class="back-to-top-wrap" aria-hidden="true">
        <span class="back-to-top-label">Return To Top</span>
        <button id="backToTop" class="back-to-top" aria-label="Back to top">↑</button>
    </div>
    </div></div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<style>
/* Products page grid: 3 per row with equal spacing */
.categories-grid {
    grid-template-columns: repeat(3, 1fr);
    gap: 20px; /* equal spacing between columns and rows */
}
/* Group blocks for Packaging / Janitorial / Safety */
.group-block { margin-bottom: 28px; }
.group-title { margin: 10px 0 12px; font-size: 1.25rem; }
@media (max-width: 900px) {
    .categories-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 520px) {
    /* Keep a minimum of 2 buttons per row even on narrow displays */
    .categories-grid { grid-template-columns: repeat(2, 1fr); }
    /* Hide Shop buttons on very narrow displays for a cleaner look */
    .categories .category-card .cat-body .shop-btn { display: none; }
}
.subcategory-panel[hidden] { display: none !important; }
.subcats-head { display:flex; align-items:center; gap:12px; margin: 4px 0 12px; }
.back-to-cats { appearance:none; border:1px solid #d7e1ea; background:#fff; color:#0a2a43; padding:6px 10px; border-radius:6px; cursor:pointer; }
html.theme-dark .back-to-cats, body.theme-dark .back-to-cats { background:#0f1722; color:#e6edf3; border-color:#2a3847; }
</style>

<script>
// Toggle between all categories and subcategories for categories that have subcats
document.addEventListener('DOMContentLoaded', function(){
    var allCats = document.getElementById('allCategories');
    function scrollToHero(){
        try { window.scrollTo({ top: 0, behavior: 'smooth' }); }
        catch(e) { window.scrollTo(0,0); }
    }
    if (!allCats) return;
    allCats.addEventListener('click', function(e){
        var card = e.target.closest && e.target.closest('.category-card');
        if (!card) return;
        var hasSub = card.getAttribute('data-has-subcats');
        var targetId = card.getAttribute('data-subcats-target');
        if (hasSub && targetId) {
            e.preventDefault();
            var panel = document.getElementById(targetId);
            if (!panel) return;
            allCats.style.display = 'none';
            panel.hidden = false;
            // scroll to the Products heading at the top
            scrollToHero();
        }
        // else: normal navigation occurs
    });
    // Back buttons for all subcategory panels
    document.querySelectorAll('.back-to-cats').forEach(function(backBtn){
        backBtn.addEventListener('click', function(){
            var openPanels = document.querySelectorAll('.subcategory-panel:not([hidden])');
            openPanels.forEach(function(p){ p.hidden = true; });
            allCats.style.display = '';
            // scroll to the Products heading at the top
            scrollToHero();
        });
    });

    // If landing with ?cat=... from the homepage, auto-open that category's subcategories
    try {
        var params = new URLSearchParams(window.location.search);
        var cat = (params.get('cat') || '').toLowerCase();
        if (cat) {
            // Map url slugs used on index.php to SKU labels used on products.php panels
            var map = {
                'corrugated': 'corrugated-boxes',
                'tape': 'tape',
                'packaging-supplies': 'packaging-supplies',
                'paper-products': 'paper-products',
                'bubble-products': 'bubble-products',
                'foam': 'foam'
            };
            var slug = map[cat] || cat;
            var targetId = (slug === 'corrugated-boxes') ? 'subcats-corrugated' : ('subcats-' + slug);
            var panel = document.getElementById(targetId);
            if (panel) {
                allCats.style.display = 'none';
                panel.hidden = false;
                scrollToHero();
            }
        }
    } catch (e) { /* no-op */ }

    // If a ?cat=slug is present, auto-open that category's subcategories panel
    var params = new URLSearchParams(window.location.search);
    var slug = params.get('cat');
    if (slug) {
        var targetId = (slug === 'corrugated-boxes' || slug === 'corrugated' ? 'subcats-corrugated' : 'subcats-' + slug);
        var panel = document.getElementById(targetId);
        if (panel) {
            allCats.style.display = 'none';
            panel.hidden = false;
            // scroll to the Products heading at the top
            scrollToHero();
        }
    }
});
</script>

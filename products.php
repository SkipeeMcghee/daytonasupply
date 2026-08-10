<?php
$title = 'Products - Shop by Category';
require_once __DIR__ . '/includes/categories.php';
require_once __DIR__ . '/includes/functions.php';

$categoryGroups = getCategoryTree(false);
$allProducts = getAllProducts();
$dealSkus = [];
foreach ($allProducts as $product) {
    if (!empty($product['deal'])) $dealSkus[(string)($product['name'] ?? '')] = true;
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="container"><div class="form-card">
    <section class="page-hero centered">
        <h1>Products</h1>
        <p class="lead">Browse all categories, or go directly to <a href="catalogue.php" class="proceed-btn btn-catalog">All Products</a></p>
    </section>

    <section class="categories" aria-label="Shop by category">
        <div id="allCategories">
            <?php foreach ($categoryGroups as $group): ?>
                <div class="group-block">
                    <h2 class="group-title"><?= htmlspecialchars($group['name']) ?></h2>
                    <div class="grid categories-grid">
                        <?php foreach ($group['categories'] as $category): ?>
                            <a class="category-card" href="catalogue.php?cat=<?= urlencode($category['slug']) ?>" data-subcats-target="subcats-<?= htmlspecialchars($category['slug']) ?>">
                                <img src="<?= htmlspecialchars(resolveCategoryImage($category)) ?>" alt="<?= htmlspecialchars($category['name']) ?>" loading="lazy">
                                <div class="cat-body"><h3><?= htmlspecialchars($category['name']) ?></h3><button class="shop-btn">Shop<br>Category</button></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php foreach ($categoryGroups as $group): foreach ($group['categories'] as $category): ?>
            <?php
                $categorySkus = array_fill_keys(getCategoryAssignments((int)$category['id'], true), true);
                $hasDeals = false;
                foreach ($dealSkus as $sku => $_) {
                    if (isset($categorySkus[$sku])) { $hasDeals = true; break; }
                }
                $categoryImage = resolveCategoryImage($category);
            ?>
            <div class="subcategory-panel" id="subcats-<?= htmlspecialchars($category['slug']) ?>" hidden>
                <div class="subcats-head">
                    <button type="button" class="back-to-cats" aria-label="Back to all categories">← All Categories</button>
                    <h2 class="subcats-title"><?= htmlspecialchars($category['name']) ?></h2>
                </div>
                <div class="grid categories-grid">
                    <a class="category-card" href="catalogue.php?cat=<?= urlencode($category['slug']) ?>">
                        <img src="<?= htmlspecialchars($categoryImage) ?>" alt="All <?= htmlspecialchars($category['name']) ?>" loading="lazy">
                        <div class="cat-body"><h3>All <?= htmlspecialchars($category['name']) ?></h3><button class="shop-btn">Shop<br>Category</button></div>
                    </a>
                    <?php foreach ($category['children'] as $subcategory): ?>
                        <a class="category-card" href="catalogue.php?cat=<?= urlencode($category['slug']) ?>&amp;sub=<?= urlencode($subcategory['slug']) ?>">
                            <img src="<?= htmlspecialchars(resolveCategoryImage($subcategory, $category)) ?>" alt="<?= htmlspecialchars($subcategory['name']) ?>" loading="lazy">
                            <div class="cat-body"><h3><?= htmlspecialchars($subcategory['name']) ?></h3><button class="shop-btn">Shop<br>Subcategory</button></div>
                        </a>
                    <?php endforeach; ?>
                    <?php if ($hasDeals): ?>
                        <a class="category-card" href="catalogue.php?cat=<?= urlencode($category['slug']) ?>&amp;onsale=1">
                            <img src="<?= htmlspecialchars($categoryImage) ?>" alt="<?= htmlspecialchars($category['name']) ?> Deals" loading="lazy">
                            <div class="cat-body"><h3><?= htmlspecialchars($category['name']) ?> Deals</h3><button class="shop-btn">Shop<br>Deals</button></div>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; endforeach; ?>
    </section>
</div></div>

<style>
.categories-grid { grid-template-columns:repeat(3,1fr); gap:20px; }
.group-block { margin-bottom:28px; }
.group-title { margin:18px 0 14px; font-size:1.8rem; font-weight:800; color:#0b5ed7; display:flex; align-items:center; gap:.65rem; }
.group-title::before { content:''; width:6px; height:1.4em; background:#0b5ed7; border-radius:3px; }
.subcategory-panel[hidden] { display:none !important; }
.subcats-head { display:flex; align-items:center; gap:12px; margin:4px 0 12px; }
.subcats-title { margin:0; font-size:1.5rem; }
.back-to-cats { border:1px solid #d7e1ea; background:#fff; color:#0b2238; padding:8px 12px; border-radius:6px; font-weight:700; cursor:pointer; }
html.theme-dark .back-to-cats, body.theme-dark .back-to-cats { background:#0f1722; color:#e6edf3; border-color:#2a3847; }
@media (max-width:760px) { .categories-grid { grid-template-columns:repeat(2,1fr); gap:12px; } }
@media (max-width:420px) { .categories-grid { grid-template-columns:1fr; } }
</style>
<script>
document.addEventListener('DOMContentLoaded', function(){
    var allCategories = document.getElementById('allCategories');
    if (!allCategories) return;
    function openPanel(id){
        var panel = document.getElementById(id);
        if (!panel) return false;
        document.querySelectorAll('.subcategory-panel').forEach(function(item){ item.hidden = true; });
        allCategories.hidden = true;
        panel.hidden = false;
        window.scrollTo({top:0, behavior:'smooth'});
        return true;
    }
    allCategories.addEventListener('click', function(event){
        var card = event.target.closest('.category-card[data-subcats-target]');
        if (!card) return;
        if (openPanel(card.getAttribute('data-subcats-target'))) event.preventDefault();
    });
    document.querySelectorAll('.back-to-cats').forEach(function(button){
        button.addEventListener('click', function(){
            document.querySelectorAll('.subcategory-panel').forEach(function(item){ item.hidden = true; });
            allCategories.hidden = false;
            window.history.replaceState({}, '', 'products.php');
            window.scrollTo({top:0, behavior:'smooth'});
        });
    });
    var slug = new URLSearchParams(window.location.search).get('cat');
    if (slug) openPanel('subcats-' + slug.toLowerCase());
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
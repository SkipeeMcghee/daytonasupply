<?php
// Product catalogue page with search capability.

// Start a session so cart and user data persist across requests.
// Ensure session cookie parameters are set before starting the session so
// the cookie is sent with consistent domain/path/secure flags. This helps
// avoid cases where the browser does not send the session cookie between
// requests (common when hostnames/subdomains differ between requests).
$host = $_SERVER['HTTP_HOST'] ?? '';
$hostNoPort = preg_replace('/:\\d+$/', '', $host);
$isLocal = ($hostNoPort === 'localhost' || filter_var($hostNoPort, FILTER_VALIDATE_IP));
$secure = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off');
if (!$isLocal) {
    // Use a broad domain (strip leading www.) so cookies work across subdomains
    $cookieDomain = '.' . preg_replace('/^www\./i', '', $hostNoPort);
} else {
    $cookieDomain = '';
}
// Use SameSite=Lax to allow top-level navigation POSTs while protecting CSRF.
$params = [
    'lifetime' => 0,
    'path' => '/',
    'domain' => $cookieDomain ?: null,
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax'
];
// session_set_cookie_params accepts an array in PHP 7.3+
@session_set_cookie_params($params);
session_start();
// If running on localhost or CLI enable error display for debugging to help
// diagnose HTTP 500 responses during development. This will not affect
// production if your site is served from a public host.
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
$host = $_SERVER['HTTP_HOST'] ?? '';
if (php_sapi_name() === 'cli' || $remote === '127.0.0.1' || $remote === '::1' || stripos($host, 'localhost') !== false) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}
// Include dependencies and catch exceptions during bootstrap so we can
// surface a friendly error page and log the failure (this catches errors
// like DB connection failures that would otherwise trigger a 500 with
// no project-level log entry).
try {
    require __DIR__ . '/includes/db.php';
    require __DIR__ . '/includes/functions.php';
} catch (Exception $e) {
    $errorRef = bin2hex(random_bytes(6));
    $msg = '[' . date('c') . '] catalogue.php bootstrap error (' . $errorRef . '): ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    error_log($msg);
    // Attempt to persist to project-level locations
    $candidates = [__DIR__ . '/data/logs', __DIR__ . '/data', __DIR__];
    foreach ($candidates as $dir) {
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents(rtrim($dir, '\\/').'/catalogue_errors.log', $msg, FILE_APPEND | LOCK_EX);
    }
    http_response_code(500);
    include __DIR__ . '/includes/header.php';
    echo '<h1>Product Catalogue</h1>';
    echo '<p>Sorry, we are unable to perform that search right now. Our team has been notified.</p>';
    echo '<p>Please quote reference <strong>' . htmlspecialchars($errorRef) . '</strong> when contacting support.</p></main>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$title = 'Catalog';

// Toggle favorite handler (AJAX-aware, DB-backed). Requires login.
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
    // non-AJAX fallback: redirect back
    $back = 'catalogue.php';
    if (!empty($_GET['search'])) $back .= '?search=' . urlencode($_GET['search']);
    header('Location: ' . $back);
    exit;
}

// If the form to add a product was submitted, update the session cart
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['product_id'], $_POST['quantity'])) {
    $pid = (int)$_POST['product_id'];
    $qty = (int)$_POST['quantity'];
    if ($qty < 1) {
        $qty = 1;
    }
    // Ensure cart structure
    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }
    // Read product snapshot at time of add-to-cart so the cart shows
    // the SKU/description/price as they were when the user added the item.
    // Prefer values posted from the catalogue listing to avoid relying on
    // a back-end DB lookup at POST time (helps in transient DB failure cases
    // and matches what the user saw when they clicked Add).
    $postedName = isset($_POST['product_name']) ? trim((string)$_POST['product_name']) : null;
    $postedDesc = isset($_POST['product_description']) ? trim((string)$_POST['product_description']) : null;
    $postedPrice = isset($_POST['product_price']) ? (float)str_replace(',', '', $_POST['product_price']) : null;
    $prod = getProductById($pid);
    $snapshot = [
        'product_id' => $pid,
        'quantity' => $qty,
        'product_name' => $postedName !== null ? $postedName : ($prod ? getProductDisplayName($prod) : ''),
        'product_description' => $postedDesc !== null ? $postedDesc : ($prod ? getProductDescription($prod) : ''),
        'product_price' => $postedPrice !== null ? $postedPrice : ($prod ? getProductPrice($prod) : 0.0)
    ];
    // If item exists in cart already (array or simple qty), merge quantities
    if (isset($_SESSION['cart'][$pid])) {
        $existing = $_SESSION['cart'][$pid];
        if (is_array($existing) && isset($existing['quantity'])) {
            $existing['quantity'] = (int)$existing['quantity'] + $qty;
            // keep original snapshot fields (name/price/desc) from first add
            $_SESSION['cart'][$pid] = $existing;
        } else {
            // legacy numeric qty stored previously, convert to snapshot
            $existingQty = (int)$existing;
            $snapshot['quantity'] = $existingQty + $qty;
            $_SESSION['cart'][$pid] = $snapshot;
        }
    } else {
        $_SESSION['cart'][$pid] = $snapshot;
    }
    // Persist a fallback cart snapshot. Use a stable cookie key when possible
    // so the frontend and subsequent requests can load the same snapshot even
    // when PHP session handling is unreliable in the environment.
    try {
        $cartDir = __DIR__ . '/data/carts';
        if (!is_dir($cartDir)) @mkdir($cartDir, 0755, true);
        // Prefer a cookie-based key so the browser will present the same key
        // on future requests (covers cases where PHPSESSID is not preserved).
        $cartKey = $_COOKIE['dg_cart_key'] ?? null;
        if (!$cartKey) {
            try { $cartKey = bin2hex(random_bytes(10)); } catch (Exception $_) { $cartKey = uniqid('c', true); }
            // set cookie for 30 days; path=/ so it's available site-wide
            setcookie('dg_cart_key', $cartKey, time() + 60*60*24*30, '/');
            // also update $_COOKIE for current request handling
            $_COOKIE['dg_cart_key'] = $cartKey;
        }
        if ($cartKey) {
            @file_put_contents($cartDir . '/cart_' . $cartKey . '.json', json_encode($_SESSION['cart']), LOCK_EX);
        }
        // Also write a session-id keyed snapshot so servers can load the
        // cart by PHPSESSID when session storage is unreliable.
        $sid = session_id();
        if ($sid) {
            @file_put_contents($cartDir . '/sess_' . $sid . '.json', json_encode($_SESSION['cart']), LOCK_EX);
        }
    } catch (Exception $e) { /* no-op */ }
    // Also persist a compact JSON version of the cart in a cookie as a
    // lightweight fallback for environments where PHP sessions are
    // unreliable (e.g. some dev setups). The cookie is site-wide and
    // lasts 30 days. We Base64-encode to keep the value safe for cookie
    // transport without URL-encoding issues.
    try {
        $cartJson = json_encode($_SESSION['cart']);
        if ($cartJson !== false) {
            $cookieVal = base64_encode($cartJson);
            // 30 days
            setcookie('dg_cart', $cookieVal, time() + 60*60*24*30, '/');
            $_COOKIE['dg_cart'] = $cookieVal;
        }
    } catch (Exception $_) { /* ignore cookie failures */ }
    // If this is an AJAX request, return JSON and do not redirect.
    $isAjax = false;
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        $isAjax = true;
    } elseif (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
        $isAjax = true;
    }
    if ($isAjax) {
        // compute cart count: support both legacy numeric entries and
        // snapshot-shaped entries stored as arrays with a 'quantity' key.
        $count = 0;
        foreach ($_SESSION['cart'] as $entry) {
            if (is_array($entry) && isset($entry['quantity'])) {
                $count += (int)$entry['quantity'];
            } else {
                $count += (int)$entry;
            }
        }
        header('Content-Type: application/json');
        $resp = ['success' => true, 'cartCount' => $count, 'productId' => $pid];
        // Ensure session data is written immediately so subsequent requests see updates
        @session_write_close();
        echo json_encode($resp);
        exit;
    }
    // Redirect back to catalogue to implement the Post/Redirect/Get pattern and
    // keep the user on this page. Preserve current filters (search/category/sub/favorites/onsale)
    // and add a fragment so the browser scrolls to the product just added.
    $params = [];
    $src = function($k){ return $_POST[$k] ?? ($_GET[$k] ?? null); };
    $sv = $src('search'); if ($sv !== null && $sv !== '') { $params['search'] = (string)$sv; }
    $skuV = $src('sku'); if ($skuV !== null && $skuV !== '') { $params['sku'] = (string)$skuV; }
    $subV = $src('sub'); if ($subV !== null && $subV !== '') { $params['sub'] = (string)$subV; }
    $favV = $src('favorites'); if ($favV !== null && $favV !== '' && (int)$favV === 1) { $params['favorites'] = 1; }
    $saleV = $src('onsale'); if ($saleV !== null && $saleV !== '' && (int)$saleV === 1) { $params['onsale'] = 1; }
    // Backward compat: if legacy show param present and equals favorites/onsale, reflect it
    $showV = $src('show');
    if (!isset($params['favorites']) && $showV === 'favorites') { $params['favorites'] = 1; }
    if (!isset($params['onsale']) && $showV === 'onsale') { $params['onsale'] = 1; }
    $searchParam = !empty($params) ? ('?' . http_build_query($params)) : '';
    $fragment = '#product-' . $pid;
    // If an added_id was posted (we inject it client-side), propagate for highlight
    $addedId = isset($_POST['product_id']) ? (int)$_POST['product_id'] : null;
    $highlightFragment = $addedId ? ('#added-' . $addedId) : $fragment;
    $addedParam = $addedId ? ('&added_id=' . $addedId) : '';
    header('Location: catalogue.php' . $searchParam . $addedParam . $highlightFragment);
    exit;
}

// Retrieve search term from the query string
$search = normalizeScalar($_GET['search'] ?? '', 150, '');
// Independent toggle filters: favorites and on sale
// Backward compatibility: also honor legacy show=favorites|onsale
$favoritesOn = false;
$onSaleOn = false;
if (isset($_GET['favorites'])) { $favoritesOn = (bool)((int)$_GET['favorites']); }
elseif (isset($_GET['fav'])) { $favoritesOn = (bool)((int)$_GET['fav']); }
elseif (isset($_GET['show']) && $_GET['show'] === 'favorites') { $favoritesOn = true; }
if (isset($_GET['onsale'])) { $onSaleOn = (bool)((int)$_GET['onsale']); }
elseif (isset($_GET['sale'])) { $onSaleOn = (bool)((int)$_GET['sale']); }
elseif (isset($_GET['show']) && $_GET['show'] === 'onsale') { $onSaleOn = true; }
// SKU/type filter key
// Accept either `sku` (short code or label) or `cat` (slug from index.php) so
// links like catalogue.php?cat=foam work as expected. We map a slug to the
// nearest SKU filter label and populate $skuKey with that label so the
// UI renders the corresponding button as active.
$skuKey = normalizeScalar($_GET['sku'] ?? '', 64, '');
$catParam = normalizeScalar($_GET['cat'] ?? '', 64, '');
$subParam = normalizeScalar($_GET['sub'] ?? '', 64, '');
if ($skuKey === '' && $catParam !== '') {
    // Deterministic slug -> SKU filter label map for the homepage category tiles.
    $slugMap = [
        'corrugated' => 'CORRUGATED BOXES',
        'tape' => 'TAPE',
        'packaging-supplies' => 'PACKAGING SUPPLIES',
        'paper-products' => 'PAPER PRODUCTS',
        'bubble-products' => 'BUBBLE PRODUCTS',
        'foam' => 'FOAM'
    ];
    $lowerSlug = strtolower($catParam);
    if (isset($slugMap[$lowerSlug])) {
        $skuKey = $slugMap[$lowerSlug];
    } else {
        // Fallback to best-effort heuristics when a slug isn't in the map
        $catToken = strtoupper(str_replace(['-', '_'], ' ', $catParam));
        $matched = null;
        foreach ($skuFilters as $label => $codes) {
            $labelNorm = strtoupper($label);
            if ($labelNorm === $catToken) {
                $matched = $label;
                break;
            }
            if (strpos($labelNorm, $catToken) !== false || strpos($catToken, str_replace(' ', '_', $labelNorm)) !== false) {
                $matched = $label;
                break;
            }
        }
        if ($matched !== null) {
            $skuKey = $matched;
        } else {
            $skuKey = $catParam;
        }
    }
}

// Determine which products to show
// Strategy: always fetch the full "show all" product list and then
// filter it in-memory when a search term is provided. This ensures the
// search behaves as a client-side filter of the full catalogue and
// avoids environment-specific database search differences on hosts.
try {
    // Load all products first (consistent with "Show All")
    $products = getAllProducts();
    // If a search term was provided, filter the loaded products in PHP.
    if ($search !== '') {
        $term = $search; // already normalized by normalizeScalar
        $products = array_values(array_filter($products, function($p) use ($term) {
            $name = $p['name'] ?? '';
            $desc = $p['description'] ?? '';
            // Case-insensitive substring match against name or description
            return (stripos($name, $term) !== false) || (stripos($desc, $term) !== false);
        }));
    }
} catch (Exception $e) {
    // Build a detailed message for diagnostics and include a short error reference
    $errorRef = bin2hex(random_bytes(6)); // short identifier for cross-referencing
    $msg = '[' . date('c') . '] catalogue.php error (' . $errorRef . '): ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    // Log to the system logger first
    error_log($msg);
    // Try a sequence of project-local writable locations so at least one
    // place captures the error even when the webserver user has restricted
    // permissions.  We prefer data/logs/, then data/, then project root.
    $candidates = [__DIR__ . '/data/logs', __DIR__ . '/data', __DIR__];
    $written = false;
    foreach ($candidates as $dir) {
        try {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $logFile = rtrim($dir, '\\/') . '/catalogue_errors.log';
            $res = @file_put_contents($logFile, $msg, FILE_APPEND | LOCK_EX);
            if ($res !== false) {
                $written = true;
                break;
            }
        } catch (Exception $inner) {
            // ignore and try next candidate
        }
    }
    if (!$written) {
        // As a last resort, attempt to write to PHP's sys_get_temp_dir()
        $tmp = sys_get_temp_dir() ?: null;
        if ($tmp) {
            @file_put_contents(rtrim($tmp, '\\/') . '/daytona_catalogue_errors.log', $msg, FILE_APPEND | LOCK_EX);
        }
    }
    http_response_code(500);
    include __DIR__ . '/includes/header.php';
    echo '<h1>Product Catalogue</h1>';
    echo '<p>Sorry, we are unable to perform that search right now. Our team has been notified.</p>';
    echo '<p>If this problem continues, please contact support and quote reference <strong>' . htmlspecialchars($errorRef) . '</strong>.</p></main>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

// Build favorites set for the logged-in user
$favoriteSkuSet = [];
if (!empty($_SESSION['customer']['id'])) {
    $favSkus = getFavoriteSkusByCustomerId((int)$_SESSION['customer']['id']);
    if ($favSkus) {
        foreach ($favSkus as $s) { $favoriteSkuSet[$s] = true; }
    }
}
// If showing favorites, filter the products list by the stored favorites (by product name/SKU)
if ($favoritesOn) {
    if (!empty($favoriteSkuSet)) {
        $products = array_values(array_filter($products, function($p) use ($favoriteSkuSet) {
            $sku = getProductDisplayName($p);
            return $sku !== '' && isset($favoriteSkuSet[$sku]);
        }));
    } else {
        // Not logged in or no favorites — show none
        $products = [];
    }
}

// If showing on-sale only, filter to products with active deals (deal=1)
if ($onSaleOn) {
    $products = array_values(array_filter($products, function($p){ return !empty($p['deal']); }));
}

// Load shared SKU filters and grouping from includes/sku_filters.php
$skuData = @include __DIR__ . '/includes/sku_filters.php';
$skuFilters = is_array($skuData) && isset($skuData['filters']) ? $skuData['filters'] : [];


// Apply SKU/type filter if provided
if ($skuKey !== '') {
    // normalize incoming key to uppercase and match against keys
    $skuKeyNorm = strtoupper($skuKey);
    // find matching filter entry by key or by value
    $matchedKey = null;
    foreach ($skuFilters as $label => $codes) {
        if (strtoupper($label) === $skuKeyNorm || strtoupper($label) === strtoupper(str_replace(' ', '_', $skuKey))) {
            $matchedKey = $label;
            break;
        }
    }
    // also allow user to pass a short code directly (e.g., sku=BAR)
    $codesToMatch = [];
    if ($matchedKey) {
        $codesToMatch = $skuFilters[$matchedKey];
    } else {
        // treat skuKey as a single code
        $codesToMatch = [$skuKey];
    }
    $products = array_values(array_filter($products, function($p) use ($codesToMatch) {
        $name = strtoupper($p['name'] ?? '');
        foreach ($codesToMatch as $c) {
            if ($c === '') continue;
            $cUp = strtoupper($c);
            if (strpos($name, $cUp) === 0) return true; // SKU prefix match
        }
        return false;
    }));
}

// Optional subcategory filter: Corrugated Cube Boxes (e.g., RSC 4 x 4 x 4)
// When sub=cube and the selected SKU category is CORRUGATED BOXES, narrow results to items with cubic dimensions.
if ($subParam === 'cube') {
    // Determine if current filter context is Corrugated Boxes
    $isCorrugated = false;
    if ($skuKey !== '') {
        $isCorrugated = (strcasecmp($matchedKey ?? $skuKey, 'CORRUGATED BOXES') === 0);
    } elseif ($catParam !== '') {
        $isCorrugated = (strcasecmp($catParam, 'corrugated') === 0);
    }
    if ($isCorrugated) {
        $products = array_values(array_filter($products, function($p){
            $name = (string)($p['name'] ?? '');
            $desc = (string)($p['description'] ?? '');
            $hay = $name . ' ' . $desc;
            // Match dimension triplets like 4 x 4 x 4 (allow spaces and case-insensitive); numbers can be 1-3 digits.
            if (preg_match('/\b(\d{1,3})\s*[x×]\s*\1\s*[x×]\s*\1\b/i', $hay)) return true;
            return false;
        }));
    }
}

include __DIR__ . '/includes/header.php';
?>
<style>
/* Inline actions row: keep Add and star side-by-side */
.actions-inline { display: inline-flex; align-items: center; gap: 8px; }

/* Item/Price/Actions layout: make Price compact */
table.catalogue-table th:nth-child(2),
table.catalogue-table td:nth-child(2) {
    width: 140px;
    white-space: nowrap;
    text-align: right;
}
/* Description clip + colors */
.cat-desc-clip { color: rgba(11,34,56,0.8); display:inline-block; max-width:68ch; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
html.theme-dark .cat-desc-clip, body.theme-dark .cat-desc-clip { color: #ffffff; opacity: 0.92; }

/* Override global mobile table scroller for catalogue: keep it fitting, not scrolling */
@media (max-width: 768px) {
    table.catalogue-table { display: table !important; width: 100% !important; overflow: visible !important; table-layout: fixed; }
    table.catalogue-table th, table.catalogue-table td { white-space: normal !important; min-width: 0 !important; padding: 8px 6px; font-size: 13px; }
}

/* Compress layout at ≤640px: keep description (smaller), shrink thumbnail, make Actions even narrower, use line breaks */
@media (max-width: 640px) {
    /* Rebalance widths: maximize Item, keep Price compact, and make Actions tighter */
    table.catalogue-table th:nth-child(1), table.catalogue-table td:nth-child(1) { width: 56%; }
    table.catalogue-table th:nth-child(2), table.catalogue-table td:nth-child(2) { width: 14%; text-align: right; }
    table.catalogue-table th:nth-child(3), table.catalogue-table td:nth-child(3) { width: 30%; }
    /* Visually tighten header so it feels narrower */
    table.catalogue-table tr:first-child th { padding: 6px 4px; font-size: 12px; }
    table.catalogue-table tr:first-child th:nth-child(3) { font-size: 11.5px; }
    /* Thumbnail smaller */
    table.catalogue-table td:first-child img { width: 34px !important; height: 34px !important; }
    /* Stack name and description with small, tight typography */
    table.catalogue-table td:first-child strong { display:block; font-size: 12.5px; line-height: 1.15; max-width:100%; word-break: break-word; }
    table.catalogue-table .cat-desc-clip { display:block !important; white-space: normal !important; font-size: 11.5px; line-height: 1.15; margin-top: 2px; max-width: 100%; overflow: hidden; }
    /* Actions stacked to fit; show Add + star inline row */
    table.catalogue-table td:nth-child(3) { display: flex; flex-direction: column; align-items: stretch; gap: 4px; }
    table.catalogue-table td:nth-child(3) form.cart-add { display: flex !important; flex-direction: column; gap: 4px; width: 100%; }
    table.catalogue-table td:nth-child(3) form.cart-add input[type="number"] { width: 3.2em; padding: 2px 4px; }
    table.catalogue-table td:nth-child(3) .actions-inline { display: flex; align-items: stretch; gap: 8px; }
    table.catalogue-table td:nth-child(3) .actions-inline button[type="submit"] { flex: 1 1 auto; }
    table.catalogue-table td:nth-child(3) .fav-toggle { margin-left: 0 !important; flex: 0 0 auto; }
}

/* Small screens: stack Actions cell controls vertically */
@media (max-width: 600px) {
    table.catalogue-table th:nth-child(2),
    table.catalogue-table td:nth-child(2) { width: 70px; }
    table.catalogue-table td:nth-child(3) {
        display: flex;
        flex-direction: column;
        align-items: stretch;
        gap: 6px;
    }
    table.catalogue-table td:nth-child(3) form.cart-add { display:flex !important; flex-direction:column; gap:6px; width:100%; }
    table.catalogue-table td:nth-child(3) form.cart-add input[type="number"] { display:block; box-sizing:border-box; margin-bottom:6px; }
    table.catalogue-table td:nth-child(3) form.cart-add select.qty-preset { display:block; width:100% !important; box-sizing:border-box; margin-bottom:6px; }
    table.catalogue-table td:nth-child(3) .actions-inline { display:flex; gap:8px; align-items:stretch; }
    table.catalogue-table td:nth-child(3) .actions-inline button[type="submit"] { flex:1 1 auto; }
    table.catalogue-table td:nth-child(3) .fav-toggle { margin-left:0 !important; width:auto; }
}

/* Ultra-small screens: aggressively compress to fit, keep description visible but tiny */
@media (max-width: 420px) {
    table.catalogue-table { table-layout: fixed; width: 100%; }
    table.catalogue-table th, table.catalogue-table td { white-space: normal; word-break: break-word; overflow-wrap: anywhere; padding: 5px 4px; font-size: 11px; }
    /* Ultra-small: maximize Item, keep Price compact, keep Actions tight */
    table.catalogue-table th:nth-child(1), table.catalogue-table td:nth-child(1) { width: 56%; }
    table.catalogue-table th:nth-child(2), table.catalogue-table td:nth-child(2) { width: 14%; text-align: right; }
    table.catalogue-table th:nth-child(3), table.catalogue-table td:nth-child(3) { width: 30%; }
    /* Visually tighter header */
    table.catalogue-table tr:first-child th { padding: 6px 3px; font-size: 11.5px; }
    table.catalogue-table tr:first-child th:nth-child(3) { font-size: 11px; }
    table.catalogue-table td:first-child img { width: 28px !important; height: 28px !important; }
    table.catalogue-table td:first-child strong { font-size: 11.5px; line-height: 1.12; }
    table.catalogue-table .cat-desc-clip { font-size: 10.5px; line-height: 1.12; }
    table.catalogue-table td:nth-child(3) form.cart-add input[type="number"] { width: 2.8em !important; padding: 2px 3px; }
    table.catalogue-table td:nth-child(3) form.cart-add select.qty-preset { min-width: 0; width: 100% !important; }
    table.catalogue-table td:nth-child(3) .actions-inline button[type="submit"] { padding: 4px 6px; font-size: 11.5px; }
}
</style>
<style>
/* Show the dropdown on all screen sizes; hide the pill buttons */
.catalogue-cats-select-wrap { display: block !important; }
.catalogue-cats-buttons { display: none !important; }
</style>
<style>
/* On-sale visuals: tint full row and show compact green badge next to favorite.
    Do not use !important so the flash-added animation can temporarily override. */
.catalogue-table tr.sale-row { background: #f1fbf4; }
/* Make cells transparent so the row background shows as a single band; harmonize divider */
.catalogue-table tr.sale-row td { background: transparent; border-bottom-color: #e0f3e8; }
/* Dark mode: single uniform tint for sale rows; keep cells transparent to avoid seams */
html.theme-dark .catalogue-table tr.sale-row,
body.theme-dark .catalogue-table tr.sale-row { background: rgba(16,185,129,0.16) !important; }
html.theme-dark .catalogue-table tr.sale-row td,
body.theme-dark .catalogue-table tr.sale-row td { background: transparent !important; border-bottom-color: rgba(16,185,129,0.25) !important; }
.on-sale-badge {
    display: inline-flex;
    align-items: center;
    background: #16a34a; /* emerald-600 */
    color: #fff;
    border-radius: 999px;
    padding: 4px 8px;
    font-weight: 800;
    font-size: 12px;
    line-height: 1;
    margin-left: 8px;
    vertical-align: middle;
}
@media (max-width: 600px) {
    .on-sale-badge { display:block; margin-left:0; }
}
</style>
<div class="container"><div class="form-card">
<section class="page-hero">
    <h1>Catalog</h1>
    <p class="lead">Browse through our extensive product listings or click on a category below to go directly to a product group. Use the SEARCH box to find a product or product group. Click ADD to order a product. When ready, <a href="cart.php" class="proceed-btn btn-cart">PROCEED TO CART</a></p>
</section>
<div class="catalogue-view-row" style="margin-bottom:8px; display:flex; gap:10px; align-items:center;">
    <div style="font-weight:700;">View:</div>
    <?php
        // Active states: Show All is active when no specific category is selected
        $showAllActive = ($skuKey === '');
        $favActive = $favoritesOn;
        $saleActive = $onSaleOn;
        // Helper to build URLs preserving current context
        $baseParams = [];
        if ($search !== '') { $baseParams['search'] = $search; }
        if ($skuKey !== '') { $baseParams['sku'] = $skuKey; }
        if ($subParam !== '') { $baseParams['sub'] = $subParam; }
        if ($favoritesOn) { $baseParams['favorites'] = 1; }
        if ($onSaleOn) { $baseParams['onsale'] = 1; }
        $buildUrl = function(array $params) {
            $qs = http_build_query($params);
            return 'catalogue.php' . ($qs ? ('?' . $qs) : '');
        };
        // Show All: clear the category (and sub), keep search and toggles
        $allParams = $baseParams;
        unset($allParams['sku'], $allParams['sub']);
        $allUrl = $buildUrl($allParams);
        // Favorites toggle: flip favorites flag but preserve everything else
        $favParams = $baseParams;
        if ($favActive) { unset($favParams['favorites']); } else { $favParams['favorites'] = 1; }
        $favUrl = $buildUrl($favParams);
        // On Sale toggle: flip onsale flag but preserve everything else
        $saleParams = $baseParams;
        if ($saleActive) { unset($saleParams['onsale']); } else { $saleParams['onsale'] = 1; }
        $saleUrl = $buildUrl($saleParams);
    ?>
    <a href="<?= htmlspecialchars($allUrl) ?>" class="sku-btn<?= $showAllActive ? ' active' : '' ?>">Show All</a>
    <a href="<?= htmlspecialchars($favUrl) ?>" class="sku-btn<?= $favActive ? ' active' : '' ?>" aria-pressed="<?= $favActive ? 'true' : 'false' ?>">Favorites <span class="mini-star">★</span></a>
    <a href="<?= htmlspecialchars($saleUrl) ?>" class="sku-btn<?= $saleActive ? ' active' : '' ?>" aria-pressed="<?= $saleActive ? 'true' : 'false' ?>">On Sale</a>
</div>
<!-- Category dropdown (all sizes) with divisions -->
<div class="catalogue-cats-select-wrap" style="margin-bottom:12px; display:block;">
    <label for="catalogueCategorySelect" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;">Select category</label>
    <select id="catalogueCategorySelect" style="padding:8px 10px;border:1px solid #d7e1ea;border-radius:8px;min-width:280px;max-width:100%;">
        <?php
            // Build dropdown option URLs preserving search and toggle filters
            $skuData = @include __DIR__ . '/includes/sku_filters.php';
            $skuGroups = is_array($skuData) && isset($skuData['groups']) ? $skuData['groups'] : [];
            $optBase = [];
            if ($search !== '') { $optBase['search'] = $search; }
            if ($favoritesOn) { $optBase['favorites'] = 1; }
            if ($onSaleOn) { $optBase['onsale'] = 1; }
            $allUrl = 'catalogue.php' . (!empty($optBase) ? ('?' . http_build_query($optBase)) : '');
        ?>
        <option value="<?= htmlspecialchars($allUrl) ?>"<?= $skuKey === '' ? ' selected' : '' ?>>All categories</option>
        <?php foreach ($skuGroups as $groupLabel => $labels): ?>
            <optgroup label="<?= htmlspecialchars($groupLabel) ?>">
                <?php foreach ($labels as $label): if (!isset($skuFilters[$label])) continue; 
                    $key = urlencode($label);
                    $params = $optBase;
                    $params['sku'] = $label;
                    $url = 'catalogue.php?' . http_build_query($params);
                    $isActiveSku = (strtoupper($skuKey) === strtoupper($label));
                ?>
                    <option value="<?= htmlspecialchars($url) ?>"<?= $isActiveSku ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </optgroup>
        <?php endforeach; ?>
    </select>
</div>
<div class="catalogue-cats-buttons" style="margin-bottom:12px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
    <?php foreach ($skuFilters as $label => $codes):
        // build a URL preserving search and toggles
        $key = urlencode($label);
        $params = [];
        if ($search !== '') { $params['search'] = $search; }
        if ($favoritesOn) { $params['favorites'] = 1; }
        if ($onSaleOn) { $params['onsale'] = 1; }
        $params['sku'] = $label;
        $url = 'catalogue.php?' . http_build_query($params);
        $isActiveSku = (strtoupper($skuKey) === strtoupper($label));
    ?>
        <a href="<?= htmlspecialchars($url) ?>" class="sku-btn<?= $isActiveSku ? ' active' : '' ?>" data-sku="<?= htmlspecialchars($label) ?>"><?= htmlspecialchars($label) ?></a>
    <?php endforeach; ?>
</div>
<form method="get" action="catalogue.php" style="margin-bottom:1em;">
    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search products...">
    <?php if ($skuKey !== ''): ?>
        <input type="hidden" name="sku" value="<?= htmlspecialchars($skuKey) ?>">
    <?php endif; ?>
    <?php if ($subParam !== ''): ?>
        <input type="hidden" name="sub" value="<?= htmlspecialchars($subParam) ?>">
    <?php endif; ?>
    <?php if ($favoritesOn): ?>
        <input type="hidden" name="favorites" value="1">
    <?php endif; ?>
    <?php if ($onSaleOn): ?>
        <input type="hidden" name="onsale" value="1">
    <?php endif; ?>
    <button type="submit" class="proceed-btn">Search</button>
    <?php if ($search !== ''): ?>
        <?php
            $clearParams = [];
            if ($skuKey !== '') { $clearParams['sku'] = $skuKey; }
            if ($subParam !== '') { $clearParams['sub'] = $subParam; }
            if ($favoritesOn) { $clearParams['favorites'] = 1; }
            if ($onSaleOn) { $clearParams['onsale'] = 1; }
            $clearUrl = 'catalogue.php' . (!empty($clearParams) ? ('?' . http_build_query($clearParams)) : '');
        ?>
        <a href="<?= htmlspecialchars($clearUrl) ?>">Clear</a>
    <?php endif; ?>
    
</form>
<?php if (empty($products)): ?>
    <p>No products found.</p>
<?php else: ?>
<?php $catalogueLoggedIn = !empty($_SESSION['customer']); ?>
<table class="catalogue-table">
    <tr>
        <th>Item</th>
        <?php if ($catalogueLoggedIn): ?>
            <th>Price</th>
            <th>Actions</th>
        <?php else: ?>
            <!-- No 'Account' heading per request; second column intentionally left without a visible heading -->
            <th></th>
        <?php endif; ?>
    </tr>
    <?php foreach ($products as $p): ?>
        <?php $onSale = !empty($p['deal']); ?>
        <tr id="product-<?= (int)$p['id'] ?>"<?= $onSale ? ' class="sale-row"' : '' ?>>
            <?php
                $name = (string)($p['name'] ?? '');
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
                if (!is_file(__DIR__ . '/assets/DaytonaSupplyDSlogo.png')) {
                    $placeholder = '/assets/images/DaytonaSupplyDSlogo.png';
                }
                $thumb = $imgUrl ?: $placeholder;
                $infoUrl = 'productinfo.php?id=' . (int)$p['id'];
            ?>
            <td>
                <a href="<?= htmlspecialchars($infoUrl) ?>" style="display:flex;align-items:flex-start;gap:10px;color:inherit;text-decoration:none;">
                    <img src="<?= htmlspecialchars($thumb) ?>" alt="" style="width:54px;height:54px;object-fit:contain;border-radius:6px;border:1px solid rgba(11,34,56,0.06);background:#fff;flex:0 0 auto;">
                    <span>
                        <strong class="cat-desc-clip" style="font-weight:800;"><?= htmlspecialchars($p['description']) ?></strong><br>
                        <span class="cat-name" style="font-weight:400;"><?= htmlspecialchars($p['name']) ?></span>
                        <?php if ($onSale): ?><span class="on-sale-badge">On Sale!</span><?php endif; ?>
                    </span>
                </a>
            </td>
            <td>
                <?php if ($catalogueLoggedIn): ?>
                    $<?= number_format($p['price'], 2) ?>
                <?php else: ?>
                    <a href="login.php?next=<?= urlencode('catalogue.php#product-' . (int)$p['id']) ?>" class="login-required" style="font-size:.85rem; font-weight:600; color:#0b5ed7; text-decoration:none;">Log in</a>
                <?php endif; ?>
            </td>
            <?php if ($catalogueLoggedIn): ?>
            <td>
                <form method="post" action="catalogue.php" class="cart-add" style="margin:0; display:inline-block;">
                    <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                    <input type="hidden" name="product_name" value="<?= htmlspecialchars($p['name']) ?>">
                    <input type="hidden" name="product_description" value="<?= htmlspecialchars($p['description'] ?? $p['name']) ?>">
                    <input type="hidden" name="product_price" value="<?= htmlspecialchars(number_format((float)($p['price'] ?? 0), 2, '.', '')) ?>">
                    <span class="qty-wrap" style="display:inline-flex;gap:6px;align-items:center;">
                        <input type="number" name="quantity" value="1" min="1" step="1" style="width:4.5em;">
                        <select class="qty-preset" aria-label="Quick quantity" style="padding:6px 8px;border:1px solid #d7e1ea;border-radius:6px;">
                            <option value="1">1</option>
                            <option value="5">5</option>
                            <option value="10">10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="75">75</option>
                            <option value="100">100</option>
                        </select>
                    </span>
                    <?php if ($search !== ''): ?>
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                    <?php endif; ?>
                    <?php if ($skuKey !== ''): ?>
                        <input type="hidden" name="sku" value="<?= htmlspecialchars($skuKey) ?>">
                    <?php endif; ?>
                    <?php if ($subParam !== ''): ?>
                        <input type="hidden" name="sub" value="<?= htmlspecialchars($subParam) ?>">
                    <?php endif; ?>
                    <?php if ($favoritesOn): ?>
                        <input type="hidden" name="favorites" value="1">
                    <?php endif; ?>
                    <?php if ($onSaleOn): ?>
                        <input type="hidden" name="onsale" value="1">
                    <?php endif; ?>
                    <div class="actions-inline">
                        <button type="submit">Add</button>
                        <?php
                            $skuName = getProductDisplayName($p);
                            $isFav = $skuName !== '' && isset($favoriteSkuSet[$skuName]);
                        ?>
                        <button type="button" class="fav-toggle <?php echo $isFav ? 'fav-on' : ''; ?>" data-product-id="<?= (int)$p['id'] ?>" aria-pressed="<?= $isFav ? 'true' : 'false' ?>" title="<?= $isFav ? 'Remove from favorites' : 'Add to favorites' ?>" style="background:transparent;border:0;padding:4px;cursor:pointer;margin-left:0;vertical-align:middle;">
                            <!-- SVG star: hollow by default (white stroke), filled yellow when .fav-on is present -->
                            <svg class="fav-icon" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="M12 .587l3.668 7.431 8.2 1.192-5.934 5.787 1.402 8.169L12 18.897l-7.336 3.866 1.402-8.169L.132 9.21l8.2-1.192z" />
                            </svg>
                        </button>
                    </div>
                </form>
            </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
</table>
    
<?php endif; ?>
    <script>
    // Sync preset dropdown to quantity input; keep custom typing intact
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
        <script>
        // Mobile category dropdown navigation
        (function(){
            var sel = document.getElementById('catalogueCategorySelect');
            if (!sel) return;
            sel.addEventListener('change', function(){
                var url = sel.value || '';
                if (url) window.location.href = url;
            });
        })();
        </script>
</div></div>
    <!-- Back to top -->
    <div id="backToTopWrap" class="back-to-top-wrap" aria-hidden="true">
        <span class="back-to-top-label">Return To Top</span>
        <button id="backToTop" class="back-to-top" aria-label="Back to top">↑</button>
    </div>
<?php include __DIR__ . '/includes/footer.php'; ?>
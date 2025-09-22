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
    echo '<main><h1>Product Catalogue</h1>';
    echo '<p>Sorry, we are unable to perform that search right now. Our team has been notified.</p>';
    echo '<p>Please quote reference <strong>' . htmlspecialchars($errorRef) . '</strong> when contacting support.</p></main>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$title = 'Catalog';

// If the form to add a product was submitted, update the session cart
// Toggle favorite handler (AJAX-aware)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['favorite_product_id'])) {
    $fid = (int)$_POST['favorite_product_id'];
    if (!isset($_SESSION['favorites']) || !is_array($_SESSION['favorites'])) {
        $_SESSION['favorites'] = [];
    }
    if (in_array($fid, $_SESSION['favorites'], true)) {
        // unfavorite
        $_SESSION['favorites'] = array_values(array_filter($_SESSION['favorites'], function($v) use ($fid) { return $v != $fid; }));
        $favorited = false;
    } else {
        $_SESSION['favorites'][] = $fid;
        $favorited = true;
    }
    $isAjax = false;
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        $isAjax = true;
    }
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'favorited' => $favorited, 'productId' => $fid]);
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
        // compute cart count
        $count = 0;
        foreach ($_SESSION['cart'] as $q) $count += (int)$q;
        header('Content-Type: application/json');
        $resp = ['success' => true, 'cartCount' => $count, 'productId' => $pid];
    // Ensure session data is written immediately so subsequent requests see updates
    @session_write_close();
    echo json_encode($resp);
        exit;
    }
    // Redirect back to catalogue to implement the Post/Redirect/Get pattern and
    // keep the user on this page. Preserve the search term if provided and
    // add a fragment so the browser scrolls to the product just added.
    $searchParam = '';
    if (!empty($_POST['search'])) {
        $searchParam = '?search=' . urlencode($_POST['search']);
    } elseif (!empty($_GET['search'])) {
        $searchParam = '?search=' . urlencode($_GET['search']);
    }
    $fragment = '#product-' . $pid;
    header('Location: catalogue.php' . $searchParam . $fragment);
    exit;
}

// Retrieve search term from the query string
$search = normalizeScalar($_GET['search'] ?? '', 150, '');
// Favorites filter
$showMode = normalizeScalar($_GET['show'] ?? 'all', 32, 'all');
// SKU/type filter key
$skuKey = normalizeScalar($_GET['sku'] ?? '', 64, '');

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
    echo '<main><h1>Product Catalogue</h1>';
    echo '<p>Sorry, we are unable to perform that search right now. Our team has been notified.</p>';
    echo '<p>If this problem continues, please contact support and quote reference <strong>' . htmlspecialchars($errorRef) . '</strong>.</p></main>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

// If showing favorites, filter the products list by session favorites
if ($showMode === 'favorites') {
    $favoriteIds = $_SESSION['favorites'] ?? [];
    $products = array_values(array_filter($products, function($p) use ($favoriteIds) { return in_array((int)$p['id'], $favoriteIds, true); }));
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

include __DIR__ . '/includes/header.php';
?>
<section class="page-hero">
    <h1>Catalog</h1>
    <p class="lead">Browse through our extensive product listings or click on a category below to go directly to a product group. Use the SEARCH box to find a product or product group. Click ADD to order a product. When ready, <a href="cart.php" class="proceed-btn btn-cart">PROCEED TO CART</a></p>
</section>
<div style="margin-bottom:8px; display:flex; gap:10px; align-items:center;">
    <div style="font-weight:700;">View:</div>
    <?php
        $allActive = ($showMode !== 'favorites');
        $favActive = ($showMode === 'favorites');
        $allUrl = 'catalogue.php' . ($search ? '?search=' . urlencode($search) : '');
        $favUrl = 'catalogue.php?show=favorites' . ($search ? '&search=' . urlencode($search) : '');
    ?>
    <a href="<?= htmlspecialchars($allUrl) ?>" class="sku-btn<?= $allActive ? ' active' : '' ?>">Show All</a>
    <a href="<?= htmlspecialchars($favUrl) ?>" class="sku-btn<?= $favActive ? ' active' : '' ?>">Favorites <span class="mini-star">★</span></a>
</div>
<div style="margin-bottom:12px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
    <?php foreach ($skuFilters as $label => $codes):
        // build a short key safe for URLs (use label words joined with underscore)
        $key = urlencode($label);
        $url = 'catalogue.php?sku=' . $key;
        if ($search) $url .= '&search=' . urlencode($search);
        $isActiveSku = (strtoupper($skuKey) === strtoupper($label));
    ?>
        <a href="<?= htmlspecialchars($url) ?>" class="sku-btn<?= $isActiveSku ? ' active' : '' ?>" data-sku="<?= htmlspecialchars($label) ?>"><?= htmlspecialchars($label) ?></a>
    <?php endforeach; ?>
</div>
<form method="get" action="catalogue.php" style="margin-bottom:1em;">
    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search products...">
    <button type="submit" class="proceed-btn">Search</button>
    <?php if ($search !== ''): ?>
        <a href="catalogue.php">Clear</a>
    <?php endif; ?>
</form>
<?php if (empty($products)): ?>
    <p>No products found.</p>
<?php else: ?>
<table class="catalogue-table">
    <tr>
        <th>Name</th>
        <th>Description</th>
        <th>Price</th>
        <th>Add to Cart</th>
    </tr>
    <?php foreach ($products as $p): ?>
        <tr id="product-<?= (int)$p['id'] ?>">
            <td><?= htmlspecialchars($p['name']) ?></td>
            <td><?= htmlspecialchars($p['description']) ?></td>
            <td>$<?= number_format($p['price'], 2) ?></td>
            <td>
                <form method="post" action="catalogue.php" class="cart-add" style="margin:0; display:inline-block;">
                    <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                    <input type="number" name="quantity" value="1" min="1" style="width:3em;">
                    <?php if ($search !== ''): ?>
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                    <?php endif; ?>
                    <button type="submit">Add</button>
                </form>
                <?php
                    $favIds = $_SESSION['favorites'] ?? [];
                    $isFav = in_array((int)$p['id'], $favIds, true);
                ?>
                <button class="fav-toggle <?php echo $isFav ? 'fav-on' : ''; ?>" data-product-id="<?= (int)$p['id'] ?>" aria-pressed="<?= $isFav ? 'true' : 'false' ?>" title="Add to favorites" style="background:transparent;border:0;padding:4px;cursor:pointer;margin-left:8px;vertical-align:middle;">
                    <!-- SVG star: hollow by default (white stroke), filled yellow when .fav-on is present -->
                    <svg class="fav-icon" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M12 .587l3.668 7.431 8.2 1.192-5.934 5.787 1.402 8.169L12 18.897l-7.336 3.866 1.402-8.169L.132 9.21l8.2-1.192z" />
                    </svg>
                </button>
            </td>
        </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
<?php
// Shared header for all pages.  Includes HTML doctype, head section
// with styles and a navigation bar.  Starts the <main> element.

// Ensure a session is started so cart and user data can persist.
if (session_status() === PHP_SESSION_NONE) {
    // Set consistent cookie params to ensure the session cookie is sent
    // across subdomains and respects secure flags in production.
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $hostNoPort = preg_replace('/:\\d+$/', '', $host);
    $isLocal = ($hostNoPort === 'localhost' || filter_var($hostNoPort, FILTER_VALIDATE_IP));
    $secure = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off');
    $cookieDomain = $isLocal ? '' : ('.' . preg_replace('/^www\./i', '', $hostNoPort));
    @session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => $cookieDomain ?: null,
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Redirect legacy/mistyped hostnames to the site's homepage.
// This must run before any output so headers can be sent safely.
$host = strtolower($_SERVER['HTTP_HOST'] ?? '');
$host = preg_replace('/:\\d+$/', '', $host); // strip port if present
if ($host === 'www.daytona.com') {
    // Permanent redirect to the canonical homepage. Update the target if your canonical
    // domain changes. We explicitly use https here since the request the user mentioned
    // is over HTTPS.
    header('HTTP/1.1 301 Moved Permanently');
    header('Location: https://www.daytonasupply.com/');
    exit;
}

// If the site is being served over HTTPS, instruct modern browsers
// to automatically upgrade insecure (http://) subresources to HTTPS.
// This helps mitigate mixed-content warnings without changing runtime URLs.
if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
    // Send the CSP header early before any output is emitted.
    header('Content-Security-Policy: upgrade-insecure-requests');
}

// Determine the number of items in the cart (stored in session)
$cartCount = 0;
if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $entry) {
        if (is_array($entry) && isset($entry['quantity'])) {
            $cartCount += (int)$entry['quantity'];
        } else {
            $cartCount += (int)$entry;
        }
    }
}
// Format the displayed cart count: "empty" for 0, 1..999 for counts, and "999+" for 1000 or more
$displayCart = ($cartCount === 0) ? 'empty' : (($cartCount >= 1000) ? '999+' : (string)$cartCount);
// Check if user is logged in
$loggedIn = isset($_SESSION['customer']);
// Check if admin logged in
$adminLoggedIn = isset($_SESSION['admin']);
// If a logged-in customer has a preference stored in session, use it to render theme class server-side
$serverThemeClass = '';
if ($loggedIn) {
    $pref = $_SESSION['customer']['darkmode'] ?? null;
    if ($pref === null) {
        // Some installs store flat customer array with 'id' only; try loading from DB when available
        try {
            if (!empty($_SESSION['customer']['id'])) {
                // Ensure getDb() is available. Only include db.php when necessary to avoid early DB initialization on simple pages.
                if (!function_exists('getDb')) {
                    @include_once __DIR__ . '/db.php';
                }
                if (!function_exists('getDb')) throw new Exception('getDb unavailable');
                $db = getDb();
                $stmt = $db->prepare('SELECT darkmode FROM customers WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $_SESSION['customer']['id']]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && isset($row['darkmode'])) $pref = (int)$row['darkmode'];
            }
        } catch (Exception $e) { /* ignore DB failures here */ }
    }
    if ($pref) $serverThemeClass = 'theme-dark';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo isset($title) ? htmlspecialchars($title) : 'Daytona Supply'; ?></title>
    <meta name="description" content="<?php echo isset($metaDescription) ? htmlspecialchars($metaDescription) : 'Daytona Supply — local packaging and janitorial supplier.'; ?>">
    <link rel="canonical" href="<?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/'); ?>">
    <meta property="og:title" content="Daytona Supply">
    <meta property="og:description" content="Local B2B packaging & janitorial supplies">
    <meta name="twitter:card" content="summary_large_image">
    <?php $cssPath = __DIR__ . '/../assets/styles.css'; ?>
    <link rel="stylesheet" href="assets/styles.css?v=<?php echo file_exists($cssPath) ? filemtime($cssPath) : time(); ?>">
</head>
<body class="<?php echo $serverThemeClass; ?>">
    <header class="site-header" role="banner">
        <div class="header-inner container">
            <div class="brand" style="margin-right:auto; margin-left:0;">
                <a href="index.php" aria-label="Daytona Supply home">
                    <img src="assets/images/Logowhite.png" alt="Daytona Supply" class="logo">
                </a>
            </div>
            <div class="search-wrap">
                <form role="search" action="catalogue.php" method="get" class="search-form">
                    <label for="search-input" class="sr-only">Search products</label>
                    <!-- use `search` param to match catalogue.php's parameter name -->
                    <input id="search-input" name="search" type="search" placeholder="Search SKUs, items, categories" aria-label="Search products">
                    <button class="search-btn" aria-label="Search">Search</button>
                </form>
            </div>
                <div class="header-actions">
                    <a class="action link-phone" href="tel:3867887009">Call: (386) 788-7009</a>
                    <?php if ($loggedIn): ?>
                        <div class="has-account action" tabindex="0" aria-haspopup="true" aria-expanded="false">
                            <button type="button" class="account-toggle" aria-label="Toggle account menu" aria-controls="account-menu" aria-expanded="false">
                                <span class="acct-toggle-bar"></span>
                                <span class="acct-toggle-bar"></span>
                            </button>
                            <a class="account-link" href="account.php">My Account</a>
                            <div class="account-menu" id="account-menu" role="menu" aria-label="Account menu">
                                <label class="dark-toggle" role="menuitem" style="display:flex;align-items:center;gap:.6rem;padding:8px 10px;">
                                    <span>Dark mode</span>
                                    <label class="dark-toggle" style="margin-left:.6rem;">
                                        <input type="checkbox" id="darkmode_toggle" name="darkmode_toggle" <?php echo ($serverThemeClass === 'theme-dark') ? 'checked' : ''; ?> />
                                        <span class="dark-toggle-switch" aria-hidden="true"></span>
                                    </label>
                                </label>
                                <a role="menuitem" href="logout.php">Log out</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <a class="action" href="login.php">Login / Register</a>
                    <?php endif; ?>
                    <!-- Nav cart button (upper-right) -->
                    <a class="nav-cart action" href="cart.php" id="cart-link" aria-label="View cart">Cart (<span id="cart-count"><?php echo htmlspecialchars($displayCart); ?></span>)</a>
                </div>
        </div>

        <nav class="main-nav" role="navigation" aria-label="Primary">
            <div class="container nav-inner">
                <button id="nav-toggle" class="nav-toggle" aria-expanded="false" aria-controls="nav-menu" aria-label="Toggle navigation"><span class="sr-only">Menu</span></button>
                <div id="nav-menu" class="nav-menu" hidden>
                    <ul class="nav-cats">
                        <?php
                        // Load shared SKU filters and groups. returns ['filters'=>..., 'groups'=>...]
                        $skuData = @include __DIR__ . '/sku_filters.php';
                        $skuFilters = is_array($skuData) && isset($skuData['filters']) ? $skuData['filters'] : [];
                        $skuGroups = is_array($skuData) && isset($skuData['groups']) ? $skuData['groups'] : [];
                        ?>
                        <li class="has-mega products-item"><a class="cat-btn" href="catalogue.php">Products</a>
                            <div class="mega" role="menu">
                                <?php foreach ($skuGroups as $groupLabel => $labels): ?>
                                    <div class="mega-col">
                                        <h4><?php echo htmlspecialchars($groupLabel); ?></h4>
                                        <ul>
                                            <?php foreach ($labels as $label): if (!isset($skuFilters[$label])) continue; ?>
                                                <?php $url = 'catalogue.php?sku=' . urlencode($label); ?>
                                                <li><a href="<?php echo htmlspecialchars($url); ?>"><?php echo htmlspecialchars($label); ?></a></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </li>
                        <li><a href="about.php">About Us</a></li>
                        <li><a href="contact.php">Contact Us</a></li>
                        <li><a href="shipping.php">Shipping</a></li>
                        <!-- Removed All Products and Partner as requested -->
                    </ul>
                </div>
            </div>
        </nav>
    </header>
    <main>
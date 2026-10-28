<?php
require_once __DIR__ . '/../includes/db.php';

function storefrontAssert($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
}

function storefrontRemoveFixture($path)
{
    foreach (new DirectoryIterator($path) as $entry) {
        if ($entry->isDot()) continue;
        if ($entry->isDir()) storefrontRemoveFixture($entry->getPathname());
        else unlink($entry->getPathname());
    }
    if (!rmdir($path)) throw new RuntimeException('Cannot clean storefront fixture.');
}

function storefrontRequest($path, $loggedIn = false, $post = null)
{
    global $baseUrl, $customerCookie;
    $headers = ['Connection: close'];
    if ($loggedIn) $headers[] = 'Cookie: ' . $customerCookie;
    $options = ['method' => $post === null ? 'GET' : 'POST',
        'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 15,
        'follow_location' => 0];
    if ($post !== null) {
        $options['header'] .= "\r\nContent-Type: application/x-www-form-urlencoded";
        $options['content'] = http_build_query($post);
    }
    $html = file_get_contents($baseUrl . $path, false, stream_context_create(['http' => $options]));
    storefrontAssert($html !== false, 'Storefront request failed.');
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
    storefrontAssert(!preg_match('/(?:Warning:|Notice:|Fatal error:)/', $html), 'PHP diagnostics in ' . $path);
    return [(int) $match[1], $html, $http_response_header];
}

$fixture = tempnam(sys_get_temp_dir(), 'daytona_inventory_');
unlink($fixture);
mkdir($fixture, 0700);
foreach (['web', 'web/includes', 'web/ajax', 'web/api', 'web/assets', 'web/data', 'sessions', 'config'] as $dir) {
    mkdir($fixture . '/' . $dir);
}
foreach (['catalogue.php', 'productinfo.php', 'cart.php', 'checkout.php', 'deals.php',
    'includes/db.php', 'includes/functions.php', 'includes/categories.php', 'includes/category_baseline.php',
    'includes/product_images.php', 'includes/inventory_display.php', 'includes/header.php', 'includes/footer.php',
    'ajax/search_suggestions.php', 'api/catalog_sync.php', 'assets/styles.css', 'assets/inventory.css'] as $file) {
    copy(__DIR__ . '/../' . $file, $fixture . '/web/' . $file);
}
$token = bin2hex(random_bytes(32));
file_put_contents($fixture . '/web/includes/sync_config.php', '<?php putenv(' . var_export('SYNC_TOKEN=' . $token, true) . ');');
file_put_contents($fixture . '/web/_customer.php', <<<'PHP'
<?php
session_start();
$_SESSION['customer'] = ['id' => 1, 'name' => 'Inventory Test', 'email' => 'inventory@example.invalid', 'darkmode' => 0];
echo 'ready';
PHP
);
file_put_contents($fixture . '/web/_public.php', <<<'PHP'
<?php
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';
header('Content-Type: application/json');
echo json_encode(['list' => getAllProducts(), 'single' => getProductById(1), 'fresh' => getAllProductsFresh()]);
PHP
);
$db = new PDO('sqlite:' . $fixture . '/catalog.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
initDatabase($db);
$db->exec('ALTER TABLE order_items ADD COLUMN product_name TEXT');
$db->exec('ALTER TABLE order_items ADD COLUMN product_description TEXT');
$db->exec('ALTER TABLE order_items ADD COLUMN product_price REAL');
$insert = $db->prepare('INSERT INTO products(id, name, description, price, qty, qty_updated_at, is_hidden) VALUES (?, ?, ?, ?, ?, ?, ?)');
foreach ([120, 3, -3, null, 24, 25, 1, 0, 120] as $i => $qty) {
    $insert->execute([$i + 1, 'TEST-' . ($i + 1), 'Inventory test product ' . ($i + 1),
        $i === 6 ? 0 : 1, $qty, gmdate('Y-m-d H:i:s', time() - ($i === 4 ? 7 * 3600 : 12 * 60)), $i === 8 ? 1 : 0]);
}
$db->exec("INSERT INTO customers(id, name, email, password_hash) VALUES (1, 'Inventory Test', 'inventory@example.invalid', 'unused')");
$insert = null;
$db = null;
$server = null;
try {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    storefrontAssert($socket !== false, 'Cannot reserve storefront test port.');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $baseUrl = 'http://' . $address;
    $command = escapeshellarg(PHP_BINARY) . ' -d session.save_path=' . escapeshellarg($fixture . '/sessions')
        . ' -d smtp_port=1 -S ' . $address . ' -t ' . escapeshellarg($fixture . '/web');
    $server = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $fixture . '/server.log', 'a'],
        2 => ['file', $fixture . '/server.log', 'a']], $pipes, $fixture,
        array_merge(getenv(), ['DB_DRIVER' => 'sqlite', 'DB_PATH' => $fixture . '/catalog.sqlite',
            'APP_ENV' => 'development']), ['bypass_shell' => true]);
    storefrontAssert(is_resource($server), 'Cannot start storefront test server.');
    fclose($pipes[0]);
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($probe) { fclose($probe); $ready = true; break; }
        usleep(100000);
    }
    storefrontAssert($ready && proc_get_status($server)['running'], 'Storefront server did not become ready.');
    list($status, $html, $headers) = storefrontRequest('/_customer.php');
    foreach ($headers as $header) {
        if (preg_match('/^Set-Cookie: (PHPSESSID=[^;]+)/', $header, $match)) $customerCookie = $match[1];
    }
    storefrontAssert($status === 200 && isset($customerCookie), 'Cannot establish customer session.');
    list($status, $guest) = storefrontRequest('/catalogue.php');
    storefrontAssert($status === 200 && strpos($guest, 'login-required') !== false, 'Guest catalogue failed.');
    foreach (['cat-stock', 'In stock', 'Low stock', 'Out of stock', 'data-qty', 'inventory.css'] as $text) {
        storefrontAssert(strpos($guest, $text) === false, 'Guest catalogue leaked inventory: ' . $text);
    }
    preg_match('/<table class="catalogue-table">(.*?)<\/table>/s', $guest, $guestTable);
    preg_match('/<tr>(.*?)<\/tr>/s', $guestTable[1], $guestHeading);
    storefrontAssert(substr_count($guestHeading[1], '<th>') === 3, 'Guest catalogue must retain three columns.');
    list($status, $public) = storefrontRequest('/_public.php');
    storefrontAssert($status === 200 && strpos($public, '"qty') === false, 'Public readers fetched inventory.');
    storefrontAssert(strpos(file_get_contents($fixture . '/web/data/cache_products.json'), '"qty') === false,
        'Public product cache contains inventory.');
    list($status, $customer) = storefrontRequest('/catalogue.php', true);
    storefrontAssert($status === 200 && substr_count($customer, 'class="cat-stock ') === 7, 'Incorrect customer badge count.');
    foreach (['In stock - 120 left', 'Low stock - 3 left', 'Low stock - 24 left', 'In stock - 25 left', 'Low stock - 1 left', 'Out of stock'] as $text) {
        storefrontAssert(strpos($customer, $text) !== false, 'Missing badge: ' . $text);
    }
    storefrontAssert(!preg_match('/(?<![A-Za-z0-9])-[0-9]+\s*(?:left|<\/span>)/', $customer) && !preg_match('/\b0 left\b/', $customer)
        && strpos($customer, 'product-9"') === false, 'Raw negative, zero count or hidden row rendered.');
    preg_match('/<table class="catalogue-table">(.*?)<\/table>/s', $customer, $table);
    preg_match('/<tr>(.*?)<\/tr>/s', $table[1], $heading);
    storefrontAssert(substr_count($heading[1], '<th>') === 3, 'Catalogue gained a column.');
    foreach ([1 => 'In stock - 120 left', 2 => 'Low stock - 3 left', 3 => 'Out of stock',
        4 => null, 5 => 'Low stock - 24 left', 6 => 'In stock - 25 left', 7 => 'Low stock - 1 left', 8 => 'Out of stock'] as $id => $text) {
        list($status, $html) = storefrontRequest('/productinfo.php?id=' . $id);
        storefrontAssert($status === 200 && strpos($html, 'cat-stock') === false
            && strpos($html, 'Updated ') === false, 'Guest detail leaked inventory.');
        list($status, $html) = storefrontRequest('/productinfo.php?id=' . $id, true);
        storefrontAssert($status === 200, 'Customer detail failed.');
        if ($text === null) {
            storefrontAssert(strpos($html, 'class="cat-stock ') === false && strpos($html, 'Updated ') === false, 'NULL detail rendered stock.');
        } else {
            storefrontAssert(strpos($html, $text) !== false, 'Incorrect detail badge.');
            storefrontAssert((strpos($html, 'Updated ') !== false) === ($id !== 5), 'Incorrect detail freshness.');
        }
    }
    list($status) = storefrontRequest('/productinfo.php?id=9', true);
    storefrontAssert($status === 404, 'Hidden detail became visible.');
    foreach (['/ajax/search_suggestions.php?q=TEST', '/deals.php', '/cart.php'] as $path) {
        list($status, $html) = storefrontRequest($path);
        storefrontAssert($status === 200 && strpos($html, '"qty"') === false && strpos($html, 'cat-stock') === false,
            'Guest secondary path leaked inventory: ' . $path);
    }
    $db = new PDO('sqlite:' . $fixture . '/catalog.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $before = $db->query('SELECT qty, qty_updated_at FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    list($status) = storefrontRequest('/catalogue.php', true, ['product_id' => 2, 'quantity' => 100]);
    storefrontAssert($status === 302, 'Add to cart was gated by stock.');
    list($status, $html) = storefrontRequest('/checkout.php', true, ['tax_region' => 'exempt', 'po_number' => 'INVENTORY-TEST']);
    storefrontAssert($status === 200 && strpos($html, 'Thank you! Your order has been submitted') !== false,
        'End-to-end order failed.');
    $orderedItems = $db->query('SELECT product_id, quantity FROM order_items')->fetchAll(PDO::FETCH_ASSOC);
    storefrontAssert(count($orderedItems) === 1 && (int) $orderedItems[0]['quantity'] === 100,
        'Order quantity was clamped: ' . json_encode($orderedItems));
    storefrontAssert($before === $db->query('SELECT qty, qty_updated_at FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'Checkout changed QuickBooks-owned inventory.');
    $stats = file_get_contents($baseUrl . '/api/catalog_sync.php?action=stats', false,
        stream_context_create(['http' => ['header' => 'X-Sync-Token: ' . $token]]));
    $stats = json_decode($stats, true);
    storefrontAssert($stats['qty']['tracked'] === 8 && $stats['qty']['out'] === 2 && $stats['qty']['low'] === 3,
        'Stats changed after order.');
    echo "Storefront HTTP tests passed (guest/customer pages, private queries/cache, boundaries, hidden/zero-price rows, freshness, suggestions, order without stock decrement or clamp).\n";
    if (in_array('--browser', $argv, true)) {
        echo "Browser fixture ready: " . $baseUrl . "/_customer.php (available for 180 seconds)\n";
        fflush(STDOUT);
        sleep(180);
    }
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    $insert = null;
    $db = null;
    storefrontRemoveFixture($fixture);
}

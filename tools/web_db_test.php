<?php
// Web-based test to see what database the web interface is actually using
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Set admin session for testing
$_SESSION['admin'] = true;

header('Content-Type: text/plain');

echo "=== Web Environment Database Test ===\n\n";

try {
    $db = getDb();
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "Database driver: $driver\n";
    
    if ($driver === 'mysql') {
        $stmt = $db->query("SELECT DATABASE() as db_name");
        $result = $stmt->fetch();
        echo "MySQL database: {$result['db_name']}\n";
    }
    
    // Count products
    $stmt = $db->query('SELECT COUNT(*) as count FROM products');
    $count = $stmt->fetch()['count'];
    echo "Total products: $count\n";
    
    // Look for the SBO product
    $stmt = $db->prepare('SELECT * FROM products WHERE name LIKE ?');
    $stmt->execute(['%SBO%6765%']);
    $sboProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($sboProducts) . " products matching SBO 6765:\n";
    foreach ($sboProducts as $prod) {
        echo "  ID: {$prod['id']}, Name: '{$prod['name']}', Price: {$prod['price']}\n";
    }
    
    // Check cache file status
    $cacheFile = __DIR__ . '/../data/cache_products.json';
    echo "\nCache file exists: " . (file_exists($cacheFile) ? 'YES' : 'NO') . "\n";
    if (file_exists($cacheFile)) {
        $cacheTime = filemtime($cacheFile);
        echo "Cache file modified: " . date('Y-m-d H:i:s', $cacheTime) . "\n";
        echo "Cache age: " . (time() - $cacheTime) . " seconds\n";
    }
    
    // Test getAllProducts
    echo "\n=== Testing getAllProducts() ===\n";
    $products = getAllProducts();
    echo "getAllProducts returned " . count($products) . " products\n";
    
    // Find SBO product in getAllProducts
    $sboFromCache = null;
    foreach ($products as $prod) {
        if (strpos($prod['name'], 'SBO') !== false && strpos($prod['name'], '6765') !== false) {
            $sboFromCache = $prod;
            break;
        }
    }
    
    if ($sboFromCache) {
        echo "SBO product from getAllProducts():\n";
        echo "  ID: {$sboFromCache['id']}, Name: '{$sboFromCache['name']}', Price: {$sboFromCache['price']}\n";
    } else {
        echo "SBO product NOT found in getAllProducts()\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
?>
<?php
// Web-based test to simulate exact manager portal save
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Set admin session for testing
$_SESSION['admin'] = true;

header('Content-Type: text/plain');

echo "=== Web Environment Save Test ===\n\n";

try {
    $db = getDb();
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "Database driver: $driver\n";
    
    // Find the SBO product (ID 392 in MySQL)
    $stmt = $db->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([392]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        echo "ERROR: Product ID 392 not found!\n";
        exit;
    }
    
    echo "Original product:\n";
    echo "  ID: {$product['id']}\n";
    echo "  Name: '{$product['name']}'\n";
    echo "  Price: {$product['price']}\n";
    
    // Test your exact changes
    $newName = 'SBO-6765'; // Remove space
    $newPrice = 9.95;
    
    echo "\nApplying changes:\n";
    echo "  New name: '$newName'\n";
    echo "  New price: $newPrice\n";
    
    // Check cache before save
    $cacheFile = __DIR__ . '/../data/cache_products.json';
    $cacheBefore = file_exists($cacheFile) ? filemtime($cacheFile) : 0;
    echo "Cache time before save: " . ($cacheBefore ? date('H:i:s', $cacheBefore) : 'none') . "\n";
    
    // Call saveProduct (this should invalidate cache)
    echo "\nCalling saveProduct...\n";
    $result = saveProduct([
        'name' => $newName,
        'description' => $product['description'],
        'price' => $newPrice
    ], 392);
    
    echo "saveProduct returned: " . ($result ? 'TRUE' : 'FALSE') . "\n";
    
    // Check cache after save
    $cacheAfter = file_exists($cacheFile) ? filemtime($cacheFile) : 0;
    echo "Cache time after save: " . ($cacheAfter ? date('H:i:s', $cacheAfter) : 'none') . "\n";
    echo "Cache was " . ($cacheAfter !== $cacheBefore ? 'CHANGED' : 'NOT CHANGED') . "\n";
    
    // Direct DB check
    $stmt = $db->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([392]);
    $updated = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "\nDirect DB check:\n";
    echo "  Name: '{$updated['name']}'\n";
    echo "  Price: {$updated['price']}\n";
    
    $nameOk = ($updated['name'] === $newName);
    $priceOk = (abs((float)$updated['price'] - $newPrice) < 0.01);
    echo "Database updated correctly: " . (($nameOk && $priceOk) ? 'YES' : 'NO') . "\n";
    
    // Test getAllProducts
    echo "\n=== Testing getAllProducts() ===\n";
    $allProducts = getAllProducts();
    
    $foundProduct = null;
    foreach ($allProducts as $prod) {
        if ((int)$prod['id'] === 392) {
            $foundProduct = $prod;
            break;
        }
    }
    
    if ($foundProduct) {
        echo "getAllProducts returned:\n";
        echo "  Name: '{$foundProduct['name']}'\n";
        echo "  Price: {$foundProduct['price']}\n";
        
        $cacheNameOk = ($foundProduct['name'] === $newName);
        $cachePriceOk = (abs((float)$foundProduct['price'] - $newPrice) < 0.01);
        echo "Cache shows updated data: " . (($cacheNameOk && $cachePriceOk) ? 'YES' : 'NO') . "\n";
        
        if (!$cacheNameOk || !$cachePriceOk) {
            echo "\n⚠️  CACHE PROBLEM DETECTED!\n";
            echo "Database has correct data but getAllProducts() returns old data.\n";
        }
    } else {
        echo "ERROR: Product not found in getAllProducts()\n";
    }
    
    // Restore original
    echo "\nRestoring original values...\n";
    saveProduct([
        'name' => $product['name'],
        'description' => $product['description'],
        'price' => $product['price']
    ], 392);
    
    echo "Test completed.\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
?>
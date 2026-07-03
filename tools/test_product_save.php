<?php
// Quick product save test - run this from command line or browser
// Usage: php test_product_save.php or browse to it

session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Set admin session for testing
$_SESSION['admin'] = true;

echo "=== Product Save Test ===\n\n";

try {
    $db = getDb();
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "Using database driver: $driver\n";
    
    if (!empty($GLOBALS['DB_FALLBACK_REASON'])) {
        echo "WARNING: Using SQLite fallback - MySQL connection failed!\n";
    }
    
    // Get first product
    $stmt = $db->query('SELECT * FROM products LIMIT 1');
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        echo "No products found to test with.\n";
        exit;
    }
    
    $id = (int)$product['id'];
    $originalDesc = $product['description'];
    
    echo "Testing with product ID: $id\n";
    echo "Original description: '$originalDesc'\n";
    
    // Simulate form data like the manager portal sends
    $testDesc = $originalDesc . ' [SAVE TEST ' . date('H:i:s') . ']';
    
    $_POST = [
        'save_products' => '1',
        'name_' . $id => $product['name'],
        'desc_' . $id => $testDesc,
        'price_' . $id => number_format((float)$product['price'], 2)
    ];
    
    echo "Simulating form POST with description: '$testDesc'\n";
    
    // Test the exact same logic as the manager portal
    $products = getAllProducts();
    $updated = 0;
    
    foreach ($products as $prod) {
        $prodId = (int)($prod['id'] ?? 0);
        if ($prodId !== $id) continue; // Only test our target product
        
        $nameField = 'name_' . $prodId;
        $descField = 'desc_' . $prodId;
        $priceField = 'price_' . $prodId;
        
        if (!isset($_POST[$nameField], $_POST[$priceField])) {
            echo "ERROR: Form fields not found!\n";
            continue;
        }
        
        $postedName = trim($_POST[$nameField]);
        $postedDesc = trim($_POST[$descField]);
        $postedPrice = (float)str_replace(',', '', $_POST[$priceField]);
        
        echo "Posted values - name: '$postedName', desc: '$postedDesc', price: $postedPrice\n";
        
        $origName = (string)($prod['name'] ?? '');
        $origDesc = (string)($prod['description'] ?? '');
        $origPrice = (float)($prod['price'] ?? 0.0);
        
        $dirty = false;
        if ($postedName !== $origName) { $dirty = true; echo "Name changed\n"; }
        if ($postedDesc !== $origDesc) { $dirty = true; echo "Description changed\n"; }
        if (abs($postedPrice - $origPrice) > 0.00001) { $dirty = true; echo "Price changed\n"; }
        
        if ($dirty) {
            echo "Calling saveProduct...\n";
            saveProduct([
                'name' => $postedName,
                'description' => $postedDesc,
                'price' => $postedPrice
            ], $prodId);
            $updated++;
            echo "saveProduct completed\n";
        } else {
            echo "No changes detected\n";
        }
    }
    
    echo "Updated $updated products\n";
    
    // Verify the change
    $stmt = $db->prepare('SELECT description FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $newDesc = $stmt->fetchColumn();
    
    echo "Description after save: '$newDesc'\n";
    echo "Save successful: " . ($newDesc === $testDesc ? 'YES' : 'NO') . "\n";
    
    // Clean up - restore original
    $stmt = $db->prepare('UPDATE products SET description = ? WHERE id = ?');
    $stmt->execute([$originalDesc, $id]);
    echo "Restored original description\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
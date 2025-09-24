<?php
// Specific test for the SBO-6765 product issue
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Set admin session
$_SESSION['admin'] = true;

echo "=== SBO-6765 Product Test ===\n\n";

try {
    $db = getDb();
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "Database driver: $driver\n";
    
    // Look for the SBO product
    $stmt = $db->prepare('SELECT * FROM products WHERE name LIKE ?');
    $stmt->execute(['%SBO%6765%']);
    $sboProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($sboProducts) . " products matching SBO 6765:\n";
    foreach ($sboProducts as $prod) {
        echo "  ID: {$prod['id']}, Name: '{$prod['name']}', Price: {$prod['price']}\n";
    }
    
    if (empty($sboProducts)) {
        // Look for any SBO products
        $stmt = $db->prepare('SELECT * FROM products WHERE name LIKE ? LIMIT 5');
        $stmt->execute(['%SBO%']);
        $allSbo = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "\nAll SBO products (first 5):\n";
        foreach ($allSbo as $prod) {
            echo "  ID: {$prod['id']}, Name: '{$prod['name']}', Price: {$prod['price']}\n";
        }
    }
    
    // Test with the first SBO product found
    if (!empty($sboProducts)) {
        $testProduct = $sboProducts[0];
        $id = (int)$testProduct['id'];
        
        echo "\n=== Testing Save on Product ID $id ===\n";
        echo "Original name: '{$testProduct['name']}'\n";
        echo "Original price: {$testProduct['price']}\n";
        
        // Test the exact changes the user made
        $newName = str_replace('SBO- ', 'SBO-', $testProduct['name']); // Remove space
        $newPrice = 9.95;
        
        echo "New name: '$newName'\n";
        echo "New price: $newPrice\n";
        
        // Save the changes
        saveProduct([
            'name' => $newName,
            'description' => $testProduct['description'],
            'price' => $newPrice
        ], $id);
        
        echo "Called saveProduct...\n";
        
        // Check if it saved
        $stmt = $db->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $updated = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo "After save:\n";
        echo "  Name: '{$updated['name']}'\n";
        echo "  Price: {$updated['price']}\n";
        
        $nameChanged = ($updated['name'] === $newName);
        $priceChanged = (abs((float)$updated['price'] - $newPrice) < 0.01);
        
        echo "Name saved correctly: " . ($nameChanged ? 'YES' : 'NO') . "\n";
        echo "Price saved correctly: " . ($priceChanged ? 'YES' : 'NO') . "\n";
        
        if ($nameChanged && $priceChanged) {
            echo "\n✓ Save worked! Now testing getAllProducts()...\n";
            
            // Test getAllProducts to see if it returns the updated data
            $allProducts = getAllProducts();
            $foundUpdated = null;
            foreach ($allProducts as $prod) {
                if ((int)$prod['id'] === $id) {
                    $foundUpdated = $prod;
                    break;
                }
            }
            
            if ($foundUpdated) {
                echo "getAllProducts returned:\n";
                echo "  Name: '{$foundUpdated['name']}'\n";
                echo "  Price: {$foundUpdated['price']}\n";
                
                $getAllCorrect = ($foundUpdated['name'] === $newName && abs((float)$foundUpdated['price'] - $newPrice) < 0.01);
                echo "getAllProducts shows correct data: " . ($getAllCorrect ? 'YES' : 'NO') . "\n";
                
                if (!$getAllCorrect) {
                    echo "\n⚠️  PROBLEM: Direct DB query shows updated data, but getAllProducts() shows old data!\n";
                    echo "This suggests a caching issue.\n";
                }
            } else {
                echo "ERROR: Product not found in getAllProducts() result\n";
            }
        }
        
        // Restore original values
        saveProduct([
            'name' => $testProduct['name'],
            'description' => $testProduct['description'],
            'price' => $testProduct['price']
        ], $id);
        echo "\nRestored original values\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
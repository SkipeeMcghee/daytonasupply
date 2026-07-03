<?php
// Test actual manager portal form submission
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Set admin session for testing
$_SESSION['admin'] = true;

header('Content-Type: text/plain');

echo "=== Manager Portal Form Submission Test ===\n\n";

// Simulate the exact form submission
$_POST['save_products'] = '1';
$_POST['name_392'] = 'SBO-6765';  // Remove space
$_POST['desc_392'] = ''; // Empty description
$_POST['price_392'] = '9.95';

echo "Simulating form submission:\n";
echo "  save_products = {$_POST['save_products']}\n";
echo "  name_392 = '{$_POST['name_392']}'\n";
echo "  desc_392 = '{$_POST['desc_392']}'\n";
echo "  price_392 = '{$_POST['price_392']}'\n";

// Get original product state
$db = getDb();
$stmt = $db->prepare('SELECT * FROM products WHERE id = ?');
$stmt->execute([392]);
$originalProduct = $stmt->fetch(PDO::FETCH_ASSOC);

echo "\nOriginal product before save:\n";
echo "  Name: '{$originalProduct['name']}'\n";
echo "  Description: '{$originalProduct['description']}'\n";
echo "  Price: {$originalProduct['price']}\n";

// Enable output buffering to capture any output from the manager portal logic
ob_start();

try {
    // Include the actual manager portal processing logic
    // We'll inline the save logic here to test it
    
    if (isset($_POST['save_products'])) {
        echo "\nProcessing save_products...\n";
        
        $products = getAllProducts();
        $updated = 0;
        echo "Starting with " . count($products) . " products\n";
        
        foreach ($products as $prod) {
            $id = (int)($prod['id'] ?? 0);
            if ($id !== 392) continue; // Only process our test product
            
            $nameField = 'name_' . $id;
            $descField = 'desc_' . $id;
            $priceField = 'price_' . $id;
            
            if (!isset($_POST[$nameField], $_POST[$priceField])) { 
                echo "ERROR: Required fields missing for product $id\n";
                continue; 
            }

            $postedName = $_POST[$nameField] ?? '';
            $postedDesc = $_POST[$descField] ?? '';
            $postedPrice = (float)str_replace(',', '', (string)($_POST[$priceField] ?? '0'));

            $origName = (string)($prod['name'] ?? '');
            $origDesc = (string)($prod['description'] ?? '');
            $origPrice = (float)($prod['price'] ?? 0.0);

            echo "Comparing values for product $id:\n";
            echo "  Name: '$origName' -> '$postedName'\n";
            echo "  Desc: '$origDesc' -> '$postedDesc'\n";
            echo "  Price: $origPrice -> $postedPrice\n";

            $dirty = false;
            if ($postedName !== $origName) { 
                $dirty = true; 
                echo "  Name changed!\n";
            }
            if ($postedDesc !== $origDesc) { 
                $dirty = true; 
                echo "  Description changed!\n";
            }
            if (abs($postedPrice - $origPrice) > 0.00001) { 
                $dirty = true; 
                echo "  Price changed!\n";
            }

            if ($dirty) {
                echo "  Product is dirty, calling saveProduct...\n";
                saveProduct([
                    'name' => $postedName,
                    'description' => $postedDesc,
                    'price' => $postedPrice
                ], $id);
                $updated++;
                echo "  saveProduct called successfully\n";
            } else {
                echo "  No changes detected\n";
            }
        }
        
        echo "Updated $updated products total\n";
        
        // Invalidate cache
        invalidateProductsCache();
        echo "Cache invalidated\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

$output = ob_get_clean();
echo $output;

// Check final state
$stmt = $db->prepare('SELECT * FROM products WHERE id = ?');
$stmt->execute([392]);
$finalProduct = $stmt->fetch(PDO::FETCH_ASSOC);

echo "\nFinal product state:\n";
echo "  Name: '{$finalProduct['name']}'\n";
echo "  Description: '{$finalProduct['description']}'\n";
echo "  Price: {$finalProduct['price']}\n";

$nameChanged = ($finalProduct['name'] !== $originalProduct['name']);
$priceChanged = (abs((float)$finalProduct['price'] - (float)$originalProduct['price']) > 0.001);

echo "\nChanges applied:\n";
echo "  Name changed: " . ($nameChanged ? 'YES' : 'NO') . "\n";
echo "  Price changed: " . ($priceChanged ? 'YES' : 'NO') . "\n";

// Test getAllProducts after save
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
    echo "getAllProducts shows:\n";
    echo "  Name: '{$foundProduct['name']}'\n";
    echo "  Price: {$foundProduct['price']}\n";
    
    $cacheCorrect = ($foundProduct['name'] === $_POST['name_392'] && 
                     abs((float)$foundProduct['price'] - 9.95) < 0.001);
    echo "Cache reflects changes: " . ($cacheCorrect ? 'YES' : 'NO') . "\n";
}

// Restore original values
echo "\nRestoring original values...\n";
saveProduct([
    'name' => $originalProduct['name'],
    'description' => $originalProduct['description'],
    'price' => $originalProduct['price']
], 392);

echo "Test completed.\n";
?>
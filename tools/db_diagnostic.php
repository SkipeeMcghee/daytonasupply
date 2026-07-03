<?php
// Database diagnostic tool for troubleshooting product save issues
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Only allow admin access
if (!isset($_SESSION['admin'])) {
    header('HTTP/1.1 403 Forbidden');
    echo 'Access denied. Please log in to the manager portal first.';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

echo "=== Database Diagnostic Tool ===\n\n";

try {
    $db = getDb();
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    echo "Database Driver: $driver\n";
    
    // Check if we're in fallback mode
    if (!empty($GLOBALS['DB_FALLBACK_REASON'])) {
        echo "WARNING: Database fallback is active!\n";
        echo "Fallback reason: " . substr($GLOBALS['DB_FALLBACK_REASON'], 0, 200) . "...\n\n";
    }
    
    // Test database write
    echo "\n=== Testing Database Write ===\n";
    
    // First, get current product count
    $stmt = $db->query('SELECT COUNT(*) FROM products');
    $productCount = $stmt->fetchColumn();
    echo "Current products in database: $productCount\n";
    
    // Try to get a sample product to test update
    $stmt = $db->query('SELECT * FROM products LIMIT 1');
    $sampleProduct = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($sampleProduct) {
        $testId = (int)$sampleProduct['id'];
        $originalName = $sampleProduct['name'];
        echo "\nTesting update on product ID: $testId\n";
        echo "Original name: $originalName\n";
        
        // Test update
        $testName = $originalName . '_TEST_' . date('His');
        echo "Attempting to update name to: $testName\n";
        
        $updateStmt = $db->prepare('UPDATE products SET name = :name WHERE id = :id');
        $result = $updateStmt->execute([':name' => $testName, ':id' => $testId]);
        $rowCount = $updateStmt->rowCount();
        
        echo "Update result: " . ($result ? 'SUCCESS' : 'FAILED') . "\n";
        echo "Rows affected: $rowCount\n";
        
        // Check if the change persisted
        $checkStmt = $db->prepare('SELECT name FROM products WHERE id = :id');
        $checkStmt->execute([':id' => $testId]);
        $updatedName = $checkStmt->fetchColumn();
        
        echo "Name after update: $updatedName\n";
        echo "Update persisted: " . ($updatedName === $testName ? 'YES' : 'NO') . "\n";
        
        // Restore original name
        $restoreStmt = $db->prepare('UPDATE products SET name = :name WHERE id = :id');
        $restoreStmt->execute([':name' => $originalName, ':id' => $testId]);
        echo "Restored original name: $originalName\n";
        
    } else {
        echo "No products found to test with.\n";
    }
    
    echo "\n=== Testing saveProduct Function ===\n";
    if ($sampleProduct) {
        $testId = (int)$sampleProduct['id'];
        $originalData = [
            'name' => $sampleProduct['name'],
            'description' => $sampleProduct['description'] ?? '',
            'price' => (float)($sampleProduct['price'] ?? 0)
        ];
        
        echo "Testing saveProduct function with ID: $testId\n";
        echo "Original data: " . json_encode($originalData) . "\n";
        
        // Test with modified data
        $testData = $originalData;
        $testData['description'] = ($testData['description'] ?? '') . ' [TEST ' . date('His') . ']';
        
        echo "Test data: " . json_encode($testData) . "\n";
        
        // Call saveProduct
        saveProduct($testData, $testId);
        
        // Check result
        $checkStmt = $db->prepare('SELECT * FROM products WHERE id = :id');
        $checkStmt->execute([':id' => $testId]);
        $updatedProduct = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        echo "Product after saveProduct: " . json_encode($updatedProduct) . "\n";
        echo "saveProduct worked: " . ($updatedProduct['description'] === $testData['description'] ? 'YES' : 'NO') . "\n";
        
        // Restore original
        saveProduct($originalData, $testId);
        echo "Restored original data\n";
    }
    
    echo "\n=== Cache Status ===\n";
    $cacheFile = __DIR__ . '/../data/cache_products.json';
    if (file_exists($cacheFile)) {
        $cacheTime = filemtime($cacheFile);
        echo "Cache file exists: YES\n";
        echo "Cache file timestamp: " . date('Y-m-d H:i:s', $cacheTime) . "\n";
        echo "Cache age: " . (time() - $cacheTime) . " seconds\n";
    } else {
        echo "Cache file exists: NO\n";
    }
    
    if (function_exists('apcu_fetch')) {
        echo "APCu available: YES\n";
        $cached = @apcu_fetch('daytona_all_products_v1', $ok);
        echo "APCu cache exists: " . ($ok ? 'YES' : 'NO') . "\n";
        if ($ok && is_array($cached)) {
            echo "APCu cache product count: " . count($cached) . "\n";
        }
    } else {
        echo "APCu available: NO\n";
    }
    
    echo "\n=== Diagnostic Complete ===\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
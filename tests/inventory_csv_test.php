<?php

require_once __DIR__ . '/../includes/inventory.php';

$path = tempnam(sys_get_temp_dir(), 'inventory_csv_');
file_put_contents($path, "description,name,price\nWidget,SKU-2,12.50\nService,Bad Debt,\nDispenser,SOA-2010,\nMissing,SKU-3,\nFirst,SKU-1,\"\$1,234.56\"\n");

try {
    $result = parseInventoryCsv($path);
    if (array_column($result['items'], 'name') !== ['SKU-1', 'SKU-2', 'SOA-2010']) {
        throw new RuntimeException('Parsed inventory names were incorrect.');
    }
    if ($result['items'][0]['price'] !== 1234.56 || $result['items'][2]['price'] !== 0.0) {
        throw new RuntimeException('Parsed inventory prices were incorrect.');
    }
    if ($result['excluded_count'] !== 1 || $result['blank_price_count'] !== 1) {
        throw new RuntimeException('CSV skip counts were incorrect.');
    }
    echo "Inventory CSV parser test passed.\n";
} finally {
    @unlink($path);
}
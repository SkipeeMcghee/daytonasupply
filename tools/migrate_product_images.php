<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$reprocess = in_array('--reprocess', $argv ?? [], true);
$products = getAllProductsFresh();
$counts = ['migrated' => 0, 'reprocessed' => 0, 'skipped' => 0, 'unmatched' => 0, 'failed' => 0];

foreach ($products as $product) {
    $sku = trim((string)($product['name'] ?? ''));
    if ($sku === '') continue;
    $managedImages = getProductImages($sku);
    if ($managedImages) {
        if (!$reprocess) {
            $counts['skipped']++;
            continue;
        }
        foreach ($managedImages as $managedImage) {
            $path = getProductImageStorageDirectory() . '/' . basename((string)$managedImage['filename']);
            if (!is_file($path)) {
                fwrite(STDERR, "Missing managed image for {$sku}: {$path}\n");
                $counts['failed']++;
                continue;
            }
            if ($dryRun) {
                echo "Would reprocess {$sku}: {$path}\n";
                $counts['reprocessed']++;
                continue;
            }
            try {
                normalizeProductImage($path, $path);
                echo "Reprocessed {$sku}: {$managedImage['filename']}\n";
                $counts['reprocessed']++;
            } catch (Throwable $error) {
                fwrite(STDERR, "Failed {$sku}: {$error->getMessage()}\n");
                $counts['failed']++;
            }
        }
        continue;
    }
    $slug = productImageSlug($sku);
    $source = null;
    foreach (['jpg', 'jpeg', 'png', 'webp', 'gif'] as $extension) {
        $candidate = getProductImageStorageDirectory() . '/' . $slug . '.' . $extension;
        if (is_file($candidate)) { $source = $candidate; break; }
    }
    if ($source === null) {
        $counts['unmatched']++;
        continue;
    }
    if ($dryRun) {
        echo "Would migrate {$sku}: {$source}\n";
        $counts['migrated']++;
        continue;
    }
    try {
        addProductImageFromPath($sku, $source);
        echo "Migrated {$sku}\n";
        $counts['migrated']++;
    } catch (Throwable $error) {
        fwrite(STDERR, "Failed {$sku}: {$error->getMessage()}\n");
        $counts['failed']++;
    }
}

echo sprintf(
    "%s: %d migrated, %d reprocessed, %d already managed, %d without legacy images, %d failed.\n",
    $dryRun ? 'Dry run' : 'Migration complete',
    $counts['migrated'],
    $counts['reprocessed'],
    $counts['skipped'],
    $counts['unmatched'],
    $counts['failed']
);
exit($counts['failed'] > 0 ? 1 : 0);
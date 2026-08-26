<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/product_images.php';

function assertProductImageTest(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$temporaryDirectory = sys_get_temp_dir() . '/daytona-product-images-' . bin2hex(random_bytes(6));
mkdir($temporaryDirectory, 0755, true);
putenv('PRODUCT_IMAGE_DIR=' . $temporaryDirectory);
putenv('PRODUCT_IMAGE_URL=/test-product-images');
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$missingSchemaDb = new PDO('sqlite::memory:');
$missingSchemaDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
assertProductImageTest(
    getProductImages('MISSING-SCHEMA', $missingSchemaDb) === [],
    'Missing gallery schema must fall back to an empty gallery instead of blanking public pages.'
);

ensureProductImageSchema($db);

function insertProductImageFixture(PDO $db, string $sku, string $filename, int $sort, bool $primary): int
{
    file_put_contents(getProductImageStorageDirectory() . '/' . $filename, 'fixture');
    $stmt = $db->prepare('INSERT INTO product_images (product_sku, filename, sort_order, is_primary, created_at) VALUES (:sku, :filename, :sort, :primary, :created)');
    $stmt->execute([':sku' => $sku, ':filename' => $filename, ':sort' => $sort, ':primary' => $primary ? 1 : 0, ':created' => '2026-08-26 12:00:00']);
    return (int)$db->lastInsertId();
}

try {
    $legacySku = 'LEGACY-100';
    $legacyPath = $temporaryDirectory . '/' . productImageSlug($legacySku) . '.png';
    file_put_contents($legacyPath, 'legacy-image-fixture');
    $legacyImages = getProductImagesIncludingLegacy($legacySku, $db);
    assertProductImageTest(count($legacyImages) === 1 && !empty($legacyImages[0]['is_legacy']), 'Legacy primary must appear in the manager gallery.');
    assertProductImageTest($legacyImages[0]['is_primary'] && $legacyImages[0]['id'] === 0, 'Legacy primary must be protected and identified as the current primary.');
    assertProductImageTest($legacyImages[0]['url'] === resolveLegacyProductImage($legacySku), 'Manager and storefront legacy image URLs must match.');
    assertProductImageTest(is_file($legacyPath), 'Listing a legacy primary must never remove its file.');

    $firstId = insertProductImageFixture($db, 'TEST-100', 'stable-a.jpg', 0, true);
    $secondId = insertProductImageFixture($db, 'TEST-100', 'stable-b.jpg', 1, false);
    $thirdId = insertProductImageFixture($db, 'TEST-100', 'stable-c.jpg', 2, false);
    $images = getProductImages('TEST-100', $db);
    assertProductImageTest(count($images) === 3, 'Expected three gallery images.');
    assertProductImageTest($images[0]['id'] === $firstId && $images[0]['is_primary'], 'Expected exactly one primary image first.');
    $stableUrl = $images[1]['url'];

    reorderProductImages('TEST-100', [$firstId, $thirdId, $secondId], $db);
    $images = getProductImages('TEST-100', $db);
    assertProductImageTest(array_column($images, 'id') === [$firstId, $thirdId, $secondId], 'Expected submitted image order.');
    assertProductImageTest($images[2]['url'] === $stableUrl, 'Reordering must not change public URLs.');

    setPrimaryProductImage('TEST-100', $thirdId, $db);
    $images = getProductImages('TEST-100', $db);
    assertProductImageTest($images[0]['id'] === $thirdId && count(array_filter($images, function (array $image): bool { return $image['is_primary']; })) === 1, 'Setting primary must leave exactly one primary image.');

    renameProductImagesSku('TEST-100', 'TEST-RENAMED', $db);
    assertProductImageTest(getProductImages('TEST-100', $db) === [], 'Old SKU must no longer own images after rename.');
    $renamed = getProductImages('TEST-RENAMED', $db);
    assertProductImageTest(count($renamed) === 3 && in_array($stableUrl, array_column($renamed, 'url'), true), 'Rename must preserve gallery files and URLs.');

    deleteProductImage('TEST-RENAMED', $thirdId, $db);
    $remaining = getProductImages('TEST-RENAMED', $db);
    assertProductImageTest(count($remaining) === 2 && $remaining[0]['is_primary'], 'Deleting primary must promote a remaining image.');
    assertProductImageTest(!is_file($temporaryDirectory . '/stable-c.jpg'), 'Deleting an image must remove its file.');

    for ($index = 0; $index < PRODUCT_IMAGE_LIMIT; $index++) {
        insertProductImageFixture($db, 'LIMIT-TEST', 'limit-' . $index . '.jpg', $index, $index === 0);
    }
    try {
        addProductImageFromPath('LIMIT-TEST', $temporaryDirectory . '/stable-a.jpg', $db);
        throw new RuntimeException('The gallery limit must reject a tenth image.');
    } catch (InvalidArgumentException $error) {
        assertProductImageTest(stripos($error->getMessage(), 'nine') !== false, 'Gallery limit error must explain the nine-image maximum.');
    }

    if (extension_loaded('gd')) {
        $fixture = imagecreatetruecolor(300, 150);
        $red = imagecolorallocate($fixture, 220, 20, 20);
        imagefill($fixture, 0, 0, $red);
        $fixturePath = $temporaryDirectory . '/source.png';
        imagepng($fixture, $fixturePath);
        imagedestroy($fixture);
        $created = addProductImageFromPath('NORMALIZE-1', $fixturePath, $db);
        $dimensions = getimagesize($temporaryDirectory . '/' . $created['filename']);
        assertProductImageTest($dimensions[0] === 2000 && $dimensions[1] === 2000 && $dimensions['mime'] === 'image/jpeg', 'Normalized image must be a 2000x2000 JPEG.');
        $normalized = imagecreatefromjpeg($temporaryDirectory . '/' . $created['filename']);
        $corner = imagecolorsforindex($normalized, imagecolorat($normalized, 5, 5));
        imagedestroy($normalized);
        assertProductImageTest($corner['red'] > 245 && $corner['green'] > 245 && $corner['blue'] > 245, 'Normalized image must use a white canvas.');
    } else {
        $source = $temporaryDirectory . '/no-gd-source.jpg';
        file_put_contents($source, 'not decoded before GD capability check');
        try {
            normalizeProductImage($source, $temporaryDirectory . '/no-gd-output.jpg');
            throw new RuntimeException('Missing GD must prevent image normalization.');
        } catch (RuntimeException $error) {
            assertProductImageTest(stripos($error->getMessage(), 'GD') !== false, 'Missing GD error must explain the required extension.');
        }
        echo "GD unavailable locally; verified actionable normalization failure.\n";
    }

    echo "Product image tests passed.\n";
} finally {
    foreach (glob($temporaryDirectory . '/*') ?: [] as $path) @unlink($path);
    @rmdir($temporaryDirectory);
}
<?php

declare(strict_types=1);

const PRODUCT_IMAGE_LIMIT = 9;
const PRODUCT_IMAGE_SIZE = 2000;

function productImageSlug(string $sku): string
{
    $slug = strtolower((string)preg_replace('/[^a-z0-9]+/i', '-', trim($sku)));
    $slug = trim((string)preg_replace('/-+/', '-', $slug), '-');
    return $slug !== '' ? $slug : 'product';
}

function getProductImageStorageDirectory(): string
{
    $configured = trim((string)(getenv('PRODUCT_IMAGE_DIR') ?: ''));
    return $configured !== '' ? rtrim($configured, '\\/') : __DIR__ . '/../assets/uploads/products';
}

function getProductImageBaseUrl(): string
{
    $configured = trim((string)(getenv('PRODUCT_IMAGE_URL') ?: ''));
    return rtrim($configured !== '' ? $configured : '/assets/uploads/products', '/');
}

function productImageUrl(string $filename): string
{
    return getProductImageBaseUrl() . '/' . rawurlencode(basename($filename));
}

function resolveLegacyProductImage(string $sku): ?string
{
    $path = findLegacyProductImagePath($sku);
    return $path !== null ? productImageUrl(basename($path)) : null;
}

function findLegacyProductImagePath(string $sku): ?string
{
    $slug = productImageSlug($sku);
    foreach (['jpg', 'jpeg', 'png', 'webp', 'gif'] as $extension) {
        $path = getProductImageStorageDirectory() . '/' . $slug . '.' . $extension;
        if (is_file($path)) return $path;
    }
    return null;
}

function getProductImages(string $sku, ?PDO $db = null): array
{
    $sku = trim($sku);
    if ($sku === '') return [];
    $db = $db ?? getDb();
    try {
        $stmt = $db->prepare(
            'SELECT id, product_sku, filename, sort_order, is_primary, created_at
               FROM product_images
              WHERE product_sku = :sku
              ORDER BY is_primary DESC, sort_order ASC, id ASC'
        );
        $stmt->execute([':sku' => $sku]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $error) {
        static $logged = false;
        if (!$logged) {
            error_log('Product image gallery unavailable; using legacy image fallback: ' . $error->getMessage());
            $logged = true;
        }
        return [];
    }
    return array_map(function (array $row): array {
        $row['id'] = (int)$row['id'];
        $row['sort_order'] = (int)$row['sort_order'];
        $row['is_primary'] = (int)$row['is_primary'] === 1;
        $row['url'] = productImageUrl((string)$row['filename']);
        return $row;
    }, $rows);
}

function getProductImagesIncludingLegacy(string $sku, ?PDO $db = null): array
{
    $images = getProductImages($sku, $db);
    if ($images) return $images;
    $legacyPath = findLegacyProductImagePath($sku);
    $legacyUrl = resolveLegacyProductImage($sku);
    if ($legacyPath === null || $legacyUrl === null) return [];
    return [[
        'id' => 0,
        'product_sku' => $sku,
        'filename' => basename($legacyPath),
        'sort_order' => 0,
        'is_primary' => true,
        'is_legacy' => true,
        'url' => $legacyUrl,
    ]];
}

function resolvePrimaryProductImage(string $sku, bool $includeLegacy = true, ?PDO $db = null): ?string
{
    $images = getProductImages($sku, $db);
    if ($images) return (string)$images[0]['url'];
    return $includeLegacy ? resolveLegacyProductImage($sku) : null;
}

function assertProductImageBelongsToSku(PDO $db, int $imageId, string $sku): array
{
    $stmt = $db->prepare('SELECT * FROM product_images WHERE id = :id AND product_sku = :sku LIMIT 1');
    $stmt->execute([':id' => $imageId, ':sku' => $sku]);
    $image = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$image) throw new InvalidArgumentException('Product image does not exist.');
    return $image;
}

function normalizeProductImageOrder(PDO $db, string $sku): void
{
    $stmt = $db->prepare('SELECT id, is_primary FROM product_images WHERE product_sku = :sku ORDER BY is_primary DESC, sort_order ASC, id ASC');
    $stmt->execute([':sku' => $sku]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return;
    $primaryId = (int)$rows[0]['id'];
    $update = $db->prepare('UPDATE product_images SET sort_order = :sort, is_primary = :primary WHERE id = :id');
    foreach ($rows as $index => $row) {
        $update->execute([':sort' => $index, ':primary' => (int)$row['id'] === $primaryId ? 1 : 0, ':id' => (int)$row['id']]);
    }
}

function setPrimaryProductImage(string $sku, int $imageId, ?PDO $db = null): void
{
    $db = $db ?? getDb();
    assertProductImageBelongsToSku($db, $imageId, $sku);
    $db->beginTransaction();
    try {
        $clear = $db->prepare('UPDATE product_images SET is_primary = 0 WHERE product_sku = :sku');
        $clear->execute([':sku' => $sku]);
        $set = $db->prepare('UPDATE product_images SET is_primary = 1 WHERE id = :id');
        $set->execute([':id' => $imageId]);
        normalizeProductImageOrder($db, $sku);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function reorderProductImages(string $sku, array $imageIds, ?PDO $db = null): void
{
    $db = $db ?? getDb();
    $current = getProductImages($sku, $db);
    $currentIds = array_map(function (array $image): int { return (int)$image['id']; }, $current);
    $submittedIds = array_values(array_map('intval', $imageIds));
    $sortedCurrent = $currentIds;
    $sortedSubmitted = $submittedIds;
    sort($sortedCurrent);
    sort($sortedSubmitted);
    if (!$current || $sortedCurrent !== $sortedSubmitted || count($submittedIds) !== count(array_unique($submittedIds))) {
        throw new InvalidArgumentException('Image order does not match the current gallery.');
    }
    $primaryId = (int)$current[0]['id'];
    $db->beginTransaction();
    try {
        $update = $db->prepare('UPDATE product_images SET sort_order = :sort WHERE id = :id AND product_sku = :sku');
        foreach ($submittedIds as $index => $id) {
            $update->execute([':sort' => $index, ':id' => $id, ':sku' => $sku]);
        }
        setPrimaryProductImageWithinTransaction($db, $sku, $primaryId);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function setPrimaryProductImageWithinTransaction(PDO $db, string $sku, int $imageId): void
{
    $clear = $db->prepare('UPDATE product_images SET is_primary = 0 WHERE product_sku = :sku');
    $clear->execute([':sku' => $sku]);
    $set = $db->prepare('UPDATE product_images SET is_primary = 1 WHERE id = :id AND product_sku = :sku');
    $set->execute([':id' => $imageId, ':sku' => $sku]);
}

function deleteProductImage(string $sku, int $imageId, ?PDO $db = null): void
{
    $db = $db ?? getDb();
    $image = assertProductImageBelongsToSku($db, $imageId, $sku);
    $db->beginTransaction();
    try {
        $delete = $db->prepare('DELETE FROM product_images WHERE id = :id AND product_sku = :sku');
        $delete->execute([':id' => $imageId, ':sku' => $sku]);
        normalizeProductImageOrder($db, $sku);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
    $path = getProductImageStorageDirectory() . '/' . basename((string)$image['filename']);
    if (is_file($path) && !@unlink($path)) error_log('Unable to remove product image file: ' . $path);
}

function deleteAllProductImages(string $sku, ?PDO $db = null): void
{
    $db = $db ?? getDb();
    $images = getProductImages($sku, $db);
    $stmt = $db->prepare('DELETE FROM product_images WHERE product_sku = :sku');
    $stmt->execute([':sku' => $sku]);
    foreach ($images as $image) {
        $path = getProductImageStorageDirectory() . '/' . basename((string)$image['filename']);
        if (is_file($path) && !@unlink($path)) error_log('Unable to remove product image file: ' . $path);
    }
}

function renameProductImagesSku(string $oldSku, string $newSku, ?PDO $db = null): void
{
    $oldSku = trim($oldSku);
    $newSku = trim($newSku);
    if ($oldSku === '' || $newSku === '' || $oldSku === $newSku) return;
    $db = $db ?? getDb();
    $existing = getProductImages($newSku, $db);
    $moving = getProductImages($oldSku, $db);
    if (!$moving) return;
    if (count($existing) + count($moving) > PRODUCT_IMAGE_LIMIT) {
        throw new RuntimeException('The renamed product would exceed the image limit.');
    }
    $stmt = $db->prepare('UPDATE product_images SET product_sku = :new_sku WHERE product_sku = :old_sku');
    $stmt->execute([':new_sku' => $newSku, ':old_sku' => $oldSku]);
    normalizeProductImageOrder($db, $newSku);
}

function normalizeProductImage(string $sourcePath, string $destinationPath): void
{
    if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
        throw new RuntimeException('Image processing is unavailable. Enable the PHP GD extension and try again.');
    }
    $info = @getimagesize($sourcePath);
    if (!$info || empty($info[0]) || empty($info[1]) || empty($info['mime'])) {
        throw new InvalidArgumentException('The uploaded file is not a readable image.');
    }
    $width = (int)$info[0];
    $height = (int)$info[1];
    if ($width > 12000 || $height > 12000 || $width * $height > 50000000) {
        throw new InvalidArgumentException('The image dimensions are too large to process safely.');
    }
    $loaders = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png' => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
        'image/gif' => 'imagecreatefromgif',
    ];
    $mime = strtolower((string)$info['mime']);
    $loader = $loaders[$mime] ?? '';
    if ($loader === '' || !function_exists($loader)) {
        throw new InvalidArgumentException('This image format is not supported by the server.');
    }
    $source = @$loader($sourcePath);
    if (!$source) throw new InvalidArgumentException('The uploaded image could not be decoded.');
    if ($mime === 'image/jpeg') $source = orientProductJpeg($source, $sourcePath);
    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    $canvas = imagecreatetruecolor(PRODUCT_IMAGE_SIZE, PRODUCT_IMAGE_SIZE);
    if (!$canvas) {
        imagedestroy($source);
        throw new RuntimeException('Unable to allocate the normalized image.');
    }
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefill($canvas, 0, 0, $white);
    $inset = 40;
    $available = PRODUCT_IMAGE_SIZE - ($inset * 2);
    $scale = min($available / $sourceWidth, $available / $sourceHeight);
    $targetWidth = max(1, (int)round($sourceWidth * $scale));
    $targetHeight = max(1, (int)round($sourceHeight * $scale));
    $targetX = (int)floor((PRODUCT_IMAGE_SIZE - $targetWidth) / 2);
    $targetY = (int)floor((PRODUCT_IMAGE_SIZE - $targetHeight) / 2);
    imagecopyresampled($canvas, $source, $targetX, $targetY, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
    $directory = dirname($destinationPath);
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        imagedestroy($source);
        imagedestroy($canvas);
        throw new RuntimeException('Product image storage is not writable.');
    }
    $temporary = $directory . '/.' . basename($destinationPath) . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $written = imagejpeg($canvas, $temporary, 90);
    imagedestroy($source);
    imagedestroy($canvas);
    if (!$written) {
        @unlink($temporary);
        throw new RuntimeException('Unable to publish the normalized image.');
    }
    $backup = null;
    if (is_file($destinationPath)) {
        $backup = $destinationPath . '.backup-' . bin2hex(random_bytes(4));
        if (!rename($destinationPath, $backup)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to replace the existing normalized image.');
        }
    }
    if (!rename($temporary, $destinationPath)) {
        @unlink($temporary);
        if ($backup !== null) @rename($backup, $destinationPath);
        throw new RuntimeException('Unable to publish the normalized image.');
    }
    if ($backup !== null) @unlink($backup);
}

function orientProductJpeg($image, string $sourcePath)
{
    if (!function_exists('exif_read_data')) return $image;
    $exif = @exif_read_data($sourcePath);
    $orientation = (int)($exif['Orientation'] ?? 1);
    $rotated = null;
    if ($orientation === 3) $rotated = imagerotate($image, 180, 0);
    if ($orientation === 6) $rotated = imagerotate($image, -90, 0);
    if ($orientation === 8) $rotated = imagerotate($image, 90, 0);
    if ($rotated) {
        imagedestroy($image);
        return $rotated;
    }
    return $image;
}

function addProductImageFromPath(string $sku, string $sourcePath, ?PDO $db = null): array
{
    $sku = trim($sku);
    if ($sku === '') throw new InvalidArgumentException('A product SKU is required.');
    $db = $db ?? getDb();
    $images = getProductImages($sku, $db);
    if (count($images) >= PRODUCT_IMAGE_LIMIT) throw new InvalidArgumentException('A product may have up to nine images.');
    $filename = productImageSlug($sku) . '-' . bin2hex(random_bytes(8)) . '.jpg';
    $destination = getProductImageStorageDirectory() . '/' . $filename;
    normalizeProductImage($sourcePath, $destination);
    try {
        $sortOrder = count($images);
        $stmt = $db->prepare('INSERT INTO product_images (product_sku, filename, sort_order, is_primary, created_at) VALUES (:sku, :filename, :sort, :primary, :created_at)');
        $stmt->execute([
            ':sku' => $sku,
            ':filename' => $filename,
            ':sort' => $sortOrder,
            ':primary' => $sortOrder === 0 ? 1 : 0,
            ':created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $error) {
        @unlink($destination);
        throw $error;
    }
    $created = assertProductImageBelongsToSku($db, (int)$db->lastInsertId(), $sku);
    $created['id'] = (int)$created['id'];
    $created['sort_order'] = (int)$created['sort_order'];
    $created['is_primary'] = (int)$created['is_primary'] === 1;
    $created['url'] = productImageUrl($filename);
    return $created;
}
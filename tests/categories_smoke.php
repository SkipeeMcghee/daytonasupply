<?php

require_once __DIR__ . '/../includes/categories.php';
require_once __DIR__ . '/../includes/category_baseline.php';

function assertCategoryTest(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$groups = getCategoryTree(false);
assertCategoryTest(count($groups) > 0, 'Expected at least one active category group.');

$slugs = [];
$categoryCount = 0;
$assignmentCount = 0;
foreach ($groups as $group) {
    foreach ($group['categories'] as $category) {
        $categoryCount++;
        assertCategoryTest(!isset($slugs[$category['slug']]), 'Category slugs must be unique.');
        $slugs[$category['slug']] = true;
        $direct = getCategoryAssignments((int)$category['id'], false);
        $inherited = getCategoryAssignments((int)$category['id'], true);
        assertCategoryTest(count($inherited) >= count($direct), 'Parent assignments must include direct assignments.');
        $assignmentCount += count($direct);
        foreach ($category['children'] as $child) {
            $categoryCount++;
            assertCategoryTest(!isset($slugs[$child['slug']]), 'Category slugs must be unique.');
            $slugs[$child['slug']] = true;
            $assignmentCount += count(getCategoryAssignments((int)$child['id'], false));
        }
    }
}

$status = getCategoryAssignmentStatus();
assertCategoryTest(isset($status['unassigned'], $status['stale']), 'Assignment status must include unassigned and stale lists.');
$baseline = restoreCategoryBaseline(false);
assertCategoryTest($baseline['version'] === 1, 'Expected category baseline version 1.');
assertCategoryTest($baseline['groups'] === 4, 'Baseline must contain four groups.');
assertCategoryTest($baseline['categories'] === 27, 'Baseline must contain 27 categories.');
assertCategoryTest($baseline['stale'] === 0, 'Baseline must not create stale assignments.');

$fallbackCategory = null;
$fallbackSku = null;
foreach ($groups as $group) {
    foreach ($group['categories'] as $category) {
        foreach (array_merge([$category], $category['children']) as $candidate) {
            if (!empty($candidate['image_path'])) continue;
            $candidateSkus = getCategoryAssignments((int)$candidate['id'], empty($candidate['parent_id']));
            if (!$candidateSkus) continue;
            $hasProductImage = false;
            foreach ($candidateSkus as $candidateSku) {
                if (resolveUploadedProductImage($candidateSku) !== null) $hasProductImage = true;
            }
            if (!$hasProductImage) {
                $fallbackCategory = $candidate;
                $fallbackSku = $candidateSkus[0];
                break 3;
            }
        }
    }
}
assertCategoryTest($fallbackCategory !== null && $fallbackSku !== null, 'Expected an image-less category with assigned products for fallback testing.');
$fallbackSlug = strtolower((string)preg_replace('/[^a-z0-9]+/i', '-', $fallbackSku));
$fallbackSlug = trim((string)preg_replace('/-+/', '-', $fallbackSlug), '-');
if ($fallbackSlug === '') $fallbackSlug = 'product';
$uploadDirectory = __DIR__ . '/../assets/uploads/products';
if (!is_dir($uploadDirectory)) mkdir($uploadDirectory, 0755, true);
$temporaryImage = $uploadDirectory . '/' . $fallbackSlug . '.webp';
file_put_contents($temporaryImage, 'category-fallback-test');
try {
    assertCategoryTest(
        resolveCategoryImage($fallbackCategory) === '/assets/uploads/products/' . rawurlencode($fallbackSlug . '.webp'),
        'An image-less category must use the first contained product image.'
    );
} finally {
    if (is_file($temporaryImage)) unlink($temporaryImage);
}

echo sprintf(
    "Category smoke test passed: %d groups, %d categories, %d direct assignments, %d unassigned, %d stale.\n",
    count($groups),
    $categoryCount,
    $assignmentCount,
    count($status['unassigned']),
    count($status['stale'])
);
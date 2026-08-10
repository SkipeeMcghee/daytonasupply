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

echo sprintf(
    "Category smoke test passed: %d groups, %d categories, %d direct assignments, %d unassigned, %d stale.\n",
    count($groups),
    $categoryCount,
    $assignmentCount,
    count($status['unassigned']),
    count($status['stale'])
);
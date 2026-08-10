<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/category_baseline.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$apply = in_array('--apply', $argv, true);
$force = in_array('--force', $argv, true);
$db = getDb();
ensureCategorySchema($db);
$existingCategoryCount = (int)$db->query('SELECT COUNT(*) FROM categories')->fetchColumn();
$existingAssignmentCount = (int)$db->query('SELECT COUNT(*) FROM category_product_assignments')->fetchColumn();
if ($apply && !$force && ($existingCategoryCount > 0 || $existingAssignmentCount > 0)) {
    fwrite(STDERR, "Taxonomy already contains data. Refusing to overwrite manager changes. Use --force only for an intentional baseline restore.\n");
    exit(1);
}

try {
    $summary = restoreCategoryBaseline($apply);
    $summary = ['mode' => $apply ? 'apply' : 'dry-run'] + $summary;
    foreach ($summary as $key => $value) echo str_pad((string)$key, 24) . ': ' . $value . PHP_EOL;
    if (!$apply) echo PHP_EOL . 'No changes committed. Run with --apply to seed an empty taxonomy.' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Category baseline failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/categories.php';

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
    fwrite(STDERR, "Taxonomy already contains data. Refusing to overwrite manager changes. Use --force only for an intentional reseed.\n");
    exit(1);
}
$legacy = require __DIR__ . '/../includes/sku_filters.php';
$filters = is_array($legacy['filters'] ?? null) ? $legacy['filters'] : [];
$groups = is_array($legacy['groups'] ?? null) ? $legacy['groups'] : [];
$products = $db->query('SELECT name, description FROM products ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

$slugOverrides = [
    'CORRUGATED BOXES' => 'corrugated',
];
$imageMap = [
    'CORRUGATED BOXES' => 'assets/images/boxes.png',
    'TAPE' => 'assets/images/tape.png',
    'PACKAGING SUPPLIES' => 'assets/images/stretchfilm.png',
    'PAPER PRODUCTS' => 'assets/images/paper.png',
    'BUBBLE PRODUCTS' => 'assets/images/bubble.png',
    'FOAM' => 'assets/images/foam.png',
];
$featured = array_fill_keys(array_keys($imageMap), true);

$groupedLabels = [];
foreach ($groups as $labels) {
    foreach ((array)$labels as $label) $groupedLabels[(string)$label] = true;
}
$ungroupedLabels = array_values(array_diff(array_keys($filters), array_keys($groupedLabels)));
if ($ungroupedLabels) $groups['Other'] = $ungroupedLabels;

function findOrCreateGroup(PDO $db, string $name, int $sortOrder): int
{
    $find = $db->prepare('SELECT id FROM category_groups WHERE name = :name LIMIT 1');
    $find->execute([':name' => $name]);
    $id = (int)$find->fetchColumn();
    if ($id > 0) {
        $db->prepare('UPDATE category_groups SET sort_order = :sort, active = 1 WHERE id = :id')->execute([':sort' => $sortOrder, ':id' => $id]);
        return $id;
    }
    $insert = $db->prepare('INSERT INTO category_groups (name, sort_order, active) VALUES (:name, :sort, 1)');
    $insert->execute([':name' => $name, ':sort' => $sortOrder]);
    return (int)$db->lastInsertId();
}

function findOrCreateCategory(PDO $db, int $groupId, ?int $parentId, string $name, string $slug, ?string $imagePath, int $sortOrder, bool $featured): int
{
    $find = $db->prepare('SELECT id FROM categories WHERE slug = :slug LIMIT 1');
    $find->execute([':slug' => $slug]);
    $id = (int)$find->fetchColumn();
    $params = [
        ':group' => $groupId,
        ':parent' => $parentId,
        ':name' => $name,
        ':slug' => $slug,
        ':image' => $imagePath,
        ':sort' => $sortOrder,
        ':featured' => $featured ? 1 : 0,
    ];
    if ($id > 0) {
        $params[':id'] = $id;
        $db->prepare('UPDATE categories SET group_id = :group, parent_id = :parent, name = :name, slug = :slug, image_path = COALESCE(image_path, :image), sort_order = :sort, active = 1, featured_homepage = :featured WHERE id = :id')->execute($params);
        return $id;
    }
    $db->prepare('INSERT INTO categories (group_id, parent_id, name, slug, image_path, sort_order, active, featured_homepage) VALUES (:group, :parent, :name, :slug, :image, :sort, 1, :featured)')->execute($params);
    return (int)$db->lastInsertId();
}

function skuMatchesCodes(string $sku, array $codes): bool
{
    $sku = strtoupper($sku);
    foreach ($codes as $code) {
        $code = strtoupper(trim((string)$code));
        if ($code !== '' && strpos($sku, $code) === 0) return true;
    }
    return false;
}

function isCubeProduct(array $product): bool
{
    $haystack = (string)($product['name'] ?? '') . ' ' . (string)($product['description'] ?? '');
    return (bool)preg_match('/\b(\d{1,3})\s*[x×]\s*\1\s*[x×]\s*\1\b/i', $haystack);
}

$db->beginTransaction();
try {
    $categoryIds = [];
    $groupOrder = 0;
    foreach ($groups as $groupName => $labels) {
        $groupId = findOrCreateGroup($db, (string)$groupName, $groupOrder++);
        foreach (array_values((array)$labels) as $categoryOrder => $label) {
            if (!isset($filters[$label])) continue;
            $slug = $slugOverrides[$label] ?? categorySlugify((string)$label);
            $categoryIds[$label] = findOrCreateCategory(
                $db,
                $groupId,
                null,
                (string)$label,
                $slug,
                $imageMap[$label] ?? null,
                $categoryOrder,
                isset($featured[$label])
            );
        }
    }

    $insertAssignment = $db->prepare('INSERT INTO category_product_assignments (category_id, product_sku) VALUES (:category, :sku)');
    $assignmentCount = 0;
    foreach ($categoryIds as $label => $categoryId) {
        $db->prepare('DELETE FROM category_product_assignments WHERE category_id = :id')->execute([':id' => $categoryId]);
        foreach ($products as $product) {
            if (!skuMatchesCodes((string)$product['name'], (array)$filters[$label])) continue;
            $insertAssignment->execute([':category' => $categoryId, ':sku' => (string)$product['name']]);
            $assignmentCount++;
        }
    }

    $corrugatedId = $categoryIds['CORRUGATED BOXES'] ?? null;
    $cubeCount = 0;
    if ($corrugatedId) {
        $corrugated = getCategoryById((int)$corrugatedId);
        $cubeId = findOrCreateCategory($db, (int)$corrugated['group_id'], (int)$corrugatedId, 'Cube Corrugated Boxes', 'cube', null, 0, false);
        $db->prepare('DELETE FROM category_product_assignments WHERE category_id = :id')->execute([':id' => $cubeId]);
        $corrugatedSkus = array_fill_keys(getCategoryAssignments((int)$corrugatedId), true);
        foreach ($products as $product) {
            $sku = (string)$product['name'];
            if (!isset($corrugatedSkus[$sku]) || !isCubeProduct($product)) continue;
            $insertAssignment->execute([':category' => $cubeId, ':sku' => $sku]);
            $cubeCount++;
        }
    }

    $status = getCategoryAssignmentStatus();
    $summary = [
        'mode' => $apply ? 'apply' : 'dry-run',
        'products' => count($products),
        'groups' => count($groups),
        'categories' => count($categoryIds),
        'category_assignments' => $assignmentCount,
        'cube_assignments' => $cubeCount,
        'unassigned_products' => count($status['unassigned']),
        'stale_assignments' => count($status['stale']),
    ];

    if ($apply) $db->commit();
    else $db->rollBack();

    foreach ($summary as $key => $value) echo str_pad($key, 24) . ': ' . $value . PHP_EOL;
    if (!$apply) echo PHP_EOL . 'No changes committed. Run with --apply to seed the taxonomy.' . PHP_EOL;
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, 'Category migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
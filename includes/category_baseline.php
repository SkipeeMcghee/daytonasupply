<?php

declare(strict_types=1);

require_once __DIR__ . '/categories.php';

function getCategoryBaselineDefinition(): array
{
    return [
        'version' => 1,
        'groups' => [
            ['name' => 'Packaging', 'categories' => [
                ['name' => 'CORRUGATED BOXES', 'slug' => 'corrugated', 'codes' => ['BAR', 'PAD', 'CLEARANCEBOX050514', 'SBO', 'ROL', 'OPF', 'SFC', 'SIZ', 'SOB'], 'image' => 'assets/images/boxes.png', 'featured' => true, 'children' => [
                    ['name' => 'Cube Corrugated Boxes', 'slug' => 'cube', 'matcher' => 'cube'],
                ]],
                ['name' => 'TAPE', 'slug' => 'tape', 'codes' => ['TAP'], 'image' => 'assets/images/tape.png', 'featured' => true],
                ['name' => 'PACKAGING SUPPLIES', 'slug' => 'packaging-supplies', 'codes' => ['PAC'], 'image' => 'assets/images/stretchfilm.png', 'featured' => true],
                ['name' => 'PAPER PRODUCTS', 'slug' => 'paper-products', 'codes' => ['PAP'], 'image' => 'assets/images/paper.png', 'featured' => true],
                ['name' => 'POLY', 'slug' => 'poly', 'codes' => ['POS']],
                ['name' => 'POLY BAGS', 'slug' => 'poly-bags', 'codes' => ['POL']],
                ['name' => 'MAILERS', 'slug' => 'mailers', 'codes' => ['BMA', 'MDC', 'PMA']],
                ['name' => 'TRASH CAN LINERS', 'slug' => 'trash-can-liners', 'codes' => ['LIN']],
                ['name' => 'BUBBLE PRODUCTS', 'slug' => 'bubble-products', 'codes' => ['BPA'], 'image' => 'assets/images/bubble.png', 'featured' => true],
                ['name' => 'FOAM', 'slug' => 'foam', 'codes' => ['FOA'], 'image' => 'assets/images/foam.png', 'featured' => true],
            ]],
            ['name' => 'Janitorial', 'categories' => [
                ['name' => 'BATHROOM', 'slug' => 'bathroom', 'codes' => ['BAT']],
                ['name' => 'BROOMS AND BRUSHES', 'slug' => 'brooms-and-brushes', 'codes' => ['BRB']],
                ['name' => 'CLEANERS AND DEGREASERS', 'slug' => 'cleaners-and-degreasers', 'codes' => ['CLD']],
                ['name' => 'DEODORIZER', 'slug' => 'deodorizer', 'codes' => ['DEO']],
                ['name' => 'DISINFECTANTS', 'slug' => 'disinfectants', 'codes' => ['DIS']],
                ['name' => 'FLOOR PRODUCTS', 'slug' => 'floor-products', 'codes' => ['FLO']],
                ['name' => 'MATS', 'slug' => 'mats', 'codes' => ['MAT']],
                ['name' => 'SOAP AND SANITIZER', 'slug' => 'soap-and-sanitizer', 'codes' => ['SAN', 'SOA']],
                ['name' => 'SPONGES AND SCRUBBERS', 'slug' => 'sponges-and-scrubbers', 'codes' => ['SPS']],
                ['name' => 'RAGS', 'slug' => 'rags', 'codes' => ['RAG']],
                ['name' => 'FOODSERVICE', 'slug' => 'foodservice', 'codes' => ['FOO']],
            ]],
            ['name' => 'Safety', 'categories' => [
                ['name' => 'GLOVES', 'slug' => 'gloves', 'codes' => ['GLO']],
                ['name' => 'SAFETY EQUIPMENT', 'slug' => 'safety-equipment', 'codes' => ['SAF']],
                ['name' => 'TOOLS & EQUIPMENT', 'slug' => 'tools-equipment', 'codes' => ['TEQ']],
                ['name' => 'PEST CONTROL', 'slug' => 'pest-control', 'codes' => ['PCO']],
            ]],
            ['name' => 'Other', 'categories' => [
                ['name' => 'OFFICE', 'slug' => 'office', 'codes' => ['OFF']],
            ]],
        ],
    ];
}

function categoryBaselineProductMatches(array $product, array $category, ?array $parentSkus = null): bool
{
    $sku = (string)($product['name'] ?? '');
    if ($parentSkus !== null && !isset($parentSkus[$sku])) return false;
    if (($category['matcher'] ?? '') === 'cube') {
        $haystack = $sku . ' ' . (string)($product['description'] ?? '');
        return (bool)preg_match('/\b(\d{1,3})\s*[x×]\s*\1\s*[x×]\s*\1\b/i', $haystack);
    }
    $upperSku = strtoupper($sku);
    foreach ((array)($category['codes'] ?? []) as $code) {
        $code = strtoupper(trim((string)$code));
        if ($code !== '' && strpos($upperSku, $code) === 0) return true;
    }
    return false;
}

function restoreCategoryBaseline(bool $commit = true): array
{
    $db = getDb();
    ensureCategorySchema($db);
    $definition = getCategoryBaselineDefinition();
    $products = $db->query('SELECT name, description FROM products ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    $startedTransaction = !$db->inTransaction();
    if ($startedTransaction) $db->beginTransaction();

    try {
        $db->exec('DELETE FROM category_product_assignments');
        $db->exec('DELETE FROM categories');
        $db->exec('DELETE FROM category_groups');

        $insertGroup = $db->prepare('INSERT INTO category_groups (name, sort_order, active) VALUES (:name, :sort, 1)');
        $insertCategory = $db->prepare('INSERT INTO categories (group_id, parent_id, name, slug, image_path, sort_order, active, featured_homepage) VALUES (:group, :parent, :name, :slug, :image, :sort, 1, :featured)');
        $insertAssignment = $db->prepare('INSERT INTO category_product_assignments (category_id, product_sku) VALUES (:category, :sku)');
        $categoryCount = 0;
        $assignmentCount = 0;

        foreach ($definition['groups'] as $groupOrder => $group) {
            $insertGroup->execute([':name' => $group['name'], ':sort' => $groupOrder]);
            $groupId = (int)$db->lastInsertId();
            foreach ($group['categories'] as $categoryOrder => $category) {
                $insertCategory->execute([
                    ':group' => $groupId,
                    ':parent' => null,
                    ':name' => $category['name'],
                    ':slug' => $category['slug'],
                    ':image' => $category['image'] ?? null,
                    ':sort' => $categoryOrder,
                    ':featured' => !empty($category['featured']) ? 1 : 0,
                ]);
                $categoryId = (int)$db->lastInsertId();
                $categoryCount++;
                $parentSkus = [];
                foreach ($products as $product) {
                    if (!categoryBaselineProductMatches($product, $category)) continue;
                    $sku = (string)$product['name'];
                    $insertAssignment->execute([':category' => $categoryId, ':sku' => $sku]);
                    $parentSkus[$sku] = true;
                    $assignmentCount++;
                }
                foreach ((array)($category['children'] ?? []) as $childOrder => $child) {
                    $insertCategory->execute([
                        ':group' => $groupId,
                        ':parent' => $categoryId,
                        ':name' => $child['name'],
                        ':slug' => $child['slug'],
                        ':image' => $child['image'] ?? null,
                        ':sort' => $childOrder,
                        ':featured' => 0,
                    ]);
                    $childId = (int)$db->lastInsertId();
                    $categoryCount++;
                    foreach ($products as $product) {
                        if (!categoryBaselineProductMatches($product, $child, $parentSkus)) continue;
                        $insertAssignment->execute([':category' => $childId, ':sku' => (string)$product['name']]);
                        $assignmentCount++;
                    }
                }
            }
        }

        $status = getCategoryAssignmentStatus();
        $summary = [
            'version' => (int)$definition['version'],
            'products' => count($products),
            'groups' => count($definition['groups']),
            'categories' => $categoryCount,
            'assignments' => $assignmentCount,
            'unassigned' => count($status['unassigned']),
            'stale' => count($status['stale']),
        ];
        if ($startedTransaction) {
            if ($commit) $db->commit();
            else $db->rollBack();
        }
        return $summary;
    } catch (Throwable $e) {
        if ($startedTransaction && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
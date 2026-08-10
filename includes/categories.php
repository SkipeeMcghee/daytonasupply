<?php

require_once __DIR__ . '/db.php';

function categorySlugify(string $name): string
{
    $slug = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
    return $slug !== '' ? $slug : 'category';
}

function getCategoryImageStorageDirectory(): string
{
    $configured = trim((string)(getenv('CATEGORY_IMAGE_DIR') ?: ''));
    return $configured !== '' ? rtrim($configured, '\\/') : __DIR__ . '/../assets/uploads/categories';
}

function getCategoryImageBaseUrl(): string
{
    $configured = trim((string)(getenv('CATEGORY_IMAGE_URL') ?: ''));
    return rtrim($configured !== '' ? $configured : '/assets/uploads/categories', '/');
}

function categoryUploadReference(string $filename): string
{
    return 'category-upload:' . basename($filename);
}

function resolveUploadedProductImage(string $sku): ?string
{
    $slug = strtolower((string)preg_replace('/[^a-z0-9]+/i', '-', $sku));
    $slug = trim((string)preg_replace('/-+/', '-', $slug), '-');
    if ($slug === '') $slug = 'product';
    foreach (['jpg', 'jpeg', 'png', 'webp', 'gif'] as $extension) {
        $filename = $slug . '.' . $extension;
        if (is_file(__DIR__ . '/../assets/uploads/products/' . $filename)) {
            return '/assets/uploads/products/' . rawurlencode($filename);
        }
    }
    return null;
}

function getFirstCategoryProductImage(int $categoryId, bool $includeChildren): ?string
{
    if ($categoryId <= 0) return null;
    $sql = 'SELECT DISTINCT a.product_sku
              FROM category_product_assignments a
              JOIN categories c ON c.id = a.category_id
             WHERE c.id = :category_id';
    if ($includeChildren) $sql .= ' OR c.parent_id = :parent_id';
    $sql .= ' ORDER BY a.product_sku';
    $stmt = getDb()->prepare($sql);
    $params = [':category_id' => $categoryId];
    if ($includeChildren) $params[':parent_id'] = $categoryId;
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $sku) {
        $image = resolveUploadedProductImage((string)$sku);
        if ($image !== null) return $image;
    }
    return null;
}

function uniqueCategorySlug(PDO $db, string $name, ?int $excludeId = null): string
{
    $base = categorySlugify($name);
    $slug = $base;
    $suffix = 2;
    while (true) {
        $sql = 'SELECT id FROM categories WHERE slug = :slug';
        $params = [':slug' => $slug];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :id';
            $params[':id'] = $excludeId;
        }
        $stmt = $db->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        if (!$stmt->fetchColumn()) return $slug;
        $slug = $base . '-' . $suffix++;
    }
}

function getCategoryGroups(bool $includeInactive = false): array
{
    $db = getDb();
    $sql = 'SELECT * FROM category_groups';
    if (!$includeInactive) $sql .= ' WHERE active = 1';
    $sql .= ' ORDER BY sort_order ASC, id ASC';
    return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function getCategoryTree(bool $includeInactive = false): array
{
    $groups = getCategoryGroups($includeInactive);
    $db = getDb();
    $sql = 'SELECT c.*,
                   (SELECT COUNT(*) FROM category_product_assignments a WHERE a.category_id = c.id) AS direct_product_count
              FROM categories c';
    if (!$includeInactive) $sql .= ' WHERE c.active = 1';
    $sql .= ' ORDER BY c.group_id ASC, c.parent_id ASC, c.sort_order ASC, c.id ASC';
    $categories = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $byGroup = [];
    $children = [];
    foreach ($categories as $category) {
        $category['children'] = [];
        if ($category['parent_id'] === null) {
            $byGroup[(int)$category['group_id']][] = $category;
        } else {
            $children[(int)$category['parent_id']][] = $category;
        }
    }
    foreach ($groups as &$group) {
        $parents = $byGroup[(int)$group['id']] ?? [];
        foreach ($parents as &$parent) {
            $parent['children'] = $children[(int)$parent['id']] ?? [];
            $parent['image_url'] = resolveCategoryImage($parent);
            $parent['product_count'] = (int)$parent['direct_product_count'];
            foreach ($parent['children'] as &$child) {
                $child['image_url'] = resolveCategoryImage($child, $parent);
                $parent['product_count'] += (int)$child['direct_product_count'];
            }
            unset($child);
        }
        unset($parent);
        $group['categories'] = $parents;
    }
    unset($group);
    return $groups;
}

function getCategoryById(int $id): ?array
{
    $stmt = getDb()->prepare('SELECT * FROM categories WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function getCategoryBySlug(string $slug, bool $activeOnly = true): ?array
{
    $sql = 'SELECT * FROM categories WHERE slug = :slug';
    if ($activeOnly) $sql .= ' AND active = 1';
    $stmt = getDb()->prepare($sql . ' LIMIT 1');
    $stmt->execute([':slug' => strtolower(trim($slug))]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function getTopLevelCategories(bool $featuredOnly = false, bool $includeInactive = false): array
{
    $sql = 'SELECT c.*, g.name AS group_name, g.sort_order AS group_sort_order
              FROM categories c
              JOIN category_groups g ON g.id = c.group_id
             WHERE c.parent_id IS NULL';
    if (!$includeInactive) $sql .= ' AND c.active = 1 AND g.active = 1';
    if ($featuredOnly) $sql .= ' AND c.featured_homepage = 1';
    $sql .= ' ORDER BY g.sort_order ASC, g.id ASC, c.sort_order ASC, c.id ASC';
    return getDb()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function getChildCategories(int $parentId, bool $includeInactive = false): array
{
    $sql = 'SELECT * FROM categories WHERE parent_id = :parent';
    if (!$includeInactive) $sql .= ' AND active = 1';
    $sql .= ' ORDER BY sort_order ASC, id ASC';
    $stmt = getDb()->prepare($sql);
    $stmt->execute([':parent' => $parentId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCategoryImageMapBySku(): array
{
    $sql = 'SELECT a.product_sku, c.*, p.name AS parent_name, p.slug AS parent_slug, p.image_path AS parent_image_path
              FROM category_product_assignments a
              JOIN categories c ON c.id = a.category_id AND c.active = 1
              JOIN category_groups g ON g.id = c.group_id AND g.active = 1
              LEFT JOIN categories p ON p.id = c.parent_id
             ORDER BY g.sort_order ASC, c.parent_id ASC, c.sort_order ASC, c.id ASC';
    $images = [];
    foreach (getDb()->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($images[$row['product_sku']])) continue;
        $parent = $row['parent_id'] === null ? null : [
            'name' => $row['parent_name'],
            'slug' => $row['parent_slug'],
            'image_path' => $row['parent_image_path'],
        ];
        $images[$row['product_sku']] = resolveCategoryImage($row, $parent);
    }
    return $images;
}

function createCategoryGroup(string $name): int
{
    $name = trim($name);
    if ($name === '' || strlen($name) > 128) throw new InvalidArgumentException('Group name is required and must be 128 characters or fewer.');
    $db = getDb();
    $duplicate = $db->prepare('SELECT id FROM category_groups WHERE LOWER(name) = LOWER(:name) LIMIT 1');
    $duplicate->execute([':name' => $name]);
    if ($duplicate->fetchColumn()) throw new InvalidArgumentException('A category group with that name already exists.');
    $sort = (int)$db->query('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM category_groups')->fetchColumn();
    $stmt = $db->prepare('INSERT INTO category_groups (name, sort_order, active) VALUES (:name, :sort, 1)');
    $stmt->execute([':name' => $name, ':sort' => $sort]);
    return (int)$db->lastInsertId();
}

function saveCategoryGroup(string $name, ?int $id = null, bool $active = true): int
{
    $name = trim($name);
    if ($name === '' || strlen($name) > 128) throw new InvalidArgumentException('Group name is required and must be 128 characters or fewer.');
    if ($id === null) return createCategoryGroup($name);
    $stmt = getDb()->prepare('UPDATE category_groups SET name = :name, active = :active WHERE id = :id');
    $stmt->execute([':name' => $name, ':active' => $active ? 1 : 0, ':id' => $id]);
    if ($stmt->rowCount() === 0) {
        $check = getDb()->prepare('SELECT id FROM category_groups WHERE id = :id');
        $check->execute([':id' => $id]);
        if (!$check->fetchColumn()) throw new InvalidArgumentException('Category group does not exist.');
    }
    return $id;
}

function reorderCategoryGroup(int $id, string $direction): void
{
    if (!in_array($direction, ['up', 'down'], true)) throw new InvalidArgumentException('Invalid reorder direction.');
    $db = getDb();
    $stmt = $db->prepare('SELECT id, sort_order FROM category_groups WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$group) throw new InvalidArgumentException('Category group does not exist.');
    $operator = $direction === 'up' ? '<' : '>';
    $order = $direction === 'up' ? 'DESC' : 'ASC';
    $neighborStmt = $db->prepare("SELECT id, sort_order FROM category_groups WHERE sort_order $operator :sort ORDER BY sort_order $order, id $order LIMIT 1");
    $neighborStmt->execute([':sort' => (int)$group['sort_order']]);
    $neighbor = $neighborStmt->fetch(PDO::FETCH_ASSOC);
    if (!$neighbor) return;
    $db->beginTransaction();
    try {
        $swap = $db->prepare('UPDATE category_groups SET sort_order = :sort WHERE id = :id');
        $swap->execute([':sort' => (int)$neighbor['sort_order'], ':id' => $id]);
        $swap->execute([':sort' => (int)$group['sort_order'], ':id' => (int)$neighbor['id']]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function deleteCategoryGroup(int $id): void
{
    $db = getDb();
    $count = $db->prepare('SELECT COUNT(*) FROM categories WHERE group_id = :id');
    $count->execute([':id' => $id]);
    if ((int)$count->fetchColumn() > 0) throw new InvalidArgumentException('Move or delete this group\'s categories first.');
    $db->prepare('DELETE FROM category_groups WHERE id = :id')->execute([':id' => $id]);
}

function saveCategory(array $data, ?int $id = null): int
{
    $db = getDb();
    $name = trim((string)($data['name'] ?? ''));
    $groupId = (int)($data['group_id'] ?? 0);
    $parentId = !empty($data['parent_id']) ? (int)$data['parent_id'] : null;
    if ($name === '' || strlen($name) > 128) throw new InvalidArgumentException('Category name is required and must be 128 characters or fewer.');
    if ($groupId <= 0) throw new InvalidArgumentException('A category group is required.');
    $groupCheck = $db->prepare('SELECT id FROM category_groups WHERE id = :id');
    $groupCheck->execute([':id' => $groupId]);
    if (!$groupCheck->fetchColumn()) throw new InvalidArgumentException('Category group does not exist.');
    if ($parentId !== null) {
        $parent = getCategoryById($parentId);
        if (!$parent || $parent['parent_id'] !== null) throw new InvalidArgumentException('Subcategories may only be placed below a top-level category.');
        if ((int)$parent['group_id'] !== $groupId) throw new InvalidArgumentException('A subcategory must use its parent category group.');
        if ($id !== null && $parentId === $id) throw new InvalidArgumentException('A category cannot be its own parent.');
    }

    $duplicateSql = 'SELECT id FROM categories WHERE group_id = :group AND LOWER(name) = LOWER(:name)';
    $duplicateParams = [
        ':group' => $groupId,
        ':name' => $name,
    ];
    if ($parentId === null) {
        $duplicateSql .= ' AND parent_id IS NULL';
    } else {
        $duplicateSql .= ' AND parent_id = :parent_id';
        $duplicateParams[':parent_id'] = $parentId;
    }
    if ($id !== null) {
        $duplicateSql .= ' AND id <> :id';
        $duplicateParams[':id'] = $id;
    }
    $duplicate = $db->prepare($duplicateSql . ' LIMIT 1');
    $duplicate->execute($duplicateParams);
    if ($duplicate->fetchColumn()) throw new InvalidArgumentException('A category with that name already exists at this level.');

    $active = !empty($data['active']) ? 1 : 0;
    $featured = $parentId === null && !empty($data['featured_homepage']) ? 1 : 0;
    $db->beginTransaction();
    try {
        if ($id === null) {
            $orderStmt = $db->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM categories WHERE group_id = :group AND ((parent_id IS NULL AND :parent_is_null = 1) OR parent_id = :parent_id)');
            $orderStmt->bindValue(':group', $groupId, PDO::PARAM_INT);
            $orderStmt->bindValue(':parent_is_null', $parentId === null ? 1 : 0, PDO::PARAM_INT);
            if ($parentId === null) $orderStmt->bindValue(':parent_id', null, PDO::PARAM_NULL);
            else $orderStmt->bindValue(':parent_id', $parentId, PDO::PARAM_INT);
            $orderStmt->execute();
            $sortOrder = (int)$orderStmt->fetchColumn();
            $slug = uniqueCategorySlug($db, $name);
            $stmt = $db->prepare('INSERT INTO categories (group_id, parent_id, name, slug, sort_order, active, featured_homepage) VALUES (:group, :parent, :name, :slug, :sort, :active, :featured)');
            $stmt->execute([':group' => $groupId, ':parent' => $parentId, ':name' => $name, ':slug' => $slug, ':sort' => $sortOrder, ':active' => $active, ':featured' => $featured]);
            $id = (int)$db->lastInsertId();
        } else {
            $existing = getCategoryById($id);
            if (!$existing) throw new InvalidArgumentException('Category does not exist.');
            $stmt = $db->prepare('UPDATE categories SET group_id = :group, parent_id = :parent, name = :name, active = :active, featured_homepage = :featured WHERE id = :id');
            $stmt->execute([':group' => $groupId, ':parent' => $parentId, ':name' => $name, ':active' => $active, ':featured' => $featured, ':id' => $id]);
            if ($parentId !== null) {
                $db->prepare('UPDATE categories SET parent_id = NULL, featured_homepage = 0 WHERE parent_id = :id')->execute([':id' => $id]);
            } else {
                $db->prepare('UPDATE categories SET group_id = :group WHERE parent_id = :id')->execute([':group' => $groupId, ':id' => $id]);
            }
        }
        $db->commit();
        return $id;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function deleteCategory(int $id): void
{
    $category = getCategoryById($id);
    if (!$category) throw new InvalidArgumentException('Category does not exist.');
    $stmt = getDb()->prepare('DELETE FROM categories WHERE id = :id');
    $stmt->execute([':id' => $id]);
}

function setCategoryImagePath(int $id, ?string $imagePath): void
{
    if (!getCategoryById($id)) throw new InvalidArgumentException('Category does not exist.');
    $stmt = getDb()->prepare('UPDATE categories SET image_path = :path WHERE id = :id');
    if ($imagePath === null) $stmt->bindValue(':path', null, PDO::PARAM_NULL);
    else $stmt->bindValue(':path', ltrim($imagePath, '/'), PDO::PARAM_STR);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
}

function reorderCategory(int $id, string $direction): void
{
    if (!in_array($direction, ['up', 'down'], true)) throw new InvalidArgumentException('Invalid reorder direction.');
    $db = getDb();
    $category = getCategoryById($id);
    if (!$category) throw new InvalidArgumentException('Category does not exist.');
    $operator = $direction === 'up' ? '<' : '>';
    $order = $direction === 'up' ? 'DESC' : 'ASC';
    $sql = "SELECT id, sort_order FROM categories WHERE group_id = :group AND ((parent_id IS NULL AND :parent_is_null = 1) OR parent_id = :parent_id) AND sort_order $operator :sort ORDER BY sort_order $order, id $order LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':group', (int)$category['group_id'], PDO::PARAM_INT);
    $stmt->bindValue(':parent_is_null', $category['parent_id'] === null ? 1 : 0, PDO::PARAM_INT);
    if ($category['parent_id'] === null) $stmt->bindValue(':parent_id', null, PDO::PARAM_NULL);
    else $stmt->bindValue(':parent_id', (int)$category['parent_id'], PDO::PARAM_INT);
    $stmt->bindValue(':sort', (int)$category['sort_order'], PDO::PARAM_INT);
    $stmt->execute();
    $neighbor = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$neighbor) return;
    $db->beginTransaction();
    try {
        $swap = $db->prepare('UPDATE categories SET sort_order = :sort WHERE id = :id');
        $swap->execute([':sort' => (int)$neighbor['sort_order'], ':id' => $id]);
        $swap->execute([':sort' => (int)$category['sort_order'], ':id' => (int)$neighbor['id']]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function replaceCategoryAssignments(int $categoryId, array $skus): void
{
    if (!getCategoryById($categoryId)) throw new InvalidArgumentException('Category does not exist.');
    $normalized = [];
    foreach ($skus as $sku) {
        $sku = trim((string)$sku);
        if (strlen($sku) > 190) throw new InvalidArgumentException('Assigned SKUs must be 190 characters or fewer.');
        if ($sku !== '') $normalized[$sku] = true;
    }
    $db = getDb();
    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM category_product_assignments WHERE category_id = :id')->execute([':id' => $categoryId]);
        $insert = $db->prepare('INSERT INTO category_product_assignments (category_id, product_sku) VALUES (:id, :sku)');
        foreach (array_keys($normalized) as $sku) $insert->execute([':id' => $categoryId, ':sku' => $sku]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function getCategoryAssignments(int $categoryId, bool $includeChildren = false): array
{
    $db = getDb();
    if ($includeChildren) {
        $stmt = $db->prepare('SELECT DISTINCT a.product_sku FROM category_product_assignments a JOIN categories c ON c.id = a.category_id WHERE c.id = :category_id OR c.parent_id = :parent_id ORDER BY a.product_sku');
        $stmt->execute([':category_id' => $categoryId, ':parent_id' => $categoryId]);
    } else {
        $stmt = $db->prepare('SELECT product_sku FROM category_product_assignments WHERE category_id = :id ORDER BY product_sku');
        $stmt->execute([':id' => $categoryId]);
    }
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function filterProductsByCategory(array $products, int $categoryId): array
{
    $category = getCategoryById($categoryId);
    if (!$category) return [];
    $allowed = array_fill_keys(getCategoryAssignments($categoryId, $category['parent_id'] === null), true);
    return array_values(array_filter($products, function (array $product) use ($allowed): bool {
        return isset($allowed[(string)($product['name'] ?? '')]);
    }));
}

function getCategoryAssignmentStatus(): array
{
    $db = getDb();
    $isMySql = strtolower((string)$db->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';
    $skuMatch = $isMySql ? 'BINARY a.product_sku = BINARY p.name' : 'a.product_sku = p.name';
    $unassigned = $db->query('SELECT p.name, p.description FROM products p LEFT JOIN category_product_assignments a ON ' . $skuMatch . ' WHERE a.product_sku IS NULL ORDER BY p.name')->fetchAll(PDO::FETCH_ASSOC);
    $stale = $db->query('SELECT DISTINCT a.product_sku FROM category_product_assignments a LEFT JOIN products p ON ' . $skuMatch . ' WHERE p.id IS NULL ORDER BY a.product_sku')->fetchAll(PDO::FETCH_COLUMN);
    return ['unassigned' => $unassigned, 'stale' => array_map('strval', $stale)];
}

function resolveCategoryImage(array $category, ?array $parent = null): string
{
    foreach ([$category['image_path'] ?? ''] as $path) {
        if (strpos((string)$path, 'category-upload:') === 0) {
            $filename = basename(substr((string)$path, strlen('category-upload:')));
            if ($filename !== '' && is_file(getCategoryImageStorageDirectory() . DIRECTORY_SEPARATOR . $filename)) {
                return getCategoryImageBaseUrl() . '/' . rawurlencode($filename);
            }
            continue;
        }
        $path = ltrim((string)$path, '/');
        if ($path !== '' && is_file(__DIR__ . '/../' . $path)) return '/' . $path;
    }
    $productImage = getFirstCategoryProductImage((int)($category['id'] ?? 0), empty($category['parent_id']));
    if ($productImage !== null) return $productImage;
    if ($parent !== null) {
        $parentPath = $parent['image_path'] ?? '';
        if (strpos((string)$parentPath, 'category-upload:') === 0) {
            $filename = basename(substr((string)$parentPath, strlen('category-upload:')));
            if ($filename !== '' && is_file(getCategoryImageStorageDirectory() . DIRECTORY_SEPARATOR . $filename)) {
                return getCategoryImageBaseUrl() . '/' . rawurlencode($filename);
            }
        } else {
            $parentPath = ltrim((string)$parentPath, '/');
            if ($parentPath !== '' && is_file(__DIR__ . '/../' . $parentPath)) return '/' . $parentPath;
        }
    }
    return '/assets/images/DaytonaSupplyDSlogo.png';
}
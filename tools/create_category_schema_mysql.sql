-- Daytona Supply category taxonomy schema
-- Safe to run against an existing MySQL/MariaDB database.
-- This creates new tables only; it does not alter existing application tables.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS category_groups (
    id INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(128) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_category_groups_name (name),
    KEY idx_category_groups_order (active, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
    id INT NOT NULL AUTO_INCREMENT,
    group_id INT NOT NULL,
    parent_id INT NULL,
    name VARCHAR(128) NOT NULL,
    slug VARCHAR(160) NOT NULL,
    image_path VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    featured_homepage TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_slug (slug),
    KEY idx_categories_tree (group_id, parent_id, active, sort_order, id),
    CONSTRAINT fk_categories_group
        FOREIGN KEY (group_id) REFERENCES category_groups(id),
    CONSTRAINT fk_categories_parent
        FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS category_product_assignments (
    category_id INT NOT NULL,
    product_sku VARCHAR(190) NOT NULL,
    PRIMARY KEY (category_id, product_sku),
    KEY idx_category_assignments_sku (product_sku),
    CONSTRAINT fk_category_assignments_category
        FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Shared-hosting-compatible verification using ordinary SHOW statements.
SHOW TABLES LIKE 'category_groups';
SHOW TABLES LIKE 'categories';
SHOW TABLES LIKE 'category_product_assignments';

SHOW CREATE TABLE category_groups;
SHOW CREATE TABLE categories;
SHOW CREATE TABLE category_product_assignments;
ALTER TABLE `products`
  ADD COLUMN `qty` INT NULL DEFAULT NULL AFTER `description`,
  ADD COLUMN `qty_updated_at` DATETIME NULL DEFAULT NULL AFTER `qty`;

-- Rollback after restoring the previous code:
-- ALTER TABLE `products` DROP COLUMN `qty_updated_at`, DROP COLUMN `qty`;

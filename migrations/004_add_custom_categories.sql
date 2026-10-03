-- ============================================================================
-- Smart Split: Migration 004 - Custom Workspace Categories Taxonomy
-- ============================================================================

-- 1. Add group_id to categories table if not already present
ALTER TABLE `categories`
ADD COLUMN `group_id` BIGINT UNSIGNED NULL AFTER `id`,
ADD CONSTRAINT `fk_categories_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE;

-- 2. Modify slug to allow multiple workspaces to have same slug
ALTER TABLE `categories` DROP INDEX `slug`;
ALTER TABLE `categories` ADD INDEX `idx_categories_group_slug` (`group_id`, `slug`);

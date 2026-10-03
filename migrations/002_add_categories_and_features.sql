-- ============================================================================
-- Smart Split: Migration 002 - Categories, Recurring Rules & V2 Features
-- ============================================================================

-- 1. Categories Table
CREATE TABLE IF NOT EXISTS `categories` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `slug` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(80) NOT NULL,
    `icon` VARCHAR(10) NOT NULL,
    `color_hex` VARCHAR(7) NOT NULL DEFAULT '#475569',
    `is_system` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Seed Standard System Categories
INSERT IGNORE INTO `categories` (`slug`, `name`, `icon`, `color_hex`, `is_system`) VALUES
('general', 'General', '📦', '#475569', 1),
('food_dining', 'Food & Dining', '🍽️', '#d97706', 1),
('travel_transport', 'Travel & Transport', '✈️', '#2563eb', 1),
('housing_rent', 'Housing & Rent', '🏠', '#059669', 1),
('utilities_bills', 'Utilities & Bills', '⚡', '#7c3aed', 1),
('groceries', 'Groceries', '🛒', '#ea580c', 1),
('entertainment', 'Entertainment', '🎟️', '#db2777', 1);

-- 3. Add category_id to expenses table
ALTER TABLE `expenses` 
ADD COLUMN `category_id` BIGINT UNSIGNED NULL AFTER `split_type`,
ADD CONSTRAINT `fk_expenses_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL;

-- 4. Recurring Rules Table
CREATE TABLE IF NOT EXISTS `recurring_rules` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `group_id` BIGINT UNSIGNED NOT NULL,
    `title` VARCHAR(150) NOT NULL,
    `total_amount_cents` INT UNSIGNED NOT NULL,
    `split_type` ENUM('EQUAL', 'EXACT', 'PERCENTAGE', 'SHARES') NOT NULL DEFAULT 'EQUAL',
    `category_id` BIGINT UNSIGNED NULL,
    `frequency` ENUM('WEEKLY', 'BIWEEKLY', 'MONTHLY', 'YEARLY') NOT NULL DEFAULT 'MONTHLY',
    `next_run_date` DATE NOT NULL,
    `end_date` DATE NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by_member_id` BIGINT UNSIGNED NOT NULL,
    `payload_json` JSON NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_recurring_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_recurring_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_recurring_creator` FOREIGN KEY (`created_by_member_id`) REFERENCES `members` (`id`) ON DELETE RESTRICT,
    INDEX `idx_recurring_active_date` (`is_active`, `next_run_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

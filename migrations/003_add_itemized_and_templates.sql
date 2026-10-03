-- ============================================================================
-- Smart Split: Migration 003 - Itemized Line Items, Expense Templates,
-- Receipts & Member Financial Identifiers
-- ============================================================================

-- 1. Expense Items (Line Items on an Itemized Receipt)
CREATE TABLE IF NOT EXISTS `expense_items` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `expense_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(150) NOT NULL,
    `amount_cents` INT UNSIGNED NOT NULL,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_expense_items_expense` FOREIGN KEY (`expense_id`) REFERENCES `expenses` (`id`) ON DELETE CASCADE,
    INDEX `idx_expense_items_lookup` (`expense_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Expense Item Assignments (Which members consumed which line items)
CREATE TABLE IF NOT EXISTS `expense_item_assignments` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `item_id` BIGINT UNSIGNED NOT NULL,
    `member_id` BIGINT UNSIGNED NOT NULL,
    `amount_owed_cents` INT UNSIGNED NOT NULL,
    CONSTRAINT `fk_item_assign_item` FOREIGN KEY (`item_id`) REFERENCES `expense_items` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_item_assign_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `uq_item_member` UNIQUE (`item_id`, `member_id`),
    INDEX `idx_item_assign_member` (`member_id`, `item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Expense Templates (Reusable presets for recurring / standard expenses)
CREATE TABLE IF NOT EXISTS `expense_templates` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `group_id` BIGINT UNSIGNED NOT NULL,
    `title` VARCHAR(150) NOT NULL,
    `total_amount_cents` INT UNSIGNED NOT NULL DEFAULT 0,
    `split_type` ENUM('EQUAL', 'EXACT', 'PERCENTAGE', 'SHARES') NOT NULL DEFAULT 'EQUAL',
    `category_id` BIGINT UNSIGNED NULL,
    `payload_json` JSON NOT NULL,
    `created_by_member_id` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_templates_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_templates_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_templates_creator` FOREIGN KEY (`created_by_member_id`) REFERENCES `members` (`id`) ON DELETE RESTRICT,
    INDEX `idx_templates_group` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Receipt Attachments
CREATE TABLE IF NOT EXISTS `receipt_attachments` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `expense_id` BIGINT UNSIGNED NOT NULL,
    `file_name` VARCHAR(255) NOT NULL,
    `file_path` VARCHAR(255) NOT NULL,
    `file_size_bytes` INT UNSIGNED NOT NULL,
    `mime_type` VARCHAR(100) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_attachments_expense` FOREIGN KEY (`expense_id`) REFERENCES `expenses` (`id`) ON DELETE CASCADE,
    INDEX `idx_attachments_expense` (`expense_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Add Tax, Tip, Discount, and Notes to Expenses Table
ALTER TABLE `expenses`
ADD COLUMN `tax_cents` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `total_amount_cents`,
ADD COLUMN `tip_cents` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `tax_cents`,
ADD COLUMN `discount_cents` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `tip_cents`,
ADD COLUMN `notes` TEXT NULL AFTER `is_deleted`;

-- 6. Update Split Type ENUMs to Support ITEMIZED
ALTER TABLE `expenses` MODIFY COLUMN `split_type` ENUM('EQUAL', 'EXACT', 'PERCENTAGE', 'SHARES', 'ITEMIZED') NOT NULL DEFAULT 'EQUAL';
ALTER TABLE `recurring_rules` MODIFY COLUMN `split_type` ENUM('EQUAL', 'EXACT', 'PERCENTAGE', 'SHARES', 'ITEMIZED') NOT NULL DEFAULT 'EQUAL';
ALTER TABLE `expense_templates` MODIFY COLUMN `split_type` ENUM('EQUAL', 'EXACT', 'PERCENTAGE', 'SHARES', 'ITEMIZED') NOT NULL DEFAULT 'EQUAL';

-- 7. Add UPI, Color, and Email to Members Table
ALTER TABLE `members`
ADD COLUMN `upi_id` VARCHAR(80) NULL AFTER `name`,
ADD COLUMN `color_hex` VARCHAR(7) NOT NULL DEFAULT '#2563eb' AFTER `upi_id`,
ADD COLUMN `email` VARCHAR(120) NULL AFTER `color_hex`;


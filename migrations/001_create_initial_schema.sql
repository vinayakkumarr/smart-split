-- ============================================================================
-- Smart Split: Initial Database Schema Migration
-- Storage Engine: InnoDB
-- Charset: utf8mb4 / utf8mb4_unicode_ci
-- Currency Precision: Strict Integer Cents (INT / BIGINT UNSIGNED)
-- ============================================================================

-- 1. Groups Table
CREATE TABLE IF NOT EXISTS `groups` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `uuid` CHAR(36) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `currency_code` CHAR(3) NOT NULL DEFAULT 'INR',
    `invite_token` VARCHAR(64) NOT NULL,
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `uq_groups_uuid` UNIQUE (`uuid`),
    CONSTRAINT `uq_groups_invite_token` UNIQUE (`invite_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Group Members Table
CREATE TABLE IF NOT EXISTS `members` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `group_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(60) NOT NULL,
    `member_token` VARCHAR(64) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_members_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
    CONSTRAINT `uq_members_token` UNIQUE (`member_token`),
    CONSTRAINT `uq_group_member_name` UNIQUE (`group_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Expenses Table
CREATE TABLE IF NOT EXISTS `expenses` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `group_id` BIGINT UNSIGNED NOT NULL,
    `title` VARCHAR(150) NOT NULL,
    `total_amount_cents` INT UNSIGNED NOT NULL,
    `split_type` ENUM('EQUAL', 'EXACT', 'PERCENTAGE', 'SHARES') NOT NULL DEFAULT 'EQUAL',
    `expense_date` DATE NOT NULL,
    `created_by_member_id` BIGINT UNSIGNED NOT NULL,
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_expenses_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_expenses_creator` FOREIGN KEY (`created_by_member_id`) REFERENCES `members` (`id`) ON DELETE RESTRICT,
    INDEX `idx_expenses_group_lookup` (`group_id`, `is_deleted`, `expense_date` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Expense Payers Table (Supports Multi-Payer per Expense)
CREATE TABLE IF NOT EXISTS `expense_payers` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `expense_id` BIGINT UNSIGNED NOT NULL,
    `member_id` BIGINT UNSIGNED NOT NULL,
    `amount_paid_cents` INT UNSIGNED NOT NULL,
    CONSTRAINT `fk_payers_expense` FOREIGN KEY (`expense_id`) REFERENCES `expenses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_payers_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `uq_expense_payer` UNIQUE (`expense_id`, `member_id`),
    INDEX `idx_payers_member_lookup` (`member_id`, `expense_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Expense Splits Table (Stores Individual Debtor Allocations)
CREATE TABLE IF NOT EXISTS `expense_splits` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `expense_id` BIGINT UNSIGNED NOT NULL,
    `member_id` BIGINT UNSIGNED NOT NULL,
    `amount_owed_cents` INT UNSIGNED NOT NULL,
    `split_value` DECIMAL(8, 4) NULL COMMENT 'Original percentage (e.g. 33.3333) or share units (e.g. 2.0)',
    CONSTRAINT `fk_splits_expense` FOREIGN KEY (`expense_id`) REFERENCES `expenses` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_splits_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `uq_expense_split` UNIQUE (`expense_id`, `member_id`),
    INDEX `idx_splits_member_lookup` (`member_id`, `expense_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Settlements Table (Direct Debt Payments Between Members)
CREATE TABLE IF NOT EXISTS `settlements` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `group_id` BIGINT UNSIGNED NOT NULL,
    `payer_member_id` BIGINT UNSIGNED NOT NULL,
    `payee_member_id` BIGINT UNSIGNED NOT NULL,
    `amount_cents` INT UNSIGNED NOT NULL,
    `settled_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `notes` VARCHAR(255) NULL,
    `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_settlements_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_settlements_payer` FOREIGN KEY (`payer_member_id`) REFERENCES `members` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_settlements_payee` FOREIGN KEY (`payee_member_id`) REFERENCES `members` (`id`) ON DELETE RESTRICT,
    INDEX `idx_settlements_group_lookup` (`group_id`, `is_deleted`, `settled_date` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Activity Logs Table (Full Audit Trail)
CREATE TABLE IF NOT EXISTS `activity_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `group_id` BIGINT UNSIGNED NOT NULL,
    `actor_member_id` BIGINT UNSIGNED NULL,
    `action` VARCHAR(50) NOT NULL COMMENT 'e.g. EXPENSE_ADDED, EXPENSE_DELETED, SETTLEMENT_RECORDED',
    `entity_type` VARCHAR(30) NOT NULL COMMENT 'e.g. expenses, settlements, members',
    `entity_id` BIGINT UNSIGNED NOT NULL,
    `payload_json` JSON NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_logs_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
    INDEX `idx_logs_group_recent` (`group_id`, `created_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

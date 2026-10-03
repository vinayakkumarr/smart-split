-- Smart Split V2 — Migration 010: Idempotency Keys Storage
-- Enables server-authoritative deduplication for financial transactions.

CREATE TABLE IF NOT EXISTS `idempotency_keys` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `group_id` BIGINT UNSIGNED NOT NULL,
    `idempotency_key` VARCHAR(128) NOT NULL,
    `expense_id` BIGINT UNSIGNED NOT NULL,
    `request_hash` VARCHAR(64) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_group_idempotency` (`group_id`, `idempotency_key`),
    INDEX `idx_expense_id` (`expense_id`),
    INDEX `idx_created_at` (`created_at`),
    CONSTRAINT `fk_idempotency_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_idempotency_expense` FOREIGN KEY (`expense_id`) REFERENCES `expenses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

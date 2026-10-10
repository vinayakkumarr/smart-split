-- Smart Split V2 — Migration 013: Settlement Idempotency Keys Storage
-- Enables server-authoritative deduplication for debt settlement transactions.

CREATE TABLE IF NOT EXISTS `settlement_idempotency_keys` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `group_id` BIGINT UNSIGNED NOT NULL,
    `idempotency_key` VARCHAR(128) NOT NULL,
    `settlement_id` BIGINT UNSIGNED NOT NULL,
    `request_hash` VARCHAR(64) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_group_settlement_idempotency` (`group_id`, `idempotency_key`),
    INDEX `idx_settlement_id` (`settlement_id`),
    INDEX `idx_created_at` (`created_at`),
    CONSTRAINT `fk_settlement_idemp_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_settlement_idemp_settlement` FOREIGN KEY (`settlement_id`) REFERENCES `settlements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

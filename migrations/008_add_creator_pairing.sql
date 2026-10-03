-- Migration 008: Add Secure Guest Creator Device Pairing Codes (P0)
-- Enables single-use, time-limited cryptographic pairing between devices for guest workspace creators.

CREATE TABLE IF NOT EXISTS `creator_pairing_codes` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `group_id` BIGINT UNSIGNED NOT NULL,
    `code_hash` VARCHAR(64) NOT NULL UNIQUE,
    `expires_at` DATETIME NOT NULL,
    `is_used` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_pairing_lookup` (`code_hash`, `is_used`, `expires_at`),
    INDEX `idx_pairing_group` (`group_id`),
    CONSTRAINT `fk_pairing_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

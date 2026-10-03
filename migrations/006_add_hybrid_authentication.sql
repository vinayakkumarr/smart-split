-- ============================================================================
-- Smart Split: Migration 006 - Hybrid Progressive Authentication
-- Storage Engine: InnoDB | Charset: utf8mb4 / utf8mb4_unicode_ci
-- ============================================================================

-- 1. Users Table (Independent Identity Container)
CREATE TABLE IF NOT EXISTS `users` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(191) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `display_name` VARCHAR(80) NOT NULL,
    `recovery_code_hash` VARCHAR(255) NOT NULL COMMENT 'Bcrypt hash of alphanumeric recovery key',
    `avatar_emoji` VARCHAR(10) NOT NULL DEFAULT '👤',
    `avatar_color` VARCHAR(7) NOT NULL DEFAULT '#2563eb',
    `failed_login_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `locked_until` DATETIME NULL DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `uq_users_email` UNIQUE (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. User Sessions Table (Stateful Server-Side Token Validation)
CREATE TABLE IF NOT EXISTS `user_sessions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `session_token_hash` CHAR(64) NOT NULL COMMENT 'SHA-256 hash of 64-hex-character cookie token',
    `ip_address` VARCHAR(45) NULL,
    `user_agent` VARCHAR(255) NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_active_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `uq_sessions_token_hash` UNIQUE (`session_token_hash`),
    INDEX `idx_sessions_expiry_lookup` (`expires_at`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Link members to users (Nullable Progressive Claiming)
ALTER TABLE `members`
ADD COLUMN `user_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `group_id`,
ADD CONSTRAINT `fk_members_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
ADD INDEX `idx_members_user_group` (`user_id`, `group_id`);

-- 4. Link groups to owner users (Nullable Progressive Ownership)
ALTER TABLE `groups`
ADD COLUMN `owner_user_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `invite_token`,
ADD CONSTRAINT `fk_groups_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

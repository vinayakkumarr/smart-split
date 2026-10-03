-- Migration 007: Add Persistent Rate Limiting Table (SEC-07)
-- Supports atomic increment and time-window expiration across distributed/concurrent requests.

CREATE TABLE IF NOT EXISTS `rate_limits` (
    `key_hash` VARCHAR(64) NOT NULL PRIMARY KEY,
    `attempts` INT UNSIGNED NOT NULL DEFAULT 1,
    `window_expires_at` INT UNSIGNED NOT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_window_expires` (`window_expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

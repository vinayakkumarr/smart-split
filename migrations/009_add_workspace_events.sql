-- Smart Split V2 — Migration 009: Real-Time Collaboration Workspace Events Log
-- Enables Server-Sent Events (SSE) real-time streaming with zero runtime Redis/WebSocket dependencies.

CREATE TABLE IF NOT EXISTS `workspace_events` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `group_id` INT NOT NULL,
    `event_type` VARCHAR(64) NOT NULL,
    `entity_id` INT NULL,
    `version` BIGINT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_group_event_id` (`group_id`, `id`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

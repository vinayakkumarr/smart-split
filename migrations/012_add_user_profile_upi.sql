-- ============================================================================
-- Smart Split: Migration 012 - Add Personal UPI Identity to Users Profile
-- Storage Engine: InnoDB | Charset: utf8mb4 / utf8mb4_unicode_ci
-- ============================================================================

ALTER TABLE `users`
ADD COLUMN `upi_id` VARCHAR(80) NULL DEFAULT NULL AFTER `avatar_color`;

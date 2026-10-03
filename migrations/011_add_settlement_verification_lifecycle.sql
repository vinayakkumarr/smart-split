-- Smart Split V2 — Migration 011: Settlement Verification Lifecycle & Attribution
-- Adds role-aware settlement lifecycle states, payment attribution, and immutable reversal tracking.

ALTER TABLE `settlements`
    ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'CONFIRMED' AFTER `amount_cents`,
    ADD COLUMN `recorded_by_member_id` BIGINT UNSIGNED NULL AFTER `status`,
    ADD COLUMN `payment_method` VARCHAR(30) NOT NULL DEFAULT 'OTHER' AFTER `recorded_by_member_id`,
    ADD COLUMN `reference_id` VARCHAR(100) NULL AFTER `payment_method`,
    ADD COLUMN `confirmed_by_member_id` BIGINT UNSIGNED NULL AFTER `notes`,
    ADD COLUMN `confirmed_at` DATETIME NULL AFTER `confirmed_by_member_id`,
    ADD COLUMN `disputed_by_member_id` BIGINT UNSIGNED NULL AFTER `confirmed_at`,
    ADD COLUMN `disputed_at` DATETIME NULL AFTER `disputed_by_member_id`,
    ADD COLUMN `dispute_reason` VARCHAR(255) NULL AFTER `disputed_at`,
    ADD COLUMN `reversed_by_member_id` BIGINT UNSIGNED NULL AFTER `dispute_reason`,
    ADD COLUMN `reversed_at` DATETIME NULL AFTER `reversed_by_member_id`,
    ADD COLUMN `reversal_reason` VARCHAR(255) NULL AFTER `reversed_at`,
    ADD COLUMN `reversal_of_id` BIGINT UNSIGNED NULL AFTER `reversal_reason`,
    ADD CONSTRAINT `fk_settlements_recorded_by` FOREIGN KEY (`recorded_by_member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `fk_settlements_confirmed_by` FOREIGN KEY (`confirmed_by_member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `fk_settlements_disputed_by` FOREIGN KEY (`disputed_by_member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `fk_settlements_reversed_by` FOREIGN KEY (`reversed_by_member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `fk_settlements_reversal_of` FOREIGN KEY (`reversal_of_id`) REFERENCES `settlements` (`id`) ON DELETE SET NULL,
    ADD INDEX `idx_settlements_status` (`group_id`, `is_deleted`, `status`),
    ADD INDEX `idx_settlements_reversal` (`reversal_of_id`);

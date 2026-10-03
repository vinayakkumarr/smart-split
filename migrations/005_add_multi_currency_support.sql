-- ============================================================================
-- Smart Split: Migration 005 - Multi-Currency & Cross-Exchange Rate Support
-- ============================================================================

-- Add original foreign currency tracking fields to expenses table
ALTER TABLE `expenses`
ADD COLUMN `original_currency_code` VARCHAR(3) NULL DEFAULT NULL AFTER `discount_cents`,
ADD COLUMN `original_amount_cents` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `original_currency_code`,
ADD COLUMN `exchange_rate` DECIMAL(14, 6) NULL DEFAULT NULL AFTER `original_amount_cents`;

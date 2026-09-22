-- YSMS Migration: Recovery in VLSFO / LSMGO cards (Dashboard enhancement)
-- Run this once on your live database (phpMyAdmin > SQL tab, or via CLI).
--
-- Note: the `recovery_amount` column (used for the existing Total/This Month
-- Recovery cards, reading TABLES 54B!O21) is assumed to already exist on your
-- live `surveys` table. If it does not exist yet, uncomment the first
-- statement below as well.

-- ALTER TABLE `surveys` ADD COLUMN `recovery_amount` DECIMAL(12,3) DEFAULT NULL AFTER `overdue_days`;

ALTER TABLE `surveys`
    ADD COLUMN `vlsfo_recovery` DECIMAL(12,3) DEFAULT NULL,
    ADD COLUMN `lsmgo_recovery` DECIMAL(12,3) DEFAULT NULL;

-- Recovery in HSFO card (TABLES 54B!N20). The app also adds this column
-- itself on demand (SHOW COLUMNS safety net in index.php, report_detail.php,
-- completed_detail.php and ajax/upload_handler.php), so running this by
-- hand is optional — it's here for reference only.
ALTER TABLE `surveys`
    ADD COLUMN `hsfo_recovery` DECIMAL(12,3) DEFAULT NULL;

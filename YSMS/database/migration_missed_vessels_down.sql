-- Roll back the Missed Vessels module (only if it must be removed).
-- Nothing is deleted: every Missed vessel goes back to Pending Vessels first (same row, same id),
-- then the 'Missed' value is taken off the status ENUM. The two tables are KEPT as the record of
-- what happened; drop them by hand only after a backup, if ever.
--
-- Check first:  SELECT COUNT(*) FROM surveys WHERE status = 'Missed';
--               SHOW COLUMNS FROM surveys LIKE 'status';   (list every other value below)

UPDATE `surveys` SET `status` = 'Pending Vessel' WHERE `status` = 'Missed';

ALTER TABLE `surveys`
    MODIFY COLUMN `status` ENUM('Pending Vessel','Pending Report','Completed','Cancelled') NULL DEFAULT 'Pending Vessel';

-- Optional, after a backup:
-- DROP TABLE `survey_status_history`;
-- DROP TABLE `survey_missed`;

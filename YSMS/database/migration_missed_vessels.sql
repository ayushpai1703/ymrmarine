-- Missed Vessels module (missed.php, ajax/missed_vessels.php, includes/missed_vessels.php).
-- Purely additive: one new status value and two new tables. No existing row, column, index or
-- foreign key is changed or removed. Safe to run more than once.
--
-- The same changes also run automatically the first time missed.php or ajax/missed_vessels.php
-- is opened (missedEnsureSchema()), the same way the other YSMS modules create their tables.
-- Running this file by hand is for environments where the app user has no ALTER/CREATE rights,
-- or to apply it in a planned window.
--
-- STEP 1 — check the current status values FIRST:
--     SHOW COLUMNS FROM surveys LIKE 'status';
-- The ALTER below assumes they are exactly
--     'Pending Vessel','Pending Report','Completed','Cancelled'   (default 'Pending Vessel', NULL allowed)
-- If the database has any other value, add it to the list below too — an ENUM change must list
-- every existing value. If 'Missed' is already there, skip STEP 2.

-- STEP 2 — allow the new status (appends 'Missed'; existing values keep their meaning)
ALTER TABLE `surveys`
    MODIFY COLUMN `status` ENUM('Pending Vessel','Pending Report','Completed','Cancelled','Missed') NULL DEFAULT 'Pending Vessel';

-- STEP 3 — the current reason per missed vessel (kept after a restore)
CREATE TABLE IF NOT EXISTS `survey_missed` (
    `survey_id` INT(11) NOT NULL,
    `remarks` TEXT NOT NULL,
    `marked_by` INT(11) DEFAULT NULL,
    `marked_at` DATETIME NOT NULL,
    `remarks_updated_by` INT(11) DEFAULT NULL,
    `remarks_updated_at` DATETIME DEFAULT NULL,
    `restored_by` INT(11) DEFAULT NULL,
    `restored_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`survey_id`),
    KEY `idx_survey_missed_marked_at` (`marked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STEP 4 — append-only status history (marked_as_missed / remark_updated / restored_to_pending)
CREATE TABLE IF NOT EXISTS `survey_status_history` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `survey_id` INT(11) NOT NULL,
    `action_type` VARCHAR(40) NOT NULL,
    `previous_status` VARCHAR(40) DEFAULT NULL,
    `new_status` VARCHAR(40) DEFAULT NULL,
    `remarks` TEXT DEFAULT NULL,
    `previous_remarks` TEXT DEFAULT NULL,
    `action_by` INT(11) DEFAULT NULL,
    `action_at` DATETIME NOT NULL,
    `request_key` CHAR(32) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_survey_status_history_request` (`request_key`),
    KEY `idx_survey_status_history_survey` (`survey_id`, `action_at`),
    KEY `idx_survey_status_history_action` (`action_type`, `action_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

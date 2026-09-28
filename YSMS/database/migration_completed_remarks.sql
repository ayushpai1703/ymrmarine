-- Completed report Remarks + optional attachments (completed_detail.php).
-- Purely additive — no existing table, column, or FK is touched. These same
-- CREATE TABLE IF NOT EXISTS statements also run automatically the first
-- time completed_detail.php loads (see ensureCompletedRemarksTables() in
-- includes/completed_remarks_helpers.php); running this file by hand is
-- optional, kept for reference / environments without runtime CREATE rights.

CREATE TABLE IF NOT EXISTS `completed_remarks` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `survey_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `remarks` TEXT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_completed_remarks_survey` (`survey_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `completed_remark_attachments` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `remark_id` INT(11) NOT NULL,
    `file_name` VARCHAR(255) NOT NULL,
    `file_path` VARCHAR(255) NOT NULL,
    `file_size` INT(11) DEFAULT NULL,
    `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_completed_remark_attachments_remark` (`remark_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

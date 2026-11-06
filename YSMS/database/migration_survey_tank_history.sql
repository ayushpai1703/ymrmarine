-- Survey Report tank version history (Admin only): every saved version of a tank's Sounding,
-- Volume, Density and Temperature. Written by ajax/survey_reports.php (save / generate) in the same
-- transaction as the report's current values; rows are never updated or deleted by the app.
--
-- Applied automatically and idempotently at runtime (srEnsureHistory(), includes/survey_reports.php).
-- Running this file by hand is optional and safe to repeat. Additive only: no existing table or
-- column is changed, no data is touched. The older tables survey_report_tank_saved and
-- survey_report_tank_log are left as they are (no longer written).
--
-- Reverse: database/migration_survey_tank_history_down.sql

CREATE TABLE IF NOT EXISTS `survey_tank_entry_history` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `report_id` int(11) NOT NULL COMMENT 'survey_reports.id',
    `survey_id` int(11) DEFAULT NULL COMMENT 'surveys.id (the vessel)',
    `grade` varchar(10) NOT NULL COMMENT 'vlsfo, ulsfo, hsfo, lsmgo, hsmgo',
    `tank_id` varchar(40) NOT NULL COMMENT 'stable id of the tank row in the report',
    `tank_name` varchar(100) NOT NULL COMMENT 'name at the time of this version',
    `version_number` int(11) NOT NULL COMMENT '1, 2, 3 ... per tank',
    `sounding` varchar(30) DEFAULT NULL COMMENT 'metres (typed, or reference height - ullage); GAUGE etc. as text',
    `volume` decimal(14,3) DEFAULT NULL,
    `density` decimal(8,4) DEFAULT NULL,
    `temperature` decimal(6,1) DEFAULT NULL,
    `changed_by` int(11) DEFAULT NULL COMMENT 'users.id',
    `changed_at` datetime NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_steh_tank_version` (`report_id`, `tank_id`, `version_number`),
    KEY `idx_steh_survey` (`survey_id`),
    KEY `idx_steh_tank` (`tank_id`),
    CONSTRAINT `fk_steh_report` FOREIGN KEY (`report_id`) REFERENCES `survey_reports` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Vessels Data module (vessels_data.php): change history of every edit made there.
--
-- Applied automatically and idempotently at runtime by includes/vessels_data.php
-- (vdHistoryTable()), the same pattern the other migration_*.sql files here use. Running this
-- file by hand is optional and safe to repeat.
--
-- Additive only: no existing table or column is changed. Rows are only ever inserted
-- (never updated or deleted by the application). No foreign key to surveys, so deleting a
-- survey from Admin Controls keeps working exactly as before.
--
-- Reverse: database/migration_vessel_change_history_down.sql

CREATE TABLE IF NOT EXISTS `vessel_change_history` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `survey_id` int(11) NOT NULL COMMENT 'surveys.id',
    `user_id` int(11) DEFAULT NULL COMMENT 'users.id of who made the change',
    `field_name` varchar(50) NOT NULL COMMENT 'vessel, imo, report_no, client, agent, type, port, surveyor, assigned, appointment, completed, report_date, remarks, notes, status, recovery_amount',
    `old_value` text DEFAULT NULL COMMENT 'readable value before (names, not ids)',
    `new_value` text DEFAULT NULL COMMENT 'readable value after',
    `source` varchar(20) NOT NULL DEFAULT 'grid' COMMENT 'grid | bulk | import',
    `changed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_vch_survey` (`survey_id`, `changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The grid's per-user layout (column order, hidden columns, widths, sort, page size) uses the
-- existing `list_grid_prefs` table (page = 'vessels_data'); nothing new is needed for it.

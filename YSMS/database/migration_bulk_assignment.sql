-- Bulk Vessel Assignment via Excel Upload — audit/history tables.
-- Purely additive: no existing table, column, or FK is touched. These same
-- CREATE TABLE IF NOT EXISTS statements also run automatically the first
-- time bulk_assign_vessels.php / bulk_assignment_history.php loads (see
-- ensureBulkAssignmentTables() in includes/bulk_assign_helpers.php), so
-- running this file by hand is optional — it's provided for reference and
-- for environments where the app user has no ALTER/CREATE privilege at
-- runtime.

CREATE TABLE IF NOT EXISTS `bulk_assignment_batches` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `batch_token` VARCHAR(64) NOT NULL,
    `uploaded_by` INT(11) NOT NULL,
    `original_filename` VARCHAR(255) NOT NULL,
    `stored_path` VARCHAR(255) NOT NULL,
    `sheet_name` VARCHAR(255) DEFAULT NULL,
    `total_rows` INT(11) NOT NULL DEFAULT 0,
    `valid_rows` INT(11) NOT NULL DEFAULT 0,
    `duplicate_rows` INT(11) NOT NULL DEFAULT 0,
    `already_assigned_rows` INT(11) NOT NULL DEFAULT 0,
    `error_rows` INT(11) NOT NULL DEFAULT 0,
    `inserted_rows` INT(11) NOT NULL DEFAULT 0,
    `status` VARCHAR(20) NOT NULL DEFAULT 'uploaded',
    `ip_address` VARCHAR(64) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_bulk_batch_token` (`batch_token`),
    KEY `idx_bulk_batch_user` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bulk_assignment_rows` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `batch_id` INT(11) NOT NULL,
    `excel_row_number` INT(11) NOT NULL,
    `vessel_name` VARCHAR(255) DEFAULT NULL,
    `imo_number` VARCHAR(20) DEFAULT NULL,
    `surveyor_label` VARCHAR(255) DEFAULT NULL,
    `client_label` VARCHAR(255) DEFAULT NULL,
    `status` VARCHAR(40) NOT NULL,
    `message` TEXT DEFAULT NULL,
    `survey_id` INT(11) DEFAULT NULL,
    `report_number` VARCHAR(50) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bulk_rows_batch` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

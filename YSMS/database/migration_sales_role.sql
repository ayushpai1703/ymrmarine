-- Sales role (Phase 1): includes/sales.php. Backward compatible: one new role row, two new
-- tables, two nullable columns on `clients`. Also done at runtime by salesEnsure() when missing.
-- Reverse: migration_sales_role_down.sql

INSERT INTO roles (name) SELECT 'Sales' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM roles WHERE name = 'Sales');

-- Monthly targets the Admin sets for a sales person (admin_sales.php)
CREATE TABLE IF NOT EXISTS `sales_targets` (
    `user_id` int(11) NOT NULL,
    `ym` char(7) NOT NULL COMMENT 'YYYY-MM',
    `surveys` int(11) DEFAULT NULL COMMENT 'surveys in the month (by assign date, not cancelled)',
    `new_clients` int(11) DEFAULT NULL COMMENT 'clients added in the month',
    `updated_by` int(11) DEFAULT NULL,
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `ym`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Survey requests made by a sales person (assign_vessel.php, Sales role)
CREATE TABLE IF NOT EXISTS `sales_requests` (
    `survey_id` int(11) NOT NULL,
    `user_id` int(11) NOT NULL,
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`survey_id`),
    KEY `idx_sales_requests_user` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- When / by whom a client was added (existing clients keep NULL: their date is unknown)
ALTER TABLE `clients` ADD COLUMN `created_at` DATETIME NULL;
ALTER TABLE `clients` MODIFY `created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE `clients` ADD COLUMN `created_by` INT NULL;

-- Invoices module (invoices.php).
--
-- Applied automatically and idempotently at runtime by includes/invoice_helpers.php
-- (invoiceEnsureTable() + invoiceBackfill()), the same pattern used by the other
-- migration_*.sql files here. Running this file by hand is optional and safe to repeat.
--
-- `survey_id` is the vessel / report reference: in this schema one `surveys` row is both
-- the vessel's assignment and its survey report (report_number), so it is stored once.
-- No existing table or column is changed. Invoice status lives only in this table.
-- No foreign key to surveys: deleting a survey from Admin Controls keeps working exactly as
-- before; the invoice keeps its own copy of the vessel name and report number.

CREATE TABLE IF NOT EXISTS `invoices` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `survey_id` int(11) NOT NULL COMMENT 'surveys.id (vessel + report record)',
    `vessel_name` varchar(255) DEFAULT NULL COMMENT 'copy at registration, shown if the survey is ever deleted',
    `report_number` varchar(50) DEFAULT NULL COMMENT 'copy at registration',
    `invoice_status` enum('Pending for CP Prices','Pending for Cost','Pending for Other Reason','Completed') NOT NULL DEFAULT 'Pending for CP Prices',
    `status_updated_by` int(11) DEFAULT NULL,
    `status_updated_at` datetime DEFAULT NULL,
    `invoice_no` varchar(100) DEFAULT NULL,
    `invoice_date` date DEFAULT NULL,
    `invoice_amount` decimal(14,2) DEFAULT NULL,
    `invoice_notes` varchar(500) DEFAULT NULL,
    `file_name` varchar(255) DEFAULT NULL COMMENT 'original file name',
    `file_path` varchar(255) DEFAULT NULL COMMENT 'uploads/invoices/… (Cloud Storage bucket)',
    `file_size` int(11) DEFAULT NULL,
    `uploaded_by` int(11) DEFAULT NULL,
    `uploaded_at` datetime DEFAULT NULL,
    `registered_via` varchar(20) NOT NULL DEFAULT 'upload' COMMENT 'upload | backfill',
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_invoices_survey` (`survey_id`),
    KEY `idx_invoices_status` (`invoice_status`),
    KEY `idx_invoices_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Backfill: every vessel already in Pending Reports or Completed gets its invoice row
-- (status Pending for CP Prices). INSERT IGNORE + the unique survey_id make this safe to
-- run any number of times; survey rows are only read.
INSERT IGNORE INTO `invoices` (`survey_id`, `vessel_name`, `report_number`, `invoice_status`, `registered_via`)
SELECT s.`id`, s.`vessel_name`, s.`report_number`, 'Pending for CP Prices', 'backfill'
FROM `surveys` s
WHERE s.`status` IN ('Pending Report', 'Completed');

-- Invoice numbers: YMR-<client short code>-<n>, n from 1000, +1 per vessel invoice
-- (includes/invoice_helpers.php invoiceAllocateNumber(); also created at runtime).
CREATE TABLE IF NOT EXISTS `invoice_number_seq` (
    `id` tinyint NOT NULL,
    `next_no` int NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO `invoice_number_seq` (`id`, `next_no`) VALUES (1, 1000);

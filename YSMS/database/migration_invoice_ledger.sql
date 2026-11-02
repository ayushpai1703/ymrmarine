-- Invoice Airtable (invoices_airtable.php): payment tracking for the invoices of the Invoices
-- module. Backward compatible: only new tables + two nullable columns on `invoices`. Also run
-- at runtime by includes/invoice_ledger.php (ledgerEnsureTables) and
-- includes/invoice_helpers.php (invoiceEnsureGenColumns) when missing.

-- Recovery invoice's own date and total (vessel invoice uses invoice_date / invoice_amount).
ALTER TABLE `invoices`
    ADD COLUMN `rec_invoice_date` DATE NULL,
    ADD COLUMN `rec_invoice_amount` DECIMAL(14,2) NULL;

-- Per invoice document (vessel invoice / recovery invoice): due date, remarks, cancelled.
CREATE TABLE IF NOT EXISTS `invoice_accounts` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `invoice_id` int(11) NOT NULL COMMENT 'invoices.id',
    `kind` enum('invoice','recovery') NOT NULL DEFAULT 'invoice',
    `due_date` date DEFAULT NULL,
    `remarks` varchar(1000) DEFAULT NULL,
    `cancelled` tinyint(1) NOT NULL DEFAULT 0,
    `cancelled_reason` varchar(500) DEFAULT NULL,
    `updated_by` int(11) DEFAULT NULL,
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_invoice_accounts_doc` (`invoice_id`, `kind`),
    KEY `idx_invoice_accounts_due` (`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Payment transactions: never overwritten; a wrong entry is voided (status = 'void').
CREATE TABLE IF NOT EXISTS `invoice_payments` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `invoice_id` int(11) NOT NULL COMMENT 'invoices.id',
    `kind` enum('invoice','recovery') NOT NULL DEFAULT 'invoice',
    `payment_date` date NOT NULL,
    `amount` decimal(14,2) NOT NULL,
    `payment_mode` varchar(30) NOT NULL,
    `reference` varchar(120) DEFAULT NULL,
    `transaction_id` varchar(120) DEFAULT NULL,
    `remarks` varchar(500) DEFAULT NULL,
    `receipt_name` varchar(255) DEFAULT NULL,
    `receipt_path` varchar(255) DEFAULT NULL,
    `status` enum('success','void') NOT NULL DEFAULT 'success',
    `void_reason` varchar(500) DEFAULT NULL,
    `voided_by` int(11) DEFAULT NULL,
    `voided_at` datetime DEFAULT NULL,
    `request_id` char(32) DEFAULT NULL COMMENT 'one per Add Payment form; stops double saves',
    `created_by` int(11) DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_invoice_payments_request` (`request_id`),
    UNIQUE KEY `uq_invoice_payments_txn` (`invoice_id`, `kind`, `transaction_id`),
    KEY `idx_invoice_payments_doc` (`invoice_id`, `kind`, `status`),
    KEY `idx_invoice_payments_date` (`payment_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Activity log (edits, payments, voids, cancel / restore).
CREATE TABLE IF NOT EXISTS `invoice_activity` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `invoice_id` int(11) NOT NULL,
    `kind` enum('invoice','recovery') NOT NULL DEFAULT 'invoice',
    `user_id` int(11) DEFAULT NULL,
    `action` varchar(40) NOT NULL,
    `details` varchar(1000) DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_invoice_activity_doc` (`invoice_id`, `kind`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Each user's grid layout (column order, widths, hidden columns, sort).
CREATE TABLE IF NOT EXISTS `invoice_grid_prefs` (
    `user_id` int(11) NOT NULL,
    `prefs` text NOT NULL,
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

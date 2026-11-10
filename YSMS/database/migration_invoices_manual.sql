-- Invoices: Add Invoice (manual entry) and Edit.
--
-- Applied automatically and idempotently at runtime by includes/invoice_helpers.php
-- (invoiceEnsureGenColumns(), invoiceEnsureNumberKey()), like the other migration_*.sql files.
-- Running this by hand is optional. No existing row is changed; no table is added.

-- A manual invoice need not belong to a survey (still UNIQUE: one entry per survey at most).
ALTER TABLE `invoices` MODIFY `survey_id` int(11) NULL COMMENT 'surveys.id (vessel + report record); empty for a manual invoice';

-- The invoice's own vessel & survey details. Used instead of the linked survey's only when
-- own_details = 1 (manual invoices, and entries whose details were changed with Edit).
-- Entries from Pending Reports keep own_details = 0 and show their survey as before.
ALTER TABLE `invoices`
    ADD COLUMN `own_details` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN `client_id` INT NULL,
    ADD COLUMN `port_id` INT NULL,
    ADD COLUMN `survey_type_ids` VARCHAR(255) NULL,
    ADD COLUMN `survey_completed_date` DATE NULL,
    ADD COLUMN `created_by` INT NULL,
    ADD COLUMN `request_id` CHAR(32) NULL COMMENT 'one per Add Invoice form; stops double saves',
    ADD UNIQUE KEY `uq_invoices_request` (`request_id`);

-- One invoice number per entry. Added at runtime only when no number is already stored twice
-- (check first: SELECT invoice_no, COUNT(*) FROM invoices WHERE invoice_no IS NOT NULL
--  GROUP BY invoice_no HAVING COUNT(*) > 1;). The application also checks every number under
-- the invoice_number_seq row lock (same number, or the same running number under another
-- client code), so this key is extra protection.
ALTER TABLE `invoices` ADD UNIQUE KEY `uq_invoices_no` (`invoice_no`);

-- Read-only check of the current series (run before / after deploy):
--   SELECT next_no FROM invoice_number_seq WHERE id = 1;
--   SELECT invoice_no FROM invoices WHERE invoice_no REGEXP '-[0-9]+$'
--   ORDER BY CAST(SUBSTRING_INDEX(invoice_no, '-', -1) AS UNSIGNED) DESC LIMIT 5;

-- Invoices (invoices.php): delete a wrong entry while it is still pending, before any invoice
-- is made for it (no invoice number, no PDF, no payment). The row is kept and marked deleted,
-- so the vessel is not registered again by the backfill, and it can be restored from the
-- Deleted list. Backward compatible: three nullable columns. Also added at runtime by
-- includes/invoice_helpers.php (invoiceEnsureGenColumns) when missing.
-- Reverse: migration_invoices_delete_down.sql

ALTER TABLE `invoices`
    ADD COLUMN `deleted_at` DATETIME NULL,
    ADD COLUMN `deleted_by` INT NULL,
    ADD COLUMN `delete_reason` VARCHAR(255) NULL;

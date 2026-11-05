-- Reverse of migration_invoices_delete.sql. Entries that were deleted become visible again.
ALTER TABLE `invoices`
    DROP COLUMN `deleted_at`,
    DROP COLUMN `deleted_by`,
    DROP COLUMN `delete_reason`;

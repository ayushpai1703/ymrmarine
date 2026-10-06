-- Document Replace / Re-upload (Pending Vessels / Pending Reports / Completed Vessels).
--
-- Both changes below are also applied automatically and idempotently at
-- runtime (includes/document_replace_helpers.php's ensureUploadsUploadedByColumn()
-- / ensureDocumentVersionsTable()) — the same pattern already used
-- elsewhere in this app. Running this file by hand is optional.
--
-- Nothing here duplicates an existing field or table. `uploads` itself is
-- unchanged except for one new nullable column; no row, column, or
-- behavior any existing page relies on is touched. Replacing a document
-- updates its EXISTING `uploads` row in place (same id, same survey_id,
-- same file_type) — it is never deleted and recreated — so every existing
-- query, count, and the required-file status-transition check in
-- ajax/upload_handler.php keep working exactly as before.

-- 1. "Uploaded By" — `uploads` never tracked who added a file. Nullable,
--    so every historical row (NULL) just displays as "—"; nothing existing
--    reads or writes this column today.
ALTER TABLE `uploads`
    ADD COLUMN `uploaded_by` INT(11) NULL DEFAULT NULL AFTER `uploaded_at`;

-- 2. Replacement audit history — one row per replacement. `vessel_id` and
--    `report_id` from the feature request are the same thing in this
--    schema (one `surveys` row covers both a vessel's assignment and its
--    report) — stored once as `survey_id`, not duplicated under two names.
CREATE TABLE IF NOT EXISTS `document_versions` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `document_id` int(11) NOT NULL COMMENT 'uploads.id this version replaced',
    `survey_id` int(11) NOT NULL,
    `file_type` varchar(50) DEFAULT NULL,
    `old_file_name` varchar(255) DEFAULT NULL,
    `old_file_path` varchar(255) DEFAULT NULL,
    `old_file_size` varchar(50) DEFAULT NULL,
    `new_file_name` varchar(255) DEFAULT NULL,
    `new_file_path` varchar(255) DEFAULT NULL,
    `new_file_size` varchar(50) DEFAULT NULL,
    `replaced_by` int(11) DEFAULT NULL,
    `replaced_by_role` varchar(30) DEFAULT NULL,
    `ip_address` varchar(45) DEFAULT NULL,
    `replaced_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_docver_document` (`document_id`),
    KEY `idx_docver_survey` (`survey_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- AI Email -> Vessel Assignment extraction (assign_vessel.php "Paste Email" tab).
--
-- assign_vessel.php's existing form, its fields, and the existing surveys
-- table are reused EXACTLY as-is by this feature — no new columns, no
-- renamed columns, no changed INSERT/UPDATE statements. The only schema
-- change this feature needs is the one new, fully independent table below,
-- which just logs each AI extraction ATTEMPT (for audit/debugging) and is
-- never joined to or written back into `surveys`.
--
-- Also applied automatically and idempotently at runtime by
-- includes/email_ai_helpers.php's ensureEmailExtractionLogTable() — the
-- same pattern already used elsewhere in this app. Running this file by
-- hand is optional; it exists for DBAs/ops who prefer explicit migrations.

CREATE TABLE IF NOT EXISTS `email_extraction_log` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `admin_user_id` int DEFAULT NULL,
    `source_email_subject` varchar(500) DEFAULT NULL,
    `source_email_date` varchar(100) DEFAULT NULL,
    `original_email_body` mediumtext,
    `cleaned_email_text` mediumtext,
    `extracted_json` mediumtext,
    `extraction_status` varchar(30) NOT NULL DEFAULT 'SUCCESS' COMMENT 'SUCCESS, MULTI_VESSEL, NO_VESSEL_FOUND, FAILED, INVALID_JSON',
    `vessel_count` int NOT NULL DEFAULT 0,
    `extraction_confidence` int DEFAULT NULL,
    `ip_address` varchar(45) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_email_log_admin` (`admin_user_id`),
    KEY `idx_email_log_status` (`extraction_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

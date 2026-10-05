-- AI Email -> Vessel Assignment extraction (assign_vessel.php "Paste Email" tab).
--
-- All three changes below are also applied automatically and idempotently at
-- runtime (assign_vessel.php's own safety-net column checks, and
-- includes/email_ai_helpers.php's ensureEmailExtractionLogTable()) — the
-- same pattern already used elsewhere in this app. Running this file by
-- hand is optional; it exists for DBAs/ops who prefer explicit migrations.
--
-- Nothing here duplicates an existing field: imo_number already existed
-- (see migration_imo_number.sql, added for vessels.php/reports.php search)
-- but had no input on this form — reused, not recreated. source_type is a
-- single additive flag mirroring the existing client_submitted column's
-- provenance-tracking role. The pasted email itself and the AI's raw
-- extracted JSON live only in the new email_extraction_log table — nothing
-- about a client's email content is written into `surveys`.

-- 1. IMO Number — SKIP this block entirely if migration_imo_number.sql (or
--    this app's own runtime safety-net) already added it on this database.
ALTER TABLE `surveys`
    ADD COLUMN `imo_number` VARCHAR(20) NULL DEFAULT NULL AFTER `vessel_name`;
ALTER TABLE `surveys`
    ADD INDEX `idx_surveys_imo_number` (`imo_number`);

-- 2. Provenance flag — did this assignment come from the plain Manual Form
--    or from a Paste Email AI extraction? SKIP if already present.
ALTER TABLE `surveys`
    ADD COLUMN `source_type` VARCHAR(20) NOT NULL DEFAULT 'MANUAL';

-- 3. Audit log — one row per Paste Email extraction ATTEMPT (success,
--    failure, or no-vessel-found), not only ones that end in an actual
--    assignment. survey_id is filled in after the fact if/when the admin
--    actually clicks "Assign & Save Vessel" on the AI-filled form.
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
    `survey_id` int DEFAULT NULL COMMENT 'Set once the admin actually assigns a vessel from this extraction',
    `ip_address` varchar(45) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_email_log_admin` (`admin_user_id`),
    KEY `idx_email_log_survey` (`survey_id`),
    KEY `idx_email_log_status` (`extraction_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

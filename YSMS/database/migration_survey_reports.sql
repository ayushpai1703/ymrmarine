-- Survey Reports + Report Templates (survey_reports.php, survey_report.php, admin_report_templates.php).
--
-- Applied automatically and idempotently at runtime by includes/report_templates.php
-- (rtEnsureTables()), the same pattern as the other migration_*.sql files here. Running this file
-- by hand is optional and safe to repeat. Additive only: no existing table or column is changed.
-- Files live under uploads/report_templates/ (template versions, read-only once stored) and
-- uploads/survey_reports/<report id>/ (generated Excel / PDF).
--
-- Reverse: database/migration_survey_reports_down.sql

CREATE TABLE IF NOT EXISTS `report_templates` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `kind` varchar(30) NOT NULL COMMENT 'offhire',
    `version` int(11) NOT NULL COMMENT '1, 2, 3 ... per kind',
    `original_name` varchar(255) NOT NULL COMMENT 'file name as uploaded',
    `file_path` varchar(255) NOT NULL COMMENT 'uploads/report_templates/<kind>-v<version>-<sha>.xlsx, never overwritten',
    `sha256` char(64) NOT NULL,
    `file_size` int(11) NOT NULL,
    `notes` varchar(500) DEFAULT NULL,
    `is_active` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'one active version per kind is used for new reports',
    `analysis` mediumtext NOT NULL COMMENT 'JSON: cells, tank sections, formula pattern, checks (flags), test data',
    `mapping` text DEFAULT NULL COMMENT 'JSON: Admin cell overrides and PDF sheets',
    `uploaded_by` int(11) DEFAULT NULL,
    `uploaded_at` datetime NOT NULL,
    `activated_at` datetime DEFAULT NULL,
    `activated_by` int(11) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_rt_kind_version` (`kind`, `version`),
    KEY `idx_rt_kind_active` (`kind`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `survey_reports` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `survey_id` int(11) NOT NULL COMMENT 'surveys.id',
    `template_id` int(11) NOT NULL COMMENT 'report_templates.id the report is made with',
    `kind` varchar(30) NOT NULL,
    `data` mediumtext NOT NULL COMMENT 'JSON: fields + tank rows as entered',
    `version` int(11) NOT NULL DEFAULT 1 COMMENT 'raised on every save (stops overwriting a newer save)',
    `created_by` int(11) DEFAULT NULL,
    `created_at` datetime NOT NULL,
    `updated_by` int(11) DEFAULT NULL,
    `updated_at` datetime NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_sr_survey` (`survey_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `survey_report_files` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `report_id` int(11) NOT NULL,
    `template_id` int(11) NOT NULL,
    `type` varchar(10) NOT NULL COMMENT 'xlsx | pdf',
    `file_name` varchar(255) NOT NULL,
    `file_path` varchar(255) NOT NULL COMMENT 'uploads/survey_reports/<report id>/...',
    `file_size` int(11) NOT NULL DEFAULT 0,
    `created_by` int(11) DEFAULT NULL,
    `created_at` datetime NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_srf_report` (`report_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

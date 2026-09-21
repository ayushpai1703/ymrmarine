-- Automatic Vessel Assignment from survey@ymrmarine.com — incoming email
-- integration. New tables only; nothing existing is touched or renamed.
-- Safe to run on the live database: all CREATE TABLE IF NOT EXISTS, no
-- ALTER on `surveys`/`clients`/`ports`/`users` (imo_number already exists
-- on `surveys` via migration_imo_number.sql).
--
-- These tables also self-create on first use via
-- includes/email_account.php::ensureEmailIntegrationTables() — running this
-- file by hand is optional, it's here for reference / manual cPanel-style
-- deployment as requested.

-- One row per configured incoming mailbox (normally just one: survey@ymrmarine.com).
CREATE TABLE IF NOT EXISTS `email_accounts` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `label` VARCHAR(100) NOT NULL DEFAULT 'Primary Survey Mailbox',
    `mailbox_email` VARCHAR(190) NOT NULL DEFAULT 'survey@ymrmarine.com',
    `provider` VARCHAR(30) NOT NULL DEFAULT 'cpanel', -- gmail | m365 | cpanel
    `imap_host` VARCHAR(190) NOT NULL DEFAULT '',
    `imap_port` INT(5) NOT NULL DEFAULT 993,
    `imap_encryption` VARCHAR(10) NOT NULL DEFAULT 'ssl', -- ssl | tls | none
    `auth_type` VARCHAR(20) NOT NULL DEFAULT 'app_password', -- app_password | oauth2
    `imap_username` VARCHAR(190) NOT NULL DEFAULT '',
    `imap_secret_enc` TEXT NULL DEFAULT NULL, -- AES-256-GCM ciphertext (app password or OAuth refresh token); NEVER plaintext
    `folder` VARCHAR(100) NOT NULL DEFAULT 'INBOX',
    `fetch_limit` INT(5) NOT NULL DEFAULT 20,
    `fetch_period_hours` INT(6) NOT NULL DEFAULT 24,
    `auto_refresh_seconds` INT(6) NOT NULL DEFAULT 60,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_test_status` VARCHAR(20) NULL DEFAULT NULL, -- success | failed
    `last_test_message` VARCHAR(255) NULL DEFAULT NULL,
    `last_test_at` DATETIME NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per fetched email (never deleted from the real mailbox — this is
-- just our local read/processing record).
CREATE TABLE IF NOT EXISTS `emails` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `account_id` INT(11) NOT NULL,
    `message_id` VARCHAR(255) NOT NULL,
    `imap_uid` VARCHAR(50) NOT NULL,
    `sender_email` VARCHAR(190) NULL DEFAULT NULL,
    `sender_name` VARCHAR(190) NULL DEFAULT NULL,
    `subject` VARCHAR(500) NULL DEFAULT NULL,
    `received_at` DATETIME NULL DEFAULT NULL,
    `body_text` LONGTEXT NULL DEFAULT NULL,
    `body_html` LONGTEXT NULL DEFAULT NULL,
    `has_attachment` TINYINT(1) NOT NULL DEFAULT 0,
    `processing_status` VARCHAR(20) NOT NULL DEFAULT 'NEW', -- NEW|PROCESSING|PROCESSED|DUPLICATE|IGNORED|FAILED
    `matched_survey_id` INT(11) NULL DEFAULT NULL,
    `used_by_user_id` INT(11) NULL DEFAULT NULL,
    `processed_at` DATETIME NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_account_message` (`account_id`, `message_id`(190)),
    KEY `idx_emails_uid` (`account_id`, `imap_uid`),
    KEY `idx_emails_received` (`received_at`),
    KEY `idx_emails_status` (`processing_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `email_attachments` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `email_id` INT(11) NOT NULL,
    `file_name` VARCHAR(255) NOT NULL,
    `file_path` VARCHAR(255) NOT NULL,
    `mime_type` VARCHAR(120) NULL DEFAULT NULL,
    `file_size` INT(11) NULL DEFAULT NULL,
    `extraction_status` VARCHAR(20) NOT NULL DEFAULT 'PENDING', -- PENDING|OK|FAILED|SKIPPED
    `extracted_text` LONGTEXT NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_email_attachments_email` (`email_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `email_extracted_data` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `email_id` INT(11) NOT NULL,
    `vessel_name` VARCHAR(255) NULL DEFAULT NULL,
    `imo_number` VARCHAR(20) NULL DEFAULT NULL,
    `call_sign` VARCHAR(50) NULL DEFAULT NULL,
    `port` VARCHAR(190) NULL DEFAULT NULL,
    `agent` VARCHAR(190) NULL DEFAULT NULL,
    `eta` VARCHAR(50) NULL DEFAULT NULL,
    `etb` VARCHAR(50) NULL DEFAULT NULL,
    `survey_type` VARCHAR(190) NULL DEFAULT NULL,
    `cargo` VARCHAR(255) NULL DEFAULT NULL,
    `client` VARCHAR(190) NULL DEFAULT NULL,
    `master_name` VARCHAR(190) NULL DEFAULT NULL,
    `loa` VARCHAR(50) NULL DEFAULT NULL,
    `draft` VARCHAR(50) NULL DEFAULT NULL,
    `dwt` VARCHAR(50) NULL DEFAULT NULL,
    `gt` VARCHAR(50) NULL DEFAULT NULL,
    `survey_date` VARCHAR(50) NULL DEFAULT NULL,
    `additional_instructions` TEXT NULL DEFAULT NULL,
    `confidence_score` DECIMAL(4,3) NOT NULL DEFAULT 0.000,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_email_extracted` (`email_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Admin-only settings change audit trail (section 25). Never stores the
-- credential value itself, only that it was changed.
CREATE TABLE IF NOT EXISTS `email_settings_audit_log` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `account_id` INT(11) NULL DEFAULT NULL,
    `admin_user_id` INT(11) NULL DEFAULT NULL,
    `admin_name` VARCHAR(190) NULL DEFAULT NULL,
    `action` VARCHAR(50) NOT NULL, -- save_settings | test_connection | fetch_emails | use_email | ignore_email
    `detail` VARCHAR(500) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_email_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed the one expected mailbox row if the table is empty, so the Admin
-- settings page has something to edit immediately (host/port/password still
-- need to be filled in by the Admin — see admin_email_integration.php).
INSERT INTO `email_accounts` (`label`, `mailbox_email`, `provider`, `imap_host`, `imap_port`, `imap_encryption`, `folder`)
SELECT 'Primary Survey Mailbox', 'survey@ymrmarine.com', 'cpanel', '', 993, 'ssl', 'INBOX'
WHERE NOT EXISTS (SELECT 1 FROM `email_accounts`);

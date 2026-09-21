<?php
require_once __DIR__ . '/email_crypto.php';

/**
 * Safety-net table creation, matching this codebase's existing
 * ensureSurveyAttachmentsTable()/ensurePortAnchorages() pattern — so the
 * feature works even if database/migration_email_integration.sql was never
 * run by hand.
 */
function ensureEmailIntegrationTables(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `email_accounts` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `label` VARCHAR(100) NOT NULL DEFAULT 'Primary Survey Mailbox',
            `mailbox_email` VARCHAR(190) NOT NULL DEFAULT 'survey@ymrmarine.com',
            `provider` VARCHAR(30) NOT NULL DEFAULT 'cpanel',
            `imap_host` VARCHAR(190) NOT NULL DEFAULT '',
            `imap_port` INT(5) NOT NULL DEFAULT 993,
            `imap_encryption` VARCHAR(10) NOT NULL DEFAULT 'ssl',
            `auth_type` VARCHAR(20) NOT NULL DEFAULT 'app_password',
            `imap_username` VARCHAR(190) NOT NULL DEFAULT '',
            `imap_secret_enc` TEXT NULL DEFAULT NULL,
            `folder` VARCHAR(100) NOT NULL DEFAULT 'INBOX',
            `fetch_limit` INT(5) NOT NULL DEFAULT 20,
            `fetch_period_hours` INT(6) NOT NULL DEFAULT 24,
            `auto_refresh_seconds` INT(6) NOT NULL DEFAULT 60,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `last_test_status` VARCHAR(20) NULL DEFAULT NULL,
            `last_test_message` VARCHAR(255) NULL DEFAULT NULL,
            `last_test_at` DATETIME NULL DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS `emails` (
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
            `processing_status` VARCHAR(20) NOT NULL DEFAULT 'NEW',
            `matched_survey_id` INT(11) NULL DEFAULT NULL,
            `used_by_user_id` INT(11) NULL DEFAULT NULL,
            `processed_at` DATETIME NULL DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_account_message` (`account_id`, `message_id`(190)),
            KEY `idx_emails_uid` (`account_id`, `imap_uid`),
            KEY `idx_emails_received` (`received_at`),
            KEY `idx_emails_status` (`processing_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS `email_attachments` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `email_id` INT(11) NOT NULL,
            `file_name` VARCHAR(255) NOT NULL,
            `file_path` VARCHAR(255) NOT NULL,
            `mime_type` VARCHAR(120) NULL DEFAULT NULL,
            `file_size` INT(11) NULL DEFAULT NULL,
            `extraction_status` VARCHAR(20) NOT NULL DEFAULT 'PENDING',
            `extracted_text` LONGTEXT NULL DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_email_attachments_email` (`email_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS `email_extracted_data` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS `email_settings_audit_log` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `account_id` INT(11) NULL DEFAULT NULL,
            `admin_user_id` INT(11) NULL DEFAULT NULL,
            `admin_name` VARCHAR(190) NULL DEFAULT NULL,
            `action` VARCHAR(50) NOT NULL,
            `detail` VARCHAR(500) NULL DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_email_audit_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $count = (int)$db->query("SELECT COUNT(*) FROM email_accounts")->fetchColumn();
        if ($count === 0) {
            $db->exec("INSERT INTO email_accounts (label, mailbox_email, provider, imap_host, imap_port, imap_encryption, folder)
                       VALUES ('Primary Survey Mailbox', 'survey@ymrmarine.com', 'cpanel', '', 993, 'ssl', 'INBOX')");
        }
    } catch (Throwable $e) {
        error_log('ensureEmailIntegrationTables: ' . $e->getMessage());
    }
}

/** The single configured mailbox row (there is normally only one). Returns null if none exists. */
function getEmailAccount(PDO $db): ?array {
    ensureEmailIntegrationTables($db);
    $row = $db->query("SELECT * FROM email_accounts ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Same as getEmailAccount() but with imap_secret_enc decrypted into imap_secret for IMAP use — never send this array to the browser. */
function getEmailAccountDecrypted(PDO $db): ?array {
    $row = getEmailAccount($db);
    if (!$row) return null;
    $row['imap_secret'] = ysmsEmailDecrypt((string)($row['imap_secret_enc'] ?? ''));
    return $row;
}

/** Insert/update settings. $secret === null means "leave the stored credential unchanged". */
function saveEmailAccount(PDO $db, array $fields, ?string $secret): void {
    ensureEmailIntegrationTables($db);
    $existing = getEmailAccount($db);
    $params = [
        'label'                => $fields['label'],
        'mailbox_email'        => $fields['mailbox_email'],
        'provider'             => $fields['provider'],
        'imap_host'            => $fields['imap_host'],
        'imap_port'            => $fields['imap_port'],
        'imap_encryption'      => $fields['imap_encryption'],
        'auth_type'            => $fields['auth_type'],
        'imap_username'        => $fields['imap_username'],
        'folder'               => $fields['folder'],
        'fetch_limit'          => $fields['fetch_limit'],
        'fetch_period_hours'   => $fields['fetch_period_hours'],
        'auto_refresh_seconds' => $fields['auto_refresh_seconds'],
    ];
    if ($existing) {
        $sql = "UPDATE email_accounts SET label=:label, mailbox_email=:mailbox_email, provider=:provider,
                imap_host=:imap_host, imap_port=:imap_port, imap_encryption=:imap_encryption,
                auth_type=:auth_type, imap_username=:imap_username, folder=:folder,
                fetch_limit=:fetch_limit, fetch_period_hours=:fetch_period_hours,
                auto_refresh_seconds=:auto_refresh_seconds";
        if ($secret !== null) {
            $sql .= ", imap_secret_enc=:imap_secret_enc";
            $params['imap_secret_enc'] = ysmsEmailEncrypt($secret);
        }
        $sql .= " WHERE id=:id";
        $params['id'] = $existing['id'];
        $db->prepare($sql)->execute($params);
    } else {
        $params['imap_secret_enc'] = $secret !== null ? ysmsEmailEncrypt($secret) : '';
        $db->prepare("INSERT INTO email_accounts
            (label, mailbox_email, provider, imap_host, imap_port, imap_encryption, auth_type, imap_username, imap_secret_enc, folder, fetch_limit, fetch_period_hours, auto_refresh_seconds)
            VALUES (:label, :mailbox_email, :provider, :imap_host, :imap_port, :imap_encryption, :auth_type, :imap_username, :imap_secret_enc, :folder, :fetch_limit, :fetch_period_hours, :auto_refresh_seconds)"
        )->execute($params);
    }
}

function recordEmailTestResult(PDO $db, int $accountId, bool $ok, string $message): void {
    try {
        $stmt = $db->prepare("UPDATE email_accounts SET last_test_status = ?, last_test_message = ?, last_test_at = NOW() WHERE id = ?");
        $stmt->execute([$ok ? 'success' : 'failed', substr($message, 0, 255), $accountId]);
    } catch (Throwable $e) {
        error_log('recordEmailTestResult: ' . $e->getMessage());
    }
}

function logEmailAudit(PDO $db, ?int $accountId, string $action, string $detail = ''): void {
    try {
        ensureEmailIntegrationTables($db);
        $stmt = $db->prepare("INSERT INTO email_settings_audit_log (account_id, admin_user_id, admin_name, action, detail) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $accountId,
            (int)($_SESSION['user_id'] ?? 0) ?: null,
            (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''),
            $action,
            substr($detail, 0, 500),
        ]);
    } catch (Throwable $e) {
        error_log('logEmailAudit: ' . $e->getMessage());
    }
}

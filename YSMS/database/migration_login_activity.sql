-- Login Activity / Access Monitoring — reference migration.
--
-- This table is also created automatically and idempotently at runtime by
-- includes/login_activity_helpers.php::ensureLoginActivityTable() — the
-- same "safety net" pattern already used elsewhere in this app (see
-- config/config.php's ensureSurveyAttachmentsTable / ensureFloatingNotesTable).
-- Running this file by hand is optional; it exists for DBAs/ops who prefer
-- to apply schema changes explicitly rather than relying on first-request
-- auto-create.
--
-- user_id/user_name/username/user_email/role are captured redundantly at
-- login time (not only via a users.id foreign key) so the audit trail still
-- reads correctly even if a user is later renamed, has their role changed,
-- or is deleted — an audit log must describe what happened at the time, not
-- be rewritten by later changes to the live `users` table. No new columns
-- are added to the existing `users`/`roles`/`clients` tables; this is a
-- fully separate, additive table.

CREATE TABLE IF NOT EXISTS `login_activity` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `user_id` int DEFAULT NULL,
    `user_name` varchar(150) DEFAULT NULL,
    `username` varchar(100) DEFAULT NULL,
    `user_email` varchar(150) DEFAULT NULL,
    `role` varchar(50) DEFAULT NULL,
    `ip_address` varchar(45) DEFAULT NULL,
    `country` varchar(100) DEFAULT NULL,
    `country_code` varchar(10) DEFAULT NULL,
    `device_type` varchar(50) DEFAULT NULL,
    `operating_system` varchar(100) DEFAULT NULL,
    `browser` varchar(100) DEFAULT NULL,
    `user_agent` text,
    `login_time` datetime NOT NULL,
    `logout_time` datetime DEFAULT NULL,
    `session_duration` int DEFAULT NULL COMMENT 'seconds',
    `login_status` varchar(30) NOT NULL DEFAULT 'SUCCESS' COMMENT 'SUCCESS or FAILED',
    `failure_reason` varchar(255) DEFAULT NULL,
    `session_id` varchar(255) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_role` (`role`),
    KEY `idx_login_time` (`login_time`),
    KEY `idx_login_status` (`login_status`),
    KEY `idx_ip_address` (`ip_address`),
    KEY `idx_session_id` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

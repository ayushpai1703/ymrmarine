-- Team Chat (safe to re-run; additive only — no existing table is touched).
-- The app also creates these on first use via ensureTeamChatTables()
-- in includes/chat_helpers.php, so running this file manually is optional.
-- User references (created_by, user_id, sender_id, added_by, uploaded_by) are
-- indexed but deliberately not foreign keys to `users`, matching the other
-- lazily-created tables in this app (users.id differs between schema dumps).

CREATE TABLE IF NOT EXISTS `chat_groups` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `group_name` VARCHAR(150) NOT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_group_name` (`group_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_group_members` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `group_id` INT(11) NOT NULL,
  `user_id` INT(11) NOT NULL,
  `added_by` INT(11) DEFAULT NULL,
  `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_read_message_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_member` (`group_id`, `user_id`),
  KEY `idx_chat_member_user` (`user_id`),
  CONSTRAINT `fk_chat_member_group` FOREIGN KEY (`group_id`) REFERENCES `chat_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id` INT(11) NOT NULL,
  `sender_id` INT(11) NOT NULL,
  `message_text` TEXT NULL,
  `client_token` CHAR(36) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `edited_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_chat_msg_group_id` (`group_id`, `id`),
  KEY `idx_chat_msg_sender_time` (`sender_id`, `created_at`),
  UNIQUE KEY `uq_chat_msg_client_token` (`sender_id`, `client_token`),
  CONSTRAINT `fk_chat_msg_group` FOREIGN KEY (`group_id`) REFERENCES `chat_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_attachments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` BIGINT UNSIGNED NOT NULL,
  `original_filename` VARCHAR(255) NOT NULL,
  `private_storage_object_key` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(120) NOT NULL,
  `file_size` BIGINT UNSIGNED NOT NULL,
  `uploaded_by` INT(11) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_chat_att_message` (`message_id`),
  KEY `idx_chat_att_uploader_time` (`uploaded_by`, `created_at`),
  CONSTRAINT `fk_chat_att_message` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `chat_groups` (`group_name`) VALUES ('YMR Team Group');

-- Rollback (DESTRUCTIVE — deletes all chat history; only run deliberately):
-- DROP TABLE IF EXISTS chat_attachments, chat_messages, chat_group_members, chat_groups;

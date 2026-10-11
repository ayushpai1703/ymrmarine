<?php
/**
 * Team Chat — shared helpers (tables, membership, storage, upload validation).
 * Used by team_chat.php, ajax/team_chat.php and includes/nav.php.
 *
 * Attachments live on the existing ymrmarine-ysms-uploads bucket (mounted at
 * YSMS/uploads) under uploads/chat_private/<db-name>/..., with random .bin
 * object names and a runtime-written deny-all .htaccess. They are only ever
 * served through ajax/team_chat.php?action=download after a membership check.
 */

const TEAM_CHAT_DEFAULT_GROUP = 'YMR Team Group';
const TEAM_CHAT_ALLOWED_ROLES = ['Admin', 'Super Admin', 'Surveyor'];
const TEAM_CHAT_MANAGER_ROLES = ['Admin', 'Super Admin'];
const TEAM_CHAT_MAX_TEXT = 4000;
const TEAM_CHAT_PAGE_SIZE = 30;
const TEAM_CHAT_POLL_LIMIT = 100;
const TEAM_CHAT_MSG_PER_MINUTE = 20;
const TEAM_CHAT_UPLOADS_PER_10MIN = 15;

const TEAM_CHAT_FILE_TYPES = [
    'pdf'  => ['mime' => 'application/pdf', 'label' => 'PDF'],
    'doc'  => ['mime' => 'application/msword', 'label' => 'Word'],
    'docx' => ['mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'label' => 'Word'],
    'xls'  => ['mime' => 'application/vnd.ms-excel', 'label' => 'Excel'],
    'xlsx' => ['mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'label' => 'Excel'],
    'jpg'  => ['mime' => 'image/jpeg', 'label' => 'Image'],
    'jpeg' => ['mime' => 'image/jpeg', 'label' => 'Image'],
    'png'  => ['mime' => 'image/png', 'label' => 'Image'],
    'txt'  => ['mime' => 'text/plain', 'label' => 'Text'],
    'csv'  => ['mime' => 'text/csv', 'label' => 'CSV'],
];

function teamChatMaxUploadBytes(): int {
    $mb = (int)(getenv('CHAT_MAX_UPLOAD_MB') ?: 20);
    return max(1, min($mb, 90)) * 1024 * 1024;
}

function ensureTeamChatTables(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `chat_groups` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `group_name` VARCHAR(150) NOT NULL,
            `created_by` INT(11) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_chat_group_name` (`group_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS `chat_group_members` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS `chat_messages` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS `chat_attachments` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('ensureTeamChatTables: ' . $e->getMessage());
        $done = false;
        throw $e;
    }
}

/** getDB() connects as 3-byte utf8; chat text needs utf8mb4 for emoji. */
function teamChatUseUtf8mb4(PDO $db): void {
    $db->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
}

function getTeamChatDefaultGroupId(PDO $db, int $creatorId = 0): int {
    $stmt = $db->prepare("SELECT id FROM chat_groups WHERE group_name = ?");
    $stmt->execute([TEAM_CHAT_DEFAULT_GROUP]);
    $id = (int)$stmt->fetchColumn();
    if ($id > 0) return $id;
    $ins = $db->prepare("INSERT IGNORE INTO chat_groups (group_name, created_by) VALUES (?, ?)");
    $ins->execute([TEAM_CHAT_DEFAULT_GROUP, $creatorId > 0 ? $creatorId : null]);
    $stmt->execute([TEAM_CHAT_DEFAULT_GROUP]);
    return (int)$stmt->fetchColumn();
}

/** Fresh DB lookup of the user (never trusts the session role alone). Null if inactive or not allowed in chat. */
function getTeamChatUser(PDO $db, int $userId): ?array {
    if ($userId <= 0) return null;
    $stmt = $db->prepare("SELECT u.id, u.full_name, u.status, r.name AS role_name
        FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    if (($row['status'] ?? 'Active') !== 'Active') return null;
    if (!in_array($row['role_name'] ?? '', TEAM_CHAT_ALLOWED_ROLES, true)) return null;
    return $row;
}

function teamChatCanManage(?array $user): bool {
    return $user !== null && in_array($user['role_name'] ?? '', TEAM_CHAT_MANAGER_ROLES, true);
}

function teamChatIsMember(PDO $db, int $groupId, int $userId): bool {
    $stmt = $db->prepare("SELECT 1 FROM chat_group_members WHERE group_id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$groupId, $userId]);
    return (bool)$stmt->fetchColumn();
}

/** Avatar URL relative to YSMS/, or null — same rules as nav.php / profile_dropdown.php. */
function teamChatAvatarUrl(?string $avatar, ?string $profilePic): ?string {
    foreach ([$avatar, $profilePic] as $p) {
        if ($p && strpos($p, 'uploads/') === 0 && strpos($p, '..') === false && file_exists(__DIR__ . '/../' . $p)) {
            return $p;
        }
    }
    return null;
}

function getTeamChatMembers(PDO $db, int $groupId): array {
    $stmt = $db->prepare("SELECT gm.user_id, gm.joined_at, u.full_name, u.avatar, u.profile_pic, r.name AS role_name
        FROM chat_group_members gm
        JOIN users u ON u.id = gm.user_id
        LEFT JOIN roles r ON r.id = u.role_id
        WHERE gm.group_id = ?
        ORDER BY u.full_name ASC");
    $stmt->execute([$groupId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $out[] = [
            'user_id' => (int)$m['user_id'],
            'name' => (string)$m['full_name'],
            'role' => (string)($m['role_name'] ?? ''),
            'avatar' => teamChatAvatarUrl($m['avatar'] ?? null, $m['profile_pic'] ?? null),
            'joined_at' => $m['joined_at'],
        ];
    }
    return $out;
}

/**
 * Nav badge data for the current user, without creating tables (runs on every
 * page). Returns ['member' => bool, 'unread' => int]; silently zero on error.
 */
function getTeamChatNavState(PDO $db, int $userId): array {
    try {
        $stmt = $db->prepare("SELECT gm.group_id,
                (SELECT COUNT(*) FROM chat_messages m
                  WHERE m.group_id = gm.group_id AND m.id > gm.last_read_message_id AND m.sender_id <> gm.user_id) AS unread
            FROM chat_group_members gm WHERE gm.user_id = ?");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $unread = 0;
        foreach ($rows as $r) $unread += (int)$r['unread'];
        return ['member' => !empty($rows), 'unread' => $unread];
    } catch (Throwable $e) {
        return ['member' => false, 'unread' => 0];
    }
}

// ---------------------------------------------------------------- storage

function teamChatStorageRoot(): string {
    $custom = getenv('CHAT_STORAGE_DIR');
    return rtrim($custom ?: (__DIR__ . '/../uploads/chat_private'), '/');
}

/** Staging and production share one bucket — keep each database's files apart. */
function teamChatEnvSlug(): string {
    $slug = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', defined('DB_NAME') ? DB_NAME : 'default'));
    return $slug !== '' ? $slug : 'default';
}

/** Deny-all .htaccess is written at runtime because the bucket mount hides anything baked into the image. */
function teamChatEnsureStorageDir(string $dir): void {
    $root = teamChatStorageRoot();
    foreach ([$root, $dir] as $d) {
        if (!is_dir($d) && !@mkdir($d, 0770, true) && !is_dir($d)) {
            throw new RuntimeException('Chat storage directory unavailable');
        }
    }
    $ht = $root . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\nOptions -Indexes\n");
    }
    if (!is_file($root . '/index.html')) {
        @file_put_contents($root . '/index.html', '');
    }
}

function teamChatIsValidObjectKey(string $key): bool {
    return (bool)preg_match('#^[a-z0-9_]+/\d{4}/\d{2}/[a-f0-9]{32}\.bin$#', $key);
}

function teamChatObjectPath(string $key): ?string {
    if (!teamChatIsValidObjectKey($key)) return null;
    return teamChatStorageRoot() . '/' . $key;
}

/** Moves a validated upload into private storage; returns the object key. */
function teamChatStoreUpload(string $tmpPath): string {
    $key = teamChatEnvSlug() . '/' . date('Y') . '/' . date('m') . '/' . bin2hex(random_bytes(16)) . '.bin';
    $path = teamChatStorageRoot() . '/' . $key;
    teamChatEnsureStorageDir(dirname($path));
    $moved = is_uploaded_file($tmpPath) ? @move_uploaded_file($tmpPath, $path) : @rename($tmpPath, $path);
    if (!$moved) {
        throw new RuntimeException('Could not store attachment');
    }
    @chmod($path, 0640);
    return $key;
}

function teamChatDeleteObject(string $key): void {
    $path = teamChatObjectPath($key);
    if ($path && is_file($path)) @unlink($path);
}

// ------------------------------------------------------- upload validation

function teamChatCleanFilename(string $name): string {
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[\x00-\x1F\x7F"<>:\/\\\\|?*]+/u', '', $name) ?? '';
    $name = trim($name, " .\t");
    if (mb_strlen($name, 'UTF-8') > 180) {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $name = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 170, 'UTF-8') . ($ext !== '' ? '.' . $ext : '');
    }
    return $name;
}

/**
 * Validates an entry from $_FILES by extension AND actual content.
 * Returns ['ok' => true, 'ext', 'mime', 'name', 'size'] or ['ok' => false, 'error'].
 */
function teamChatValidateUpload(array $file): array {
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    $maxBytes = teamChatMaxUploadBytes();
    $maxMb = (int)round($maxBytes / 1048576);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'error' => "File is too large. Maximum size is {$maxMb} MB."];
    }
    if ($err !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'The file did not upload completely. Please try again.'];
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Invalid upload.'];
    }
    $size = (int)@filesize($tmp);
    if ($size <= 0) return ['ok' => false, 'error' => 'The file is empty.'];
    if ($size > $maxBytes) return ['ok' => false, 'error' => "File is too large. Maximum size is {$maxMb} MB."];

    $name = teamChatCleanFilename((string)($file['name'] ?? ''));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($name === '' || !isset(TEAM_CHAT_FILE_TYPES[$ext])) {
        return ['ok' => false, 'error' => 'Unsupported file type. Allowed: PDF, DOC, DOCX, XLS, XLSX, JPG, JPEG, PNG, TXT, CSV.'];
    }

    $head = (string)@file_get_contents($tmp, false, null, 0, 8192);
    foreach (["MZ", "\x7FELF", "#!", "\xCF\xFA\xED\xFE", "\xFE\xED\xFA\xCE", "\xCA\xFE\xBA\xBE"] as $sig) {
        if (strncmp($head, $sig, strlen($sig)) === 0) {
            return ['ok' => false, 'error' => 'Executable files are not allowed.'];
        }
    }

    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    $detected = $finfo ? (string)finfo_file($finfo, $tmp) : '';
    if ($finfo) finfo_close($finfo);

    $bad = ['ok' => false, 'error' => 'The file content does not match its extension (.' . $ext . ').'];
    $ole = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    switch ($ext) {
        case 'pdf':
            if (strncmp($head, '%PDF-', 5) !== 0) return $bad;
            break;
        case 'jpg':
        case 'jpeg':
        case 'png':
            $info = @getimagesize($tmp);
            $want = $ext === 'png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;
            if (!$info || (int)$info[2] !== $want) return $bad;
            break;
        case 'doc':
        case 'xls':
            if (strncmp($head, $ole, 8) !== 0) return $bad;
            break;
        case 'docx':
        case 'xlsx':
            if (strncmp($head, "PK\x03\x04", 4) !== 0 || !class_exists('ZipArchive')) return $bad;
            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::RDONLY) !== true) return $bad;
            $core = $ext === 'docx' ? 'word/document.xml' : 'xl/workbook.xml';
            $okZip = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName($core) !== false;
            $macro = $zip->locateName('word/vbaProject.bin') !== false || $zip->locateName('xl/vbaProject.bin') !== false;
            $zip->close();
            if (!$okZip) return $bad;
            if ($macro) return ['ok' => false, 'error' => 'Files containing macros are not allowed.'];
            break;
        case 'txt':
        case 'csv':
            if (strpos($head, "\0") !== false) return $bad;
            $textMimes = ['text/plain', 'text/csv', 'text/x-csv', 'application/csv', 'text/comma-separated-values', 'text/tab-separated-values'];
            if ($detected !== '' && !in_array($detected, $textMimes, true) && strpos($detected, 'text/') !== 0) return $bad;
            break;
    }

    return ['ok' => true, 'ext' => $ext, 'mime' => TEAM_CHAT_FILE_TYPES[$ext]['mime'], 'name' => $name, 'size' => $size];
}

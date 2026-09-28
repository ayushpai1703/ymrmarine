<?php
/**
 * Completed Report Remarks + optional attachments (completed_detail.php).
 * Purely additive — two small new tables, no existing schema touched.
 */

function ensureCompletedRemarksTables(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `completed_remarks` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `survey_id` INT(11) NOT NULL,
            `user_id` INT(11) NOT NULL,
            `remarks` TEXT NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_completed_remarks_survey` (`survey_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS `completed_remark_attachments` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `remark_id` INT(11) NOT NULL,
            `file_name` VARCHAR(255) NOT NULL,
            `file_path` VARCHAR(255) NOT NULL,
            `file_size` INT(11) DEFAULT NULL,
            `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_completed_remark_attachments_remark` (`remark_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('ensureCompletedRemarksTables: ' . $e->getMessage());
    }
}

/** Can this user ADD a remark to this survey? Admin/Super Admin, or the assigned surveyor(s). Client is read-only. */
function canAddCompletedRemark(PDO $db, array $survey, string $role, int $userId): bool {
    if (in_array($role, ['Admin', 'Super Admin'], true)) return true;
    if ($role === 'Surveyor') return isSurveyorAssignedToSurvey($db, (int)$survey['id'], $userId);
    return false;
}

/** Can this user delete an existing remark? Admin only, matching the "Admin -> full access" permission note. */
function canDeleteCompletedRemark(string $role): bool {
    return $role === 'Admin';
}

/** @return array<int, array{id:int,survey_id:int,user_id:int,remarks:string,created_at:string,author_name:string,attachments:array}> newest first */
function getCompletedRemarks(PDO $db, int $surveyId): array {
    ensureCompletedRemarksTables($db);
    try {
        $stmt = $db->prepare("
            SELECT r.*, u.full_name AS author_name
            FROM completed_remarks r
            LEFT JOIN users u ON r.user_id = u.id
            WHERE r.survey_id = ?
            ORDER BY r.created_at DESC, r.id DESC
        ");
        $stmt->execute([$surveyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('getCompletedRemarks: ' . $e->getMessage());
        return [];
    }
    if (empty($rows)) return [];

    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $attByRemark = [];
    try {
        $stmt = $db->prepare("SELECT * FROM completed_remark_attachments WHERE remark_id IN ($placeholders) ORDER BY id ASC");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $att) {
            $attByRemark[(int)$att['remark_id']][] = $att;
        }
    } catch (Throwable $e) {
        error_log('getCompletedRemarks attachments: ' . $e->getMessage());
    }

    foreach ($rows as &$r) {
        $r['attachments'] = $attByRemark[(int)$r['id']] ?? [];
    }
    unset($r);
    return $rows;
}

function completedRemarkAllowedExtensions(): array {
    return ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];
}

function completedRemarkMimeSetFor(string $ext): array {
    return match ($ext) {
        'pdf' => UPLOAD_MIMES_PDF,
        'doc', 'docx' => UPLOAD_MIMES_DOC,
        'xls', 'xlsx' => UPLOAD_MIMES_XLSX,
        'jpg', 'jpeg', 'png' => UPLOAD_MIMES_IMAGE,
        default => [],
    };
}

/**
 * Saves one remark (with any attached files) for a survey. Returns
 * ['ok'=>bool,'error'=>string,'remark_id'=>int].
 */
function saveCompletedRemark(PDO $db, int $surveyId, int $userId, string $remarksText, array $files): array {
    ensureCompletedRemarksTables($db);
    $remarksText = trim($remarksText);
    if ($remarksText === '') {
        return ['ok' => false, 'error' => 'Please write a remark before saving.', 'remark_id' => 0];
    }
    if (mb_strlen($remarksText) > 5000) {
        return ['ok' => false, 'error' => 'Remarks are too long (max 5000 characters).', 'remark_id' => 0];
    }

    try {
        $stmt = $db->prepare("INSERT INTO completed_remarks (survey_id, user_id, remarks) VALUES (?, ?, ?)");
        $stmt->execute([$surveyId, $userId, $remarksText]);
        $remarkId = (int)$db->lastInsertId();
    } catch (Throwable $e) {
        error_log('saveCompletedRemark insert: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not save this remark. Please try again.', 'remark_id' => 0];
    }

    if (empty($files['name'])) {
        return ['ok' => true, 'error' => '', 'remark_id' => $remarkId];
    }

    $allowedExt = completedRemarkAllowedExtensions();
    $dir = __DIR__ . '/../uploads/completed_remarks/';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }

    $names = (array)$files['name'];
    $tmpNames = (array)$files['tmp_name'];
    $errors = (array)$files['error'];
    $sizes = (array)$files['size'];

    $insAtt = $db->prepare("INSERT INTO completed_remark_attachments (remark_id, file_name, file_path, file_size) VALUES (?, ?, ?, ?)");
    $skippedAny = false;

    foreach ($names as $i => $origName) {
        if ($origName === '' || ($errors[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) { $skippedAny = true; continue; }

        $fileArr = [
            'name' => $origName,
            'tmp_name' => $tmpNames[$i] ?? '',
            'error' => $errors[$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $sizes[$i] ?? 0,
        ];
        $mimeSet = completedRemarkMimeSetFor($ext);
        if (upload_validate($fileArr, $mimeSet, 15 * 1024 * 1024) !== '') { $skippedAny = true; continue; }

        $safe = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $dir . $safe;
        if (!@move_uploaded_file($fileArr['tmp_name'], $dest)) { $skippedAny = true; continue; }
        @chmod($dest, 0640);

        try {
            $insAtt->execute([$remarkId, basename($origName), 'uploads/completed_remarks/' . $safe, (int)$fileArr['size']]);
        } catch (Throwable $e) {
            error_log('saveCompletedRemark attachment insert: ' . $e->getMessage());
        }
    }

    return [
        'ok' => true,
        'error' => $skippedAny ? 'Remark saved. One or more files were skipped (unsupported type, too large, or failed to upload).' : '',
        'remark_id' => $remarkId,
    ];
}

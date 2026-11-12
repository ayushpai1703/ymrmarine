<?php
/**
 * Document Replace / Re-upload — shared helpers.
 *
 * Lets Admin/Surveyor swap the file behind an EXISTING `uploads` row
 * in place (same id, same survey_id, same file_type "slot") instead of
 * deleting it and creating an unrelated new row — so every other page's
 * existing queries, counts and the required-file status-transition logic
 * in ajax/upload_handler.php keep working exactly as before; this is a
 * pure content/metadata swap on a row that already exists.
 *
 * Reuses the project's existing centralized upload validation
 * (includes/upload_validation.php — upload_validate()/MIME constants,
 * shared with the root site and ajax/bulk_assign_preview.php) and the
 * same secure-random-filename convention already used for completed-
 * remark attachments (includes/completed_remarks_helpers.php) rather than
 * the older timestamp-prefixed names in ajax/upload_handler.php.
 */
require_once __DIR__ . '/../../includes/upload_validation.php';

/** SAFETY NET — `uploads` table has no uploaded_by column today, so there
 *  is no way to show "Uploaded By" for a document or to credit a
 *  replacement's PREVIOUS uploader. Purely additive; nothing existing
 *  reads or writes this column today, so leaving it NULL for every
 *  historical row changes nothing for them. */
function ensureUploadsUploadedByColumn(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = $db->query("SHOW COLUMNS FROM uploads LIKE 'uploaded_by'")->fetchAll();
        if (empty($cols)) {
            $db->exec("ALTER TABLE uploads ADD COLUMN uploaded_by INT(11) NULL DEFAULT NULL AFTER uploaded_at");
        }
    } catch (Throwable $e) {
        error_log('ensureUploadsUploadedByColumn: ' . $e->getMessage());
    }
}

/** SAFETY NET — one row per document replacement, for audit. Mirrors the
 *  established ensureXTable(PDO $db) lazy-create pattern used throughout
 *  this app. `vessel_id`/`report_id` in the feature request are the same
 *  thing in this schema (one `surveys` row covers both the vessel
 *  assignment and its report) — stored once as `survey_id`, not
 *  duplicated under two names. */
function ensureDocumentVersionsTable(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `document_versions` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `document_id` int(11) NOT NULL,
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('ensureDocumentVersionsTable: ' . $e->getMessage());
    }
}

/** Every file type ever saved into the `uploads` table across this app
 *  (PDF/Excel/Word report slots, plus free-form "Field Report" extras
 *  which include images and zips — see ajax/upload_handler.php's
 *  $isAllowedExt lists) — the full set a document being REPLACED could
 *  be, since the slot's original type is preserved, not changed. */
function documentReplaceAllowedExtensions(): array
{
    return ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'xlsm', 'jpg', 'jpeg', 'png', 'zip'];
}

function documentReplaceMimeSetFor(string $ext): array
{
    return match ($ext) {
        'pdf' => UPLOAD_MIMES_PDF,
        'doc', 'docx' => UPLOAD_MIMES_DOC,
        'xls', 'xlsx', 'xlsm' => UPLOAD_MIMES_XLSX,
        'jpg', 'jpeg', 'png' => UPLOAD_MIMES_IMAGE,
        'zip' => UPLOAD_MIMES_ZIP,
        default => [],
    };
}

/**
 * Single source of truth for "may this user replace documents on this
 * survey?" — Admin always, Surveyor only if actually assigned to THIS
 * survey, nobody else (Super Admin and Client are deliberately excluded
 * even though they have broader read access elsewhere in this app).
 * Reused identically by every page's button visibility AND by
 * ajax/document_replace.php's own independent re-check — the backend
 * check is never skipped just because the UI already hid the button.
 */
function canReplaceDocument(PDO $db, string $role, int $userId, int $surveyId): bool
{
    if ($role === 'Admin') {
        return true;
    }
    if ($role === 'Surveyor') {
        return isSurveyorAssignedToSurvey($db, $surveyId, $userId);
    }
    return false;
}

/**
 * Replaces the file behind an existing `uploads` row IN PLACE: validates
 * the new file, writes it to disk, updates the SAME row (id/survey_id/
 * file_type untouched — only file_name/file_path/file_size/uploaded_at/
 * uploaded_by change), records a document_versions audit row, and only
 * THEN removes the old physical file — never before the new one is
 * confirmed saved and the DB row updated, so a failed replacement never
 * leaves the system pointing at a missing file.
 *
 * @return array{success:bool, message:string, upload:?array}
 */
function replaceUploadedDocument(PDO $db, int $documentId, array $newFile, int $actorUserId, string $actorRole, string $ip): array
{
    ensureDocumentVersionsTable($db);
    ensureUploadsUploadedByColumn($db);

    $stmt = $db->prepare("SELECT * FROM uploads WHERE id = ?");
    $stmt->execute([$documentId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        return ['success' => false, 'message' => 'Document not found.', 'upload' => null];
    }

    if (($newFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Upload failed (error code ' . ($newFile['error'] ?? '?') . ').', 'upload' => null];
    }
    $origName = basename((string) ($newFile['name'] ?? ''));
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowedExt = documentReplaceAllowedExtensions();
    if ($origName === '' || !in_array($ext, $allowedExt, true)) {
        return ['success' => false, 'message' => 'That file type is not allowed. Allowed: ' . implode(', ', $allowedExt) . '.', 'upload' => null];
    }
    $mimeSet = documentReplaceMimeSetFor($ext);
    $validationError = upload_validate($newFile, $mimeSet, 20 * 1024 * 1024);
    if ($validationError !== '') {
        return ['success' => false, 'message' => $validationError, 'upload' => null];
    }

    // Same directory the original upload lives in, so a replacement for a
    // Field-Report extra stays alongside other extras, a Formal Report PDF
    // replacement stays alongside other formal reports, etc. — determined
    // from the EXISTING row's own path, never from client input.
    $existingRel = (string) $existing['file_path'];
    $existingDir = dirname($existingRel);
    $targetDirRel = ($existingDir === '.' || $existingDir === '') ? 'uploads' : $existingDir;
    $targetDirAbs = __DIR__ . '/../' . $targetDirRel . '/';
    if (!is_dir($targetDirAbs)) {
        @mkdir($targetDirAbs, 0755, true);
    }

    // Secure generated filename — never trust/reuse the original filename
    // for the physical path (same convention as completed_remarks_helpers.php).
    $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
    $destAbs = $targetDirAbs . $safeName;

    if (!is_uploaded_file($newFile['tmp_name']) || !@move_uploaded_file($newFile['tmp_name'], $destAbs)) {
        return ['success' => false, 'message' => 'Could not save the new file. Please try again.', 'upload' => null];
    }
    @chmod($destAbs, 0640);
    $newBytes = (int) ($newFile['size'] ?? 0);
    if ($ext === 'pdf') {   // a replaced PDF is made smaller too (includes/pdf_compress.php)
        require_once __DIR__ . '/pdf_compress.php';
        $pc = pdfCompress($destAbs);
        if ($pc['compressed']) { $newBytes = $pc['after']; @chmod($destAbs, 0640); }
    }

    $newRel = $targetDirRel . '/' . $safeName;
    $newSizeLabel = formatDocumentFileSize($newBytes);

    try {
        $db->beginTransaction();

        // Same `id` the document had before — every other page's link
        // (download.php?upload_id=X), count, and status-transition check
        // in ajax/upload_handler.php keeps pointing at the right row.
        $upd = $db->prepare("UPDATE uploads SET file_name = ?, file_path = ?, file_size = ?, uploaded_at = NOW(), uploaded_by = ? WHERE id = ?");
        $upd->execute([$origName, $newRel, $newSizeLabel, $actorUserId, $documentId]);

        $hist = $db->prepare("INSERT INTO document_versions
            (document_id, survey_id, file_type, old_file_name, old_file_path, old_file_size,
             new_file_name, new_file_path, new_file_size, replaced_by, replaced_by_role, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $hist->execute([
            $documentId, (int) $existing['survey_id'], $existing['file_type'],
            $existing['file_name'], $existing['file_path'], $existing['file_size'],
            $origName, $newRel, $newSizeLabel,
            $actorUserId, $actorRole, $ip,
        ]);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('replaceUploadedDocument DB update: ' . $e->getMessage());
        // DB update failed — the OLD file is still on disk and the OLD row
        // still points to it (we haven't touched either yet), so the
        // existing document remains fully usable. Clean up the new file
        // we just wrote since nothing references it now.
        @unlink($destAbs);
        return ['success' => false, 'message' => 'Could not update the document record. Please try again.', 'upload' => null];
    }

    // Only remove the OLD physical file after the DB row has been
    // successfully repointed at the new one — a failure here is logged
    // but never surfaced as an error to the admin, since the replacement
    // itself already fully succeeded (an orphaned old file on disk is a
    // harmless cleanup gap, not a data-loss risk).
    $oldAbs = __DIR__ . '/../' . ltrim($existingRel, '/');
    $oldReal = realpath($oldAbs);
    $uploadsBase = realpath(__DIR__ . '/../uploads');
    if ($oldReal !== false && $uploadsBase !== false && strncmp($oldReal, $uploadsBase, strlen($uploadsBase)) === 0 && is_file($oldReal)) {
        @unlink($oldReal);
    }

    $updatedStmt = $db->prepare("SELECT * FROM uploads WHERE id = ?");
    $updatedStmt->execute([$documentId]);
    $updated = $updatedStmt->fetch(PDO::FETCH_ASSOC);

    return ['success' => true, 'message' => 'Document replaced successfully.', 'upload' => $updated ?: null];
}

/** "05 Oct 2026, 18:32" — same display format as completed_detail.php's own
 *  local fmtDateTime(), reimplemented here (under a different name, so
 *  this file can be required from any of the three pages, including the
 *  two that don't already define it) rather than duplicated inline. */
function fmtDocumentDateTime($val): string
{
    if (empty($val) || $val === '0000-00-00' || $val === '0000-00-00 00:00:00') return '—';
    $ts = strtotime($val);
    if (!$ts) return '—';
    return date('d M Y, H:i', $ts);
}

/** Small lookup for the "Uploaded By" display — `uploads.uploaded_by` is
 *  only ever populated going forward (see ensureUploadsUploadedByColumn()
 *  above), so this returns '' for any historical row that predates it;
 *  callers show a neutral fallback ("—") for that case. */
function getUserFullNameById(PDO $db, int $userId): string
{
    if ($userId <= 0) return '';
    try {
        $stmt = $db->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        return (string) ($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return '';
    }
}

/** 512000 -> "500 KB", matching the existing `file_size` column's style
 *  (a formatted label string, e.g. "245 KB" / "1.2 MB" — see
 *  ajax/upload_handler.php's hardcoded labels and database.sql's seed
 *  rows), rather than introducing a differently-formatted number. */
function formatDocumentFileSize(int $bytes): string
{
    if ($bytes <= 0) return '0 KB';
    if ($bytes < 1024 * 1024) {
        return round($bytes / 1024) . ' KB';
    }
    return round($bytes / 1024 / 1024, 1) . ' MB';
}

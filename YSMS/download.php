<?php
/**
 * Authenticated download proxy for the `uploads` table (Field Report /
 * Formal Report PDF/Excel/Word files shown on report_detail.php and
 * completed_detail.php).
 *
 * Those pages already gate the SURVEY DETAIL PAGE itself by ownership
 * (Admin/Super Admin full access, Surveyor if assigned, Client only for
 * their own client_id), but until now the download buttons linked straight
 * to the static file under uploads/ — so anyone who obtained or guessed
 * that URL could fetch it directly, bypassing the page-level check
 * entirely. This endpoint re-runs the exact same ownership check before
 * streaming the file, so a Client can only ever download a report that
 * belongs to their own company, no matter how the URL was obtained.
 */
require_once 'config/config.php';
checkAuth();

$db = getDB();
$uploadId = isset($_GET['upload_id']) ? (int)$_GET['upload_id'] : 0;
$remarkAttachmentId = isset($_GET['remark_attachment_id']) ? (int)$_GET['remark_attachment_id'] : 0;

if ($uploadId <= 0 && $remarkAttachmentId <= 0) {
    http_response_code(404);
    die('File not found.');
}

if ($remarkAttachmentId > 0) {
    // Same authenticated-download pattern as the `uploads` table below, for
    // completed_detail.php's Remarks attachments (completed_remark_attachments).
    $stmt = $db->prepare("
        SELECT a.id, a.file_name, a.file_path, s.id AS survey_id, s.client_id
        FROM completed_remark_attachments a
        JOIN completed_remarks r ON r.id = a.remark_id
        JOIN surveys s ON s.id = r.survey_id
        WHERE a.id = ?
    ");
    $stmt->execute([$remarkAttachmentId]);
    $upload = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    $stmt = $db->prepare("
        SELECT up.id, up.file_name, up.file_path, s.id AS survey_id, s.client_id
        FROM uploads up
        JOIN surveys s ON s.id = up.survey_id
        WHERE up.id = ?
    ");
    $stmt->execute([$uploadId]);
    $upload = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$upload) {
    http_response_code(404);
    die('File not found.');
}

$role = $_SESSION['role'] ?? '';
$userId = (int)($_SESSION['user_id'] ?? 0);
$hasFullAccess = in_array($role, ['Admin', 'Super Admin'], true);
$isOwnClient = ($role === 'Client') && (int)$upload['client_id'] === getClientIdForUser($db, $userId);
$authorized = $hasFullAccess || isSurveyorAssignedToSurvey($db, $upload['survey_id'], $userId) || $isOwnClient;

if (!$authorized) {
    http_response_code(403);
    die('Access Denied.');
}

// file_path is always one we wrote ourselves in upload_handler.php, but
// resolve+contain it under uploads/ anyway rather than trust it blindly.
$candidate = __DIR__ . '/' . ltrim((string)$upload['file_path'], '/');
$realBase = realpath(__DIR__ . '/uploads');
$realFile = realpath($candidate);

if ($realBase === false || $realFile === false || strncmp($realFile, $realBase, strlen($realBase)) !== 0 || !is_file($realFile)) {
    http_response_code(404);
    die('File not found.');
}

$cleanName = preg_replace('/^[0-9]+_/', '', (string)$upload['file_name']);
if ($cleanName === '' || $cleanName === null) {
    $cleanName = basename($realFile);
}

$ext = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
$mimeByExt = [
    'pdf'  => 'application/pdf',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'xls'  => 'application/vnd.ms-excel',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'doc'  => 'application/msword',
];
$mime = $mimeByExt[$ext] ?? 'application/octet-stream';

// 🌟 "View" (open inline, e.g. in a new tab) vs "Download" (force a Save
// dialog) — same authenticated/authorized file, same everything else
// below; only the disposition differs. Defaults to the original
// attachment behavior so every existing download.php link is unaffected.
$inline = isset($_GET['inline']) && $_GET['inline'] === '1';

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $cleanName) . '"');
header('Content-Length: ' . filesize($realFile));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

readfile($realFile);
exit;

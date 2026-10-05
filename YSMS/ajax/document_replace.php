<?php
/**
 * Document Replace / Re-upload — POST /ajax/document_replace.php
 *
 * Swaps the file behind an EXISTING `uploads` row (Field Report / Formal
 * Report PDF/Excel/Word) in place, used by the Replace button on
 * vessel_detail.php (Pending Vessels), report_detail.php (Pending
 * Reports) and completed_detail.php (Completed Vessels).
 *
 * POST multipart: document_id (int, required), new_file (file, required).
 * Response: {"success":true,"message":"Document replaced successfully.",
 *   "upload":{"id":..,"file_name":..,"file_type":..,"file_size":..,
 *   "uploaded_at":..,"download_url":..,"view_url":..}}
 *   or {"success":false,"message":"..."}
 *
 * Authorization is Admin or Surveyor ONLY — Super Admin and Client are
 * deliberately excluded here even though both have broader READ access
 * to these same documents elsewhere in this app (download.php). This is
 * re-checked independently of whatever the calling page's UI already hid,
 * and survey_id is always derived from the document row itself (never
 * taken from the request body), so a crafted document_id can only ever
 * resolve to that document's real, actual survey for the assignment check
 * below — never let the browser assert which survey a document belongs to.
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST only.']);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/document_replace_helpers.php';
checkAuth(); // session + CSRF (X-CSRF-Token header) on this POST

$role = $_SESSION['role'] ?? '';
$userId = (int) ($_SESSION['user_id'] ?? 0);

// 🌟 Feature-level RBAC: Replace is Admin + Surveyor ONLY. Super Admin and
// Client never reach this far regardless of what document_id they send.
if (!in_array($role, ['Admin', 'Surveyor'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied. Only Admin and Surveyor may replace documents.']);
    exit;
}

$documentId = isset($_POST['document_id']) ? (int) $_POST['document_id'] : 0;
if ($documentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid document.']);
    exit;
}

$db = getDB();
ensureUploadsUploadedByColumn($db);

// Document -> its real survey_id, straight from the database — the only
// source of truth for the assignment check below. A document_id for a
// survey this Surveyor isn't assigned to is rejected before anything else
// runs (IDOR prevention: no vessel_id/survey_id taken from the client).
$docStmt = $db->prepare("SELECT id, survey_id FROM uploads WHERE id = ?");
$docStmt->execute([$documentId]);
$docRow = $docStmt->fetch(PDO::FETCH_ASSOC);
if (!$docRow) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Document not found.']);
    exit;
}
$surveyId = (int) $docRow['survey_id'];

if (!canReplaceDocument($db, $role, $userId, $surveyId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You are not authorized to replace documents on this survey.']);
    exit;
}

if (empty($_FILES['new_file']) || !is_array($_FILES['new_file'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please choose a replacement file.']);
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$result = replaceUploadedDocument($db, $documentId, $_FILES['new_file'], $userId, $role, $ip);

if (!$result['success']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $result['message']]);
    exit;
}

$upload = $result['upload'];
$cleanName = preg_replace('/^[0-9]+_/', '', (string) ($upload['file_name'] ?? ''));
if ($cleanName === '' || $cleanName === null) {
    $cleanName = (string) ($upload['file_name'] ?? '');
}

error_log(json_encode([
    'evt' => 'document_replaced',
    'document_id' => $documentId,
    'survey_id' => $surveyId,
    'replaced_by' => $userId,
    'role' => $role,
]));

echo json_encode([
    'success' => true,
    'message' => $result['message'],
    'upload' => [
        'id' => (int) $upload['id'],
        'file_name' => $cleanName,
        'file_type' => $upload['file_type'],
        'file_size' => $upload['file_size'],
        'uploaded_at' => $upload['uploaded_at'],
        'download_url' => 'download.php?upload_id=' . (int) $upload['id'],
        'view_url' => 'download.php?upload_id=' . (int) $upload['id'] . '&inline=1',
    ],
]);

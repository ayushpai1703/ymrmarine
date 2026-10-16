<?php
/**
 * Admin inline edit — saves ONE field of a survey, or renames / retypes /
 * deletes ONE uploaded file. Called by the pencil popups on the detail pages
 * (includes/admin_inline_edit.php). Admin only; CSRF via X-CSRF-Token.
 *
 * POST field edit: survey_id, field, value | values[] | (recovery parts) …
 * POST file edit:  ?file=1, survey_id, file_id, action (rename|type|delete) …
 * POST add files:  ?add_files=1, survey_id, files[], file_type, read_recovery (Pending Report only)
 * Response: {"success":bool,"message":"…","redirect":"…" (status change only)}
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST only.']);
    exit;
}
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/includes/upload_validation.php';
require_once dirname(__DIR__) . '/includes/recovery_excel.php';
require_once dirname(__DIR__) . '/includes/document_replace_helpers.php';
require_once dirname(__DIR__) . '/includes/admin_inline_edit.php';
checkAuth(); // session + CSRF

if (($_SESSION['role'] ?? '') !== 'Admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only Admin can edit these details.']);
    exit;
}

$db = getDB();
$surveyId = (int)($_POST['survey_id'] ?? 0);
$st = $db->prepare('SELECT * FROM surveys WHERE id = ?');
$st->execute([$surveyId]);
$survey = $st->fetch(PDO::FETCH_ASSOC);
if (!$survey) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Vessel not found.']);
    exit;
}

if (!empty($_GET['add_files'])) {
    $res = aieAddFiles($db, $survey, $_FILES['files'] ?? [], (string)($_POST['file_type'] ?? ''), !empty($_POST['read_recovery']), (int)$_SESSION['user_id']);
} elseif (!empty($_GET['file'])) {
    $fileId = (int)($_POST['file_id'] ?? 0);
    // The file must belong to this survey (never trust the pair from the browser).
    $own = $db->prepare('SELECT 1 FROM uploads WHERE id = ? AND survey_id = ?');
    $own->execute([$fileId, $surveyId]);
    $res = $own->fetchColumn()
        ? aieFileAction($db, $fileId, (string)($_POST['action'] ?? ''), $_POST)
        : ['ok' => false, 'message' => 'File not found on this vessel.'];
} else {
    $res = aieSaveField($db, $survey, (string)($_POST['field'] ?? ''), $_POST, $_FILES, (int)$_SESSION['user_id']);
}

if ($res['ok']) {
    $_SESSION['flash_msg'] = $res['message'];
    $_SESSION['flash_type'] = 'success';
}
echo json_encode(['success' => $res['ok'], 'message' => $res['message']] + (isset($res['redirect']) ? ['redirect' => $res['redirect']] : []));

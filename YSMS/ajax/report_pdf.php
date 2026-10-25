<?php
/**
 * Report Generator — PDF input source (includes/report_pdf.php). POST only, logged-in
 * Admin / Super Admin / Surveyor + CSRF.
 *   action=extract  file (one PDF), section, fields (JSON: the page's REPORT_FIELDS) → values with page + status
 *   action=calc     section, tanks (JSON)  → fuel totals with the approved format's formulas
 * The PDF is read from PHP's temporary upload file and deleted right after; nothing is stored.
 */
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/config/config.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST only.']);
    exit;
}
checkAuth();
function rpOut(array $b, int $code = 200): void { http_response_code($code); echo json_encode($b, JSON_UNESCAPED_UNICODE); exit; }
if (!in_array($_SESSION['role'] ?? '', ['Admin', 'Super Admin', 'Surveyor'], true)) rpOut(['success' => false, 'message' => 'Not allowed.'], 403);
session_write_close();
require_once dirname(__DIR__) . '/includes/report_pdf.php';

$action = (string)($_POST['action'] ?? '');
$section = (string)($_POST['section'] ?? '');
if (!isset(RP_SECTION_FORMAT[$section])) rpOut(['success' => false, 'message' => 'Unknown report type.'], 400);

$tmp = '';
try {
    if ($action === 'extract') {
        $f = $_FILES['file'] ?? null;
        $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) rpOut(['success' => false, 'message' => 'Choose a PDF file.', 'retry' => false], 400);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || (int)$f['size'] > RP_MAX_BYTES) rpOut(['success' => false, 'message' => 'This PDF is larger than 14 MB. Split it (e.g. one document per file) and upload the parts.', 'retry' => false], 413);
        if ($err !== UPLOAD_ERR_OK) rpOut(['success' => false, 'message' => 'The upload did not complete.', 'retry' => true], 400);
        $tmp = (string)$f['tmp_name'];
        $isPdf = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) === 'pdf'
            && (!function_exists('finfo_open') || finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmp) === 'application/pdf');
        if (!$isPdf) rpOut(['success' => false, 'message' => 'That file is not a PDF.', 'retry' => false], 415);
        $defs = rpCleanFieldDefs(json_decode((string)($_POST['fields'] ?? ''), true));
        if (!$defs) rpOut(['success' => false, 'message' => 'Report fields missing.', 'retry' => false], 400);
        @set_time_limit(240);
        $res = rpExtract($tmp, $defs, $section);
        if (!$res['ok']) rpOut(['success' => false, 'message' => $res['message'], 'retry' => $res['retry']], 502);
        rpOut(['success' => true, 'doc' => $res['doc']]);
    }
    if ($action === 'calc') {
        $tanks = json_decode((string)($_POST['tanks'] ?? ''), true);
        if (!is_array($tanks)) rpOut(['success' => false, 'message' => 'Tank readings missing.'], 400);
        $res = rpCalcTotals($section, $tanks);
        rpOut(['success' => $res['ok']] + $res, $res['ok'] ? 200 : 422);
    }
    rpOut(['success' => false, 'message' => 'Unknown action.'], 400);
} catch (Throwable $e) {
    error_log('report_pdf ' . $action . ': ' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
    rpOut(['success' => false, 'message' => 'The PDF could not be processed. Try again, or fill the form by hand.', 'retry' => true], 500);
} finally {
    if ($tmp !== '' && is_file($tmp)) @unlink($tmp);
}

<?php
/**
 * Report template management (admin_report_templates.php). Admin only; CSRF for POST is
 * checked in config.php.
 *
 *   GET  a=list&kind=offhire          versions (newest first) with their analysis
 *   POST a=upload  kind, file, notes  new version (the file is stored as uploaded, never replaced)
 *   POST a=activate id                use this version for new reports
 *   POST a=mapping id, fields (JSON), print_sheets (JSON)
 *   POST a=test    id                 generate the template's own data → xlsx + pdf + comparison
 *   GET  a=file&id=&which=original|test&path=   download
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/report_templates.php';
checkAuth();

function rtJson(int $code, array $d): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($d);
    exit;
}
if (($_SESSION['role'] ?? '') !== 'Admin') rtJson(403, ['ok' => false, 'error' => 'Only Admin can manage report templates.']);
$db = getDB();
rtEnsureTables($db);
$a = (string)($_REQUEST['a'] ?? '');
$uid = (int)($_SESSION['user_id'] ?? 0);
@set_time_limit(180);
@ini_set('memory_limit', '512M');

try {
    if ($a === 'list') {
        $kind = (string)($_GET['kind'] ?? 'offhire');
        $rows = array_map(function ($t) {
            unset($t['analysis']['sample'], $t['analysis']['expected']);
            $t['effective_fields'] = rtEffective($t)['fields'];
            $t['effective_print'] = rtEffective($t)['print_sheets'];
            return $t;
        }, rtTemplates($db, $kind));
        rtJson(200, ['ok' => true, 'templates' => $rows, 'groups' => RT_GROUPS, 'soffice' => rtSoffice() !== '']);
    }
    if ($a === 'file') {
        $t = rtTemplate($db, (int)($_GET['id'] ?? 0));
        if (!$t) rtJson(404, ['ok' => false, 'error' => 'Not found.']);
        $which = (string)($_GET['which'] ?? 'original');
        if ($which === 'original') { $abs = rtAbs($t['file_path']); $name = 'v' . $t['version'] . ' - ' . $t['original_name']; }
        else {
            $rel = (string)($_GET['path'] ?? '');
            if (!preg_match('#^uploads/report_templates/tests/test-' . preg_quote($t['kind'], '#') . '-v' . $t['version'] . '-[0-9-]+\.(xlsx|pdf)$#', $rel)) rtJson(404, ['ok' => false, 'error' => 'Not found.']);
            $abs = rtAbs($rel); $name = 'TEST v' . $t['version'] . ' - ' . basename($rel);
        }
        if (!$abs) rtJson(404, ['ok' => false, 'error' => 'File not found.']);
        $pdf = substr($abs, -4) === '.pdf';
        header('Content-Type: ' . ($pdf ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
        header('Content-Disposition: ' . ($pdf && !empty($_GET['inline']) ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $name) . '"');
        header('Content-Length: ' . filesize($abs));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($abs);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') rtJson(400, ['ok' => false, 'error' => 'Unknown action.']);
    if ($a === 'upload') {
        $r = rtUpload($db, (string)($_POST['kind'] ?? 'offhire'), $_FILES['file'] ?? [], (string)($_POST['notes'] ?? ''), $uid);
        rtJson($r['ok'] ? 200 : 422, $r);
    }
    $t = rtTemplate($db, (int)($_POST['id'] ?? 0));
    if (!$t) rtJson(404, ['ok' => false, 'error' => 'Template version not found.']);
    if ($a === 'activate') rtJson(rtActivate($db, $t['id'], $uid) ? 200 : 422, ['ok' => true]);
    if ($a === 'mapping') {
        $r = rtSaveMapping($db, $t, (array)json_decode((string)($_POST['fields'] ?? '{}'), true), (array)json_decode((string)($_POST['print_sheets'] ?? '[]'), true), $uid);
        rtJson($r['ok'] ? 200 : 422, $r);
    }
    if ($a === 'test') rtJson(200, rtTestGenerate($t, true));
} catch (Throwable $e) {
    error_log('ajax/report_templates ' . $a . ': ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    rtJson(500, ['ok' => false, 'error' => 'Something went wrong. Please try again.']);
}
rtJson(400, ['ok' => false, 'error' => 'Unknown action.']);

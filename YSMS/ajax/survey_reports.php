<?php
/**
 * Survey Reports API (survey_reports.php, survey_report.php). Access: includes/survey_reports.php.
 * CSRF for POST is checked in config.php.
 *
 *   GET  a=token                      the current CSRF token (after signing in again)
 *   GET  a=list                       reports this user can see
 *   GET  a=surveys&q=                 vessels a new report can be made for
 *   POST a=start   survey_id, kind    "Start Survey" on a vessel: opens its report (the draft when one exists, else a new one)
 *   GET  a=for_survey&survey_id=      the vessel's report (id, type, state) or none
 *   GET  a=tank_history&id= | &survey_id=   version history of the saved tanks (Admin only)
 *   GET  a=preview&id=                the report as it prints now, as a temporary PDF (nothing is stored)
 *   GET  a=get&id=                    data, version, form config, files
 *   POST a=save    id, version, data  saves the draft (refused when someone saved meanwhile)
 *   POST a=generate id, pdf=1         Excel (+ PDF) from the saved data
 *   POST a=use_latest id              move the report to the active template version
 *   POST a=lookup  id, imo            vessel particulars by IMO (AI + web search); nothing is saved
 *   GET  a=version&id=                the report's version (live check)
 *   GET  a=previous&id=               an earlier attendance of the vessel (particulars, grades, tank names + heights)
 *   GET  a=verify&id=                server calculation of the saved data + what is still missing
 *   GET  a=file&id=<file id>          download a generated file
 *   GET  a=template&id=<report id>    the report's template workbook (for the offline Excel)
 */
ob_start(); // a stray warning / notice must never break the JSON answer
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/survey_reports.php';

function srJson(int $code, array $d): void {
    $stray = '';
    while (ob_get_level() > 0) $stray .= (string)ob_get_clean();
    if (trim($stray) !== '') error_log('ajax/survey_reports stray output: ' . mb_substr(trim(strip_tags($stray)), 0, 300));
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($d);
    exit;
}
// signed out (session expired, or the server instance changed): a JSON answer, not the login redirect
// (which would land on ajax/login.php and answer "Invalid Request" with 200 → "Server error (200)")
if (empty($_SESSION['user_id'])) srJson(401, ['ok' => false, 'login' => true, 'error' => 'Your session has expired — sign in again. Your changes are kept on this device.']);
csrf_require();
if (!srCanUse()) srJson(403, ['ok' => false, 'error' => 'You do not have access to Survey Reports.']);
$db = getDB();
rtEnsureTables($db);
srEnsureHistory($db);
$a = (string)($_REQUEST['a'] ?? '');
$uid = (int)($_SESSION['user_id'] ?? 0);
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
@set_time_limit(180);
@ini_set('memory_limit', '512M');

$scope = function () use ($uid): array {
    if (in_array(srRole(), ['Admin', 'Super Admin'], true)) return ['1=1', []];
    return ['(r.created_by = ? OR s.surveyor_id = ? OR s.id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = ?))', [$uid, $uid, $uid]];
};

try {
    if ($a === 'token') srJson(200, ['ok' => true, 'csrf' => csrf_token()]); // a fresh token after signing in again in another tab
    if ($a === 'list') {
        [$w, $p] = $scope();
        $q = $db->prepare("SELECT r.id, r.survey_id, r.kind, r.version, r.data, r.updated_at, r.created_at, s.vessel_name AS s_vessel, s.report_number, s.status, t.version AS template_version,
                u.full_name AS updated_by_name, cu.full_name AS created_by_name, (SELECT COUNT(*) FROM survey_report_files f WHERE f.report_id = r.id) AS files,
                (SELECT MAX(f.created_at) FROM survey_report_files f WHERE f.report_id = r.id) AS last_file,
                (SELECT MAX(f.id) FROM survey_report_files f WHERE f.report_id = r.id AND f.type = 'pdf') AS last_pdf,
                (SELECT MAX(f.id) FROM survey_report_files f WHERE f.report_id = r.id AND f.type = 'xlsx') AS last_xlsx,
                (SELECT COUNT(*) FROM survey_tank_entry_history h WHERE h.report_id = r.id AND h.version_number > 1) AS tank_changes
            FROM survey_reports r LEFT JOIN surveys s ON s.id = r.survey_id LEFT JOIN report_templates t ON t.id = r.template_id
            LEFT JOIN users u ON u.id = r.updated_by LEFT JOIN users cu ON cu.id = r.created_by
            WHERE $w ORDER BY r.updated_at DESC LIMIT 500");
        $q->execute($p);
        $rows = array_map(function ($r) {
            $d = json_decode((string)$r['data'], true) ?: [];
            $r['vessel_name'] = trim((string)($d['fields']['vessel_name'] ?? '')) ?: ($r['s_vessel'] ?: '');
            $r['kind_label'] = RT_KINDS[$r['kind']] ?? $r['kind'];
            unset($r['data'], $r['s_vessel']);
            return $r;
        }, $q->fetchAll(PDO::FETCH_ASSOC));
        $kinds = [];
        foreach (RT_KINDS as $k => $label) $kinds[] = ['key' => $k, 'label' => $label, 'active' => (bool)rtActive($db, $k)];
        srJson(200, ['ok' => true, 'reports' => $rows, 'can_create' => false, 'kinds' => $kinds,
            'active' => (bool)array_filter($kinds, fn($k) => $k['active']), 'is_admin' => srRole() === 'Admin']);
    }
    if ($a === 'surveys') {
        [$w, $p] = $scope();
        $like = '%' . addcslashes(mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60), '%_\\') . '%';
        $st = srRole() === 'Admin' ? "s.status <> 'Cancelled'" : "s.status IN ('Pending Vessel', 'Pending Report')";
        $q = $db->prepare("SELECT s.id, s.vessel_name, s.report_number, s.status, p.port_name, DATE(s.assign_date) AS assigned FROM surveys s LEFT JOIN ports p ON p.id = s.port_id
            WHERE $w AND $st AND (s.vessel_name LIKE ? OR s.report_number LIKE ?) ORDER BY FIELD(s.status, 'Pending Report', 'Pending Vessel', 'Completed'), s.assign_date DESC LIMIT 50");
        $q->execute([...$p, $like, $like]);
        srJson(200, ['ok' => true, 'surveys' => $q->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($a === 'file') {
        $q = $db->prepare('SELECT * FROM survey_report_files WHERE id = ?');
        $q->execute([(int)($_GET['id'] ?? 0)]);
        $f = $q->fetch(PDO::FETCH_ASSOC);
        if (!$f || !srReport($db, (int)$f['report_id']) || !($abs = rtAbs($f['file_path']))) srJson(404, ['ok' => false, 'error' => 'File not found.']);
        // the Excel stays in the system (the Word report generator reads it); only the Admin can download it
        if ($f['type'] !== 'pdf' && srRole() !== 'Admin') srJson(403, ['ok' => false, 'error' => 'The Excel report is kept in the system and used by the Word report generator.']);
        $pdf = $f['type'] === 'pdf';
        header('Content-Type: ' . ($pdf ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
        header('Content-Disposition: ' . ($pdf && !empty($_GET['inline']) ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $f['file_name']) . '"');
        header('Content-Length: ' . filesize($abs));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        while (ob_get_level() > 0) ob_end_clean(); // stream the file, nothing before it
        readfile($abs);
        exit;
    }
    if ($a === 'template') {
        $r = srReport($db, (int)($_GET['id'] ?? 0));
        $t = $r ? rtTemplate($db, $r['template_id']) : null;
        if (!$r || !$t || !($abs = rtAbs($t['file_path']))) srJson(404, ['ok' => false, 'error' => 'Template not found.']);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="template-v' . $t['version'] . '.xlsx"');
        header('Cache-Control: private, max-age=31536000, immutable'); // a template version never changes
        header('X-Content-Type-Options: nosniff');
        while (ob_get_level() > 0) ob_end_clean(); // stream the file, nothing before it
        readfile($abs);
        exit;
    }
    if ($a === 'get') {
        $r = srReport($db, (int)($_GET['id'] ?? 0));
        if (!$r) srJson(404, ['ok' => false, 'error' => 'Report not found.']);
        $t = rtTemplate($db, $r['template_id']);
        $active = rtActive($db, $r['kind']);
        srJson(200, ['ok' => true, 'report' => ['id' => $r['id'], 'version' => $r['version'], 'data' => $r['data'], 'updated_at' => $r['updated_at'], 'updated_by' => $r['updated_by_name'],
            'vessel' => srTitle($r), 'survey_id' => (int)$r['survey_id'], 'status' => $r['survey']['status'] ?? '', 'kind' => $r['kind'], 'kind_label' => RT_KINDS[$r['kind']] ?? $r['kind']],
            'is_admin' => srRole() === 'Admin',
            'can_edit' => srCanEdit($r['survey'], $r), 'config' => srEditorConfig($t), 'files' => srFiles($db, $r['id']),
            'newer_template' => $active && $active['id'] !== $t['id'] ? ['id' => $active['id'], 'version' => $active['version']] : null]);
    }
    if ($a === 'tank_history') { // Admin only — checked before anything is looked up
        if (srRole() !== 'Admin') srJson(403, ['ok' => false, 'error' => 'Only Admin can see the tank history.']);
        if (!empty($_GET['survey_id'])) {
            $s = sfSurvey($db, (int)$_GET['survey_id']);
            if (!$s || !sfCanAccess($db, $s)) srJson(404, ['ok' => false, 'error' => 'Vessel not found.']);
            $r = srReportForSurvey($db, (int)$s['id']);
            if (!$r) srJson(200, ['ok' => true, 'report' => null, 'tanks' => []]);
        } else {
            $r = srReport($db, (int)($_GET['id'] ?? 0));
            if (!$r) srJson(404, ['ok' => false, 'error' => 'Report not found.']);
        }
        srJson(200, ['ok' => true, 'report' => ['id' => $r['id'], 'kind_label' => RT_KINDS[$r['kind']] ?? $r['kind']], 'tanks' => srTankHistory($db, $r)]);
    }
    if ($a === 'for_survey') {
        $s = sfSurvey($db, (int)($_GET['survey_id'] ?? 0));
        if (!$s || !sfCanAccess($db, $s)) srJson(404, ['ok' => false, 'error' => 'Vessel not found.']);
        $r = srReportForSurvey($db, (int)$s['id']);
        srJson(200, ['ok' => true, 'report' => $r ? ['id' => $r['id'], 'kind' => $r['kind'], 'kind_label' => RT_KINDS[$r['kind']] ?? $r['kind'], 'updated_at' => $r['updated_at'], 'updated_by' => $r['updated_by_name']] : null,
            'can_start' => in_array(srRole(), ['Admin', 'Surveyor'], true) && srCanEdit($s)]);
    }
    if ($a === 'preview') { // view only: nothing is stored, no file record, no status change
        $r = srReport($db, (int)($_GET['id'] ?? 0));
        $t = $r ? rtTemplate($db, $r['template_id']) : null;
        if (!$r || !$t || !rtAbs($t['file_path'])) srJson(404, ['ok' => false, 'error' => 'Report not found.']);
        $p = srPreviewPdf($t, $r);
        if (!$p['ok']) srJson(422, ['ok' => false, 'error' => 'The preview could not be made: ' . $p['error']]);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="preview.pdf"');
        header('Content-Length: ' . filesize($p['pdf']));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Report-Version: ' . $r['version']);
        while (ob_get_level() > 0) ob_end_clean(); // stream the file, nothing before it
        readfile($p['pdf']);
        exit;
    }
    if ($a === 'verify') { // view-only users see the checked summary too
        $r = srReport($db, (int)($_GET['id'] ?? 0));
        $t = $r ? rtTemplate($db, $r['template_id']) : null;
        if (!$r || !$t) srJson(404, ['ok' => false, 'error' => 'Report not found.']);
        srJson(200, ['ok' => true] + srVerify($t, $r['data']));
    }
    if ($a === 'version') { // the live check of an open report: only its version (cheap), the page then loads it when newer
        $r = srReport($db, (int)($_GET['id'] ?? 0));
        if (!$r) srJson(404, ['ok' => false, 'error' => 'Report not found.']);
        srJson(200, ['ok' => true, 'version' => $r['version']]);
    }
    if ($a === 'previous') { // "Fill from previous attendance": particulars, grades, tank names + heights of an earlier survey of the vessel
        $r = srReport($db, (int)($_GET['id'] ?? 0));
        if (!$r) srJson(404, ['ok' => false, 'error' => 'Report not found.']);
        if (!srCanEdit($r['survey'], $r)) srJson(403, ['ok' => false, 'error' => 'View only.']);
        srJson(200, ['ok' => true, 'previous' => srPrevious($db, $r)]);
    }
    if (!$isPost) srJson(400, ['ok' => false, 'error' => 'Unknown action.']);

    if ($a === 'start') { // only from a vessel: no report without a valid vessel
        if (!in_array(srRole(), ['Admin', 'Surveyor'], true)) srJson(403, ['ok' => false, 'error' => 'View only.']);
        $s = sfSurvey($db, (int)($_POST['survey_id'] ?? 0));
        if (!$s || !sfCanAccess($db, $s)) srJson(404, ['ok' => false, 'error' => 'Vessel not found.']);
        if (!srCanEdit($s)) { // not pending any more: an existing report opens (view), nothing new is made
            $ex = srReportForSurvey($db, (int)$s['id']);
            if ($ex) srJson(200, ['ok' => true, 'id' => $ex['id'], 'created' => false]);
            srJson(403, ['ok' => false, 'error' => 'This vessel is no longer pending — a survey cannot be started.']);
        }
        $res = srStart($db, $s, (string)($_POST['kind'] ?? ''), $uid);
        srJson($res['ok'] ? 200 : 422, $res);
    }

    $r = srReport($db, (int)($_POST['id'] ?? 0));
    if (!$r) srJson(404, ['ok' => false, 'error' => 'Report not found.']);
    if (!srCanEdit($r['survey'], $r)) srJson(403, ['ok' => false, 'error' => srRole() === 'Super Admin' ? 'View only.' : 'This vessel is no longer pending — the report can no longer be changed.']);
    $t = rtTemplate($db, $r['template_id']);
    $eff = rtEffective($t);

    if ($a === 'save' || $a === 'generate') {
        $errors = [];
        if (isset($_POST['data'])) {
            $in = json_decode((string)$_POST['data'], true);
            if (!is_array($in)) srJson(422, ['ok' => false, 'error' => 'Bad data.']);
            if ((int)($_POST['version'] ?? 0) !== $r['version']) {
                srJson(409, ['ok' => false, 'conflict' => true, 'error' => 'Someone else saved this report meanwhile (' . ($r['updated_by_name'] ?: 'another user') . ', ' . substr((string)$r['updated_at'], 0, 16) . ').', 'server' => ['version' => $r['version'], 'data' => $r['data']]]);
            }
            [$clean, $errors] = rtCleanData($eff, $in);
            // keep what was typed even when a value is still wrong (it is a draft); generation needs it clean
            $store = $clean;
            foreach ($errors as $k => $msg) {
                if (strpos($k, 'f:') === 0) { $fk = substr($k, 2); $store['fields'][$fk] = (string)(is_array($in['fields'][$fk] ?? null) ? ($in['fields'][$fk]['v'] ?? '') : ($in['fields'][$fk] ?? '')); }
            }
            $errors = array_filter($errors, fn($k) => strpos($k, 't:') !== 0, ARRAY_FILTER_USE_KEY); // tanks are checked per tank (srTankErrors)
            srCleanExtra($eff, $in, $store, $errors); // grades, step, extra values, tank rows of every grade as typed
            $ver = $r['version'] + 1;
            $versions = 0;
            // the current values and the tank versions are written together or not at all
            $db->beginTransaction();
            try {
                $lock = $db->prepare('SELECT version FROM survey_reports WHERE id = ? FOR UPDATE');
                $lock->execute([$r['id']]);
                if ((int)$lock->fetchColumn() !== $r['version']) {
                    $db->rollBack();
                    srJson(409, ['ok' => false, 'conflict' => true, 'error' => 'Someone else saved this report meanwhile — reload it.']);
                }
                $upd = $db->prepare('UPDATE survey_reports SET data = ?, version = ?, updated_by = ?, updated_at = ? WHERE id = ? AND version = ?');
                $upd->execute([json_encode($store), $ver, $uid ?: null, date('Y-m-d H:i:s'), $r['id'], $r['version']]);
                $versions = srTankVersionsApply($db, $r, $store, false, $uid);
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
            $r['version'] = $ver; $r['data'] = $store;
        }
        if ($a === 'save') srJson(200, ['ok' => true, 'version' => $r['version'], 'errors' => (object)$errors, 'tank_versions' => $versions ?? 0]);
        $g = srGenerate($db, $r, !empty($_POST['pdf']), $uid);
        if (!$g['ok']) srJson(422, ['ok' => false, 'error' => $g['error'], 'issues' => $g['issues'] ?? [], 'version' => $r['version']]);
        // the report as made: tanks still being edited are recorded too, so no reading escapes the history
        $db->beginTransaction();
        try {
            $db->prepare('SELECT id FROM survey_reports WHERE id = ? FOR UPDATE')->execute([$r['id']]);
            srTankVersionsApply($db, $r, $r['data'], true, $uid);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        srJson(200, ['ok' => true, 'version' => $r['version'], 'files' => srFiles($db, $r['id']), 'new_files' => $g['file_ids'], 'results' => $g['results'], 'pdf_error' => $g['pdf_error']]);
    }
    if ($a === 'lookup') {
        $last = (int)($_SESSION['sr_lookup_at'] ?? 0);
        if (time() - $last < 5) srJson(429, ['ok' => false, 'error' => 'Please wait a few seconds before the next lookup.']);
        $_SESSION['sr_lookup_at'] = time();
        $types = array_map(fn($f) => $f['type'], $eff['fields']);
        $res = srVesselLookup((string)($_POST['imo'] ?? ''), $types);
        srJson($res['ok'] ? 200 : 422, $res);
    }
    if ($a === 'use_latest') {
        $active = rtActive($db, $r['kind']);
        if (!$active) srJson(422, ['ok' => false, 'error' => 'No active template.']);
        $db->prepare('UPDATE survey_reports SET template_id = ?, version = version + 1, updated_by = ?, updated_at = ? WHERE id = ?')->execute([$active['id'], $uid ?: null, date('Y-m-d H:i:s'), $r['id']]);
        srJson(200, ['ok' => true]);
    }
} catch (Throwable $e) {
    error_log('ajax/survey_reports ' . $a . ': ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    srJson(500, ['ok' => false, 'error' => 'Something went wrong. Please try again.']);
}
srJson(400, ['ok' => false, 'error' => 'Unknown action.']);

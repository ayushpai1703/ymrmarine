<?php
/**
 * Survey Reports API (survey_reports.php, survey_report.php). Access: includes/survey_reports.php.
 * CSRF for POST is checked in config.php.
 *
 *   GET  a=list                       reports this user can see
 *   GET  a=surveys&q=                 vessels a new report can be made for
 *   POST a=create  survey_id, kind    new report on the active template version
 *   GET  a=get&id=                    data, version, form config, files
 *   POST a=save    id, version, data  saves the draft (refused when someone saved meanwhile)
 *   POST a=generate id, pdf=1         Excel (+ PDF) from the saved data
 *   POST a=use_latest id              move the report to the active template version
 *   GET  a=file&id=<file id>          download a generated file
 *   GET  a=template&id=<report id>    the report's template workbook (for the offline Excel)
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/survey_reports.php';
checkAuth();

function srJson(int $code, array $d): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($d);
    exit;
}
if (!srCanUse()) srJson(403, ['ok' => false, 'error' => 'You do not have access to Survey Reports.']);
$db = getDB();
rtEnsureTables($db);
$a = (string)($_REQUEST['a'] ?? '');
$uid = (int)($_SESSION['user_id'] ?? 0);
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
@set_time_limit(180);
@ini_set('memory_limit', '512M');

$scope = function () use ($uid): array {
    if (in_array(srRole(), ['Admin', 'Super Admin'], true)) return ['1=1', []];
    return ['(s.surveyor_id = ? OR s.id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = ?))', [$uid, $uid]];
};

try {
    if ($a === 'list') {
        [$w, $p] = $scope();
        $q = $db->prepare("SELECT r.id, r.survey_id, r.kind, r.version, r.updated_at, r.created_at, s.vessel_name, s.report_number, s.status, t.version AS template_version,
                u.full_name AS updated_by_name, (SELECT COUNT(*) FROM survey_report_files f WHERE f.report_id = r.id) AS files,
                (SELECT MAX(f.created_at) FROM survey_report_files f WHERE f.report_id = r.id) AS last_file
            FROM survey_reports r JOIN surveys s ON s.id = r.survey_id LEFT JOIN report_templates t ON t.id = r.template_id LEFT JOIN users u ON u.id = r.updated_by
            WHERE $w ORDER BY r.updated_at DESC LIMIT 500");
        $q->execute($p);
        srJson(200, ['ok' => true, 'reports' => $q->fetchAll(PDO::FETCH_ASSOC), 'can_create' => srRole() !== 'Super Admin', 'active' => (bool)rtActive($db, 'offhire')]);
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
        $pdf = $f['type'] === 'pdf';
        header('Content-Type: ' . ($pdf ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
        header('Content-Disposition: ' . ($pdf && !empty($_GET['inline']) ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $f['file_name']) . '"');
        header('Content-Length: ' . filesize($abs));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
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
        readfile($abs);
        exit;
    }
    if ($a === 'get') {
        $r = srReport($db, (int)($_GET['id'] ?? 0));
        if (!$r) srJson(404, ['ok' => false, 'error' => 'Report not found.']);
        $t = rtTemplate($db, $r['template_id']);
        $active = rtActive($db, $r['kind']);
        srJson(200, ['ok' => true, 'report' => ['id' => $r['id'], 'version' => $r['version'], 'data' => $r['data'], 'updated_at' => $r['updated_at'], 'updated_by' => $r['updated_by_name'],
            'vessel' => $r['survey']['vessel_name'], 'survey_id' => (int)$r['survey_id'], 'status' => $r['survey']['status']],
            'can_edit' => srCanEdit($r['survey']), 'config' => srEditorConfig($t), 'files' => srFiles($db, $r['id']),
            'newer_template' => $active && $active['id'] !== $t['id'] ? ['id' => $active['id'], 'version' => $active['version']] : null]);
    }
    if (!$isPost) srJson(400, ['ok' => false, 'error' => 'Unknown action.']);

    if ($a === 'create') {
        if (srRole() === 'Super Admin') srJson(403, ['ok' => false, 'error' => 'View only.']);
        $kind = (string)($_POST['kind'] ?? 'offhire');
        $s = sfSurvey($db, (int)($_POST['survey_id'] ?? 0));
        if (!$s || !sfCanAccess($db, $s)) srJson(404, ['ok' => false, 'error' => 'Vessel not found.']);
        if (!srCanEdit($s)) srJson(403, ['ok' => false, 'error' => 'Reports can be made while the vessel is Pending Vessel / Pending Report.']);
        $t = rtActive($db, $kind);
        if (!$t) srJson(422, ['ok' => false, 'error' => 'No ' . (RT_KINDS[$kind] ?? $kind) . ' template is active yet. An Admin uploads it under Report Templates.']);
        $now = date('Y-m-d H:i:s');
        $db->prepare('INSERT INTO survey_reports (survey_id, template_id, kind, data, version, created_by, created_at, updated_by, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?)')
           ->execute([$s['id'], $t['id'], $kind, json_encode(srDefaults(rtEffective($t), $s)), $uid ?: null, $now, $uid ?: null, $now]);
        srJson(200, ['ok' => true, 'id' => (int)$db->lastInsertId()]);
    }

    $r = srReport($db, (int)($_POST['id'] ?? 0));
    if (!$r) srJson(404, ['ok' => false, 'error' => 'Report not found.']);
    if (!srCanEdit($r['survey'])) srJson(403, ['ok' => false, 'error' => srRole() === 'Super Admin' ? 'View only.' : 'This vessel is no longer pending — the report can no longer be changed.']);
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
            foreach ($eff['sections'] as $sk => $sec) { // tank rows as typed (row positions must stay as the user sees them)
                $store['tanks'][$sk] = array_values(array_map(fn($row) => array_map(fn($c) => is_scalar($row[$c] ?? '') ? mb_substr(trim((string)($row[$c] ?? '')), 0, 60) : '', array_combine(array_keys(RT_TANK_COLS), array_keys(RT_TANK_COLS))),
                    array_slice((array)($in['tanks'][$sk] ?? []), 0, RT_MAX_TANKS)));
            }
            $ver = $r['version'] + 1;
            $upd = $db->prepare('UPDATE survey_reports SET data = ?, version = ?, updated_by = ?, updated_at = ? WHERE id = ? AND version = ?');
            $upd->execute([json_encode($store), $ver, $uid ?: null, date('Y-m-d H:i:s'), $r['id'], $r['version']]);
            if (!$upd->rowCount()) srJson(409, ['ok' => false, 'conflict' => true, 'error' => 'Someone else saved this report meanwhile — reload it.']);
            $r['version'] = $ver; $r['data'] = $store;
        }
        if ($a === 'save') srJson(200, ['ok' => true, 'version' => $r['version'], 'errors' => (object)$errors]);
        $g = srGenerate($db, $r, !empty($_POST['pdf']), $uid);
        if (!$g['ok']) srJson(422, ['ok' => false, 'error' => $g['error'], 'errors' => (object)($g['errors'] ?? []), 'version' => $r['version']]);
        srJson(200, ['ok' => true, 'version' => $r['version'], 'files' => srFiles($db, $r['id']), 'new_files' => $g['file_ids'], 'results' => $g['results'], 'pdf_error' => $g['pdf_error']]);
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

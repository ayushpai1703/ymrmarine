<?php
/**
 * Live Survey Reports API — Admin only (live_survey_reports.php). Reports made on the survey report link.
 *
 *   GET  a=list                    the reports with something typed (vessel / IMO / surveyor), status counts
 *   GET  a=detail&id=              one report: tanks as they are now, every saved version of each tank,
 *                                  log book ROB per grade, the calculation constants, files made + their downloads, lock
 *   POST a=lock    id, hours       the link becomes read only now (0) or after 1 / 4 / 24 hours
 *   POST a=unlock  id              edit access again on the link
 * CSRF for POST is checked by checkAuth() (config.php).
 */
ob_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/live_survey_reports.php';

function lsrJson(int $code, array $d): void {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($d);
    exit;
}

if (empty($_SESSION['user_id'])) lsrJson(401, ['ok' => false, 'error' => 'Sign in again.']);
checkAuth();
if (($_SESSION['role'] ?? '') !== 'Admin') lsrJson(403, ['ok' => false, 'error' => 'Admin only.']);

$a = (string)($_REQUEST['a'] ?? '');
try {
    $db = getDB();
    srgEnsure($db);
    if ($a === 'list') {
        $rows = array_values(array_filter(lsrList($db, null, 500), fn($r) => $r['vessel'] !== '' || $r['imo'] !== '' || $r['surveyor'] !== ''));
        $count = ['progress' => 0, 'check' => 0, 'done' => 0];
        foreach ($rows as &$r) {
            $count[$r['status']['key']]++;
            $r['ago'] = lsrAgo($r['status']['hours']);
        }
        unset($r);
        lsrJson(200, ['ok' => true, 'rows' => $rows, 'count' => $count]);
    }

    $id = (int)($_REQUEST['id'] ?? 0);
    $q = $db->prepare('SELECT r.*, g.lock_at, g.user_id, u.full_name AS user_name FROM survey_report_guests g JOIN survey_reports r ON r.id = g.report_id
        LEFT JOIN users u ON u.id = g.user_id WHERE r.id = ? AND r.survey_id IS NULL');
    $q->execute([$id]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) lsrJson(404, ['ok' => false, 'error' => 'Report not found.']);

    if ($a === 'lock' || $a === 'unlock') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') lsrJson(405, ['ok' => false, 'error' => 'POST only.']);
        if ($a === 'unlock') {
            $db->prepare('UPDATE survey_report_guests SET lock_at = NULL, lock_by = ? WHERE report_id = ?')->execute([(int)$_SESSION['user_id'], $id]);
        } else {
            $h = (int)($_POST['hours'] ?? -1);
            if (!in_array($h, [0, 1, 4, 24], true)) lsrJson(422, ['ok' => false, 'error' => 'Choose now, 1, 4 or 24 hours.']);
            $db->prepare('UPDATE survey_report_guests SET lock_at = ?, lock_by = ? WHERE report_id = ?')->execute([date('Y-m-d H:i:s', time() + $h * 3600), (int)$_SESSION['user_id'], $id]);
        }
        $l = srgLock($db, $id);
        lsrJson(200, ['ok' => true, 'lock_at' => $l['lock_at'], 'locked' => $l['locked']]);
    }

    if ($a === 'detail') {
        $data = json_decode((string)$row['data'], true) ?: [];
        $report = ['id' => (int)$row['id'], 'survey_id' => null, 'data' => $data];
        $t = rtTemplate($db, (int)$row['template_id']);
        $f = (array)($data['fields'] ?? []);
        $txt = fn($k) => trim((string)(is_array($f[$k] ?? null) ? ($f[$k]['v'] ?? '') : ($f[$k] ?? '')));
        $grades = [];
        foreach ((array)($data['grades'] ?? []) as $g) {
            if (!isset(SR_GRADES[$g])) continue;
            $grades[] = ['key' => $g, 'short' => SR_GRADES[$g][0], 'name' => SR_GRADES[$g][1], 'logbook' => $txt('brob_started_' . $g),
                'tanks' => array_values(array_map(fn($r) => ['id' => (string)($r['id'] ?? ''), 'name' => (string)($r['name'] ?? ''), 'ref_height' => (string)($r['ref_height'] ?? ''),
                    'sounding' => srSounding($r), 'volume' => (string)($r['volume'] ?? ''), 'density' => (string)($r['density'] ?? ''), 'temp' => (string)($r['temp'] ?? ''), 'done' => ($r['done'] ?? '') === '1'],
                    (array)($data['tanks'][$g] ?? [])))];
        }
        // files made, each with its downloads
        $files = srFiles($db, (int)$row['id']);
        $dl = $db->prepare('SELECT d.file_id, d.downloaded_at, d.ip, u.full_name FROM survey_report_downloads d LEFT JOIN users u ON u.id = d.user_id WHERE d.report_id = ? ORDER BY d.id');
        $dl->execute([(int)$row['id']]);
        $byFile = [];
        foreach ($dl->fetchAll(PDO::FETCH_ASSOC) as $d) $byFile[(int)$d['file_id']][] = ['at' => $d['downloaded_at'], 'by' => $d['full_name'] ?: 'Link (no sign-in)'];
        foreach ($files as &$fl) $fl['downloads'] = $byFile[(int)$fl['id']] ?? [];
        unset($fl);
        $l = srgLock($db, (int)$row['id']);
        $started = strtotime((string)$row['created_at']) ?: time();
        $st = lsrStatus($started);
        lsrJson(200, ['ok' => true,
            'report' => ['id' => (int)$row['id'], 'kind' => RT_KINDS[$row['kind']] ?? $row['kind'], 'vessel' => $txt('vessel_name'), 'imo' => $txt('imo'), 'surveyor' => $txt('surveyor_name'),
                'signed_in_as' => (string)($row['user_name'] ?? ''), 'started_at' => $row['created_at'], 'updated_at' => $row['updated_at'], 'status' => $st, 'ago' => lsrAgo($st['hours'])],
            'grades' => $grades, 'history' => srTankHistory($db, $report), 'calc' => $t ? rtCalcConfig(rtEffective($t)) : null,
            'files' => $files, 'lock' => $l]);
    }
    lsrJson(400, ['ok' => false, 'error' => 'Unknown action.']);
} catch (Throwable $e) {
    error_log('ajax/live_survey_reports ' . $a . ': ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    lsrJson(500, ['ok' => false, 'error' => 'Something went wrong. Please try again.']);
}

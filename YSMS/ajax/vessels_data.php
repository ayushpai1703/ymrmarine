<?php
/**
 * Vessels Data grid API (vessels_data.php). Visibility and edit rights: includes/vessels_data.php
 * (Admin edits; Super Admin views all; a Surveyor views the vessels assigned to them). CSRF for
 * every POST is checked in config.php (csrf_require, token from the form field or X-CSRF-Token).
 *
 *   GET  a=list     filters (q, vessel, report_no, agent, imo, status[], type[], port[], client[],
 *                   surveyor[], a_from, a_to, c_from, c_to, r_min, r_max, ids), sort (JSON),
 *                   page, per (25/50/100/250, 0 = all) → rows, total, pages, summary
 *   GET  a=row      id → one row (after a save)
 *   GET  a=history  id → change history of the vessel
 *   POST a=update   id, field, value, orig → saves one cell (Admin)
 *   POST a=status   id, status, completed?, report_date? → status change (Admin)
 *   POST a=bulk_status ids, status, completed?, report_date? → all or nothing (Admin)
 *   POST a=prefs    prefs (JSON) → this user's grid layout
 *   POST a=import_preview  file → checked rows and the changes they would make (Admin)
 *   POST a=import_apply    token → applies a previewed import (Admin)
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/vessels_data.php';
require_once __DIR__ . '/../includes/vessels_data_import.php';
checkAuth();

function vdJson(int $code, array $data): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data);
    exit;
}

if (!vdCanView()) vdJson(403, ['ok' => false, 'error' => 'You do not have access to Vessels Data.']);
$db = getDB();
$action = (string)($_REQUEST['a'] ?? '');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
if ($isPost) vdHistoryTable($db); // create the history table (DDL) before any transaction below
$needAdmin = function () { if (!vdCanEdit()) vdJson(403, ['ok' => false, 'error' => 'Only Admin can change vessel records.']); };
$rowOut = function (int $id) use ($db): ?array {
    [$scope, $params] = vdScope();
    $q = $db->prepare(VD_SELECT . VD_FROM . " WHERE s.id = ? AND $scope");
    $q->execute([$id, ...$params]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    return $r ? vdRow($db, $r, vdSurveyorIds($db, [$id])) : null;
};

try {
    if ($action === 'list') {
        [$f, $where, $params] = vdFilters($db, $_GET);
        $per = (int)($_GET['per'] ?? 50);
        $cnt = $db->prepare('SELECT COUNT(*) ' . VD_FROM . " WHERE $where");
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();
        $capped = false;
        if ($per === 0) { $per = max(1, min($total, VD_ALL_MAX)); $capped = $total > VD_ALL_MAX; $all = true; }
        elseif (!in_array($per, VD_PER, true)) $per = 50;
        $pages = max(1, (int)ceil($total / $per));
        $page = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
        $st = $db->prepare(VD_SELECT . VD_FROM . " WHERE $where ORDER BY " . vdOrderSql($db, json_decode((string)($_GET['sort'] ?? '[]'), true))
            . " LIMIT $per OFFSET " . (($page - 1) * $per));
        $st->execute($params);
        $raw = $st->fetchAll(PDO::FETCH_ASSOC);
        $sv = vdSurveyorIds($db, array_column($raw, 'id'));
        $rows = array_map(fn($r) => vdRow($db, $r, $sv), $raw);

        // Summary: vessels per status over the other filters (so the cards are the status
        // breakdown and work as quick filters); recovery over all filters, counted exactly as
        // the Recovery page counts it (Pending Report + Completed, N/A types as 0, positive).
        [, $w2, $p2] = vdFilters($db, $_GET, ['status']);
        $sq = $db->prepare('SELECT s.status, COUNT(*) n ' . VD_FROM . " WHERE $w2 GROUP BY s.status");
        $sq->execute($p2);
        $by = array_fill_keys(VD_STATUSES, 0);
        foreach ($sq->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $n) if (isset($by[$k])) $by[$k] = (int)$n;
        $rq = $db->prepare('SELECT COALESCE(SUM(' . recoveryCountedSql('s') . '), 0) total, COALESCE(SUM(' . recoveryCountedSql('s', 'vlsfo_recovery') . '), 0) vlsfo,
            COALESCE(SUM(' . recoveryCountedSql('s', 'lsmgo_recovery') . '), 0) lsmgo, COALESCE(SUM(' . recoveryCountedSql('s', 'hsfo_recovery') . '), 0) hsfo, COUNT(*) n '
            . VD_FROM . " WHERE $where AND s.status IN ('Pending Report', 'Completed')");
        $rq->execute($params);
        $rec = $rq->fetch(PDO::FETCH_ASSOC) ?: [];
        vdJson(200, ['ok' => true, 'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => isset($all) ? 0 : $per, 'capped' => $capped,
            'summary' => ['total' => array_sum($by), 'Pending Vessel' => $by['Pending Vessel'], 'Pending Report' => $by['Pending Report'],
                'Completed' => $by['Completed'], 'Cancelled' => $by['Cancelled'],
                'recovery' => round((float)($rec['total'] ?? 0), 3), 'vlsfo' => round((float)($rec['vlsfo'] ?? 0), 3),
                'lsmgo' => round((float)($rec['lsmgo'] ?? 0), 3), 'hsfo' => round((float)($rec['hsfo'] ?? 0), 3), 'recovery_vessels' => (int)($rec['n'] ?? 0)],
            'loaded_at' => date('Y-m-d H:i:s')]);
    }

    if ($action === 'row') {
        $r = $rowOut((int)($_GET['id'] ?? 0));
        if (!$r) vdJson(404, ['ok' => false, 'error' => 'Vessel not found.']);
        vdJson(200, ['ok' => true, 'row' => $r]);
    }

    if ($action === 'history') {
        $id = (int)($_GET['id'] ?? 0);
        if (!vdLoad($db, $id)) vdJson(404, ['ok' => false, 'error' => 'Vessel not found.']);
        vdJson(200, ['ok' => true, 'history' => vdHistory($db, $id)]);
    }

    if (!$isPost) vdJson(400, ['ok' => false, 'error' => 'Unknown action.']);

    if ($action === 'prefs') {
        $p = json_decode((string)($_POST['prefs'] ?? ''), true);
        if (!is_array($p)) vdJson(422, ['ok' => false, 'error' => 'Bad layout.']);
        $cols = ['id', 'imo', 'report_no', 'client', 'agent', 'type', 'port', 'surveyor', 'assigned', 'appointment', 'completed', 'report_date', 'status',
            'due', 'overdue', 'recovery', 'vlsfo', 'lsmgo', 'hsfo', 'remarks', 'notes', 'live_status', 'updated', 'agent_email', 'actions'];
        $sorts = vdSorts($db);
        $clean = [
            'order' => array_values(array_intersect(array_map('strval', (array)($p['order'] ?? [])), $cols)),
            'hidden' => array_values(array_intersect(array_map('strval', (array)($p['hidden'] ?? [])), $cols)),
            'widths' => [], 'sort' => [],
            'per' => in_array((int)($p['per'] ?? 50), array_merge(VD_PER, [0]), true) ? (int)$p['per'] : 50,
        ];
        foreach ((array)($p['widths'] ?? []) as $k => $w) if (in_array($k, $cols, true) || $k === 'vessel') $clean['widths'][$k] = max(60, min(600, (int)$w));
        foreach (array_slice((array)($p['sort'] ?? []), 0, 3) as $x) {
            if (is_array($x) && isset($sorts[$x['f'] ?? ''])) $clean['sort'][] = ['f' => $x['f'], 'd' => ($x['d'] ?? '') === 'desc' ? 'desc' : 'asc'];
        }
        listGridPrefsTable($db);
        $db->prepare('INSERT INTO list_grid_prefs (user_id, page, prefs) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE prefs = VALUES(prefs)')
           ->execute([(int)($_SESSION['user_id'] ?? 0), 'vessels_data', json_encode($clean)]);
        vdJson(200, ['ok' => true, 'prefs' => $clean]);
    }

    if ($action === 'update') {
        $needAdmin();
        $id = (int)($_POST['id'] ?? 0);
        $key = (string)($_POST['field'] ?? '');
        if (!isset(VD_FIELDS[$key])) vdJson(422, ['ok' => false, 'error' => 'This field cannot be edited here.']);
        $s = vdLoad($db, $id);
        if (!$s) vdJson(404, ['ok' => false, 'error' => 'Vessel not found.']);
        $res = vdSaveField($db, $s, $key, (string)($_POST['value'] ?? ''), isset($_POST['orig']) ? (string)$_POST['orig'] : null, 'grid');
        $row = $rowOut($id);
        if (!$res['ok']) vdJson(!empty($res['conflict']) ? 409 : 422, ['ok' => false, 'error' => $res['message'], 'row' => $row]);
        vdJson(200, ['ok' => true, 'message' => $res['message'], 'changed' => $res['changed'], 'row' => $row]);
    }

    if ($action === 'status' || $action === 'bulk_status') {
        $needAdmin();
        $to = (string)($_POST['status'] ?? '');
        $dates = ['completed' => (string)($_POST['completed'] ?? ''), 'report_date' => (string)($_POST['report_date'] ?? '')];
        $ids = [];
        if ($action === 'status') $ids = [(int)($_POST['id'] ?? 0)];
        else foreach (explode(',', (string)($_POST['ids'] ?? '')) as $x) if (ctype_digit(trim($x))) $ids[] = (int)trim($x);
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) vdJson(422, ['ok' => false, 'error' => 'Choose at least one vessel.']);
        if (count($ids) > 500) vdJson(422, ['ok' => false, 'error' => 'Change at most 500 vessels at a time.']);
        if (!in_array($to, VD_STATUSES, true)) vdJson(422, ['ok' => false, 'error' => 'Unknown status.']);
        $source = $action === 'status' ? 'grid' : 'bulk';
        $done = []; $skipped = 0;
        $db->beginTransaction();
        try {
            $plans = [];
            foreach ($ids as $id) {
                $s = vdLoad($db, $id, true);
                if (!$s) throw new DomainException('Vessel #' . $id . ' was not found.');
                $plan = vdStatusPlan($s, $to, $dates);
                if (!$plan['ok']) throw new DomainException($plan['message']);
                if ($plan['same']) { $skipped++; continue; }
                $plans[] = [$s, $plan];
            }
            foreach ($plans as [$s, $plan]) { vdStatusApply($db, $s, $plan, $source); $done[] = $s; }
            $db->commit();
        } catch (DomainException $e) {
            $db->rollBack();
            vdJson(422, ['ok' => false, 'error' => $e->getMessage() . ($action === 'bulk_status' ? ' Nothing was changed.' : '')]);
        }
        foreach ($done as $s) vdNotifyStatus($db, $s, $to);
        $out = ['ok' => true, 'changed' => count($done), 'skipped' => $skipped,
            'message' => count($done) . ' vessel' . (count($done) === 1 ? '' : 's') . ' moved to ' . $to . ($skipped ? ' · ' . $skipped . ' already there' : '') . '.'];
        if ($action === 'status') $out['row'] = $rowOut($ids[0]);
        vdJson(200, $out);
    }

    if ($action === 'import_preview') {
        $needAdmin();
        vdJson(200, vdImportPreview($db, $_FILES['file'] ?? []));
    }
    if ($action === 'import_apply') {
        $needAdmin();
        $r = vdImportApply($db, (string)($_POST['token'] ?? ''));
        vdJson($r['ok'] ? 200 : 422, $r);
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('ajax/vessels_data ' . $action . ': ' . $e->getMessage());
    vdJson(500, ['ok' => false, 'error' => 'Something went wrong. Please try again.']);
}
vdJson(400, ['ok' => false, 'error' => 'Unknown action.']);

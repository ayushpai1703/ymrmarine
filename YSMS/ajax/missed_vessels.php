<?php
/**
 * Missed Vessels actions (includes/missed_vessels.php has the rules).
 *
 *   POST a=mark     id, remarks, rk  → Pending Vessel → Missed (Admin; Surveyor: own vessels)
 *   POST a=edit     id, remarks, rk  → new reason on a Missed vessel (Admin; Surveyor: own)
 *   POST a=restore  id, rk           → Missed → Pending Vessel (Admin)
 *   GET  a=counts                    → { pending, missed } for this user
 *   GET  a=history  id               → status history of one vessel this user may see
 *
 * Every write is one transaction (status + current reason + audit row). rk is a one-time
 * request key from the modal: sending the same request twice changes nothing.
 * CSRF: checkAuth() → csrf_require() for every POST.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/list_filters.php';
require_once __DIR__ . '/../includes/missed_vessels.php';
require_once __DIR__ . '/../includes/notifications.php';
checkAuth();

function mvJson(int $code, array $data): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

if (!missedCanViewList()) mvJson(403, ['ok' => false, 'error' => 'Not authorized.']);

$db = getDB();
try { missedEnsureSchema($db); } catch (Throwable $e) {
    error_log('missed vessels schema: ' . $e->getMessage());
    mvJson(500, ['ok' => false, 'error' => 'Missed Vessels is not set up yet (database migration needed).']);
}

$a = (string)($_REQUEST['a'] ?? '');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$sid = (int)($_REQUEST['id'] ?? 0);

if ($a === 'counts') {
    mvJson(200, ['ok' => true, 'pending' => missedPendingCount($db), 'missed' => missedCount($db)]);
}

if ($a === 'history') {
    $q = $db->prepare('SELECT id, status FROM surveys WHERE id = ?');
    $q->execute([$sid]);
    $s = $q->fetch(PDO::FETCH_ASSOC);
    if (!$s || !missedCanSee($db, $s)) mvJson(404, ['ok' => false, 'error' => 'Vessel not found.']);
    $rows = array_map(fn($h) => [
        'action' => missedActionLabel((string)$h['action_type']),
        'type' => (string)$h['action_type'],
        'from' => (string)($h['previous_status'] ?? ''),
        'to' => (string)($h['new_status'] ?? ''),
        'remarks' => (string)($h['remarks'] ?? ''),
        'previous_remarks' => (string)($h['previous_remarks'] ?? ''),
        'by' => (string)($h['by_name'] ?? ''),
        'at' => missedWhen($h['action_at'] ?? null),
    ], missedHistory($db, $sid));
    mvJson(200, ['ok' => true, 'history' => $rows]);
}

if (!$isPost || !in_array($a, ['mark', 'edit', 'restore'], true)) mvJson(400, ['ok' => false, 'error' => 'Unknown action.']);
if ($sid <= 0) mvJson(422, ['ok' => false, 'error' => 'Invalid vessel.']);
$rk = missedRequestKey($_POST['rk'] ?? '');

if ($a === 'mark' || $a === 'edit') {
    [$remark, $err] = missedCleanRemark($_POST['remarks'] ?? '');
    if ($err) mvJson(422, ['ok' => false, 'error' => $err, 'field' => 'remarks']);
    $res = $a === 'mark' ? missedMark($db, $sid, $remark, $rk) : missedEditRemark($db, $sid, $remark, $rk);
} else {
    $res = missedRestore($db, $sid, $rk);
}

if (empty($res['ok'])) mvJson((int)($res['code'] ?? 400), ['ok' => false, 'error' => $res['error'] ?? 'Could not save.']);

// in-app notifications (existing notifications module), after the commit; never blocks the action
if (empty($res['dup']) && empty($res['same']) && !empty($res['survey'])) {
    try {
        $s = $res['survey'];
        $who = (string)($_SESSION['full_name'] ?? 'User');
        $vn = (string)($s['vessel_name'] ?: ('#' . $sid));
        $link = 'vessel_detail.php?id=' . $sid;
        $me = missedUid();
        $surveyorIds = [(int)$s['surveyor_id']];
        $ss = $db->prepare('SELECT surveyor_id FROM survey_surveyors WHERE survey_id = ?');
        $ss->execute([$sid]);
        foreach ($ss->fetchAll(PDO::FETCH_COLUMN) as $x) $surveyorIds[] = (int)$x;
        $surveyorIds = array_values(array_unique(array_filter($surveyorIds, fn($x) => $x > 0 && $x !== $me)));
        if ($a === 'mark') {
            if (missedRole() === 'Surveyor') notifyAllAdmins($db, 'Vessel marked as missed', $who . ' marked ' . $vn . ' as missed.', 'status', $link, $me);
            foreach ($surveyorIds as $x) createNotification($db, $x, 'Vessel marked as missed', $who . ' moved ' . $vn . ' to Missed Vessels.', 'status', $link, $me);
        } elseif ($a === 'restore') {
            foreach ($surveyorIds as $x) createNotification($db, $x, 'Vessel restored', $who . ' restored ' . $vn . ' to Pending Vessels.', 'status', $link, $me);
        }
    } catch (Throwable $e) { error_log('missed vessels notify: ' . $e->getMessage()); }
}

$msg = ['mark' => 'Vessel moved to Missed Vessels.', 'edit' => 'Remarks updated.', 'restore' => 'Vessel restored to Pending Vessels.'][$a];
if (!empty($res['same'])) $msg = 'No change — the remarks are the same.';
mvJson(200, ['ok' => true, 'message' => $msg, 'duplicate' => !empty($res['dup']),
    'pending' => missedPendingCount($db), 'missed' => missedCount($db)]);

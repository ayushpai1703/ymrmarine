<?php
/**
 * Missed Vessels (missed.php, ajax/missed_vessels.php, vessels.php, vessel_detail.php, index.php).
 *
 * A Pending Vessel that our team could not attend is moved BY HAND to surveys.status = 'Missed'
 * with a mandatory reason. Nothing is deleted or copied: the same surveys row (same id, client,
 * surveyor(s), survey types, port, uploads) only changes status. Restore puts it back to
 * 'Pending Vessel'.
 *
 * Tables (additive; database/migration_missed_vessels.sql creates the same — this file also
 * creates them on first use, like the other modules do):
 *   survey_missed          one row per vessel that has ever been missed: the CURRENT reason and
 *                          who/when marked, last edited, restored. Kept after a restore.
 *   survey_status_history  append-only audit: marked_as_missed / remark_updated /
 *                          restored_to_pending, with previous/new status, remark (and the previous
 *                          remark on an edit), user and time. request_key (UNIQUE) makes a
 *                          double-submitted request a no-op.
 *
 * Permissions (all checked here, server-side):
 *   View list:      Admin, Super Admin (all); Surveyor (vessels assigned to them, primary or
 *                   through survey_surveyors). Client / Sales: no access.
 *   Mark as missed: Admin (any Pending Vessel); Surveyor (own assigned Pending Vessels).
 *   Edit remarks:   Admin (any Missed vessel); Surveyor (own assigned Missed vessels).
 *   Restore:        Admin only.
 *   Super Admin is read-only, as on Pending Vessels.
 */

const MISSED_STATUS = 'Missed';
const MISSED_REMARK_MAX = 2000;

/**
 * Make sure surveys.status (an ENUM) accepts every value in $needed, keeping all existing values
 * and the current default. Never removes or renames a value. Also safe when the column is not an
 * ENUM (then there is nothing to do).
 */
function ensureSurveyStatusValues(PDO $db, array $needed): void {
    $col = $db->query("SHOW COLUMNS FROM surveys LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) return;
    $type = (string)$col['Type'];
    if (stripos($type, 'enum(') !== 0) return;
    preg_match_all("/'((?:[^']|'')*)'/", $type, $m);
    $vals = array_map(fn($v) => str_replace("''", "'", $v), $m[1]);
    $missing = array_values(array_diff($needed, $vals));
    if (!$missing) return;
    $all = array_merge($vals, $missing);
    $def = $col['Default'] !== null && in_array($col['Default'], $all, true) ? $db->quote($col['Default']) : 'NULL';
    $db->exec('ALTER TABLE surveys MODIFY COLUMN status ENUM(' . implode(',', array_map([$db, 'quote'], $all)) . ')'
        . (strtoupper((string)$col['Null']) === 'NO' ? ' NOT NULL' : ' NULL') . ' DEFAULT ' . $def);
}

function missedEnsureSchema(PDO $db): void {
    static $done = false;
    if ($done) return;
    ensureSurveyStatusValues($db, ['Pending Vessel', 'Pending Report', 'Completed', 'Cancelled', MISSED_STATUS]);
    $db->exec("CREATE TABLE IF NOT EXISTS `survey_missed` (
        `survey_id` INT(11) NOT NULL,
        `remarks` TEXT NOT NULL,
        `marked_by` INT(11) DEFAULT NULL,
        `marked_at` DATETIME NOT NULL,
        `remarks_updated_by` INT(11) DEFAULT NULL,
        `remarks_updated_at` DATETIME DEFAULT NULL,
        `restored_by` INT(11) DEFAULT NULL,
        `restored_at` DATETIME DEFAULT NULL,
        PRIMARY KEY (`survey_id`),
        KEY `idx_survey_missed_marked_at` (`marked_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS `survey_status_history` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `survey_id` INT(11) NOT NULL,
        `action_type` VARCHAR(40) NOT NULL,
        `previous_status` VARCHAR(40) DEFAULT NULL,
        `new_status` VARCHAR(40) DEFAULT NULL,
        `remarks` TEXT DEFAULT NULL,
        `previous_remarks` TEXT DEFAULT NULL,
        `action_by` INT(11) DEFAULT NULL,
        `action_at` DATETIME NOT NULL,
        `request_key` CHAR(32) DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_survey_status_history_request` (`request_key`),
        KEY `idx_survey_status_history_survey` (`survey_id`, `action_at`),
        KEY `idx_survey_status_history_action` (`action_type`, `action_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** True when the Missed tables exist (read paths use this so they never create anything). */
function missedTablesReady(PDO $db): bool {
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool)$db->query("SHOW TABLES LIKE 'survey_missed'")->fetchColumn(); } catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

function missedRole(): string { return (string)($_SESSION['role'] ?? ''); }
function missedUid(): int { return (int)($_SESSION['user_id'] ?? 0); }

function missedCanViewList(): bool {
    return in_array(missedRole(), ['Admin', 'Super Admin', 'Surveyor'], true);
}
/** The Surveyor column / filter: Admin and Super Admin (a Surveyor only sees their own). */
function missedShowsSurveyor(): bool {
    return in_array(missedRole(), ['Admin', 'Super Admin'], true);
}

/** [whereSql, params] limiting surveys `s` to the ones this user may see. */
function missedScope(): array {
    $role = missedRole();
    if (in_array($role, ['Admin', 'Super Admin'], true)) return ['1=1', []];
    if ($role === 'Surveyor') {
        $u = missedUid();
        return ['(s.surveyor_id = ? OR s.id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = ?))', [$u, $u]];
    }
    return ['1=0', []];
}

function missedIsAssigned(PDO $db, int $surveyId): bool {
    return isSurveyorAssignedToSurvey($db, $surveyId, missedUid());
}

/** May this user mark this survey (array with id + status) as missed? */
function missedCanMark(PDO $db, array $survey): bool {
    if (($survey['status'] ?? '') !== 'Pending Vessel') return false;
    $role = missedRole();
    if ($role === 'Admin') return true;
    return $role === 'Surveyor' && missedIsAssigned($db, (int)$survey['id']);
}
function missedCanEdit(PDO $db, array $survey): bool {
    if (($survey['status'] ?? '') !== MISSED_STATUS) return false;
    $role = missedRole();
    if ($role === 'Admin') return true;
    return $role === 'Surveyor' && missedIsAssigned($db, (int)$survey['id']);
}
function missedCanRestore(array $survey): bool {
    return ($survey['status'] ?? '') === MISSED_STATUS && missedRole() === 'Admin';
}
/** May this user see this Missed vessel's reason and history? */
function missedCanSee(PDO $db, array $survey): bool {
    $role = missedRole();
    if (in_array($role, ['Admin', 'Super Admin'], true)) return true;
    return $role === 'Surveyor' && missedIsAssigned($db, (int)$survey['id']);
}

/** Trimmed remark, or an error message. */
function missedCleanRemark($raw): array {
    $r = trim(str_replace(["\r\n", "\r"], "\n", (string)$raw));
    if ($r === '') return [null, 'Please enter the reason why this vessel was missed.'];
    if (mb_strlen($r) < 5) return [null, 'Please enter a little more detail (at least 5 characters).'];
    if (mb_strlen($r) > MISSED_REMARK_MAX) return [null, 'The reason is too long (maximum ' . MISSED_REMARK_MAX . ' characters).'];
    return [$r, null];
}

/** A 32-hex request key from the client (one per modal opening), else null. */
function missedRequestKey($raw): ?string {
    $k = strtolower(trim((string)$raw));
    return preg_match('/^[0-9a-f]{32}$/', $k) ? $k : null;
}

/** Was this request already applied (double click / network retry)? */
function missedRequestDone(PDO $db, ?string $key): bool {
    if ($key === null) return false;
    $q = $db->prepare('SELECT 1 FROM survey_status_history WHERE request_key = ?');
    $q->execute([$key]);
    return (bool)$q->fetchColumn();
}

function missedLog(PDO $db, int $sid, string $action, ?string $prev, ?string $new, ?string $remarks, ?string $prevRemarks, ?string $key): void {
    $db->prepare('INSERT INTO survey_status_history (survey_id, action_type, previous_status, new_status, remarks, previous_remarks, action_by, action_at, request_key)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)')
       ->execute([$sid, $action, $prev, $new, $remarks, $prevRemarks, missedUid() ?: null, $key]);
}

/** The survey row locked for this transaction (id, status, vessel_name, surveyor_id). */
function missedLockSurvey(PDO $db, int $sid): ?array {
    $q = $db->prepare('SELECT id, status, vessel_name, surveyor_id FROM surveys WHERE id = ? FOR UPDATE');
    $q->execute([$sid]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Run $fn inside a transaction; returns its result array, or ['ok' => false, 'error' => ...].
 * $fn returns ['ok' => bool, ...]; anything not ok is rolled back.
 */
function missedTx(PDO $db, callable $fn): array {
    $db->beginTransaction();
    try {
        $res = $fn();
        if (!empty($res['ok'])) $db->commit(); else $db->rollBack();
        return $res;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($e instanceof PDOException && ($e->errorInfo[1] ?? 0) == 1062) return ['ok' => true, 'dup' => true];
        error_log('missed vessels: ' . $e->getMessage());
        return ['ok' => false, 'code' => 500, 'error' => 'Could not save. Please try again.'];
    }
}

function missedMark(PDO $db, int $sid, string $remark, ?string $key): array {
    if (missedRequestDone($db, $key)) return ['ok' => true, 'dup' => true];
    return missedTx($db, function () use ($db, $sid, $remark, $key) {
        $s = missedLockSurvey($db, $sid);
        if (!$s) return ['ok' => false, 'code' => 404, 'error' => 'Vessel not found.'];
        if ($s['status'] === MISSED_STATUS) return ['ok' => false, 'code' => 409, 'error' => 'This vessel is already in Missed Vessels.'];
        if ($s['status'] !== 'Pending Vessel') return ['ok' => false, 'code' => 409, 'error' => 'Only Pending Vessels can be marked as missed (this vessel is now "' . $s['status'] . '").'];
        if (!missedCanMark($db, $s)) return ['ok' => false, 'code' => 403, 'error' => 'You are not allowed to change this vessel.'];
        $u = $db->prepare("UPDATE surveys SET status = 'Missed' WHERE id = ? AND status = 'Pending Vessel'");
        $u->execute([$sid]);
        if ($u->rowCount() !== 1) return ['ok' => false, 'code' => 409, 'error' => 'This vessel was changed by someone else. Please refresh.'];
        $db->prepare('INSERT INTO survey_missed (survey_id, remarks, marked_by, marked_at) VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE remarks = VALUES(remarks), marked_by = VALUES(marked_by), marked_at = VALUES(marked_at),
                remarks_updated_by = NULL, remarks_updated_at = NULL, restored_by = NULL, restored_at = NULL')
           ->execute([$sid, $remark, missedUid() ?: null]);
        missedLog($db, $sid, 'marked_as_missed', 'Pending Vessel', MISSED_STATUS, $remark, null, $key);
        return ['ok' => true, 'survey' => $s];
    });
}

function missedEditRemark(PDO $db, int $sid, string $remark, ?string $key): array {
    if (missedRequestDone($db, $key)) return ['ok' => true, 'dup' => true];
    return missedTx($db, function () use ($db, $sid, $remark, $key) {
        $s = missedLockSurvey($db, $sid);
        if (!$s) return ['ok' => false, 'code' => 404, 'error' => 'Vessel not found.'];
        if ($s['status'] !== MISSED_STATUS) return ['ok' => false, 'code' => 409, 'error' => 'This vessel is no longer in Missed Vessels.'];
        if (!missedCanEdit($db, $s)) return ['ok' => false, 'code' => 403, 'error' => 'You are not allowed to change this vessel.'];
        $q = $db->prepare('SELECT remarks FROM survey_missed WHERE survey_id = ? FOR UPDATE');
        $q->execute([$sid]);
        $old = $q->fetchColumn();
        if ($old !== false && (string)$old === $remark) return ['ok' => true, 'same' => true];
        if ($old === false) {
            $db->prepare('INSERT INTO survey_missed (survey_id, remarks, marked_by, marked_at, remarks_updated_by, remarks_updated_at) VALUES (?, ?, NULL, NOW(), ?, NOW())')
               ->execute([$sid, $remark, missedUid() ?: null]);
        } else {
            $db->prepare('UPDATE survey_missed SET remarks = ?, remarks_updated_by = ?, remarks_updated_at = NOW() WHERE survey_id = ?')
               ->execute([$remark, missedUid() ?: null, $sid]);
        }
        missedLog($db, $sid, 'remark_updated', MISSED_STATUS, MISSED_STATUS, $remark, $old === false ? null : (string)$old, $key);
        return ['ok' => true, 'survey' => $s];
    });
}

function missedRestore(PDO $db, int $sid, ?string $key): array {
    if (missedRequestDone($db, $key)) return ['ok' => true, 'dup' => true];
    return missedTx($db, function () use ($db, $sid, $key) {
        $s = missedLockSurvey($db, $sid);
        if (!$s) return ['ok' => false, 'code' => 404, 'error' => 'Vessel not found.'];
        if ($s['status'] !== MISSED_STATUS) return ['ok' => false, 'code' => 409, 'error' => 'This vessel is not in Missed Vessels (it is "' . $s['status'] . '").'];
        if (!missedCanRestore($s)) return ['ok' => false, 'code' => 403, 'error' => 'Only an Admin can restore a missed vessel.'];
        $u = $db->prepare("UPDATE surveys SET status = 'Pending Vessel' WHERE id = ? AND status = 'Missed'");
        $u->execute([$sid]);
        if ($u->rowCount() !== 1) return ['ok' => false, 'code' => 409, 'error' => 'This vessel was changed by someone else. Please refresh.'];
        $q = $db->prepare('SELECT remarks FROM survey_missed WHERE survey_id = ?');
        $q->execute([$sid]);
        $rem = $q->fetchColumn();
        $db->prepare('UPDATE survey_missed SET restored_by = ?, restored_at = NOW() WHERE survey_id = ?')->execute([missedUid() ?: null, $sid]);
        missedLog($db, $sid, 'restored_to_pending', MISSED_STATUS, 'Pending Vessel', null, $rem === false ? null : (string)$rem, $key);
        return ['ok' => true, 'survey' => $s];
    });
}

/** Missed vessels this user can see. */
function missedCount(PDO $db): int {
    if (!missedCanViewList()) return 0;
    [$scope, $p] = missedScope();
    try {
        $q = $db->prepare("SELECT COUNT(*) FROM surveys s WHERE s.status = 'Missed' AND $scope");
        $q->execute($p);
        return (int)$q->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/** Pending Vessels this user can see (same scope as vessels.php for Admin / Super Admin / Surveyor). */
function missedPendingCount(PDO $db): int {
    [$scope, $p] = missedScope();
    $q = $db->prepare("SELECT COUNT(*) FROM surveys s WHERE s.status = 'Pending Vessel' AND $scope");
    $q->execute($p);
    return (int)$q->fetchColumn();
}

const MISSED_FROM = "FROM surveys s
    LEFT JOIN clients c ON s.client_id = c.id
    LEFT JOIN users u ON s.surveyor_id = u.id
    LEFT JOIN survey_types st ON s.survey_type_id = st.id
    LEFT JOIN ports p ON s.port_id = p.id
    LEFT JOIN survey_missed sm ON sm.survey_id = s.id
    LEFT JOIN users mb ON mb.id = sm.marked_by
    LEFT JOIN users eb ON eb.id = sm.remarks_updated_by";

/** Filters from GET → [state, whereSql, params]. */
function missedFilters(array $g): array {
    $date = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? (string)$v : '';
    $f = [
        'q' => mb_substr(trim((string)($g['q'] ?? '')), 0, 100),
        'client' => mb_substr(trim((string)($g['client'] ?? '')), 0, 100),
        'surveyor' => missedShowsSurveyor() ? mb_substr(trim((string)($g['surveyor'] ?? '')), 0, 100) : '',
        'port' => mb_substr(trim((string)($g['port'] ?? '')), 0, 150),
        'from' => $date($g['from'] ?? ''),
        'to' => $date($g['to'] ?? ''),
        'sort' => in_array(($g['sort'] ?? ''), ['latest', 'oldest', 'vessel'], true) ? $g['sort'] : 'latest',
    ];
    [$scope, $params] = missedScope();
    $w = ["s.status = 'Missed'", $scope];
    $like = fn($v) => '%' . addcslashes($v, '%_\\') . '%';
    if ($f['q'] !== '') { $w[] = '(s.vessel_name LIKE ? OR c.company_name LIKE ?)'; $params[] = $like($f['q']); $params[] = $like($f['q']); }
    if ($f['client'] !== '') { $w[] = 'c.company_name = ?'; $params[] = $f['client']; }
    if ($f['port'] !== '') { $w[] = 'p.port_name = ?'; $params[] = $f['port']; }
    if ($f['surveyor'] !== '') { $w[] = listFilterSurveyorNameSql([$f['surveyor']], $params); }
    if ($f['from'] !== '') { $w[] = 'sm.marked_at >= ?'; $params[] = $f['from'] . ' 00:00:00'; }
    if ($f['to'] !== '') { $w[] = 'sm.marked_at < ?'; $params[] = date('Y-m-d', strtotime($f['to'] . ' +1 day')) . ' 00:00:00'; }
    return [$f, implode(' AND ', $w), $params];
}

function missedOrderSql(string $sort): string {
    return match ($sort) {
        'oldest' => '(sm.marked_at IS NULL) ASC, sm.marked_at ASC, s.id ASC',
        'vessel' => 's.vessel_name ASC, s.id DESC',
        default => '(sm.marked_at IS NULL) ASC, sm.marked_at DESC, s.id DESC',
    };
}

const MISSED_SELECT = 'SELECT s.id, s.vessel_name, s.status, s.agent_name, s.report_number, s.assign_date, s.appointment_date,
    s.survey_type_ids, s.surveyor_id, c.company_name, u.full_name AS surveyor_name, st.type_name, p.port_name,
    sm.remarks AS missed_remarks, sm.marked_at, sm.remarks_updated_at, mb.full_name AS marked_by_name, eb.full_name AS edited_by_name ';

/** One listing row → display array (with what this user may do on it). */
function missedRowView(PDO $db, array $r): array {
    $r['status'] = MISSED_STATUS;
    return [
        'id' => (int)$r['id'],
        'vessel' => (string)($r['vessel_name'] ?? ''),
        'client' => (string)($r['company_name'] ?? ''),
        'agent' => (string)($r['agent_name'] ?? ''),
        'port' => (string)($r['port_name'] ?? ''),
        'type' => (string)getCombinedSurveyTypeNames($db, $r['survey_type_ids'] ?? '', $r['type_name'] ?? ''),
        'surveyor' => (string)getCombinedSurveyorNames($db, $r['id'], $r['surveyor_name'] ?? ''),
        'missed_at' => (string)($r['marked_at'] ?? ''),
        'remarks' => (string)($r['missed_remarks'] ?? ''),
        'marked_by' => (string)($r['marked_by_name'] ?? ''),
        'edited_at' => (string)($r['remarks_updated_at'] ?? ''),
        'edited_by' => (string)($r['edited_by_name'] ?? ''),
        'can_edit' => missedCanEdit($db, $r),
        'can_restore' => missedCanRestore($r),
    ];
}

/** The current missed record for one survey (or null). */
function missedRecord(PDO $db, int $sid): ?array {
    if (!missedTablesReady($db)) return null;
    $q = $db->prepare('SELECT sm.*, mb.full_name AS marked_by_name, eb.full_name AS edited_by_name
        FROM survey_missed sm LEFT JOIN users mb ON mb.id = sm.marked_by LEFT JOIN users eb ON eb.id = sm.remarks_updated_by WHERE sm.survey_id = ?');
    $q->execute([$sid]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Status history of one survey, newest first. */
function missedHistory(PDO $db, int $sid): array {
    if (!missedTablesReady($db)) return [];
    $q = $db->prepare('SELECT h.action_type, h.previous_status, h.new_status, h.remarks, h.previous_remarks, h.action_at, u.full_name AS by_name
        FROM survey_status_history h LEFT JOIN users u ON u.id = h.action_by WHERE h.survey_id = ? ORDER BY h.action_at DESC, h.id DESC LIMIT 100');
    $q->execute([$sid]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

function missedActionLabel(string $a): string {
    return ['marked_as_missed' => 'Marked as Missed', 'remark_updated' => 'Remarks edited', 'restored_to_pending' => 'Restored to Pending Vessels'][$a] ?? $a;
}

/** "13 Nov 2026, 04:05 PM" or '—'. */
function missedWhen(?string $dt): string {
    if (!$dt || strpos($dt, '0000') === 0) return '—';
    $t = strtotime($dt);
    return $t ? date('d M Y, h:i A', $t) : '—';
}

/** The shared modal + script tags (Mark / Edit / Restore). Echo once per page. */
function missedAssets(): string {
    $v = fn($f) => @filemtime(dirname(__DIR__) . '/' . $f);
    return '<link rel="stylesheet" href="assets/css/missed-vessels.css?v=' . $v('assets/css/missed-vessels.css') . '">'
        . '<script>window.MV_CFG = ' . json_encode(['api' => 'ajax/missed_vessels.php', 'csrf' => csrf_token()]) . ';</script>'
        . '<script src="assets/js/missed-vessels.js?v=' . $v('assets/js/missed-vessels.js') . '" defer></script>';
}

/** data-* attributes for a Mark / Edit / Restore button. */
function missedBtnData(string $act, array $v): string {
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    return 'data-mv-act="' . $h($act) . '" data-mv-id="' . (int)$v['id'] . '" data-mv-vessel="' . $h($v['vessel'] ?? '')
        . '" data-mv-client="' . $h($v['client'] ?? '') . '" data-mv-port="' . $h($v['port'] ?? '') . '" data-mv-surveyor="' . $h($v['surveyor'] ?? '') . '"'
        . (isset($v['remarks']) ? ' data-mv-remarks="' . $h($v['remarks']) . '"' : '');
}

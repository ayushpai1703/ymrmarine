<?php
/**
 * Recovery list (recovery.php page + its Excel download in export_recovery.php?full=1).
 * Same rules as the dashboard Total / This Month Recovery cards: every vessel that
 * has left Pending Vessels (Pending Report + Completed), month = Survey Completed Date.
 */

const RECOVERY_LIST_FROM = "
    FROM surveys s
    LEFT JOIN clients c ON s.client_id = c.id
    LEFT JOIN users u ON s.surveyor_id = u.id
    LEFT JOIN survey_types st ON s.survey_type_id = st.id
    LEFT JOIN ports p ON s.port_id = p.id
";

/** Vessels with no surveyor login (outsourced / unassigned placeholder) are credited to this surveyor. */
const OUTSOURCED_CREDIT_SURVEYOR = 'Apurva Dandekar';

/** SQL condition: the vessel has no surveyor login (neither primary nor co-surveyor is a Surveyor user). */
const RECOVERY_NO_SURVEYOR_SQL = "(NOT EXISTS (SELECT 1 FROM users su WHERE su.id = s.surveyor_id AND su.role_id = 2)
    AND NOT EXISTS (SELECT 1 FROM survey_surveyors ss JOIN users su ON su.id = ss.surveyor_id AND su.role_id = 2 WHERE ss.survey_id = s.id))";

/** [id, full_name] of the surveyor credited with outsourced vessels. Matches the Surveyor login by
 *  name (case / surrounding spaces ignored); with no such login the id is -1 and the row is shown
 *  under OUTSOURCED_CREDIT_SURVEYOR anyway (outsourced vessels only). */
function outsourcedCreditSurveyor(PDO $db): array {
    static $cache = null;
    if ($cache === null) {
        $st = $db->prepare('SELECT id, full_name FROM users WHERE role_id = 2 AND LOWER(TRIM(full_name)) = LOWER(?) ORDER BY id LIMIT 1');
        $st->execute([OUTSOURCED_CREDIT_SURVEYOR]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $cache = $r ? [(int)$r['id'], trim((string)$r['full_name'])] : [-1, OUTSOURCED_CREDIT_SURVEYOR];
    }
    return $cache;
}

/** Normalised filters from the query string; the surveyor filter is Admin / Super Admin only. */
function recoveryListFilters(array $get, bool $fullAccess): array {
    $str = fn($k) => trim((string)($get[$k] ?? ''));
    $status = $str('status');
    $month = $str('month');
    $sort = $str('sort');
    return [
        'q'        => $str('q'),
        'type'     => $str('type'),
        'place'    => $str('place'),
        'client'   => $str('client'),
        // -1 = OUTSOURCED_CREDIT_SURVEYOR when they have no Surveyor login (outsourced vessels only)
        'surveyor' => $fullAccess && ((int)($get['surveyor'] ?? 0) > 0 || (int)($get['surveyor'] ?? 0) === -1) ? (int)$get['surveyor'] : 0,
        'status'   => in_array($status, ['Pending Report', 'Completed'], true) ? $status : '',
        'month'    => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) ? $month : '',
        'sort'     => in_array($sort, ['oldest', 'recovery-desc', 'recovery-asc', 'name-asc'], true) ? $sort : 'newest',
    ];
}

/** Role scope only (what the user is allowed to see) → [sql, params]. */
function recoveryListScope(string $role, int $userId, int $clientRowId): array {
    $where = ["s.status IN ('Pending Report','Completed')"];
    $params = [];
    if ($role === 'Client') {
        $where[] = 's.client_id = ?';
        $params[] = $clientRowId;
    } elseif (!in_array($role, ['Admin', 'Super Admin'], true)) {
        $where[] = '(s.surveyor_id = ? OR s.id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = ?))';
        $params[] = $userId;
        $params[] = $userId;
    }
    return [$where, $params];
}

/** Role scope + filters → [whereSql, params, orderSql]. */
/** $creditId: surveyor credited with outsourced vessels (their filter includes those vessels). */
function recoveryListWhere(string $role, int $userId, int $clientRowId, array $f, int $creditId = 0): array {
    [$where, $params] = recoveryListScope($role, $userId, $clientRowId);
    if ($f['q'] !== '') {
        $where[] = '(s.vessel_name LIKE ? OR s.report_number LIKE ? OR c.company_name LIKE ? OR st.type_name LIKE ? OR p.port_name LIKE ? OR u.full_name LIKE ?)';
        $like = '%' . $f['q'] . '%';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }
    if ($f['type'] !== '') { $where[] = 'st.type_name = ?'; $params[] = $f['type']; }
    if ($f['place'] !== '') { $where[] = 'p.port_name = ?'; $params[] = $f['place']; }
    if ($f['client'] !== '') { $where[] = 'c.company_name = ?'; $params[] = $f['client']; }
    if ($f['surveyor'] === -1) {
        $where[] = RECOVERY_NO_SURVEYOR_SQL;
    } elseif ($f['surveyor'] > 0) {
        $where[] = '(s.surveyor_id = ? OR s.id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = ?)'
            . ($f['surveyor'] === $creditId ? ' OR ' . RECOVERY_NO_SURVEYOR_SQL : '') . ')';
        $params[] = $f['surveyor'];
        $params[] = $f['surveyor'];
    }
    if ($f['status'] !== '') { $where[] = 's.status = ?'; $params[] = $f['status']; }
    if ($f['month'] !== '') {
        $start = $f['month'] . '-01';
        $where[] = surveyCompletedDateSql('s') . ' >= ? AND ' . surveyCompletedDateSql('s') . ' < ?';
        $params[] = $start;
        $params[] = date('Y-m-d', strtotime($start . ' +1 month'));
    }
    $order = [
        'newest'        => SURVEY_COMPLETED_ORDER_SQL . ' DESC, s.id DESC',
        'oldest'        => SURVEY_COMPLETED_ORDER_SQL . ' IS NULL, ' . SURVEY_COMPLETED_ORDER_SQL . ' ASC, s.id ASC',
        'recovery-desc' => 's.recovery_amount IS NULL, s.recovery_amount DESC, s.id DESC',
        'recovery-asc'  => 's.recovery_amount IS NULL, s.recovery_amount ASC, s.id DESC',
        'name-asc'      => 's.vessel_name ASC, s.id DESC',
    ][$f['sort']];
    return [implode(' AND ', $where), $params, $order];
}

/** Query-string form of the filters (default values left out). */
function recoveryListQs(array $f, array $extra = []): string {
    $f = array_merge($f, $extra);
    if (($f['sort'] ?? '') === 'newest') unset($f['sort']);
    return http_build_query(array_filter($f, fn($v) => $v !== '' && $v !== null && $v !== 0));
}

/** One row ready for display/export: dates d-m-Y, recovery null / float, 'na' for types without Recovery. */
function recoveryListRow(PDO $db, array $r): array {
    $d = fn($v) => (!empty($v) && strpos((string)$v, '0000') !== 0) ? date('d-m-Y', strtotime((string)$v)) : '';
    $n = fn($v) => $v !== null ? (float)$v : null;
    return [
        'id'        => (int)$r['id'],
        'vessel'    => (string)($r['vessel_name'] ?? ''),
        'report_no' => (string)($r['report_number'] ?? ''),
        'client'    => (string)($r['company_name'] ?? ''),
        'port'      => (string)($r['port_name'] ?? ''),
        'country'   => (string)($r['country'] ?? '') ?: 'India',
        'type'      => getCombinedSurveyTypeNames($db, $r['survey_type_ids'] ?? '', $r['type_name'] ?? ''),
        'surveyor'  => !empty($r['no_surveyor']) ? outsourcedCreditSurveyor($db)[1]
                       : getCombinedSurveyorNames($db, (int)$r['id'], $r['surveyor_name'] ?? ''),
        'status'    => (string)$r['status'],
        // Same date the month filter uses (falls back like the Pending Reports list)
        'completed' => ($ts = surveyCompletedTs($r)) > 0 ? date('d-m-Y', $ts) : '',
        'report'    => $d($r['report_uploaded_date'] ?? null),
        'na'        => isRecoveryNotApplicable($db, (string)($r['survey_type_ids'] ?? ''), (string)($r['type_name'] ?? '')),
        'vlsfo'     => $n($r['vlsfo_recovery']),
        'lsmgo'     => $n($r['lsmgo_recovery']),
        'hsfo'      => $n($r['hsfo_recovery']),
        'total'     => $n($r['recovery_amount']),
    ];
}

const RECOVERY_LIST_COLUMNS = "s.id, s.vessel_name, s.report_number, s.status, s.survey_type_ids, st.type_name, c.company_name,
    u.full_name AS surveyor_name, p.port_name, p.country, s.survey_completed_date, s.appointment_date, s.assign_date, s.report_uploaded_date,
    s.vlsfo_recovery, s.lsmgo_recovery, s.hsfo_recovery, s.recovery_amount, " . RECOVERY_NO_SURVEYOR_SQL . " AS no_surveyor";

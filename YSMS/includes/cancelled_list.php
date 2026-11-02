<?php
/**
 * Cancelled Vessels grid (cancelled.php, ajax/cancelled_list.php, export_cancelled.php):
 * who sees which rows, the filters, the sortable columns and each user's grid layout.
 * Read-only on surveys.
 *
 * Visibility (same as export_cancelled.php): Admin and Super Admin see every cancelled
 * vessel, a Client only their company's, a Surveyor only the vessels they are assigned to
 * (primary or through survey_surveyors). The Surveyor column / filter is Admin-only.
 */
require_once __DIR__ . '/list_filters.php';

function cancelledScope(PDO $db): array {
    $role = (string)($_SESSION['role'] ?? '');
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if (in_array($role, ['Admin', 'Super Admin'], true)) return ['1=1', []];
    if ($role === 'Client') {
        $cid = (int)getClientIdForUser($db, $uid);
        return ['s.client_id = ?', [$cid]];
    }
    return ['(s.surveyor_id = ? OR s.id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = ?))', [$uid, $uid]];
}

function cancelledShowsSurveyor(): bool {
    return ($_SESSION['role'] ?? '') === 'Admin';
}

const CANCELLED_FROM = "FROM surveys s
    LEFT JOIN clients c ON s.client_id = c.id
    LEFT JOIN users u ON s.surveyor_id = u.id
    LEFT JOIN survey_types st ON s.survey_type_id = st.id
    LEFT JOIN ports p ON s.port_id = p.id";

/** The date shown for a cancelled vessel (report uploaded → survey completed → assigned). */
const CANCELLED_DATE_SQL = "COALESCE(NULLIF(s.report_uploaded_date, '0000-00-00 00:00:00'), NULLIF(s.survey_completed_date, '0000-00-00'), s.assign_date)";

/**
 * Filters → [state, whereSql, params]. Each list filter accepts one value or several
 * (type[] / place[] / client[] / surveyor[], as the export links send them).
 */
function cancelledFilters(PDO $db, array $g): array {
    $date = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? (string)$v : '';
    $st = [
        'q' => mb_substr(trim((string)($g['q'] ?? '')), 0, 100),
        'type' => listFilterValues($g, 'type'),
        'place' => listFilterValues($g, 'place'),
        'client' => listFilterValues($g, 'client'),
        'surveyor' => cancelledShowsSurveyor() ? listFilterValues($g, 'surveyor') : [],
        'agent' => mb_substr(trim((string)($g['agent'] ?? '')), 0, 100),
        'from' => $date($g['from'] ?? ''), 'to' => $date($g['to'] ?? ''),
        'ids' => [],
    ];
    foreach (explode(',', (string)($g['ids'] ?? '')) as $x) if (ctype_digit(trim($x))) $st['ids'][] = (int)trim($x);
    $st['ids'] = array_slice(array_values(array_unique($st['ids'])), 0, 5000);
    [$scope, $params] = cancelledScope($db);
    $where = ["s.status = 'Cancelled'", $scope];
    if ($st['q'] !== '') {
        $like = '%' . addcslashes($st['q'], '%_\\') . '%';
        $where[] = '(s.vessel_name LIKE ? OR c.company_name LIKE ? OR s.agent_name LIKE ? OR st.type_name LIKE ? OR p.port_name LIKE ? OR u.full_name LIKE ? OR s.report_number LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }
    if ($st['type']) $where[] = listFilterTypeSql($st['type'], $params);
    if ($st['place']) $where[] = listFilterInSql('p.port_name', $st['place'], $params);
    if ($st['client']) $where[] = listFilterInSql('c.company_name', $st['client'], $params);
    if ($st['surveyor']) $where[] = listFilterSurveyorNameSql($st['surveyor'], $params);
    if ($st['agent'] !== '') { $where[] = 's.agent_name LIKE ?'; $params[] = '%' . addcslashes($st['agent'], '%_\\') . '%'; }
    if ($st['from'] !== '') { $where[] = CANCELLED_DATE_SQL . ' >= ?'; $params[] = $st['from']; }
    if ($st['to'] !== '') { $where[] = CANCELLED_DATE_SQL . ' < ?'; $params[] = date('Y-m-d', strtotime($st['to'] . ' +1 day')); }
    if ($st['ids']) $where[] = 's.id IN (' . implode(',', $st['ids']) . ')';
    return [$st, implode(' AND ', $where), $params];
}

const CANCELLED_SORTS = [
    'vessel' => 's.vessel_name',
    'report_no' => 's.report_number',
    'client' => 'c.company_name',
    'agent' => 's.agent_name',
    'type' => 'st.type_name',
    'port' => 'p.port_name',
    'surveyor' => 'u.full_name',
    'date' => CANCELLED_DATE_SQL,
];

/** ORDER BY from [{f, d}] (up to 3 levels, empty values last); default newest first. */
function cancelledOrderSql($sorts): string {
    $parts = [];
    foreach (array_slice(is_array($sorts) ? $sorts : [], 0, 3) as $x) {
        $f = is_array($x) ? (string)($x['f'] ?? '') : '';
        if ($f === 'surveyor' && !cancelledShowsSurveyor()) continue;
        if (!isset(CANCELLED_SORTS[$f])) continue;
        $e = CANCELLED_SORTS[$f];
        $parts[] = "($e IS NULL) ASC, $e " . (strtolower((string)($x['d'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC');
    }
    $parts[] = 's.id DESC';
    return implode(', ', $parts);
}

/** One grid row. */
function cancelledRow(PDO $db, array $r): array {
    $d = $r['shown_date'] ?? '';
    $ok = $d && strpos((string)$d, '0000') !== 0;
    $row = [
        'id' => (int)$r['id'],
        'vessel' => (string)($r['vessel_name'] ?? ''),
        'report_no' => (string)($r['report_number'] ?? ''),
        'client' => (string)($r['company_name'] ?? ''),
        'agent' => (string)($r['agent_name'] ?? ''),
        'type' => (string)getCombinedSurveyTypeNames($db, $r['survey_type_ids'] ?? '', $r['type_name'] ?? ''),
        'port' => (string)($r['port_name'] ?? ''),
        'date' => $ok ? date('Y-m-d H:i', strtotime((string)$d)) : '',
        'assigned' => !empty($r['assign_date']) && strpos((string)$r['assign_date'], '0000') !== 0 ? substr((string)$r['assign_date'], 0, 16) : '',
        'completed' => !empty($r['survey_completed_date']) && strpos((string)$r['survey_completed_date'], '0000') !== 0 ? substr((string)$r['survey_completed_date'], 0, 10) : '',
    ];
    if (cancelledShowsSurveyor()) $row['surveyor'] = (string)getCombinedSurveyorNames($db, $r['id'], $r['surveyor_name'] ?? '');
    return $row;
}

/** Per-user grid layouts for list pages (key = page name). */
function listGridPrefsTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS `list_grid_prefs` (`user_id` int(11) NOT NULL, `page` varchar(40) NOT NULL, `prefs` text NOT NULL,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (`user_id`, `page`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function listGridPrefsLoad(PDO $db, string $page): array {
    try {
        listGridPrefsTable($db);
        $q = $db->prepare('SELECT prefs FROM list_grid_prefs WHERE user_id = ? AND page = ?');
        $q->execute([(int)($_SESSION['user_id'] ?? 0), $page]);
        return json_decode((string)$q->fetchColumn(), true) ?: [];
    } catch (Throwable $e) { return []; }
}

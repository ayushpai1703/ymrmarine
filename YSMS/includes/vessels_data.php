<?php
/**
 * Vessels Data (vessels_data.php) — one editable grid over every surveys row: Pending Vessels,
 * Pending Reports, Completed and Cancelled. An additional module: the existing pages keep
 * working unchanged and read the same surveys rows, so a change made here shows there too.
 *
 *   Who                  sees                                  can
 *   Admin                every vessel                          edit cells, change status, bulk status,
 *                                                              export, import (update existing records)
 *   Super Admin          every vessel                          view + export (no edits — as elsewhere,
 *                                                              detail-page edits are Admin only)
 *   Surveyor             vessels they are assigned to          view + export their own vessels
 *                        (primary or survey_surveyors)
 *   Client / others      — (403)
 *
 * Field rules follow the Admin inline edit on the detail pages (includes/admin_inline_edit.php):
 * same validation, the survey type → recovery N/A rule, the date rules. Recovery figures are
 * not typed here — they come from the existing recovery engine (Calculation Sheet / detail page)
 * and are shown with the Recovery page's rules (recoveryCountedSql, N/A types, Onhire minus).
 * Every change is written to vessel_change_history (database/migration_vessel_change_history.sql).
 */
require_once __DIR__ . '/list_filters.php';
require_once __DIR__ . '/cancelled_list.php'; // listGridPrefsTable / listGridPrefsLoad (per-user grid layout)
require_once __DIR__ . '/notifications.php';

const VD_STATUSES = ['Pending Vessel', 'Pending Report', 'Completed', 'Cancelled'];
const VD_UNASSIGNED_SURVEYOR = 1; // the outsourcing / unassigned placeholder user (same as AIE_UNASSIGNED_SURVEYOR)
const VD_OUTSOURCING_LABEL = 'Outsourcing / Unassigned';
// Same limits as the overdue alert on the detail pages (includes/overdue_alert.php):
// a Pending Vessel is overdue 7 days after its appointment date, a Pending Report 4 days
// after its survey completed date.
const VD_OVERDUE_SURVEY_DAYS = 7;
const VD_OVERDUE_REPORT_DAYS = 4;
const VD_PER = [25, 50, 100, 250];
const VD_ALL_MAX = 5000; // "All" rows per page, up to this many

/** Editable fields: grid key => [surveys column (or pseudo), label]. */
const VD_FIELDS = [
    'vessel'      => ['vessel_name', 'Vessel Name'],
    'imo'         => ['imo_number', 'IMO Number'],
    'report_no'   => ['report_number', 'Report Number'],
    'client'      => ['client_id', 'Client'],
    'agent'       => ['agent_name', 'Agent'],
    'type'        => ['survey_type_ids', 'Survey Type'],
    'port'        => ['port_id', 'Port'],
    'surveyor'    => ['surveyors', 'Surveyor'],
    'assigned'    => ['assign_date', 'Assigned Date'],
    'appointment' => ['appointment_date', 'Appointment Date'],
    'completed'   => ['survey_completed_date', 'Survey Completed Date'],
    'report_date' => ['report_uploaded_date', 'Report Uploaded Date'],
    'remarks'     => ['remarks', 'Remarks'],
    'notes'       => ['notes', 'Notes'],
];

function vdRole(): string { return (string)($_SESSION['role'] ?? ''); }
function vdCanView(): bool { return in_array(vdRole(), ['Admin', 'Super Admin', 'Surveyor'], true); }
function vdCanEdit(): bool { return vdRole() === 'Admin'; }
function vdFullAccess(): bool { return in_array(vdRole(), ['Admin', 'Super Admin'], true); }

/** Rows this user may see → [sql, params]. */
function vdScope(): array {
    if (vdFullAccess()) return ['1=1', []];
    $uid = (int)($_SESSION['user_id'] ?? 0);
    return ['(s.surveyor_id = ? OR s.id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = ?))', [$uid, $uid]];
}

const VD_FROM = "FROM surveys s
    LEFT JOIN clients c ON s.client_id = c.id
    LEFT JOIN users u ON s.surveyor_id = u.id
    LEFT JOIN survey_types st ON s.survey_type_id = st.id
    LEFT JOIN ports p ON s.port_id = p.id
    LEFT JOIN users ub ON ub.id = s.status_updated_by";

/** A date column as a real value or NULL (zero dates stored by old rows count as empty). */
function vdDateSql(string $col): string { return "IF(YEAR(s.$col) > 0, s.$col, NULL)"; }

/** Days past the overdue limit (negative = days still left) for Pending Vessels / Reports, else NULL. */
function vdLateSql(PDO $db): string {
    $today = $db->quote(date('Y-m-d'));
    return "(CASE WHEN s.status = 'Pending Vessel' AND YEAR(s.appointment_date) > 0 THEN DATEDIFF($today, s.appointment_date) - " . VD_OVERDUE_SURVEY_DAYS . "
        WHEN s.status = 'Pending Report' AND YEAR(s.survey_completed_date) > 0 THEN DATEDIFF($today, DATE(s.survey_completed_date)) - " . VD_OVERDUE_REPORT_DAYS . " END)";
}

function vdSorts(PDO $db): array {
    return [
        'id' => 's.id', 'vessel' => 's.vessel_name', 'imo' => 's.imo_number', 'report_no' => 's.report_number',
        'client' => 'c.company_name', 'agent' => 's.agent_name', 'type' => 'st.type_name', 'port' => 'p.port_name',
        'surveyor' => 'u.full_name', 'assigned' => vdDateSql('assign_date'), 'appointment' => vdDateSql('appointment_date'),
        'completed' => vdDateSql('survey_completed_date'), 'report_date' => vdDateSql('report_uploaded_date'),
        'status' => "FIELD(s.status, 'Pending Vessel', 'Pending Report', 'Completed', 'Cancelled')",
        'due' => '(-' . vdLateSql($db) . ')', 'overdue' => vdLateSql($db),
        'recovery' => 'ABS(s.recovery_amount)', 'vlsfo' => 'ABS(s.vlsfo_recovery)', 'lsmgo' => 'ABS(s.lsmgo_recovery)', 'hsfo' => 'ABS(s.hsfo_recovery)',
        'remarks' => 's.remarks', 'notes' => 's.notes', 'updated' => vdDateSql('status_updated_at'),
    ];
}

/** ORDER BY from [{f, d}] (up to 3 levels, empty values last); default latest Assigned Date first. */
function vdOrderSql(PDO $db, $sorts): string {
    $map = vdSorts($db);
    $parts = [];
    foreach (array_slice(is_array($sorts) ? $sorts : [], 0, 3) as $x) {
        $f = is_array($x) ? (string)($x['f'] ?? '') : '';
        if (!isset($map[$f])) continue;
        $e = $map[$f];
        $parts[] = "($e IS NULL) ASC, $e " . (strtolower((string)($x['d'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC');
    }
    if (!$parts) $parts[] = '(' . vdDateSql('assign_date') . ' IS NULL) ASC, ' . vdDateSql('assign_date') . ' DESC';
    $parts[] = 's.id DESC';
    return implode(', ', $parts);
}

/**
 * Filters → [state, whereSql, params]. $skip: filter keys to leave out (the summary counts
 * per status are taken without the status filter).
 */
function vdFilters(PDO $db, array $g, array $skip = []): array {
    $date = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? (string)$v : '';
    $num = function ($v) { $v = str_replace([',', ' '], '', trim((string)$v)); return $v !== '' && is_numeric($v) ? (float)$v : null; };
    $txt = fn($k) => mb_substr(trim((string)($g[$k] ?? '')), 0, 100);
    $st = [
        'q' => $txt('q'), 'vessel' => $txt('vessel'), 'report_no' => $txt('report_no'), 'agent' => $txt('agent'), 'imo' => $txt('imo'),
        'status' => array_values(array_intersect(listFilterValues($g, 'status'), VD_STATUSES)),
        'type' => listFilterValues($g, 'type'), 'port' => listFilterValues($g, 'port'), 'client' => listFilterValues($g, 'client'),
        'surveyor' => vdFullAccess() ? listFilterValues($g, 'surveyor') : [],
        'a_from' => $date($g['a_from'] ?? ''), 'a_to' => $date($g['a_to'] ?? ''),
        'c_from' => $date($g['c_from'] ?? ''), 'c_to' => $date($g['c_to'] ?? ''),
        'r_min' => $num($g['r_min'] ?? ''), 'r_max' => $num($g['r_max'] ?? ''),
        'ids' => [],
    ];
    foreach (explode(',', (string)($g['ids'] ?? '')) as $x) if (ctype_digit(trim($x))) $st['ids'][] = (int)trim($x);
    $st['ids'] = array_slice(array_values(array_unique($st['ids'])), 0, 5000);
    foreach ($skip as $k) $st[$k] = is_array($st[$k]) ? [] : (in_array($k, ['r_min', 'r_max'], true) ? null : '');

    [$scope, $params] = vdScope();
    $where = [$scope];
    $like = fn($v) => '%' . addcslashes($v, '%_\\') . '%';
    if ($st['q'] !== '') {
        $l = $like($st['q']);
        $where[] = '(s.vessel_name LIKE ? OR s.imo_number LIKE ? OR s.report_number LIKE ? OR c.company_name LIKE ? OR s.agent_name LIKE ? OR st.type_name LIKE ? OR p.port_name LIKE ? OR u.full_name LIKE ?
            OR s.id IN (SELECT ss.survey_id FROM survey_surveyors ss JOIN users su ON su.id = ss.surveyor_id WHERE su.full_name LIKE ?) OR s.remarks LIKE ? OR s.notes LIKE ?)';
        array_push($params, $l, $l, $l, $l, $l, $l, $l, $l, $l, $l, $l);
    }
    foreach (['vessel' => 's.vessel_name', 'report_no' => 's.report_number', 'agent' => 's.agent_name', 'imo' => 's.imo_number'] as $k => $col) {
        if ($st[$k] !== '') { $where[] = "$col LIKE ?"; $params[] = $like($st[$k]); }
    }
    if ($st['status']) { $where[] = 's.status IN (' . implode(',', array_fill(0, count($st['status']), '?')) . ')'; array_push($params, ...$st['status']); }
    if ($st['type']) $where[] = listFilterTypeSql($st['type'], $params);
    if ($st['port']) $where[] = listFilterInSql('p.port_name', $st['port'], $params);
    if ($st['client']) $where[] = listFilterInSql('c.company_name', $st['client'], $params);
    if ($st['surveyor']) {
        $names = array_values(array_filter($st['surveyor'], fn($n) => $n !== VD_OUTSOURCING_LABEL));
        $or = [];
        if ($names) { $p2 = []; $or[] = listFilterSurveyorNameSql($names, $p2); array_push($params, ...$p2); }
        if (count($names) !== count($st['surveyor'])) {
            $or[] = '(s.surveyor_id = ' . VD_UNASSIGNED_SURVEYOR . ' AND NOT EXISTS (SELECT 1 FROM survey_surveyors z WHERE z.survey_id = s.id))';
        }
        $where[] = '(' . implode(' OR ', $or) . ')';
    }
    $range = function (string $col, string $from, string $to) use (&$where, &$params) {
        if ($from !== '') { $where[] = vdDateSql($col) . ' >= ?'; $params[] = $from; }
        if ($to !== '') { $where[] = vdDateSql($col) . ' < ?'; $params[] = date('Y-m-d', strtotime($to . ' +1 day')); }
    };
    $range('assign_date', $st['a_from'], $st['a_to']);
    $range('survey_completed_date', $st['c_from'], $st['c_to']);
    if ($st['r_min'] !== null) { $where[] = 'ABS(s.recovery_amount) >= ?'; $params[] = $st['r_min']; }
    if ($st['r_max'] !== null) { $where[] = 'ABS(s.recovery_amount) <= ?'; $params[] = $st['r_max']; }
    if ($st['ids']) $where[] = 's.id IN (' . implode(',', $st['ids']) . ')';
    return [$st, implode(' AND ', $where), $params];
}

const VD_SELECT = 'SELECT s.*, c.company_name, u.full_name AS surveyor_name, st.type_name, p.port_name, p.country, ub.full_name AS status_by_name ';

/** The detail page each status opens (the existing ones — nothing duplicated). */
function vdDetailUrl(array $r): string {
    $id = (int)$r['id'];
    switch ((string)$r['status']) {
        case 'Pending Report': return 'report_detail.php?id=' . $id;
        case 'Completed': return 'completed_detail.php?id=' . $id;
        default: return 'vessel_detail.php?id=' . $id; // Pending Vessel; Cancelled opens the same page
    }
}

/** Surveyor ids per survey for a page of rows (one query). */
function vdSurveyorIds(PDO $db, array $ids): array {
    $out = [];
    if (!$ids) return $out;
    $q = $db->query('SELECT survey_id, surveyor_id FROM survey_surveyors WHERE survey_id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY id');
    foreach ($q->fetchAll(PDO::FETCH_NUM) as [$sid, $uid]) $out[(int)$sid][] = (int)$uid;
    return $out;
}

function vdDateOut($v, int $len = 10): string {
    return (!empty($v) && strpos((string)$v, '0000') !== 0) ? substr((string)$v, 0, $len) : '';
}

/** Whole days from $date to today (same as overdueCalendarDays on the detail pages). */
function vdDaysSince($date): ?int {
    $d = substr(trim((string)$date), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || $d === '0000-00-00') return null;
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
    if (!$start || $start->format('Y-m-d') !== $d) return null;
    return (int)$start->diff(new DateTimeImmutable('today'))->format('%r%a');
}

/** One grid row. $svIds: vdSurveyorIds() for the page. */
function vdRow(PDO $db, array $r, array $svIds = []): array {
    $id = (int)$r['id'];
    $typeCsv = trim((string)($r['survey_type_ids'] ?? '')) !== '' ? (string)$r['survey_type_ids'] : (string)($r['survey_type_id'] ?? '');
    $na = isRecoveryNotApplicable($db, $typeCsv, (string)($r['type_name'] ?? ''));
    $onhire = isOnhireSurvey($db, $typeCsv, (string)($r['type_name'] ?? ''));
    $q = fn($v) => $v === null || $v === '' ? null : round(abs((float)$v), 3);
    $sv = $svIds[$id] ?? [];
    if (!$sv && (int)$r['surveyor_id'] !== VD_UNASSIGNED_SURVEYOR) $sv = [(int)$r['surveyor_id']];
    $surveyor = (string)getCombinedSurveyorNames($db, $id, (int)$r['surveyor_id'] === VD_UNASSIGNED_SURVEYOR ? '' : (string)($r['surveyor_name'] ?? ''));
    // Due / Overdue: same start dates and limits as the overdue alert on the detail pages
    $due = $over = null;
    $days = null; $limit = 0;
    if ($r['status'] === 'Pending Vessel') { $days = vdDaysSince($r['appointment_date'] ?? null); $limit = VD_OVERDUE_SURVEY_DAYS; }
    elseif ($r['status'] === 'Pending Report') { $days = vdDaysSince($r['survey_completed_date'] ?? null); $limit = VD_OVERDUE_REPORT_DAYS; }
    if ($days !== null) { if ($days >= $limit) $over = $days; else $due = $limit - $days; }
    return [
        'id' => $id,
        'vessel' => (string)$r['vessel_name'],
        'imo' => (string)($r['imo_number'] ?? ''),
        'report_no' => (string)($r['report_number'] ?? ''),
        'client' => (string)($r['company_name'] ?? ''), 'client_id' => (int)$r['client_id'],
        'agent' => (string)($r['agent_name'] ?? ''),
        'type' => (string)getCombinedSurveyTypeNames($db, $typeCsv, (string)($r['type_name'] ?? '')),
        'type_ids' => array_values(array_filter(array_map('intval', explode(',', str_replace(' ', '', $typeCsv))))),
        'port' => (string)($r['port_name'] ?? ''), 'port_id' => (int)$r['port_id'], 'country' => (string)($r['country'] ?? ''),
        'surveyor' => $surveyor !== '' ? $surveyor : VD_OUTSOURCING_LABEL, 'surveyor_ids' => $sv,
        'assigned' => vdDateOut($r['assign_date'] ?? null, 16),
        'appointment' => vdDateOut($r['appointment_date'] ?? null),
        'completed' => vdDateOut($r['survey_completed_date'] ?? null),
        'report_date' => vdDateOut($r['report_uploaded_date'] ?? null),
        'status' => (string)$r['status'],
        'due' => $due, 'overdue' => $over,
        'na' => $na, 'onhire' => $onhire,
        'recovery' => $na ? null : $q($r['recovery_amount'] ?? null),
        'vlsfo' => $na ? null : $q($r['vlsfo_recovery'] ?? null),
        'lsmgo' => $na ? null : $q($r['lsmgo_recovery'] ?? null),
        'hsfo' => $na ? null : $q($r['hsfo_recovery'] ?? null),
        'remarks' => (string)($r['remarks'] ?? ''),
        'notes' => (string)($r['notes'] ?? ''),
        'live_status' => (string)($r['custom_live_status'] ?? ''),
        'updated_at' => vdDateOut($r['status_updated_at'] ?? null, 16),
        'updated_by' => (string)($r['status_by_name'] ?? ''),
        'agent_email' => vdDateOut($r['agent_email_sent_at'] ?? null, 16),
        'url' => vdDetailUrl($r),
    ];
}

/* ------------------------------------------------------------------ */
/* Change history                                                      */
/* ------------------------------------------------------------------ */

/** Same table as database/migration_vessel_change_history.sql (created here when missing). */
function vdHistoryTable(PDO $db): void {
    static $done = false;
    if ($done) return;
    $db->exec("CREATE TABLE IF NOT EXISTS `vessel_change_history` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `survey_id` int(11) NOT NULL,
        `user_id` int(11) DEFAULT NULL,
        `field_name` varchar(50) NOT NULL,
        `old_value` text DEFAULT NULL,
        `new_value` text DEFAULT NULL,
        `source` varchar(20) NOT NULL DEFAULT 'grid',
        `changed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_vch_survey` (`survey_id`, `changed_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function vdAudit(PDO $db, int $sid, string $field, ?string $old, ?string $new, string $source): void {
    vdHistoryTable($db);
    $db->prepare('INSERT INTO vessel_change_history (survey_id, user_id, field_name, old_value, new_value, source, changed_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
       ->execute([$sid, (int)($_SESSION['user_id'] ?? 0) ?: null, $field, $old, $new, $source, date('Y-m-d H:i:s')]);
}

/** History of one vessel, newest first. */
function vdHistory(PDO $db, int $sid, int $limit = 200): array {
    vdHistoryTable($db);
    $q = $db->prepare('SELECT h.field_name, h.old_value, h.new_value, h.source, h.changed_at, u.full_name FROM vessel_change_history h
        LEFT JOIN users u ON u.id = h.user_id WHERE h.survey_id = ? ORDER BY h.changed_at DESC, h.id DESC LIMIT ' . max(1, min(500, $limit)));
    $q->execute([$sid]);
    return array_map(fn($r) => [
        'field' => VD_FIELDS[$r['field_name']][1] ?? ($r['field_name'] === 'status' ? 'Status' : ucwords(str_replace('_', ' ', $r['field_name']))),
        'old' => $r['old_value'], 'new' => $r['new_value'], 'source' => $r['source'], 'at' => $r['changed_at'], 'by' => (string)($r['full_name'] ?? ''),
    ], $q->fetchAll(PDO::FETCH_ASSOC));
}

/* ------------------------------------------------------------------ */
/* Field values: current, validation, save                             */
/* ------------------------------------------------------------------ */

function vdTypeIdsOf(array $s): array {
    $raw = trim((string)($s['survey_type_ids'] ?? '')) !== '' ? (string)$s['survey_type_ids'] : (string)($s['survey_type_id'] ?? '');
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', str_replace(' ', '', $raw))))));
    sort($ids);
    return $ids;
}

function vdSurveyorIdsOf(PDO $db, array $s): array {
    $ids = vdSurveyorIds($db, [(int)$s['id']])[(int)$s['id']] ?? [];
    if (!$ids && (int)$s['surveyor_id'] !== VD_UNASSIGNED_SURVEYOR) $ids = [(int)$s['surveyor_id']];
    return $ids;
}

/** The field's value as the grid sends it (ids as "1,2", dates Y-m-d, assigned Y-m-dTH:i). */
function vdCurrent(PDO $db, array $s, string $key): string {
    switch ($key) {
        case 'client': return (string)(int)$s['client_id'];
        case 'port': return (string)(int)$s['port_id'];
        case 'type': return implode(',', vdTypeIdsOf($s));
        case 'surveyor': return implode(',', vdSurveyorIdsOf($db, $s));
        case 'assigned': $v = vdDateOut($s['assign_date'] ?? null, 16); return $v !== '' ? str_replace(' ', 'T', $v) : '';
        case 'appointment': case 'completed': case 'report_date': return vdDateOut($s[VD_FIELDS[$key][0]] ?? null);
        default: return (string)($s[VD_FIELDS[$key][0]] ?? '');
    }
}

/** Readable form of a value for the change history. */
function vdDisplay(PDO $db, string $key, string $v): string {
    if ($v === '') return $key === 'surveyor' ? VD_OUTSOURCING_LABEL : '';
    switch ($key) {
        case 'client': $q = $db->prepare('SELECT company_name FROM clients WHERE id = ?'); $q->execute([(int)$v]); return (string)$q->fetchColumn();
        case 'port': $q = $db->prepare('SELECT port_name FROM ports WHERE id = ?'); $q->execute([(int)$v]); return (string)$q->fetchColumn();
        case 'type': return (string)getCombinedSurveyTypeNames($db, $v);
        case 'surveyor':
            $ids = array_map('intval', explode(',', $v));
            $names = $db->query('SELECT id, full_name FROM users WHERE id IN (' . implode(',', $ids) . ')')->fetchAll(PDO::FETCH_KEY_PAIR);
            return implode(' + ', array_map(fn($i) => (string)($names[$i] ?? '#' . $i), $ids));
        case 'assigned': return str_replace('T', ' ', $v);
        default: return $v;
    }
}

function vdParseDate(string $v, bool $withTime = false): ?string {
    $v = trim($v);
    if ($v === '') return '';
    $fmt = $withTime ? 'Y-m-d\TH:i' : 'Y-m-d';
    $d = DateTime::createFromFormat('!' . $fmt, $v);
    if (!$d || $d->format($fmt) !== $v || (int)$d->format('Y') < 2000 || (int)$d->format('Y') > 2100) return null;
    return $d->format($withTime ? 'Y-m-d H:i:s' : 'Y-m-d');
}

/** Keep the stored time of day when only the date is (re)picked (as on the detail pages). */
function vdWithTime(string $date, $old): ?string {
    if ($date === '') return null;
    if (!empty($old) && strpos((string)$old, $date) === 0) return (string)$old;
    return $date . ' ' . (!empty($old) && strlen((string)$old) >= 19 && strpos((string)$old, '0000') !== 0 ? substr((string)$old, 11, 8) : date('H:i:s'));
}

/**
 * Validate one field → ['ok' => false, 'message'] or ['ok' => true, 'set' => [col => value],
 * 'surveyors' => ids|null (survey_surveyors to write), 'value' => canonical new value, 'note' => ''].
 */
function vdValidate(PDO $db, array $s, string $key, string $value): array {
    $err = fn($m) => ['ok' => false, 'message' => $m];
    if (!isset(VD_FIELDS[$key])) return $err('This field cannot be edited.');
    [$col, $label] = VD_FIELDS[$key];
    $v = trim($value);
    $id = (int)$s['id'];
    $ok = fn(array $set, string $canon, ?array $sv = null, string $note = '') => ['ok' => true, 'set' => $set, 'surveyors' => $sv, 'value' => $canon, 'note' => $note];
    switch ($key) {
        case 'vessel':
        case 'agent':
            if ($v === '') return $err($label . ' is required.');
            if (mb_strlen($v) > 255) return $err($label . ' is too long (255 characters max).');
            return $ok([$col => $v], $v);
        case 'imo':
            $d = preg_replace('/[^0-9]/', '', preg_replace('/imo/i', '', $v));
            if ($v !== '' && !preg_match('/^\d{7}$/', $d)) return $err('IMO Number must be 7 digits.');
            return $ok([$col => $d !== '' ? $d : null], $d);
        case 'report_no':
            if (mb_strlen($v) > 50) return $err('Report Number is too long (50 characters max).');
            if ($v !== '') {
                $q = $db->prepare('SELECT id FROM surveys WHERE report_number = ? AND id <> ? LIMIT 1');
                $q->execute([$v, $id]);
                if ($q->fetchColumn()) return $err('Report Number "' . $v . '" is already used by another vessel.');
            }
            return $ok([$col => $v !== '' ? $v : null], $v);
        case 'client':
        case 'port':
            $t = $key === 'client' ? 'clients' : 'ports';
            $q = $db->prepare("SELECT id FROM `$t` WHERE id = ?");
            $q->execute([(int)$v]);
            if (!ctype_digit($v) || !$q->fetchColumn()) return $err('Please choose a ' . strtolower($label) . '.');
            return $ok([$col => (int)$v], (string)(int)$v);
        case 'type':
            $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $v)))));
            if (!$ids) return $err('Choose at least one survey type.');
            if ((int)$db->query('SELECT COUNT(*) FROM survey_types WHERE id IN (' . implode(',', $ids) . ')')->fetchColumn() !== count($ids)) return $err('Unknown survey type selected.');
            sort($ids);
            $csv = implode(',', $ids);
            $set = ['survey_type_id' => $ids[0], 'survey_type_ids' => $csv];
            $note = '';
            if (isRecoveryNotApplicable($db, $csv)) { // same rule as the detail-page edit
                $set += ['recovery_amount' => null, 'vlsfo_recovery' => null, 'hsfo_recovery' => null, 'lsmgo_recovery' => null];
                $note = 'recovery is not applicable for this type';
            }
            return $ok($set, $csv, null, $note);
        case 'surveyor':
            $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $v)))));
            if ($ids) {
                $n = (int)$db->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'Surveyor' AND u.id IN (" . implode(',', $ids) . ')')->fetchColumn();
                if ($n !== count($ids) || in_array(VD_UNASSIGNED_SURVEYOR, $ids, true)) return $err('Unknown surveyor selected.');
            }
            return $ok(['surveyor_id' => $ids ? $ids[0] : VD_UNASSIGNED_SURVEYOR], implode(',', $ids), $ids);
        case 'assigned':
            $d = vdParseDate($v, true);
            if ($d === null) return $err('Assigned Date is not a valid date and time.');
            return $ok([$col => $d !== '' ? $d : null], $d !== '' ? substr(str_replace(' ', 'T', $d), 0, 16) : '');
        case 'appointment':
            $d = vdParseDate($v);
            if ($d === null) return $err('Appointment Date is not a valid date.');
            return $ok([$col => $d !== '' ? $d : null], $d);
        case 'completed':
        case 'report_date':
            $d = vdParseDate($v);
            if ($d === null) return $err($label . ' is not a valid date.');
            $st = (string)$s['status'];
            if ($d === '' && ($key === 'completed' ? in_array($st, ['Pending Report', 'Completed'], true) : $st === 'Completed')) {
                return $err('A ' . $st . ' record needs a ' . $label . '.');
            }
            $scd = $key === 'completed' ? $d : vdDateOut($s['survey_completed_date'] ?? null);
            $rpt = $key === 'report_date' ? $d : vdDateOut($s['report_uploaded_date'] ?? null);
            if ($scd !== '' && $rpt !== '' && $rpt < $scd) return $err('Report Uploaded Date cannot be before the Survey Completed Date.');
            return $ok([$col => vdWithTime($d, $s[$col] ?? null)], $d);
        case 'remarks':
        case 'notes':
            $v = str_replace(["\r\n", "\r"], "\n", $v); // browsers send textarea line breaks as CRLF
            if (mb_strlen($v) > 5000) return $err($label . ' is too long (5000 characters max).');
            return $ok([$col => $v !== '' ? $v : null], $v);
    }
    return $err('This field cannot be edited.');
}

/** Writes a validated change (inside the caller's transaction when there is one) + history. */
function vdApply(PDO $db, array $s, string $key, array $res, string $source): void {
    $id = (int)$s['id'];
    $old = vdCurrent($db, $s, $key);
    vdHistoryTable($db); // DDL commits implicitly in MySQL: never run it inside the transaction
    $own = !$db->inTransaction();
    if ($own) $db->beginTransaction();
    try {
        $db->prepare('UPDATE surveys SET ' . implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($res['set']))) . ' WHERE id = ?')
           ->execute([...array_values($res['set']), $id]);
        if ($res['surveyors'] !== null) {
            $db->prepare('DELETE FROM survey_surveyors WHERE survey_id = ?')->execute([$id]);
            $ins = $db->prepare('INSERT INTO survey_surveyors (survey_id, surveyor_id) VALUES (?, ?)');
            foreach ($res['surveyors'] as $u) $ins->execute([$id, $u]);
        }
        vdAudit($db, $id, $key, vdDisplay($db, $key, $old), vdDisplay($db, $key, $res['value']), $source);
        if (isset($res['set']['recovery_amount']) || array_key_exists('recovery_amount', $res['set'])) {
            $was = $s['recovery_amount'] ?? null;
            if ($was !== null) vdAudit($db, $id, 'recovery_amount', (string)abs((float)$was), '', $source);
        }
        if ($own) $db->commit();
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/**
 * Save one cell. $orig: the value the grid showed (null = don't check) — when the record
 * changed meanwhile the save is refused instead of overwriting someone else's change.
 * → ['ok', 'message', 'changed' => bool]
 */
function vdSaveField(PDO $db, array $s, string $key, string $value, ?string $orig, string $source = 'grid'): array {
    $cur = vdCurrent($db, $s, $key);
    if ($orig !== null && !vdSameValue($key, $orig, $cur)) {
        return ['ok' => false, 'conflict' => true, 'message' => VD_FIELDS[$key][1] . ' was changed by someone else meanwhile. The latest value is shown now — check it and edit again.'];
    }
    $res = vdValidate($db, $s, $key, $value);
    if (!$res['ok']) return $res;
    if (vdSameValue($key, $res['value'], $cur) && !($key === 'type' && $res['note'] !== '' && ($s['recovery_amount'] ?? null) !== null)) {
        return ['ok' => true, 'changed' => false, 'message' => 'No change.'];
    }
    try {
        vdApply($db, $s, $key, $res, $source);
    } catch (Throwable $e) {
        error_log('vessels_data save ' . $key . ' #' . (int)$s['id'] . ': ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not save. Please try again.'];
    }
    return ['ok' => true, 'changed' => true, 'message' => VD_FIELDS[$key][1] . ' saved' . ($res['note'] !== '' ? ' · ' . $res['note'] : '') . '.'];
}

function vdSameValue(string $key, string $a, string $b): bool {
    if (in_array($key, ['type', 'surveyor'], true)) {
        $ta = array_values(array_unique(array_filter(array_map('intval', explode(',', $a)))));
        $tb = array_values(array_unique(array_filter(array_map('intval', explode(',', $b)))));
        if ($key === 'type') { sort($ta); sort($tb); }
        return $ta === $tb;
    }
    if ($key === 'imo') return preg_replace('/\D/', '', $a) === preg_replace('/\D/', '', $b);
    return trim($a) === trim($b);
}

/* ------------------------------------------------------------------ */
/* Status                                                              */
/* ------------------------------------------------------------------ */

/**
 * What moving $s to $to needs: the dates a Pending Report (Survey Completed) and a Completed
 * record (Survey Completed + Report Uploaded) must have, as the upload workflow sets them.
 */
function vdStatusNeeds(array $s, string $to): array {
    $need = [];
    if (in_array($to, ['Pending Report', 'Completed'], true) && vdDateOut($s['survey_completed_date'] ?? null) === '') $need[] = 'completed';
    if ($to === 'Completed' && vdDateOut($s['report_uploaded_date'] ?? null) === '') $need[] = 'report_date';
    return $need;
}

/**
 * Validate a status change → ['ok' => false, 'message'] or ['ok' => true, 'set' => [...], 'dates' => [key => Y-m-d]].
 * $dates: dates given for the missing ones (completed / report_date, Y-m-d).
 */
function vdStatusPlan(array $s, string $to, array $dates): array {
    $err = fn($m) => ['ok' => false, 'message' => $m];
    if (!in_array($to, VD_STATUSES, true)) return $err('Unknown status.');
    if ($to === (string)$s['status']) return ['ok' => true, 'same' => true, 'set' => [], 'dates' => []];
    $set = ['status' => $to];
    $given = [];
    foreach (vdStatusNeeds($s, $to) as $k) {
        $d = vdParseDate((string)($dates[$k] ?? ''));
        if ($d === null || $d === '') return $err('Enter the ' . VD_FIELDS[$k][1] . ' for ' . $s['vessel_name'] . ' — a ' . $to . ' record needs it.');
        $given[$k] = $d;
        $set[VD_FIELDS[$k][0]] = vdWithTime($d, null);
    }
    $scd = $given['completed'] ?? vdDateOut($s['survey_completed_date'] ?? null);
    $rpt = $given['report_date'] ?? vdDateOut($s['report_uploaded_date'] ?? null);
    if ($to === 'Completed' && $scd !== '' && $rpt !== '' && $rpt < $scd) return $err('Report Uploaded Date cannot be before the Survey Completed Date (' . $s['vessel_name'] . ').');
    return ['ok' => true, 'same' => false, 'set' => $set, 'dates' => $given];
}

/** Apply a planned status change (in the caller's transaction) + history. */
function vdStatusApply(PDO $db, array $s, array $plan, string $source): void {
    $id = (int)$s['id'];
    $set = $plan['set'] + ['status_updated_by' => (int)($_SESSION['user_id'] ?? 0) ?: null, 'status_updated_at' => date('Y-m-d H:i:s')];
    $db->prepare('UPDATE surveys SET ' . implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($set))) . ' WHERE id = ?')
       ->execute([...array_values($set), $id]);
    vdAudit($db, $id, 'status', (string)$s['status'], (string)$plan['set']['status'], $source);
    foreach ($plan['dates'] as $k => $d) vdAudit($db, $id, $k, '', $d, $source);
}

/** Tell the vessel's surveyors (as the Cancel action in Admin Controls does). Never fails the save. */
function vdNotifyStatus(PDO $db, array $s, string $to): void {
    try {
        $ids = vdSurveyorIdsOf($db, $s);
        $link = vdDetailUrl(['id' => $s['id'], 'status' => $to]);
        foreach (array_unique($ids) as $uid) {
            if ($uid === (int)($_SESSION['user_id'] ?? 0)) continue;
            createNotification($db, $uid, 'Vessel status changed', ($_SESSION['full_name'] ?? 'Admin') . ' moved ' . $s['vessel_name'] . ' to ' . $to . '.',
                $to === 'Cancelled' ? 'cancel' : 'info', $link, (int)($_SESSION['user_id'] ?? 0));
        }
    } catch (Throwable $e) { error_log('vessels_data notify: ' . $e->getMessage()); }
}

/** Load one survey the current user may see (null when not found / not allowed). */
function vdLoad(PDO $db, int $id, bool $lock = false): ?array {
    [$scope, $params] = vdScope();
    $q = $db->prepare("SELECT s.* FROM surveys s WHERE s.id = ? AND $scope" . ($lock ? ' FOR UPDATE' : ''));
    $q->execute([$id, ...$params]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Option lists for the editors and filters. */
function vdOptions(PDO $db): array {
    $types = $db->query('SELECT id, type_name AS name FROM survey_types ORDER BY type_name')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($types as &$t) $t['id'] = (int)$t['id'];
    unset($t);
    return [
        'clients' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name']], $db->query('SELECT id, company_name AS name FROM clients ORDER BY company_name')->fetchAll(PDO::FETCH_ASSOC)),
        'ports' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'country' => (string)$r['country']],
            $db->query("SELECT id, port_name AS name, country FROM ports ORDER BY port_name")->fetchAll(PDO::FETCH_ASSOC)),
        'types' => $types,
        'surveyors' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'active' => $r['status'] === 'Active'],
            $db->query("SELECT u.id, u.full_name AS name, u.status FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'Surveyor' AND u.id <> " . VD_UNASSIGNED_SURVEYOR . ' ORDER BY u.full_name')->fetchAll(PDO::FETCH_ASSOC)),
    ];
}

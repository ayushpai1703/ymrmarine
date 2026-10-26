<?php
/**
 * JSON data for the Android app screens (app.php + assets/app/). Read-only: every change
 * (upload, status update, edits) still goes through the existing pages and their checks.
 *
 * Visibility is the same as the web lists and detail pages:
 *   Admin / Super Admin → every survey; Surveyor → surveys assigned to them (primary or
 *   survey_surveyors); Client → their own company's surveys.
 *
 *   GET app_api.php?a=me
 *   GET app_api.php?a=dashboard
 *   GET app_api.php?a=list&status=pending_vessel|pending_report|completed[&q=text][&overdue=1][&page=1]
 *   GET app_api.php?a=survey&id=<id>
 */
require_once 'config/config.php';
require_once 'includes/notifications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

function appOut(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') appOut(['error' => 'GET only'], 405);
if (empty($_SESSION['user_id'])) appOut(['error' => 'login'], 401);

$db = getDB();
$userId = (int)$_SESSION['user_id'];
$role = (string)($_SESSION['role'] ?? '');
$fullAccess = in_array($role, ['Admin', 'Super Admin'], true);
$clientId = $role === 'Client' ? getClientIdForUser($db, $userId) : 0;
$csrf = csrf_token(); // the app sends it with forms it posts to the existing pages
session_write_close();

const APP_STATUSES = [
    'pending_vessel' => 'Pending Vessel',
    'pending_report' => 'Pending Report',
    'completed' => 'Completed',
];

/** [sql, params] limiting `surveys s` to what this user may see. */
function appScope(string $role, bool $fullAccess, int $userId, int $clientId): array
{
    if ($fullAccess) return ['1=1', []];
    if ($role === 'Client') return ['s.client_id = ?', [$clientId]];
    return ['(s.surveyor_id = ? OR s.id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = ?))', [$userId, $userId]];
}

function appDate($v, string $fmt = 'd M Y'): string
{
    $v = (string)$v;
    if ($v === '' || strpos($v, '0000-00-00') === 0) return '';
    $t = strtotime($v);
    return $t ? date($fmt, $t) : '';
}

function appInitials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $s = '';
    foreach (array_slice($parts, 0, 2) as $p) $s .= mb_strtoupper(mb_substr($p, 0, 1));
    return $s !== '' ? $s : '?';
}

/** Same rule as includes/overdue_alert.php, loaded without its markup. */
function appOverdue(array $survey, string $role): ?array
{
    static $loaded = false;
    if (!$loaded) {
        (function () { ob_start(); include_once __DIR__ . '/includes/overdue_alert.php'; ob_end_clean(); })();
        $loaded = true;
    }
    return overdueAlertFor($survey, $role);
}

function appAvatar(PDO $db, int $userId): string
{
    try {
        $st = $db->prepare('SELECT avatar, profile_pic FROM users WHERE id = ?');
        $st->execute([$userId]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach (['avatar', 'profile_pic'] as $k) {
            $v = (string)($r[$k] ?? '');
            if ($v !== '' && strpos($v, 'uploads/') === 0 && is_file(__DIR__ . '/' . $v)) return $v;
        }
    } catch (Throwable $e) { error_log('app_api avatar: ' . $e->getMessage()); }
    return '';
}

function appCard(PDO $db, array $s, bool $fullAccess, string $role): array
{
    $over = appOverdue($s, $role);
    return [
        'id' => (int)$s['id'],
        'vessel' => (string)($s['vessel_name'] ?: 'Vessel'),
        'client' => (string)($s['company_name'] ?? ''),
        'agent' => (string)($s['agent_name'] ?? ''),
        'port' => (string)($s['port_name'] ?? ''),
        'type' => getCombinedSurveyTypeNames($db, $s['survey_type_ids'] ?? '', (string)($s['type_name'] ?? '')),
        'surveyors' => $fullAccess ? getCombinedSurveyorNames($db, $s['id'], (string)($s['surveyor_name'] ?? '')) : '',
        'appointment' => appDate($s['appointment_date'] ?? ''),
        'completed_on' => appDate($s['survey_completed_date'] ?? ''),
        'report_on' => appDate($s['report_uploaded_date'] ?? ''),
        'update' => (string)($s['custom_live_status'] ?? ''),
        'update_when' => !empty($s['status_updated_at']) ? formatLatestUpdateWhen((string)$s['status_updated_at']) : '',
        'update_by' => (string)($s['modifier_name'] ?? ''),
        'overdue' => $over ? ['kind' => $over['kind'], 'days' => $over['days']] : null,
        'status' => (string)$s['status'],
    ];
}

$a = (string)($_GET['a'] ?? '');
[$scopeSql, $scopeParams] = appScope($role, $fullAccess, $userId, $clientId);

if ($a === 'me') {
    $name = (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? '');
    $unread = 0;
    if (in_array($role, ['Admin', 'Surveyor'], true)) {
        try { $unread = (int)getUnreadNotificationCount($db, $userId); } catch (Throwable $e) { $unread = 0; }
    }
    appOut([
        'name' => $name,
        'first' => explode(' ', trim($name))[0] ?? '',
        'initials' => appInitials($name),
        'role' => $role,
        'avatar' => appAvatar($db, $userId),
        'greeting' => getGreeting(),
        'unread' => $unread,
        'csrf' => $csrf,
        'can' => [
            'assign' => in_array($role, ['Admin', 'Client'], true),
            'inbox' => in_array($role, ['Admin', 'Surveyor'], true),
            'chat' => in_array($role, ['Admin', 'Super Admin', 'Surveyor'], true),
            'recovery' => $role !== 'Client',
            'survey_form' => in_array($role, ['Admin', 'Super Admin', 'Surveyor'], true),
            'report_generator' => in_array($role, ['Admin', 'Super Admin', 'Surveyor'], true),
        ],
    ]);
}

if ($a === 'dashboard') {
    require_once 'includes/recovery_list.php';
    $counts = [];
    foreach (APP_STATUSES as $key => $status) {
        $st = $db->prepare("SELECT COUNT(*) FROM surveys s WHERE s.status = ? AND $scopeSql");
        $st->execute(array_merge([$status], $scopeParams));
        $counts[$key] = (int)$st->fetchColumn();
    }
    $cmStart = date('Y-m-01');
    $cmEnd = date('Y-m-01', strtotime('first day of next month'));
    $recBase = "s.status IN ('Pending Report','Completed') AND s.recovery_amount IS NOT NULL AND " . recoveryAppliesSql('s') . " AND $scopeSql";
    $st = $db->prepare("SELECT SUM(ABS(s.recovery_amount)) FROM surveys s WHERE $recBase");
    $st->execute($scopeParams);
    $recTotal = (float)$st->fetchColumn();
    $cd = surveyCompletedDateSql('s');
    $st = $db->prepare("SELECT SUM(ABS(s.recovery_amount)) FROM surveys s WHERE $recBase AND $cd >= ? AND $cd < ?");
    $st->execute(array_merge($scopeParams, [$cmStart, $cmEnd]));
    $recMonth = (float)$st->fetchColumn();
    $st = $db->prepare("SELECT COUNT(*) FROM surveys s WHERE s.status IN ('Pending Report','Completed') AND $scopeSql AND $cd >= ? AND $cd < ?");
    $st->execute(array_merge($scopeParams, [$cmStart, $cmEnd]));
    $shipsMonth = (int)$st->fetchColumn();

    // Needs attention: the same overdue rule as the detail pages, open surveys only.
    $attention = [];
    if (in_array($role, ['Admin', 'Super Admin', 'Surveyor'], true)) {
        $st = $db->prepare("
            SELECT s.*, c.company_name, p.port_name, st.type_name
            FROM surveys s
            JOIN clients c ON s.client_id = c.id
            LEFT JOIN ports p ON s.port_id = p.id
            LEFT JOIN survey_types st ON s.survey_type_id = st.id
            WHERE s.status IN ('Pending Vessel','Pending Report') AND $scopeSql
              AND ((s.status = 'Pending Vessel' AND s.appointment_date IS NOT NULL AND s.appointment_date <= ?)
                OR (s.status = 'Pending Report' AND s.survey_completed_date IS NOT NULL AND DATE(s.survey_completed_date) <= ?))
            ORDER BY s.id DESC LIMIT 60");
        $st->execute(array_merge($scopeParams, [date('Y-m-d', strtotime('-7 days')), date('Y-m-d', strtotime('-4 days'))]));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $over = appOverdue($row, $role);
            if (!$over) continue;
            $attention[] = ['id' => (int)$row['id'], 'vessel' => (string)$row['vessel_name'], 'status' => (string)$row['status'],
                'kind' => $over['kind'], 'days' => $over['days'], 'port' => (string)($row['port_name'] ?? '')];
        }
        usort($attention, function ($x, $y) { return $y['days'] <=> $x['days']; });
        $attention = array_slice($attention, 0, 5);
    }
    appOut([
        'counts' => $counts,
        'recovery_total' => round($recTotal, 3),
        'recovery_month' => round($recMonth, 3),
        'ships_month' => $shipsMonth,
        'month' => date('F Y'),
        'attention' => $attention,
        'today' => date('d M Y'),
    ]);
}

if ($a === 'list') {
    $key = (string)($_GET['status'] ?? '');
    if (!isset(APP_STATUSES[$key])) appOut(['error' => 'status'], 400);
    $q = trim((string)($_GET['q'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 30;
    $where = ['s.status = ?', $scopeSql];
    $params = array_merge([APP_STATUSES[$key]], $scopeParams);
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 80)) . '%';
        $where[] = '(s.vessel_name LIKE ? OR c.company_name LIKE ? OR s.agent_name LIKE ? OR st.type_name LIKE ? OR p.port_name LIKE ? OR u.full_name LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }
    if (!empty($_GET['overdue'])) {
        // Same thresholds as includes/overdue_alert.php (7 days / 4 days); open surveys only.
        if ($key === 'pending_vessel') { $where[] = 's.appointment_date IS NOT NULL AND s.appointment_date <= ?'; $params[] = date('Y-m-d', strtotime('-7 days')); }
        elseif ($key === 'pending_report') { $where[] = 'YEAR(s.survey_completed_date) > 0 AND DATE(s.survey_completed_date) <= ?'; $params[] = date('Y-m-d', strtotime('-4 days')); }
        else $where[] = '1=0';
    }
    $whereSql = implode(' AND ', $where);
    $from = "FROM surveys s
        JOIN clients c ON s.client_id = c.id
        JOIN users u ON s.surveyor_id = u.id
        LEFT JOIN users uu ON s.status_updated_by = uu.id
        LEFT JOIN survey_types st ON s.survey_type_id = st.id
        LEFT JOIN ports p ON s.port_id = p.id";
    $st = $db->prepare("SELECT COUNT(*) $from WHERE $whereSql");
    $st->execute($params);
    $total = (int)$st->fetchColumn();
    $order = $key === 'completed' ? SURVEY_REPORT_ORDER_SQL : SURVEY_APPOINTMENT_ORDER_SQL;
    $offset = ($page - 1) * $per;
    $st = $db->prepare("SELECT s.*, c.company_name, u.full_name AS surveyor_name, uu.full_name AS modifier_name, st.type_name, p.port_name
        $from WHERE $whereSql ORDER BY $order DESC, s.id DESC LIMIT $per OFFSET $offset");
    $st->execute($params);
    $items = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $items[] = appCard($db, $row, $fullAccess, $role);
    appOut(['total' => $total, 'page' => $page, 'more' => $offset + count($items) < $total, 'items' => $items]);
}

if ($a === 'survey') {
    $id = (int)($_GET['id'] ?? 0);
    $st = $db->prepare("SELECT s.*, c.company_name, u.full_name AS surveyor_name, uu.full_name AS modifier_name, st.type_name, p.port_name
        FROM surveys s
        JOIN clients c ON s.client_id = c.id
        JOIN users u ON s.surveyor_id = u.id
        LEFT JOIN users uu ON s.status_updated_by = uu.id
        LEFT JOIN survey_types st ON s.survey_type_id = st.id
        LEFT JOIN ports p ON s.port_id = p.id
        WHERE s.id = ?");
    $st->execute([$id]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    $allowed = $s && ($fullAccess || isSurveyorAssignedToSurvey($db, $id, $userId)
        || ($role === 'Client' && (int)$s['client_id'] === $clientId && $clientId > 0));
    if (!$allowed) appOut(['error' => 'not_found'], 404);

    $card = appCard($db, $s, true, $role);
    $docs = [];
    try {
        $ds = $db->prepare('SELECT id, file_name, file_type, file_size, uploaded_at FROM uploads WHERE survey_id = ? ORDER BY id ASC');
        $ds->execute([$id]);
        foreach ($ds->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $docs[] = ['id' => (int)$d['id'], 'name' => (string)$d['file_name'], 'type' => (string)$d['file_type'],
                'size' => (string)($d['file_size'] ?? ''), 'when' => appDate($d['uploaded_at'] ?? '', 'd M Y')];
        }
    } catch (Throwable $e) { error_log('app_api docs: ' . $e->getMessage()); }

    $page = ['Pending Vessel' => 'vessel_detail.php', 'Pending Report' => 'report_detail.php', 'Completed' => 'completed_detail.php'][$s['status']] ?? 'vessel_detail.php';
    $assigned = $role === 'Surveyor' && isSurveyorAssignedToSurvey($db, $id, $userId);
    $card += [
        'report_no' => (string)($s['report_number'] ?? ''),
        'imo' => (string)($s['imo_number'] ?? ''),
        'assigned_on' => appDate($s['assign_date'] ?? ''),
        'remarks' => (string)($s['remarks'] ?? ''),
        'docs' => $docs,
        'web' => $page . '?id=' . $id,
        'can_upload' => $s['status'] === 'Pending Vessel' && ($role === 'Admin' || $assigned),
        // Same as the Latest Update form on vessel_detail.php (shown to everyone who can open it).
        'can_update' => $s['status'] === 'Pending Vessel',
        'can_survey_form' => in_array($s['status'], ['Pending Vessel', 'Pending Report'], true) && ($fullAccess || $assigned),
        'can_history' => $fullAccess || $assigned,
    ];
    appOut($card);
}

appOut(['error' => 'unknown'], 400);

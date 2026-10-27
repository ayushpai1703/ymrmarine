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
 *   GET app_api.php?a=list&status=pending_vessel|pending_report|completed|cancelled[&q=text][&overdue=1][&page=1]
 *   GET app_api.php?a=survey&id=<id>
 *   GET app_api.php?a=flash                      the message the last form post left (read once)
 *   GET app_api.php?a=inbox[&unread=1][&page=1]  Admin / Surveyor
 *   GET app_api.php?a=recovery[&q=][&month=YYYY-MM][&status=][&page=1]
 *   GET app_api.php?a=formats
 *   GET app_api.php?a=profile
 *   GET app_api.php?a=history&id=<id>             previous surveys of the same vessel
 *   GET app_api.php?a=assign_form[&edit_id=<id>]   choices for Assign Vessel / Request Survey (assign_vessel.php)
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
if (($_GET['a'] ?? '') === 'flash') {
    // Upload / form pages leave their result in the session for the next page; the app shows it.
    $flash = ['message' => (string)($_SESSION['flash_msg'] ?? ''), 'type' => (string)($_SESSION['flash_type'] ?? 'success'),
        // An upload queued the admin email (includes/upload_email_outbox.php); the app asks
        // ajax/upload_email_send.php to send it, as the website's footer does.
        'email_queued' => !empty($_SESSION['upload_email_outbox'])];
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
    session_write_close();
    appOut($flash);
}
session_write_close();

const APP_STATUSES = [
    'pending_vessel' => 'Pending Vessel',
    'pending_report' => 'Pending Report',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
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
    if ($key === 'cancelled' && $role === 'Super Admin') {
        // cancelled.php shows every cancelled vessel to Admin only; others see their own.
        $where[1] = '(s.surveyor_id = ? OR s.id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = ?))';
        $params = array_merge([APP_STATUSES[$key]], [$userId, $userId], array_slice($params, 1 + count($scopeParams)));
    }
    $whereSql = implode(' AND ', $where);
    $from = "FROM surveys s
        JOIN clients c ON s.client_id = c.id
        " . ($key === 'cancelled' ? 'LEFT JOIN' : 'JOIN') . " users u ON s.surveyor_id = u.id
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
    // What the upload screen needs (rules of ajax/upload_handler.php and the detail pages' popups).
    $types = array_column($docs, 'type');
    $uploadKind = null;
    if ($role === 'Admin' || $assigned) {
        if ($s['status'] === 'Pending Vessel') $uploadKind = 'vessel';
        elseif ($s['status'] === 'Pending Report') $uploadKind = 'report';
    }
    $card += [
        'report_no' => (string)($s['report_number'] ?? ''),
        'imo' => (string)($s['imo_number'] ?? ''),
        'assigned_on' => appDate($s['assign_date'] ?? ''),
        'remarks' => (string)($s['remarks'] ?? ''),
        'docs' => $docs,
        'web' => $page . '?id=' . $id,
        'can_upload' => $uploadKind !== null,
        'upload' => $uploadKind === null ? null : [
            'kind' => $uploadKind,
            'has_pdf' => in_array('Formal Report PDF', $types, true),
            'has_excel' => in_array('Formal Report Excel', $types, true),
            'recovery_na' => isRecoveryNotApplicable($db, (string)($s['survey_type_ids'] ?? ''), (string)($s['type_name'] ?? '')),
            'today' => date('Y-m-d'),
            'min_date' => ($uploadKind === 'report' && ($d = appDate($s['survey_completed_date'] ?? '', 'Y-m-d')) !== '') ? $d : '',
        ],
        // Same as the Latest Update form on vessel_detail.php (shown to everyone who can open it).
        'can_update' => $s['status'] === 'Pending Vessel',
        // vessel_detail.php shows previous surveys (Pending Vessel) to Admin / Super Admin / Surveyor.
        'can_history' => $s['status'] === 'Pending Vessel' && ($fullAccess || $assigned),
    ];
    appOut($card);
}

if ($a === 'inbox') {
    if (!in_array($role, ['Admin', 'Surveyor'], true)) appOut(['error' => 'not_allowed'], 403);
    $unreadOnly = !empty($_GET['unread']);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 30;
    $rows = getInboxNotifications($db, $userId, $per, ($page - 1) * $per, $unreadOnly);
    $total = $unreadOnly ? getUnreadNotificationCount($db, $userId) : getNotificationCount($db, $userId);
    $items = [];
    foreach ($rows as $n) {
        $link = safeNotificationLink($n['link'] ?? null);
        $sid = ($link && preg_match('~^(vessel_detail|report_detail|completed_detail)\.php\?id=(\d+)$~', $link, $m)) ? (int)$m[2] : 0;
        $items[] = ['id' => (int)$n['id'], 'type' => (string)$n['type'], 'title' => (string)$n['title'], 'message' => (string)$n['message'],
            'read' => (bool)$n['is_read'], 'ago' => notificationTimeAgo($n['created_at'] ?? null), 'from' => (string)($n['creator_name'] ?? ''),
            'link' => $link, 'survey_id' => $sid];
    }
    appOut(['total' => $total, 'unread' => getUnreadNotificationCount($db, $userId), 'more' => ($page * $per) < $total, 'items' => $items]);
}

if ($a === 'recovery') {
    require_once 'includes/recovery_list.php';
    $f = recoveryListFilters($_GET, $fullAccess);
    $f['sort'] = 'newest';
    [$whereSql, $params, $orderSql] = recoveryListWhere($role, $userId, $clientId, $f, outsourcedCreditSurveyor($db)[0]);
    $st = $db->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(" . recoveryCountedSql('s', 'vlsfo_recovery') . "),0) AS vlsfo,
        COALESCE(SUM(" . recoveryCountedSql('s', 'lsmgo_recovery') . "),0) AS lsmgo, COALESCE(SUM(" . recoveryCountedSql('s', 'hsfo_recovery') . "),0) AS hsfo,
        COALESCE(SUM(" . recoveryCountedSql('s') . "),0) AS total " . RECOVERY_LIST_FROM . " WHERE $whereSql");
    $st->execute($params);
    $tot = $st->fetch(PDO::FETCH_ASSOC);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 30;
    $offset = ($page - 1) * $per;
    $st = $db->prepare("SELECT " . RECOVERY_LIST_COLUMNS . " " . RECOVERY_LIST_FROM . " WHERE $whereSql ORDER BY $orderSql LIMIT $per OFFSET $offset");
    $st->execute($params);
    $rows = array_map(function ($r) use ($db) {
        $x = recoveryListRow($db, $r);
        $x['total_text'] = $x['na'] ? 'N/A' : ($x['total'] !== null ? recoveryDisplay($x['total'], $x['onhire']) : '—');
        foreach (['vlsfo', 'lsmgo', 'hsfo'] as $k) $x[$k . '_text'] = $x['na'] ? 'N/A' : ($x[$k] !== null ? recoveryDisplay($x[$k], $x['onhire']) : '—');
        return $x;
    }, $st->fetchAll(PDO::FETCH_ASSOC));
    [$scopeWhere, $scopeP] = recoveryListScope($role, $userId, $clientId);
    $cd = surveyCompletedDateSql('s');
    $st = $db->prepare("SELECT DATE_FORMAT($cd, '%Y-%m') AS m " . RECOVERY_LIST_FROM . " WHERE " . implode(' AND ', $scopeWhere) . " AND $cd IS NOT NULL GROUP BY m ORDER BY m DESC");
    $st->execute($scopeP);
    $months = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $m) {
        if (preg_match('/^\d{4}-\d{2}$/', (string)$m) && strpos((string)$m, '0000') !== 0) $months[] = ['value' => $m, 'label' => date('M Y', strtotime($m . '-01'))];
    }
    appOut([
        'count' => (int)$tot['n'], 'vlsfo' => round((float)$tot['vlsfo'], 3), 'lsmgo' => round((float)$tot['lsmgo'], 3),
        'hsfo' => round((float)$tot['hsfo'], 3), 'total' => round((float)$tot['total'], 3),
        'months' => $months, 'more' => $offset + count($rows) < (int)$tot['n'], 'items' => $rows,
    ]);
}

if ($a === 'formats') {
    // Same files and order as formats_download.php (formats/ folder, newest of each name, recent first).
    $files = [];
    $dir = __DIR__ . '/formats';
    foreach (is_dir($dir) ? scandir($dir) : [] as $file) {
        if (!in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['xls', 'xlsx', 'xlsm'], true)) continue;
        $mtime = (int)@filemtime($dir . '/' . $file);
        $name = trim(preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', preg_replace('/^\d+_/', '', pathinfo($file, PATHINFO_FILENAME)))));
        $key = strtolower($name);
        if (!isset($files[$key]) || $mtime >= $files[$key]['mtime']) {
            $files[$key] = ['name' => $name, 'path' => 'formats/' . $file, 'mtime' => $mtime];
        }
    }
    $week = time() - 7 * 86400;
    $out = [];
    foreach ($files as $f) {
        $out[] = ['name' => $f['name'], 'path' => $f['path'], 'updated' => $f['mtime'] ? date('d M Y, h:i A', $f['mtime']) : '', 'recent' => $f['mtime'] >= $week];
    }
    usort($out, function ($x, $y) { return ($y['recent'] <=> $x['recent']) ?: strcasecmp($x['name'], $y['name']); });
    appOut(['items' => $out]);
}

if ($a === 'profile') {
    $st = $db->prepare('SELECT username, full_name, first_name, last_name, dob, email, phone FROM users WHERE id = ?');
    $st->execute([$userId]);
    $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $full = (string)($u['full_name'] ?? '');
    $parts = preg_split('/\s+/', trim($full), 2) ?: [''];
    appOut([
        'username' => (string)($u['username'] ?? ''),
        'full_name' => $full,
        'first_name' => (string)($u['first_name'] ?? '') !== '' ? (string)$u['first_name'] : ($parts[0] ?? ''),
        'last_name' => (string)($u['last_name'] ?? '') !== '' ? (string)$u['last_name'] : ($parts[1] ?? ''),
        'dob' => appDate($u['dob'] ?? '', 'Y-m-d'),
        'email' => (string)($u['email'] ?? ''),
        'phone' => (string)($u['phone'] ?? ''),
        'avatar' => appAvatar($db, $userId),
        // profile.php: Surveyors edit first / last name and date of birth; everyone else full name, email, phone.
        'surveyor_form' => $role === 'Surveyor',
    ]);
}

if ($a === 'history') {
    require_once 'includes/vessel_history.php';
    $id = (int)($_GET['id'] ?? 0);
    $st = $db->prepare('SELECT * FROM surveys WHERE id = ?');
    $st->execute([$id]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    // Same as vessel_detail.php: Pending Vessel, Admin / Super Admin or the assigned Surveyor.
    if (!$s || $s['status'] !== 'Pending Vessel' || !($fullAccess || ($role === 'Surveyor' && isSurveyorAssignedToSurvey($db, $id, $userId)))) {
        appOut(['error' => 'not_found'], 404);
    }
    $out = [];
    foreach (vesselHistory($db, $s) as $h) {
        $rec = function ($v) use ($h) { return $h['recovery_na'] ? 'N/A' : ($v !== null ? recoveryDisplay(abs((float)$v), (bool)$h['recovery_onhire']) : '—'); };
        $out[] = [
            'id' => (int)$h['id'], 'vessel' => (string)$h['vessel_name'], 'status' => (string)$h['status'],
            'report_no' => (string)($h['report_number'] ?? ''), 'type' => (string)$h['types'], 'client' => (string)($h['company_name'] ?? ''),
            'port' => (string)($h['port_name'] ?? ''), 'surveyors' => (string)$h['surveyors'],
            'completed_on' => appDate($h['survey_completed_date'] ?? ''), 'report_on' => appDate($h['report_uploaded_date'] ?? ''),
            'recovery' => ['total' => $rec($h['recovery_amount']), 'vlsfo' => $rec($h['vlsfo_recovery']), 'lsmgo' => $rec($h['lsmgo_recovery']), 'hsfo' => $rec($h['hsfo_recovery'])],
            'docs' => array_map(function ($f) { return ['id' => (int)$f['id'], 'name' => (string)$f['file_name'], 'type' => (string)$f['file_type'], 'when' => appDate($f['uploaded_at'] ?? '')]; }, $h['files']),
        ];
    }
    appOut(['vessel' => (string)$s['vessel_name'], 'items' => $out]);
}

if ($a === 'assign_form') {
    // Same choices assign_vessel.php offers; saving still posts to assign_vessel.php.
    if (!in_array($role, ['Admin', 'Client'], true)) appOut(['error' => 'not_allowed'], 403);
    $editId = (int)($_GET['edit_id'] ?? 0);
    if ($editId > 0 && $role !== 'Admin') appOut(['error' => 'not_allowed'], 403);
    $out = ['role' => $role, 'edit' => null];
    if ($role === 'Client') {
        $st = $db->prepare('SELECT id, company_name FROM clients WHERE user_id = ? LIMIT 1');
        $st->execute([$userId]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        $out['clients'] = $c ? [['id' => (int)$c['id'], 'name' => (string)$c['company_name']]] : [];
        $out['surveyors'] = [];
    } else {
        $out['clients'] = array_map(function ($c) { return ['id' => (int)$c['id'], 'name' => (string)$c['company_name']]; },
            $db->query('SELECT id, company_name FROM clients ORDER BY company_name ASC')->fetchAll(PDO::FETCH_ASSOC));
        $out['surveyors'] = array_map(function ($u) { return ['id' => (int)$u['id'], 'name' => (string)$u['full_name']]; },
            $db->query("SELECT id, full_name FROM users WHERE role_id = 2 AND status = 'Active' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC));
    }
    $hasCountry = (bool)$db->query("SHOW COLUMNS FROM ports LIKE 'country'")->fetch();
    $out['ports'] = array_map(function ($p) { return ['id' => (int)$p['id'], 'name' => (string)$p['port_name'], 'country' => (string)($p['country'] ?? '') ?: 'India']; },
        $db->query('SELECT id, port_name' . ($hasCountry ? ', country' : '') . ' FROM ports ORDER BY ' . ($hasCountry ? 'country ASC, ' : '') . 'port_name ASC')->fetchAll(PDO::FETCH_ASSOC));
    $out['types'] = array_map(function ($t) { return ['id' => (int)$t['id'], 'name' => (string)$t['type_name']]; },
        $db->query('SELECT id, type_name FROM survey_types ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC));
    if ($editId > 0) {
        $st = $db->prepare('SELECT * FROM surveys WHERE id = ?');
        $st->execute([$editId]);
        $e = $st->fetch(PDO::FETCH_ASSOC);
        if (!$e) appOut(['error' => 'not_found'], 404);
        $ids = [];
        try {
            $ss = $db->prepare('SELECT surveyor_id FROM survey_surveyors WHERE survey_id = ? ORDER BY id ASC');
            $ss->execute([$editId]);
            $ids = array_map('intval', $ss->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $x) { $ids = []; }
        if (!$ids && (int)$e['surveyor_id'] > 1) $ids = [(int)$e['surveyor_id']];
        $out['edit'] = [
            'id' => $editId, 'vessel' => (string)$e['vessel_name'], 'report_no' => (string)($e['report_number'] ?? ''),
            'client_id' => (int)$e['client_id'], 'agent' => (string)$e['agent_name'], 'port_id' => (int)$e['port_id'],
            'type_ids' => array_values(array_filter(array_map('intval', explode(',', (string)($e['survey_type_ids'] ?: $e['survey_type_id']))))),
            'surveyor_ids' => $ids, 'appointment' => appDate($e['appointment_date'] ?? '', 'Y-m-d'), 'remarks' => (string)($e['remarks'] ?? ''),
        ];
    }
    appOut($out);
}

appOut(['error' => 'unknown'], 400);

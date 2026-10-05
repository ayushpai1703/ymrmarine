<?php
/**
 * Login Activity — paginated/filtered login history (server-side).
 *
 * GET params: page (1-based), per_page (default 25, max 200), search
 * (name/email/username), role (exact role name or 'All'), status
 * ('All'/'Successful'/'Failed'), device ('All'/'Desktop'/'Mobile'/'Tablet'),
 * country (exact country name or 'All'), date_range
 * ('today'/'yesterday'/'last7'/'last30'/'custom'), date_from/date_to
 * (Y-m-d, only used when date_range=custom).
 *
 * Response: {"success":true,"rows":[...],"total":N,"page":P,"per_page":PP}
 * or {"success":false,"message":"..."} — same envelope convention as
 * ajax/admin_master.php, extended with paging metadata.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/login_activity_helpers.php';
checkAuth();
header('Content-Type: application/json');

// Independent backend authorization check — never rely on the menu/page
// being hidden. Every login-activity endpoint repeats this same check.
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['Admin', 'Super Admin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied']);
    exit;
}

$db = getDB();
ensureLoginActivityTable($db);

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 25);
$perPage = $perPage > 0 ? min(200, $perPage) : 25;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];

$search = trim((string)($_GET['search'] ?? ''));
if ($search !== '') {
    $where[] = '(user_name LIKE ? OR user_email LIKE ? OR username LIKE ? OR user_id = ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = ctype_digit($search) ? (int)$search : -1;
}

$filterRole = trim((string)($_GET['role'] ?? 'All'));
if ($filterRole !== '' && $filterRole !== 'All') {
    $where[] = 'role = ?';
    $params[] = $filterRole;
}

$status = trim((string)($_GET['status'] ?? 'All'));
if ($status === 'Successful') {
    $where[] = "login_status = 'SUCCESS'";
} elseif ($status === 'Failed') {
    $where[] = "login_status = 'FAILED'";
}

$device = trim((string)($_GET['device'] ?? 'All'));
if ($device !== '' && $device !== 'All') {
    $where[] = 'device_type = ?';
    $params[] = $device;
}

$country = trim((string)($_GET['country'] ?? 'All'));
if ($country !== '' && $country !== 'All') {
    $where[] = 'country = ?';
    $params[] = $country;
}

$dateRange = trim((string)($_GET['date_range'] ?? ''));
if ($dateRange === 'today') {
    $where[] = 'DATE(login_time) = CURDATE()';
} elseif ($dateRange === 'yesterday') {
    $where[] = 'DATE(login_time) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)';
} elseif ($dateRange === 'last7') {
    $where[] = 'login_time >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)';
} elseif ($dateRange === 'last30') {
    $where[] = 'login_time >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)';
} elseif ($dateRange === 'custom') {
    $from = trim((string)($_GET['date_from'] ?? ''));
    $to = trim((string)($_GET['date_to'] ?? ''));
    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $where[] = 'login_time >= ?';
        $params[] = $from . ' 00:00:00';
    }
    if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $where[] = 'login_time <= ?';
        $params[] = $to . ' 23:59:59';
    }
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $countStmt = $db->prepare("SELECT COUNT(*) FROM login_activity $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $sql = "SELECT id, user_id, user_name, username, user_email, role, ip_address, country,
                   country_code, device_type, operating_system, browser, login_time,
                   logout_time, session_duration, login_status, failure_reason
            FROM login_activity
            $whereSql
            ORDER BY login_time DESC, id DESC
            LIMIT $perPage OFFSET $offset";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['session_duration_label'] = formatSessionDuration($r['session_duration'] !== null ? (int)$r['session_duration'] : null);
        $r['country_flag'] = $r['country_code'] ? ysmsCountryFlagEmoji($r['country_code']) : '';
    }
    unset($r);

    echo json_encode(['success' => true, 'rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
} catch (Throwable $e) {
    error_log('login_activity_list: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load login activity.']);
}

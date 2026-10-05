<?php
/**
 * Login Activity — dashboard summary cards + "Latest Login by User" list.
 *
 * Response:
 * {
 *   "success": true,
 *   "summary": {
 *     "total_users": N, "logged_in_today": N,
 *     "active_sessions": N, "failed_today": N
 *   },
 *   "latest_logins": [ {user_id, user_name, username, user_email, role,
 *     login_time, country, country_code, country_flag, device_type,
 *     operating_system, browser, ip_address, is_online}, ... ]
 * }
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/login_activity_helpers.php';
checkAuth();
header('Content-Type: application/json');

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['Admin', 'Super Admin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied']);
    exit;
}

$db = getDB();
ensureLoginActivityTable($db);

try {
    $totalUsers = (int)$db->query("SELECT COUNT(*) FROM users WHERE status = 'Active'")->fetchColumn();

    $loggedInToday = (int)$db->query("SELECT COUNT(DISTINCT user_id) FROM login_activity
        WHERE login_status = 'SUCCESS' AND user_id IS NOT NULL AND DATE(login_time) = CURDATE()")->fetchColumn();

    // "Active Sessions" — no live session/heartbeat tracking exists in this
    // app (plain PHP file sessions, no sessions table), so this is an
    // honest, data-backed approximation rather than a fabricated number:
    // successful logins in the last 2 hours with no recorded logout yet.
    // Labelled clearly in the UI as an approximation, not a guaranteed
    // live count.
    $activeSessions = (int)$db->query("SELECT COUNT(*) FROM login_activity
        WHERE login_status = 'SUCCESS' AND logout_time IS NULL
        AND login_time >= DATE_SUB(NOW(), INTERVAL 2 HOUR)")->fetchColumn();

    $failedToday = (int)$db->query("SELECT COUNT(*) FROM login_activity
        WHERE login_status = 'FAILED' AND DATE(login_time) = CURDATE()")->fetchColumn();

    // Latest SUCCESS login_activity row per user_id — MAX(id) (not
    // MAX(login_time)) guarantees exactly one row per user even if two
    // logins share the same second, since id is a unique auto-increment PK.
    $latestStmt = $db->query("
        SELECT u.id AS user_id, u.full_name AS user_name, u.username, u.email AS user_email,
               r.name AS role, la.login_time, la.country, la.country_code,
               la.device_type, la.operating_system, la.browser, la.ip_address, la.logout_time
        FROM users u
        JOIN roles r ON u.role_id = r.id
        LEFT JOIN (
            SELECT la1.* FROM login_activity la1
            INNER JOIN (
                SELECT user_id, MAX(id) AS max_id FROM login_activity
                WHERE login_status = 'SUCCESS' AND user_id IS NOT NULL
                GROUP BY user_id
            ) m ON la1.id = m.max_id
        ) la ON la.user_id = u.id
        WHERE u.status = 'Active'
        ORDER BY (la.login_time IS NULL) ASC, la.login_time DESC, u.full_name ASC
    ");
    $latest = $latestStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($latest as &$row) {
        $row['country_flag'] = $row['country_code'] ? ysmsCountryFlagEmoji($row['country_code']) : '';
        // "Online" = a login recorded with no logout yet, within the same
        // best-effort 2-hour window used for the Active Sessions card.
        $row['is_online'] = $row['login_time'] && !$row['logout_time']
            && strtotime($row['login_time']) >= (time() - 7200);
    }
    unset($row);

    echo json_encode([
        'success' => true,
        'summary' => [
            'total_users' => $totalUsers,
            'logged_in_today' => $loggedInToday,
            'active_sessions' => $activeSessions,
            'failed_today' => $failedToday,
        ],
        'latest_logins' => $latest,
    ]);
} catch (Throwable $e) {
    error_log('login_activity_summary: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load summary.']);
}

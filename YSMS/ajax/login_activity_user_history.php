<?php
/**
 * Login Activity — single-user detail + full login history (for the
 * "User Detail View" modal).
 *
 * GET params: user_id (required, int).
 *
 * Response:
 * {
 *   "success": true,
 *   "user": {user_id, user_name, username, user_email, role,
 *     total_success, total_failed, first_login, latest_login,
 *     latest_ip, latest_country, latest_country_flag, latest_device,
 *     latest_browser, latest_os},
 *   "history": [ {id, login_time, logout_time, session_duration_label,
 *     ip_address, country, country_flag, device_type, operating_system,
 *     browser, login_status, failure_reason}, ... ]
 * }
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

$userId = (int)($_GET['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid user_id.']);
    exit;
}

$db = getDB();
ensureLoginActivityTable($db);

try {
    $userStmt = $db->prepare("SELECT u.id AS user_id, u.full_name AS user_name, u.username,
            u.email AS user_email, r.name AS role
        FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
    $userStmt->execute([$userId]);
    $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (!$userRow) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit;
    }

    $countStmt = $db->prepare("SELECT
            SUM(CASE WHEN login_status = 'SUCCESS' THEN 1 ELSE 0 END) AS total_success,
            SUM(CASE WHEN login_status = 'FAILED' THEN 1 ELSE 0 END) AS total_failed,
            MIN(CASE WHEN login_status = 'SUCCESS' THEN login_time ELSE NULL END) AS first_login
        FROM login_activity WHERE user_id = ?");
    $countStmt->execute([$userId]);
    $counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $latestStmt = $db->prepare("SELECT login_time, ip_address, country, country_code,
            device_type, browser, operating_system
        FROM login_activity WHERE user_id = ? AND login_status = 'SUCCESS'
        ORDER BY id DESC LIMIT 1");
    $latestStmt->execute([$userId]);
    $latest = $latestStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $user = [
        'user_id' => $userRow['user_id'],
        'user_name' => $userRow['user_name'],
        'username' => $userRow['username'],
        'user_email' => $userRow['user_email'],
        'role' => $userRow['role'],
        'total_success' => (int)($counts['total_success'] ?? 0),
        'total_failed' => (int)($counts['total_failed'] ?? 0),
        'first_login' => $counts['first_login'] ?? null,
        'latest_login' => $latest['login_time'] ?? null,
        'latest_ip' => $latest['ip_address'] ?? null,
        'latest_country' => $latest['country'] ?? null,
        'latest_country_flag' => !empty($latest['country_code']) ? ysmsCountryFlagEmoji($latest['country_code']) : '',
        'latest_device' => $latest['device_type'] ?? null,
        'latest_browser' => $latest['browser'] ?? null,
        'latest_os' => $latest['operating_system'] ?? null,
    ];

    $historyStmt = $db->prepare("SELECT id, login_time, logout_time, session_duration,
            ip_address, country, country_code, device_type, operating_system, browser,
            login_status, failure_reason
        FROM login_activity WHERE user_id = ? ORDER BY login_time DESC, id DESC LIMIT 500");
    $historyStmt->execute([$userId]);
    $history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($history as &$h) {
        $h['session_duration_label'] = formatSessionDuration($h['session_duration'] !== null ? (int)$h['session_duration'] : null);
        $h['country_flag'] = $h['country_code'] ? ysmsCountryFlagEmoji($h['country_code']) : '';
    }
    unset($h);

    echo json_encode(['success' => true, 'user' => $user, 'history' => $history]);
} catch (Throwable $e) {
    error_log('login_activity_user_history: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not load user history.']);
}

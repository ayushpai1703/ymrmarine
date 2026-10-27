<?php
/**
 * Android app: remember (save) or forget (delete) this phone's push token for the signed-in user.
 * POST token=<FCM token>, action=save|delete, csrf_token. See includes/push.php.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/push.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false]);
    exit;
}
csrf_require();

$token = trim((string)($_POST['token'] ?? ''));
if ($token === '' || strlen($token) > 512 || !preg_match('/^[A-Za-z0-9_:\-\.]+$/', $token)) {
    echo json_encode(['success' => false, 'message' => 'Invalid token.']);
    exit;
}
$db = getDB();
ensurePushTokensTable($db);
try {
    if (($_POST['action'] ?? 'save') === 'delete') {
        $db->prepare('DELETE FROM app_push_tokens WHERE token = ?')->execute([$token]);
    } else {
        // One row per phone: a token signed in again by someone else moves to them.
        $db->prepare('INSERT INTO app_push_tokens (user_id, token, platform) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), updated_at = NOW()')->execute([(int)$_SESSION['user_id'], $token, 'android']);
    }
    echo json_encode(['success' => true, 'push' => pushEnabled()]);
} catch (Throwable $e) {
    error_log('app_push_token: ' . $e->getMessage());
    echo json_encode(['success' => false]);
}

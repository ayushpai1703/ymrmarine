<?php
/**
 * Android app fingerprint login (see includes/app_login_tokens.php).
 *   POST action=enroll  device=<id>, csrf_token   signed in: new device key for this phone → {key}
 *   POST action=disable device=<id>, csrf_token   signed in: remove this phone's device key
 *   POST action=login   key=<selector:validator>  signed out: the key the phone's fingerprint unlocked
 * The key is shown once (enroll) and only kept on the phone, encrypted by its Keystore.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/app_login_tokens.php';
require_once __DIR__ . '/../includes/login_activity_helpers.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function bioOut(array $a, int $code = 200): void {
    http_response_code($code);
    echo json_encode($a);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') bioOut(['success' => false], 405);
// Only the app's own page may call this (no other site can post a login here).
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) bioOut(['success' => false], 403);

$db = getDB();
ensureAppLoginTokensTable($db);
$action = (string)($_POST['action'] ?? '');
$device = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_POST['device'] ?? ''));
$device = substr($device, 0, 64);

if ($action === 'login') {
    if (!appIsAppRequest()) bioOut(['success' => false, 'message' => 'Fingerprint login works in the app only.'], 403);
    $hit = appTokenCheck($db, trim((string)($_POST['key'] ?? '')), 'biometric');
    if (!$hit) {
        recordLoginAttempt($db, null, '(fingerprint)', false, 'Fingerprint login key not accepted');
        bioOut(['success' => false, 'message' => 'Fingerprint login is no longer set up on this phone. Sign in with your password.'], 401);
    }
    appStartSession($hit['user']);
    recordLoginAttempt($db, $hit['user'], (string)$hit['user']['username'], true);
    appRememberIssue($db, (int)$hit['user']['id']);
    bioOut(['success' => true]);
}

if (empty($_SESSION['user_id'])) bioOut(['success' => false], 401);
csrf_require();
$userId = (int)$_SESSION['user_id'];
if ($device === '') bioOut(['success' => false, 'message' => 'Missing device.'], 400);

if ($action === 'enroll') {
    // One fingerprint key per user per phone.
    $db->prepare("DELETE FROM app_login_tokens WHERE user_id = ? AND kind = 'biometric' AND device = ?")->execute([$userId, $device]);
    bioOut(['success' => true, 'key' => appTokenCreate($db, $userId, 'biometric', APP_BIOMETRIC_DAYS, $device)]);
}
if ($action === 'disable') {
    $db->prepare("DELETE FROM app_login_tokens WHERE user_id = ? AND kind = 'biometric' AND device = ?")->execute([$userId, $device]);
    bioOut(['success' => true]);
}
bioOut(['success' => false, 'message' => 'Unknown action.'], 400);

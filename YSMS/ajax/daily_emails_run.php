<?php
/**
 * Sends the daily automatic emails that are due (includes/daily_emails.php).
 *
 *  - Cloud Scheduler (or any cron): GET/POST with header "X-Cron-Token: <YSMS_CRON_TOKEN>"
 *    every 5–15 minutes. No login needed; nothing runs when the env var is unset.
 *  - Logged-in pages: includes/footer.php POSTs here (with the CSRF token) at most every
 *    10 minutes per browser, so the emails still go out while anyone is using the site.
 * Each job sends at most once per day either way (daily_email_runs.auto_key).
 */
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/config/config.php';

$cronToken = (string)(getenv('YSMS_CRON_TOKEN') ?: '');
$given = (string)($_SERVER['HTTP_X_CRON_TOKEN'] ?? '');
$viaCron = $cronToken !== '' && $given !== '' && hash_equals($cronToken, $given);
if (!$viaCron) {
    if ($given !== '') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid cron token.']);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'POST only.']);
        exit;
    }
    checkAuth(); // logged-in user + CSRF
}
session_write_close(); // don't hold the user's session lock while emails go out

require_once dirname(__DIR__) . '/includes/daily_emails.php';
try {
    $ran = dailyEmailRunDue(getDB());
    echo json_encode(['success' => true, 'ran' => (object)$ran]);
} catch (Throwable $e) {
    error_log('daily_emails_run: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Daily email check failed.']);
}

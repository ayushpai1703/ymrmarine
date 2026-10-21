<?php
/**
 * Runs the daily emails that are due (includes/daily_emails.php).
 *
 *  Cloud Scheduler (the schedule): POST ajax/daily_emails_run.php?job=surveyor_pending  and
 *  ?job=appointments, header "X-Cron-Token: <YSMS_CRON_TOKEN>", cron "0 17 * * *",
 *  time zone Asia/Kolkata — one scheduler job per email so they stay independent.
 *  Answers 200 when the job was sent / skipped / not due, 500 when it failed, so Cloud
 *  Scheduler's own retries kick in; a retry only re-sends to recipients that failed.
 *  No token configured on the server → scheduler calls are refused.
 *
 *  Logged-in pages: includes/footer.php POSTs here (session + CSRF) at most every 10 minutes
 *  per browser — only a safety net; it can never send a job twice.
 */
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/config/config.php';

$cronToken = (string)(getenv('YSMS_CRON_TOKEN') ?: '');
$given = (string)($_SERVER['HTTP_X_CRON_TOKEN'] ?? '');
$viaCron = $cronToken !== '' && $given !== '' && hash_equals($cronToken, $given);
if (!$viaCron) {
    if ($given !== '') {
        error_log('daily_emails_run: rejected scheduler call (bad or unconfigured token)');
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
$job = (string)($_GET['job'] ?? '');
if ($job !== '' && !isset(DAILY_EMAIL_JOBS[$job])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unknown job.']);
    exit;
}
try {
    $db = getDB();
    dailyEmailEnsureTables($db);
    if ($viaCron) dailyEmailSetMeta($db, 'last_scheduler_call' . ($job !== '' ? '_' . $job : ''), date('Y-m-d H:i:s'));
    // Upload admin emails nobody sent (page closed) or that failed — includes/upload_email_outbox.php
    try {
        require_once dirname(__DIR__) . '/includes/upload_email_outbox.php';
        uploadEmailSendOverdue($db);
    } catch (Throwable $e) { error_log('upload email safety net: ' . $e->getMessage()); }
    $ran = dailyEmailRunDue($db, $viaCron ? 'scheduler' : 'page', $job);
    $failed = array_filter($ran, fn($r) => $r['status'] === 'failed');
    if ($failed) http_response_code(500);
    echo json_encode(['success' => !$failed, 'ran' => (object)$ran]);
} catch (Throwable $e) {
    error_log('daily_emails_run: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Daily email check failed.']);
}

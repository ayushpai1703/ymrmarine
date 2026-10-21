<?php
/**
 * Sends the admin email queued by an upload (includes/upload_email_outbox.php).
 * Called by includes/footer.php on the page the upload redirected to; answers once the
 * mail transport has accepted (or refused) each message, so the toast never claims a
 * send that didn't happen.
 */
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/config/config.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST only.']);
    exit;
}
checkAuth(); // logged-in user + CSRF

// Only this user's own queued uploads; taken out of the session first so a reload can't ask twice
$ids = array_values(array_unique(array_map('intval', (array)($_SESSION['upload_email_outbox'] ?? []))));
unset($_SESSION['upload_email_outbox']);
session_write_close(); // don't hold the session lock while the email goes out
ignore_user_abort(true); // finish sending even if the user navigates away
@set_time_limit(240);

require_once dirname(__DIR__) . '/includes/upload_email_outbox.php';
$results = [];
try {
    $db = getDB();
    foreach ($ids as $id) {
        if ($id > 0) $results[] = ['id' => $id] + uploadEmailSend($db, $id);
    }
} catch (Throwable $e) {
    error_log('upload_email_send: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not send the admin email now; it will be retried automatically.']);
    exit;
}
echo json_encode(['success' => true, 'results' => $results]);

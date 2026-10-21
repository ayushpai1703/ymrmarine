<?php
/**
 * Report-upload admin email, sent after the upload page has already moved on.
 *
 * Sending that email (every uploaded file attached, through Gmail) took most of the
 * 20–30 s a Pending Vessel / Pending Report upload used to take, while the surveyor
 * looked at a spinner. Now ajax/upload_handler.php saves the files and the status,
 * queues the email here and redirects at once; the next page (includes/footer.php)
 * asks ajax/upload_email_send.php to send it in its own request and shows a toast
 * once Gmail / SMTP has accepted it. Same message, recipients and attachments as before
 * (notifyAdminsOfReportUpload()). Uploaded files live on the shared Cloud Storage mount,
 * so any instance can attach them.
 *
 * Safety net: ajax/daily_emails_run.php (called by every logged-in page at most every
 * 10 minutes, and by Cloud Scheduler) sends rows nobody picked up — the page was closed
 * — and retries failed ones. A row is claimed atomically, so it is never sent twice.
 */

const UPLOAD_EMAIL_MAX_ATTEMPTS = 5;

function uploadEmailEnsureTable(PDO $db): void {
    static $done = false;
    if ($done) return;
    $db->exec("CREATE TABLE IF NOT EXISTS `upload_email_outbox` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `survey_id` INT NOT NULL,
        `stage` VARCHAR(20) NOT NULL,
        `ctx` MEDIUMTEXT NOT NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
        `attempts` INT NOT NULL DEFAULT 0,
        `result` TEXT NULL,
        `created_by` INT NULL,
        `created_at` DATETIME NOT NULL,
        `claimed_at` DATETIME NULL,
        `sent_at` DATETIME NULL,
        KEY `idx_status` (`status`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** Queue the admin email for an upload; returns the outbox id. $ctx is what notifyAdminsOfReportUpload() takes. */
function uploadEmailQueue(PDO $db, int $surveyId, array $ctx, int $userId): int {
    uploadEmailEnsureTable($db);
    $st = $db->prepare("INSERT INTO upload_email_outbox (survey_id, stage, ctx, created_by, created_at) VALUES (?, ?, ?, ?, NOW())");
    $st->execute([$surveyId, (string)($ctx['new_status'] ?? ''), json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $userId ?: null]);
    return (int)$db->lastInsertId();
}

/**
 * ajax/upload_handler.php: queue the admin email (sent by the next page) and return the
 * flash text; sends it right here as before if it can't be queued.
 */
function uploadEmailQueueOrSend(PDO $db, int $surveyId, array $ctx): string {
    try {
        $_SESSION['upload_email_outbox'][] = uploadEmailQueue($db, $surveyId, $ctx, (int)($_SESSION['user_id'] ?? 0));
        return 'Sending admin email…';
    } catch (Throwable $e) {
        error_log('upload email queue: ' . $e->getMessage());
        require_once __DIR__ . '/mailer.php';
        return notifyAdminsOfReportUpload($db, $ctx);
    }
}

/**
 * Send one queued email if it is still due. Returns ['status' => sent|failed|busy|done, 'message' => …].
 * The claim is one UPDATE, so two requests can never both send it.
 */
function uploadEmailSend(PDO $db, int $id): array {
    uploadEmailEnsureTable($db);
    $claim = $db->prepare("UPDATE upload_email_outbox SET status = 'sending', attempts = attempts + 1, claimed_at = NOW()
        WHERE id = ? AND attempts < ? AND (status IN ('pending', 'failed')
            OR (status = 'sending' AND claimed_at < NOW() - INTERVAL 15 MINUTE))");
    $claim->execute([$id, UPLOAD_EMAIL_MAX_ATTEMPTS]);
    if ($claim->rowCount() !== 1) {
        $row = $db->prepare('SELECT status, result FROM upload_email_outbox WHERE id = ?');
        $row->execute([$id]);
        $r = $row->fetch(PDO::FETCH_ASSOC);
        if (!$r) return ['status' => 'done', 'message' => ''];
        return $r['status'] === 'sending'
            ? ['status' => 'busy', 'message' => 'Admin email is being sent.']
            : ['status' => $r['status'] === 'sent' ? 'done' : 'failed', 'message' => (string)$r['result']];
    }
    $row = $db->prepare('SELECT ctx FROM upload_email_outbox WHERE id = ?');
    $row->execute([$id]);
    $ctx = json_decode((string)$row->fetchColumn(), true) ?: [];
    require_once __DIR__ . '/mailer.php';
    try {
        $note = notifyAdminsOfReportUpload($db, $ctx);
    } catch (Throwable $e) {
        error_log('upload email ' . $id . ': ' . $e->getMessage());
        $note = 'Admin email failed: ' . $e->getMessage();
    }
    // notifyAdminsOfReportUpload() answers "Admin notified by email (…)" only after the transport accepted it
    $ok = strpos($note, 'Admin notified by email') === 0;
    $db->prepare("UPDATE upload_email_outbox SET status = ?, result = ?, sent_at = IF(? = 'sent', NOW(), sent_at) WHERE id = ?")
       ->execute([$ok ? 'sent' : 'failed', $note, $ok ? 'sent' : 'failed', $id]);
    return ['status' => $ok ? 'sent' : 'failed', 'message' => $note];
}

/** Safety net: rows the page never sent (after 2 min) and failed rows (every 5 min, up to the attempt limit). */
function uploadEmailSendOverdue(PDO $db, int $limit = 5): array {
    uploadEmailEnsureTable($db);
    $st = $db->prepare("SELECT id FROM upload_email_outbox WHERE attempts < ? AND (
            (status = 'pending' AND created_at < NOW() - INTERVAL 2 MINUTE)
         OR (status = 'failed' AND claimed_at < NOW() - INTERVAL 5 MINUTE)
         OR (status = 'sending' AND claimed_at < NOW() - INTERVAL 15 MINUTE))
        ORDER BY id LIMIT " . (int)$limit);
    $st->execute([UPLOAD_EMAIL_MAX_ATTEMPTS]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $out[(int)$id] = uploadEmailSend($db, (int)$id)['status'];
    }
    return $out;
}

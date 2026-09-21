<?php
/**
 * Assign Vessel page: "Recent Vessel Emails" panel data source.
 * POST (CSRF-protected, Admin-only): connects to the configured mailbox via
 * IMAP, fetches recent/new messages (bounded by the account's fetch
 * limit/period so we never download the whole mailbox), stores any not
 * already known (dedup on message_id/UID — see emails.uniq_account_message),
 * runs regex + PDF text extraction on each new one, then returns the
 * merged recent list (DB-backed, newest first) as JSON.
 *
 * Never deletes, moves, or flags anything in the real mailbox — imap_client.php
 * uses FT_PEEK throughout and never calls imap_delete()/imap_expunge().
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/email_account.php';
require_once __DIR__ . '/../includes/imap_client.php';
require_once __DIR__ . '/../includes/email_extractor.php';
require_once __DIR__ . '/../includes/pdf_extractor.php';
checkAuth();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}
if (($_SESSION['role'] ?? '') !== 'Admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not authorized.']);
    exit;
}

$db = getDB();
$account = getEmailAccountDecrypted($db);

if (!$account) {
    echo json_encode(['ok' => false, 'error' => 'No mailbox configured yet.', 'emails' => []]);
    exit;
}

$sourceEmail = $account['mailbox_email'];
$fetchError = '';

if (trim((string)$account['imap_host']) === '') {
    $fetchError = 'Mailbox not configured yet — set it up in Email Integration settings.';
} else {
    $fetchResult = imapFetchRecentMessages($account, (int)$account['fetch_limit'], (int)$account['fetch_period_hours']);
    if (!$fetchResult['ok']) {
        $fetchError = 'Unable to read ' . $sourceEmail . ': ' . $fetchResult['error'];
        error_log('vessel_emails_list fetch failed: ' . $fetchResult['error']);
    } else {
        foreach ($fetchResult['messages'] as $msg) {
            processIncomingVesselEmail($db, (int)$account['id'], $msg);
        }
    }
}

// Always return whatever we have locally, even if the live fetch just failed —
// the panel should still show previously-fetched emails.
try {
    $stmt = $db->prepare("
        SELECT e.id, e.subject, e.sender_name, e.sender_email, e.received_at, e.has_attachment,
               e.processing_status, e.matched_survey_id,
               d.vessel_name, d.imo_number, d.port, d.agent, d.eta, d.survey_type
        FROM emails e
        LEFT JOIN email_extracted_data d ON d.email_id = e.id
        WHERE e.account_id = ? AND e.processing_status != 'IGNORED'
        ORDER BY e.received_at DESC, e.id DESC
        LIMIT ?
    ");
    $limit = max((int)$account['fetch_limit'], 20);
    $stmt->bindValue(1, (int)$account['id'], PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('vessel_emails_list list query: ' . $e->getMessage());
    $rows = [];
}

$emails = array_map(static function ($r) {
    return [
        'id'             => (int)$r['id'],
        'vessel_name'    => $r['vessel_name'],
        'subject'        => $r['subject'],
        'sender_name'    => $r['sender_name'],
        'sender_email'   => $r['sender_email'],
        'received_at'    => $r['received_at'] ? date('d M Y h:i A', strtotime($r['received_at'])) : '',
        'has_attachment' => (bool)$r['has_attachment'],
        'status'         => $r['processing_status'],
        'matched_survey' => $r['matched_survey_id'] ? (int)$r['matched_survey_id'] : null,
        'imo_number'     => $r['imo_number'],
        'port'           => $r['port'],
        'agent'          => $r['agent'],
        'eta'            => $r['eta'],
        'survey_type'    => $r['survey_type'],
    ];
}, $rows);

echo json_encode([
    'ok'          => true,
    'source'      => $sourceEmail,
    'fetch_error' => $fetchError,
    'emails'      => $emails,
]);

/**
 * Stores one freshly-fetched IMAP message (idempotent on message_id) and
 * runs extraction on it. Never touches the real mailbox.
 */
function processIncomingVesselEmail(PDO $db, int $accountId, array $msg): void {
    try {
        $exists = $db->prepare("SELECT id FROM emails WHERE account_id = ? AND message_id = ? LIMIT 1");
        $exists->execute([$accountId, $msg['message_id']]);
        if ($exists->fetchColumn()) {
            return; // already known — never re-process the same email twice
        }

        $hasAttachment = !empty($msg['attachments']) ? 1 : 0;
        $ins = $db->prepare("
            INSERT INTO emails (account_id, message_id, imap_uid, sender_email, sender_name, subject, received_at, body_text, body_html, has_attachment, processing_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PROCESSING')
        ");
        $ins->execute([
            $accountId, $msg['message_id'], $msg['uid'], $msg['from_email'], $msg['from_name'],
            $msg['subject'], $msg['received_at'], $msg['body_text'], $msg['body_html'], $hasAttachment,
        ]);
        $emailId = (int)$db->lastInsertId();

        // Save attachments to disk (validated: allowed types + size cap) and extract PDF text.
        $extraText = '';
        $allowedExt = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
        $maxFileBytes = 15 * 1024 * 1024;
        $dir = __DIR__ . '/../uploads/email_attachments/' . $accountId . '/';
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }

        foreach ($msg['attachments'] as $i => $att) {
            $origName = (string)($att['name'] ?? ('attachment_' . $i));
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $bytes = (string)($att['data'] ?? '');
            $size = strlen($bytes);
            if ($ext === '' || !in_array($ext, $allowedExt, true) || $size <= 0 || $size > $maxFileBytes) {
                $attIns = $db->prepare("INSERT INTO email_attachments (email_id, file_name, file_path, mime_type, file_size, extraction_status) VALUES (?, ?, '', ?, ?, 'SKIPPED')");
                $attIns->execute([$emailId, $origName, $att['mime'] ?? '', $size]);
                continue;
            }
            $safeName = time() . '_' . $emailId . '_' . $i . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', basename($origName));
            $destPath = $dir . $safeName;
            file_put_contents($destPath, $bytes);
            $relPath = 'uploads/email_attachments/' . $accountId . '/' . $safeName;

            $extractedText = null;
            $extractionStatus = 'SKIPPED';
            if ($ext === 'pdf') {
                $extractedText = extractPdfTextFromBytes($bytes);
                $extractionStatus = $extractedText !== null ? 'OK' : 'FAILED';
                if ($extractedText !== null) {
                    $extraText .= "\n" . $extractedText;
                }
            }
            $attIns = $db->prepare("INSERT INTO email_attachments (email_id, file_name, file_path, mime_type, file_size, extraction_status, extracted_text) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $attIns->execute([$emailId, $origName, $relPath, $att['mime'] ?? '', $size, $extractionStatus, $extractedText]);
        }

        $fullText = $msg['body_text'] . "\n" . strip_tags((string)$msg['body_html']) . $extraText;
        $fields = extractVesselFieldsFromText((string)$msg['subject'], $fullText);

        $ed = $db->prepare("
            INSERT INTO email_extracted_data
                (email_id, vessel_name, imo_number, call_sign, port, agent, eta, etb, survey_type, cargo, client, master_name, loa, draft, dwt, gt, survey_date, additional_instructions, confidence_score)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ed->execute([
            $emailId, $fields['vessel_name'], $fields['imo_number'], $fields['call_sign'], $fields['port'],
            $fields['agent'], $fields['eta'], $fields['etb'], $fields['survey_type'], $fields['cargo'],
            $fields['client'], $fields['master_name'], $fields['loa'], $fields['draft'], $fields['dwt'],
            $fields['gt'], $fields['survey_date'], $fields['additional_instructions'], $fields['confidence_score'],
        ]);

        $db->prepare("UPDATE emails SET processing_status = 'PROCESSED', processed_at = NOW() WHERE id = ?")->execute([$emailId]);
    } catch (Throwable $e) {
        error_log('processIncomingVesselEmail: ' . $e->getMessage());
        if (!empty($emailId)) {
            try { $db->prepare("UPDATE emails SET processing_status = 'FAILED' WHERE id = ?")->execute([$emailId]); } catch (Throwable $e2) {}
        }
    }
}

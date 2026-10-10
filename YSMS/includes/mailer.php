<?php
require_once __DIR__ . '/../config/mail_config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/gmail_service.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Low-level HTML email sender.
 *
 * $options (optional):
 *   - from_name       : display name in From (Admin / Surveyor name)
 *   - from_email      : send as this address (the acting user's own
 *                        profile email). When it's a GMAIL_API_WORKSPACE_DOMAIN
 *                        address and the Gmail API is enabled, sends via
 *                        Gmail API domain-wide delegation so the message
 *                        lands in that user's own Sent folder; otherwise
 *                        (or on Gmail API failure) falls back to the
 *                        Workspace SMTP relay. Falls back further to
 *                        SMTP_FROM_EMAIL when empty, invalid, or neither
 *                        is configured.
 *   - reply_to_email  : Reply-To = admin or surveyor profile email
 *   - reply_to_name   : Reply-To display name
 */
function sendHtmlEmail(string $toEmail, string $toName, string $subject, string $htmlBody, string $altBody = '', array $ccEmails = [], array $attachments = [], array $options = []): bool {
    return ysmsSendHtmlEmailCore([['email' => $toEmail, 'name' => $toName]], $subject, $htmlBody, $altBody, $ccEmails, $attachments, $options);
}

/**
 * Same as sendHtmlEmail(), but addresses several recipients in ONE message
 * instead of sending one fully separate copy per recipient. Use this
 * whenever the exact same notification is going to a list of people (e.g.
 * every Admin on file) — a separate sendHtmlEmail() call per recipient
 * re-does the SMTP handshake (or Gmail API signJwt + token exchange) and
 * re-encodes every attachment from scratch for each one, which is what
 * made multi-admin notifications slow to submit.
 *
 * $recipients: list of ['email' => ..., 'name' => ...]
 */
function sendHtmlEmailMulti(array $recipients, string $subject, string $htmlBody, string $altBody = '', array $ccEmails = [], array $attachments = [], array $options = []): bool {
    return ysmsSendHtmlEmailCore($recipients, $subject, $htmlBody, $altBody, $ccEmails, $attachments, $options);
}

function ysmsSendHtmlEmailCore(array $recipients, string $subject, string $htmlBody, string $altBody, array $ccEmails, array $attachments, array $options): bool {
    $GLOBALS['ysms_last_mail_error'] = '';
    if (defined('EMAIL_NOTIFICATIONS_ENABLED') && !EMAIL_NOTIFICATIONS_ENABLED) {
        $GLOBALS['ysms_last_mail_error'] = 'Email notifications are disabled on this environment.';
        return false;
    }
    $validRecipients = [];
    foreach ($recipients as $r) {
        $email = trim((string)(is_array($r) ? ($r['email'] ?? '') : $r));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $validRecipients[] = ['email' => $email, 'name' => is_array($r) ? (string)($r['name'] ?? '') : ''];
        }
    }
    if (empty($validRecipients)) {
        $GLOBALS['ysms_last_mail_error'] = 'Invalid or empty recipient email.';
        return false;
    }

    $relayReady = defined('SMTP_RELAY_ENABLED') && SMTP_RELAY_ENABLED
        && SMTP_RELAY_USERNAME !== '' && SMTP_RELAY_PASSWORD !== '';
    $fromEmailOverride = trim((string)($options['from_email'] ?? ''));
    $useRelay = $relayReady && $fromEmailOverride !== '' && filter_var($fromEmailOverride, FILTER_VALIDATE_EMAIL);

    if ($useRelay) {
        if (SMTP_RELAY_PASSWORD === '' || SMTP_RELAY_PASSWORD === false) {
            $GLOBALS['ysms_last_mail_error'] = 'SMTP relay password is empty — set YSMS_SMTP_RELAY_PASSWORD.';
            error_log($GLOBALS['ysms_last_mail_error']);
            return false;
        }
    } elseif (SMTP_PASSWORD === '' || SMTP_PASSWORD === false) {
        $GLOBALS['ysms_last_mail_error'] = 'SMTP password is empty in config/mail_config.php — set SMTP_PASSWORD (or env YSMS_SMTP_PASSWORD).';
        error_log($GLOBALS['ysms_last_mail_error']);
        return false;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        if ($useRelay) {
            $mail->Host       = SMTP_RELAY_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_RELAY_USERNAME;
            $mail->Password   = SMTP_RELAY_PASSWORD;
            $mail->SMTPSecure = SMTP_RELAY_SECURE === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = SMTP_RELAY_PORT;
        } else {
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USERNAME;
            $mail->Password   = SMTP_PASSWORD;
            $mail->SMTPSecure = SMTP_SECURE === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = SMTP_PORT;
        }
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 30;

        $fromName = trim((string)($options['from_name'] ?? ''));
        if ($fromName === '') {
            $fromName = SMTP_FROM_NAME;
        }
        $mail->setFrom($useRelay ? $fromEmailOverride : SMTP_FROM_EMAIL, $fromName);
        foreach ($validRecipients as $r) {
            $mail->addAddress($r['email'], $r['name']);
        }

        $replyEmail = trim((string)($options['reply_to_email'] ?? ''));
        $replyName  = trim((string)($options['reply_to_name'] ?? $fromName));
        if ($replyEmail !== '' && filter_var($replyEmail, FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($replyEmail, $replyName !== '' ? $replyName : $fromName);
        }

        // Skip CC'ing an address that's already one of the To recipients —
        // otherwise that person gets the exact same message twice.
        $toEmails = array_map(static function ($r) { return strtolower($r['email']); }, $validRecipients);
        foreach ($ccEmails as $cc) {
            $cc = trim((string)$cc);
            if ($cc !== '' && filter_var($cc, FILTER_VALIDATE_EMAIL) && !in_array(strtolower($cc), $toEmails, true)) {
                $mail->addCC($cc);
            }
        }

        // Attach local files (skip missing / oversized to stay under Gmail ~25MB limit)
        $attachedBytes = 0;
        $maxTotal = 20 * 1024 * 1024; // 20 MB total safety
        foreach ($attachments as $att) {
            $path = '';
            $name = '';
            if (is_array($att)) {
                $path = (string)($att['path'] ?? '');
                $name = (string)($att['name'] ?? '');
            } else {
                $path = (string)$att;
            }
            if ($path === '' || !is_file($path) || !is_readable($path)) {
                continue;
            }
            $size = (int)filesize($path);
            if ($size <= 0 || ($attachedBytes + $size) > $maxTotal) {
                continue;
            }
            if ($name === '') {
                $name = basename($path);
            }
            $mail->addAttachment($path, $name);
            $attachedBytes += $size;
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $altBody !== '' ? $altBody : strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));

        $gmailDomain = '@' . ltrim((string)GMAIL_API_WORKSPACE_DOMAIN, '@');
        $tryGmailApi = $useRelay && defined('GMAIL_API_ENABLED') && GMAIL_API_ENABLED
            && stripos($fromEmailOverride, $gmailDomain) !== false;
        if ($tryGmailApi && $mail->preSend() && gmailApiSendRaw($fromEmailOverride, $mail->getSentMIMEMessage())) {
            return true;
        }
        if ($tryGmailApi) {
            error_log('Gmail API send failed, falling back to SMTP relay: ' . ($GLOBALS['ysms_last_mail_error'] ?? ''));
        }

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        $GLOBALS['ysms_last_mail_error'] = $mail->ErrorInfo ?: $e->getMessage();
        error_log('Email send failed: ' . $GLOBALS['ysms_last_mail_error']);
        return false;
    } catch (\Throwable $e) {
        $GLOBALS['ysms_last_mail_error'] = $e->getMessage();
        error_log('Email send failed: ' . $GLOBALS['ysms_last_mail_error']);
        return false;
    }
}

/**
 * Sends the "Forgot Password" reset link email via PHPMailer/SMTP.
 */
function sendPasswordResetEmail(string $toEmail, string $toName, string $resetLink): bool {
    $html = '
            <div style="font-family:Lexend,Arial,sans-serif;max-width:480px;margin:auto;color:#1e293b;">
                <h2 style="color:#0b1e46;">Password Reset Request</h2>
                <p>Hello ' . htmlspecialchars($toName, ENT_QUOTES, 'UTF-8') . ',</p>
                <p>We received a request to reset your YSMS account password. Click the button below to choose a new password. This link is valid for 60 minutes.</p>
                <p style="text-align:center;margin:28px 0;">
                    <a href="' . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . '" style="background:#3b32b3;color:#fff;text-decoration:none;padding:12px 28px;border-radius:10px;font-weight:600;display:inline-block;">Reset Password</a>
                </p>
                <p>If you did not request this, you can safely ignore this email.</p>
                <p style="color:#64748b;font-size:12px;">YMR Survey Management System</p>
            </div>';
    $alt = "Reset your YSMS password using this link: {$resetLink} (valid for 60 minutes).";
    return sendHtmlEmail($toEmail, $toName, 'Reset your YMR Survey Management System password', $html, $alt);
}

/**
 * Normalize phone to digits only; prefix India 91 if 10 digits.
 */
function normalizeWhatsAppPhone(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone);
    if ($digits === null || $digits === '') {
        return '';
    }
    if (strlen($digits) === 12 && strpos($digits, '91') === 0) {
        return $digits;
    }
    if (strlen($digits) === 10) {
        return '91' . $digits;
    }
    return $digits;
}

/**
 * Build plain-text job assignment message (email + WhatsApp).
 */
function buildJobAssignmentMessage(array $job, string $surveyorName = ''): string {
    $surveyorName = $surveyorName !== '' ? $surveyorName : ($job['surveyor_name'] ?? 'Surveyor');
    $client = $job['client_name'] ?? '—';
    $types = $job['survey_types'] ?? '—';
    $port = $job['port_name'] ?? '—';
    $lines = [
        'Dear ' . $surveyorName . ',',
        '',
        'Good day to you,',
        '',
        'We have been appointed by ' . $client . ', to conduct ' . $types . ', at ' . $port . '.',
        '',
        'Vessel details:',
        '',
        'Vessel      : ' . ($job['vessel_name'] ?? '—'),
        'Report No   : ' . ($job['report_number'] ?? '—'),
        'Client      : ' . $client,
        'Port        : ' . $port,
        'Survey type : ' . $types,
        'Agent       : ' . ($job['agent_name'] ?? '—'),
    ];
    if (!empty($job['remarks'])) {
        $lines[] = 'Remarks     : ' . $job['remarks'];
    }
    if (!empty($job['app_url'])) {
        $lines[] = '';
        $lines[] = 'Open YSMS: ' . $job['app_url'];
    }
    $lines[] = '';
    $lines[] = 'Thanks & Regards,';
    $lines[] = 'YMR Survey Coordination Team.';
    return implode("\n", $lines);
}

/**
 * Email surveyor about a newly assigned job.
 */
function sendJobAssignmentEmail(string $toEmail, string $toName, array $job): bool {
    $safe = static function ($v) {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    };

    $vessel = $job['vessel_name'] ?? '';
    $types  = $job['survey_types'] ?? '';
    $port   = $job['port_name'] ?? '';
    $client = $job['client_name'] ?? '';
    $report = $job['report_number'] ?? '';
    $agent  = $job['agent_name'] ?? '';

    // Subject: {VESSEL NAME} - {Type of survey} - {Survey port} - {Client name}
    $subjectParts = array_filter([
        trim((string)$vessel),
        trim((string)$types),
        trim((string)$port),
        trim((string)$client),
    ], static function ($p) { return $p !== ''; });
    $subject = $subjectParts ? implode(' - ', $subjectParts) : 'New survey job assigned';

    $html = '
        <div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:auto;color:#1e293b;font-size:14px;line-height:1.55;">
            <p>Dear ' . $safe($toName) . ',</p>
            <p>Good day to you,</p>
            <p>We have been appointed by <strong>' . $safe($client) . '</strong>, to conduct <strong>' . $safe($types) . '</strong>, at <strong>' . $safe($port) . '</strong>.</p>
            <p style="margin-bottom:8px;"><strong>Vessel details:</strong></p>
            <table style="width:100%;border-collapse:collapse;font-size:14px;margin:0 0 16px;">
                <tr>
                    <td style="padding:6px 10px;border:1px solid #e2e8f0;width:34%;background:#f8fafc;color:#64748b;">Vessel</td>
                    <td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:600;">' . $safe($vessel) . '</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;border:1px solid #e2e8f0;background:#f8fafc;color:#64748b;">Report No</td>
                    <td style="padding:6px 10px;border:1px solid #e2e8f0;font-weight:600;">' . $safe($report) . '</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;border:1px solid #e2e8f0;background:#f8fafc;color:#64748b;">Client</td>
                    <td style="padding:6px 10px;border:1px solid #e2e8f0;">' . $safe($client) . '</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;border:1px solid #e2e8f0;background:#f8fafc;color:#64748b;">Port</td>
                    <td style="padding:6px 10px;border:1px solid #e2e8f0;">' . $safe($port) . '</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;border:1px solid #e2e8f0;background:#f8fafc;color:#64748b;">Survey type</td>
                    <td style="padding:6px 10px;border:1px solid #e2e8f0;">' . $safe($types) . '</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px;border:1px solid #e2e8f0;background:#f8fafc;color:#64748b;">Agent</td>
                    <td style="padding:6px 10px;border:1px solid #e2e8f0;">' . $safe($agent) . '</td>
                </tr>
            </table>';
    if (!empty($job['remarks'])) {
        $html .= '<p style="background:#f8fafc;border-radius:8px;padding:10px 12px;font-size:13px;"><strong>Remarks:</strong> ' . $safe($job['remarks']) . '</p>';
    }
    if (!empty($job['app_url'])) {
        $html .= '<p style="margin:18px 0;">
            <a href="' . $safe($job['app_url']) . '" style="background:#3b32b3;color:#fff;text-decoration:none;padding:10px 20px;border-radius:8px;font-weight:600;display:inline-block;">Open in YSMS</a>
        </p>';
    }
    $html .= '
            <p style="margin-top:24px;">Thanks &amp; Regards,<br>
            <strong>YMR Survey Coordination Team.</strong></p>
        </div>';

    $alt = buildJobAssignmentMessage($job, $toName);
    $cc = ['survey@ymrmarine.com'];
    // From: assigning admin's own @ymrmarine.com address (via SMTP relay);
    // falls back to the default mailbox if admin has no profile email.
    $adminEmail = (string)($job['assigned_by_email'] ?? '');
    $options = [
        'from_name'      => (string)($job['assigned_by_name'] ?? SMTP_FROM_NAME),
        'from_email'     => $adminEmail,
        'reply_to_email' => $adminEmail,
        'reply_to_name'  => (string)($job['assigned_by_name'] ?? ''),
    ];
    return sendHtmlEmail($toEmail, $toName, $subject, $html, $alt, $cc, [], $options);
}

/**
 * Send WhatsApp text via optional providers.
 * Returns [ok, method, error, wa_link]
 */
function sendJobAssignmentWhatsApp(string $phone, array $job): array {
    $text = buildJobAssignmentMessage($job);
    $digits = normalizeWhatsAppPhone($phone);
    $waLink = $digits !== ''
        ? 'https://wa.me/' . $digits . '?text=' . rawurlencode($text)
        : '';

    if ($digits === '') {
        return ['ok' => false, 'method' => 'none', 'error' => 'No phone number', 'wa_link' => ''];
    }

    $callmeKey = defined('WHATSAPP_CALLMEBOT_APIKEY') ? (string)WHATSAPP_CALLMEBOT_APIKEY : '';
    if ($callmeKey === '') {
        $callmeKey = (string)(getenv('YSMS_WHATSAPP_CALLMEBOT_APIKEY') ?: '');
    }
    if ($callmeKey !== '') {
        $url = 'https://api.callmebot.com/whatsapp.php?' . http_build_query([
            'phone'  => $digits,
            'text'   => $text,
            'apikey' => $callmeKey,
        ]);
        $resp = ysmsHttpGet($url);
        if ($resp['ok']) {
            return ['ok' => true, 'method' => 'callmebot', 'error' => '', 'wa_link' => $waLink];
        }
        error_log('CallMeBot WhatsApp failed: ' . $resp['body']);
    }

    $token = defined('WHATSAPP_CLOUD_TOKEN') ? (string)WHATSAPP_CLOUD_TOKEN : (string)(getenv('YSMS_WHATSAPP_CLOUD_TOKEN') ?: '');
    $phoneId = defined('WHATSAPP_CLOUD_PHONE_ID') ? (string)WHATSAPP_CLOUD_PHONE_ID : (string)(getenv('YSMS_WHATSAPP_CLOUD_PHONE_ID') ?: '');
    if ($token !== '' && $phoneId !== '') {
        $payload = json_encode([
            'messaging_product' => 'whatsapp',
            'to' => $digits,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $text],
        ]);
        $resp = ysmsHttpPostJson(
            'https://graph.facebook.com/v19.0/' . rawurlencode($phoneId) . '/messages',
            $payload,
            ['Authorization: Bearer ' . $token, 'Content-Type: application/json']
        );
        if ($resp['ok']) {
            return ['ok' => true, 'method' => 'cloud_api', 'error' => '', 'wa_link' => $waLink];
        }
        error_log('WhatsApp Cloud API failed: ' . $resp['body']);
        return ['ok' => false, 'method' => 'cloud_api', 'error' => 'Cloud API send failed', 'wa_link' => $waLink];
    }

    return ['ok' => false, 'method' => 'link_only', 'error' => 'No WhatsApp API configured', 'wa_link' => $waLink];
}

/**
 * Notify surveyor after vessel assign.
 */
function notifySurveyorOfAssignment(PDO $db, int $surveyorId, array $job): string {
    if ($surveyorId <= 0) {
        return '';
    }
    // Outsourcing placeholder (surveyor id 1) has no real profile/email —
    // route the assignment email to Operations instead of a surveyor.
    if ($surveyorId === 1 && empty($job['force_notify'])) {
        $opsEmail = defined('SMTP_RELAY_USERNAME') && SMTP_RELAY_USERNAME !== '' ? SMTP_RELAY_USERNAME : 'ops@ymrmarine.com';
        $ok = sendJobAssignmentEmail($opsEmail, 'Operations', $job);
        if ($ok) {
            return 'Outsourced job — email sent to ' . $opsEmail . '.';
        }
        $err = !empty($GLOBALS['ysms_last_mail_error']) ? $GLOBALS['ysms_last_mail_error'] : 'check SMTP settings';
        return 'Outsourced job — EMAIL FAILED: ' . $err;
    }

    $notes = [];
    try {
        $cols = [];
        foreach ($db->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) as $c) {
            $cols[strtolower((string)$c)] = true;
        }
        $select = ['id', 'full_name'];
        if (!empty($cols['email'])) {
            $select[] = 'email';
        }
        if (!empty($cols['phone'])) {
            $select[] = 'phone';
        }
        $stmt = $db->prepare('SELECT ' . implode(',', $select) . ' FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$surveyorId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('notifySurveyorOfAssignment user fetch: ' . $e->getMessage());
        return 'Could not load surveyor contact details.';
    }

    if (!$user) {
        return 'Surveyor not found for notification.';
    }

    $name = $user['full_name'] ?? 'Surveyor';
    $email = trim((string)($user['email'] ?? ''));
    $phone = trim((string)($user['phone'] ?? ''));

    if ($email !== '') {
        // Two surveyor accounts sharing one mailbox (same vessel assigned to
        // both) must only get one email for it, not one per account — track
        // which addresses were already emailed for THIS assignment job
        // within this request (WhatsApp/in-app notification below still go
        // out per account, since those are account-specific, not email-tied).
        static $emailedThisRequest = [];
        $dedupeKey = strtolower($email) . '|' . ($job['report_number'] ?? '') . '|' . ($job['vessel_name'] ?? '');
        if (isset($emailedThisRequest[$dedupeKey])) {
            $notes[] = 'Email already sent to ' . $email . ' for this vessel (shared with another assigned surveyor)';
        } else {
            $ok = sendJobAssignmentEmail($email, $name, $job);
            if ($ok) {
                $emailedThisRequest[$dedupeKey] = true;
                $notes[] = 'Email sent to ' . $email;
            } else {
                $err = !empty($GLOBALS['ysms_last_mail_error']) ? $GLOBALS['ysms_last_mail_error'] : 'check SMTP settings';
                $notes[] = 'EMAIL FAILED: ' . $err;
                $GLOBALS['ysms_mail_failed'] = true;
            }
        }
    } else {
        $notes[] = 'EMAIL SKIPPED: No email on surveyor profile — open Profile and save email';
        $GLOBALS['ysms_mail_failed'] = true;
    }

    if ($phone !== '') {
        $wa = sendJobAssignmentWhatsApp($phone, $job);
        if ($wa['ok']) {
            $notes[] = 'WhatsApp sent';
        } elseif (!empty($wa['wa_link'])) {
            $GLOBALS['ysms_last_wa_link'] = $wa['wa_link'];
            $notes[] = ($wa['method'] === 'link_only')
                ? 'WhatsApp API not configured — open chat link below'
                : 'WhatsApp not sent — open chat link below';
        } else {
            $notes[] = 'WhatsApp not sent' . (!empty($wa['error']) ? (' (' . $wa['error'] . ')') : '');
        }
    } else {
        $notes[] = 'No phone on surveyor profile';
    }

    return implode('. ', $notes) . '.';
}

function ysmsHttpGet(string $url, int $timeout = 12): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return ['ok' => false, 'body' => $err ?: 'curl error', 'code' => $code];
        }
        return ['ok' => $code >= 200 && $code < 300, 'body' => (string)$body, 'code' => $code];
    }
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    return ['ok' => $body !== false, 'body' => (string)$body, 'code' => 0];
}

function ysmsHttpPostJson(string $url, string $json, array $headers = [], int $timeout = 15): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return ['ok' => false, 'body' => $err ?: 'curl error', 'code' => $code];
        }
        return ['ok' => $code >= 200 && $code < 300, 'body' => (string)$body, 'code' => $code];
    }
    $hdr = implode("\r\n", $headers);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => $hdr,
            'content' => $json,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    return ['ok' => $body !== false, 'body' => (string)$body, 'code' => 0];
}


/**
 * Fetch all Active Admin users that have an email address.
 * @return list of [id, full_name, email]
 */
function getAdminRecipients(PDO $db): array {
    try {
        $cols = [];
        foreach ($db->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN) as $c) {
            $cols[strtolower((string)$c)] = true;
        }
        if (empty($cols['email'])) {
            return [];
        }
        $sql = "SELECT id, full_name, email FROM users WHERE role_id = 1 AND status = 'Active' AND email IS NOT NULL AND email != ''";
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Two Admin accounts sharing one mailbox (e.g. a shared ops inbox)
        // must only ever get one email each, not one per account row.
        $seen = [];
        $deduped = [];
        foreach ($rows as $row) {
            $key = strtolower(trim((string)($row['email'] ?? '')));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $row;
        }
        return $deduped;
    } catch (\Throwable $e) {
        error_log('getAdminRecipients: ' . $e->getMessage());
        return [];
    }
}

/**
 * Email all admins when surveyor uploads reports / completes a stage.
 *
 * $info keys:
 *   vessel_name, report_number, client_name, port_name, survey_types,
 *   surveyor_name, new_status, files (list of strings), recovery_amount,
 *   app_url, stage_label
 */
function notifyAdminsOfReportUpload(PDO $db, array $info): string {
    // Outsourced survey (surveyor id 1 = placeholder, no real profile) —
    // route to Operations instead of the admin list.
    if ((int)($info['surveyor_id'] ?? 0) === 1) {
        $opsEmail = defined('SMTP_RELAY_USERNAME') && SMTP_RELAY_USERNAME !== '' ? SMTP_RELAY_USERNAME : 'ops@ymrmarine.com';
        $admins = [['full_name' => 'Operations', 'email' => $opsEmail]];
    } else {
        $admins = getAdminRecipients($db);
    }
    if (empty($admins)) {
        return 'No admin email on file.';
    }

    $safe = static function ($v) {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    };

    $attachments = $info['attachments'] ?? [];
    $attCount = 0;
    foreach ($attachments as $att) {
        $path = is_array($att) ? (string)($att['path'] ?? '') : (string)$att;
        if ($path !== '' && is_file($path)) {
            $attCount++;
        }
    }

    $stage = $info['stage_label'] ?? ($info['new_status'] ?? 'Updated');
    $subject = $stage . ': ' . ($info['vessel_name'] ?? 'Vessel') . ' (' . ($info['report_number'] ?? '') . ')';

    // Signed-document cover letter format, vessel/survey/port/date line,
    // signed off with the uploading surveyor's own profile name. The
    // Pending Vessel stage (PDF + Excel upload) also gets a VLSFO/HSFO/
    // LSMGO recovery table; the final Pending Report stage (Word report
    // upload, survey -> Completed) already had recovery reported earlier,
    // so it's just the cover line with no table.
    $isFinalStage = (($info['new_status'] ?? '') === 'Completed');
    $leadIn = $isFinalStage ? 'Final Formal Report' : 'signed documents &amp; Field Report';
    $leadInPlain = $isFinalStage ? 'Final Formal Report' : 'signed documents & Field Report';
    $surveyDate = !empty($info['survey_completed_date']) ? date('d M Y', strtotime((string)$info['survey_completed_date'])) : '';

    $html = '
        <div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:auto;color:#1e293b;font-size:14px;line-height:1.6;">
            <p>Dear sir/Madam,</p>
            <p>Good day to you!!</p>
            <p>Please find attached the ' . $leadIn . ', for the subject vessel<br>
            <strong>' . $safe($info['vessel_name'] ?? '') . '</strong> - <strong>' . $safe($info['survey_types'] ?? '') . '</strong>, attended at <strong>' . $safe($info['port_name'] ?? '') . '</strong>, on <strong>' . $safe($surveyDate) . '</strong>.</p>';

    $altLines = [
        'Dear sir/Madam,',
        '',
        'Good day to you!!',
        '',
        'Please find attached the ' . $leadInPlain . ', for the subject vessel',
        ($info['vessel_name'] ?? '') . ' - ' . ($info['survey_types'] ?? '') . ', attended at ' . ($info['port_name'] ?? '') . ', on ' . $surveyDate . '.',
    ];

    if (!$isFinalStage) {
        $recoveryNA = !empty($info['recovery_na']);
        $fmtRec = static function ($v) {
            return ($v !== null && $v !== '') ? number_format((float)$v, 3) : '0.000';
        };
        $vlsfoCell = $recoveryNA ? 'N/A' : $fmtRec($info['vlsfo_recovery'] ?? null);
        $hsfoCell  = $recoveryNA ? 'N/A' : $fmtRec($info['hsfo_recovery'] ?? null);
        $lsmgoCell = $recoveryNA ? 'N/A' : $fmtRec($info['lsmgo_recovery'] ?? null);
        $totalCell = $recoveryNA ? 'N/A (Not Applicable)' : $fmtRec($info['recovery_amount'] ?? null);

        $html .= '
            <table style="border-collapse:collapse;width:100%;max-width:420px;margin:18px 0;font-size:14px;">
                <tr>
                    <th style="border:1px solid #cbd5e1;padding:8px 12px;background:#f1f5f9;text-align:left;">VLSFO</th>
                    <th style="border:1px solid #cbd5e1;padding:8px 12px;background:#f1f5f9;text-align:left;">HSFO</th>
                    <th style="border:1px solid #cbd5e1;padding:8px 12px;background:#f1f5f9;text-align:left;">LSMGO</th>
                </tr>
                <tr>
                    <td style="border:1px solid #cbd5e1;padding:8px 12px;">' . $safe($vlsfoCell) . '</td>
                    <td style="border:1px solid #cbd5e1;padding:8px 12px;">' . $safe($hsfoCell) . '</td>
                    <td style="border:1px solid #cbd5e1;padding:8px 12px;">' . $safe($lsmgoCell) . '</td>
                </tr>
                <tr>
                    <td colspan="2" style="border:1px solid #cbd5e1;padding:8px 12px;text-align:center;font-weight:700;">TOTAL</td>
                    <td style="border:1px solid #cbd5e1;padding:8px 12px;font-weight:700;">' . $safe($totalCell) . '</td>
                </tr>
            </table>';

        $altLines[] = '';
        $altLines[] = 'VLSFO: ' . $vlsfoCell;
        $altLines[] = 'HSFO: ' . $hsfoCell;
        $altLines[] = 'LSMGO: ' . $lsmgoCell;
        $altLines[] = 'TOTAL: ' . $totalCell;
    }

    $html .= '
            <p>Thanks &amp; Regards,<br>
            ' . $safe($info['surveyor_name'] ?? '') . '<br>
            YMR MARINE SOLUTIONS LLP</p>
        </div>';

    $altLines[] = '';
    $altLines[] = 'Thanks & Regards,';
    $altLines[] = (string)($info['surveyor_name'] ?? '');
    $altLines[] = 'YMR MARINE SOLUTIONS LLP';
    $alt = implode("\n", $altLines);

    // From: surveyor's own @ymrmarine.com address (via SMTP relay); falls
    // back to the default mailbox if surveyor has no profile email.
    $surveyorEmail = (string)($info['surveyor_email'] ?? '');
    $options = [
        'from_name'      => (string)($info['surveyor_name'] ?? SMTP_FROM_NAME),
        'from_email'     => $surveyorEmail,
        'reply_to_email' => $surveyorEmail,
        'reply_to_name'  => (string)($info['surveyor_name'] ?? ''),
    ];
    // 🌟 ONE message addressed to every admin at once, instead of one fully
    // separate sendHtmlEmail() per admin — the old per-admin loop re-did the
    // SMTP/Gmail API handshake and re-encoded every attachment from scratch
    // for each recipient, which is what made submitting the upload slow
    // when there were several admins on file. The shared ops inbox is CC'd
    // once (sendHtmlEmailMulti already skips it if it's also one of the To
    // recipients, so it never gets a duplicate copy).
    $recipients = array_map(static function ($admin) {
        return ['email' => trim((string)($admin['email'] ?? '')), 'name' => (string)($admin['full_name'] ?? 'Admin')];
    }, $admins);
    $recipientCount = count(array_filter($recipients, static function ($r) { return $r['email'] !== ''; }));

    if (!sendHtmlEmailMulti($recipients, $subject, $html, $alt, ['survey@ymrmarine.com'], $attachments, $options)) {
        $err = !empty($GLOBALS['ysms_last_mail_error']) ? $GLOBALS['ysms_last_mail_error'] : 'check SMTP';
        return 'Admin email failed: ' . $err;
    }
    $msg = 'Admin notified by email (' . $recipientCount . ')';
    if ($attCount > 0) {
        $msg .= ' with ' . $attCount . ' attachment(s)';
    }
    return $msg . '.';
}

/**
 * Email all admins the moment a Client submits a vessel assignment request
 * directly (no surveyor picked yet — Admin still needs to assign one).
 *
 * $info keys: vessel_name, report_number, client_name, port_name,
 * survey_types, agent_name, app_url
 */
function notifyAdminsOfClientVesselRequest(PDO $db, array $info): string {
    $admins = getAdminRecipients($db);
    if (empty($admins)) {
        return 'No admin email on file.';
    }

    $safe = static function ($v) {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    };

    $subject = 'New vessel from ' . ($info['client_name'] ?? 'Client') . ' — needs a surveyor: ' . ($info['vessel_name'] ?? '');

    $html = '
        <div style="font-family:Lexend,Arial,sans-serif;max-width:520px;margin:auto;color:#1e293b;">
            <h2 style="color:#0b1e46;margin-bottom:8px;">New Vessel — Needs a Surveyor</h2>
            <p>A client submitted a vessel assignment directly. Please assign a surveyor.</p>
            <table style="width:100%;border-collapse:collapse;font-size:14px;margin:16px 0;">
                <tr><td style="padding:6px 0;color:#64748b;">Vessel</td><td style="padding:6px 0;font-weight:600;">' . $safe($info['vessel_name'] ?? '') . '</td></tr>
                <tr><td style="padding:6px 0;color:#64748b;">Report No</td><td style="padding:6px 0;font-weight:600;">' . $safe($info['report_number'] ?? '') . '</td></tr>
                <tr><td style="padding:6px 0;color:#64748b;">Client</td><td style="padding:6px 0;">' . $safe($info['client_name'] ?? '') . '</td></tr>
                <tr><td style="padding:6px 0;color:#64748b;">Port</td><td style="padding:6px 0;">' . $safe($info['port_name'] ?? '') . '</td></tr>
                <tr><td style="padding:6px 0;color:#64748b;">Survey type</td><td style="padding:6px 0;">' . $safe($info['survey_types'] ?? '') . '</td></tr>
                <tr><td style="padding:6px 0;color:#64748b;">Agent</td><td style="padding:6px 0;">' . $safe($info['agent_name'] ?? '') . '</td></tr>
            </table>';
    if (!empty($info['app_url'])) {
        $html .= '<p style="text-align:center;margin:24px 0;">
            <a href="' . $safe($info['app_url']) . '" style="background:#3b32b3;color:#fff;text-decoration:none;padding:12px 24px;border-radius:10px;font-weight:600;display:inline-block;">Assign a Surveyor</a>
        </p>';
    }
    $html .= '<p style="color:#64748b;font-size:12px;">YMR Survey Management System</p></div>';

    $altLines = [
        'New Vessel — Needs a Surveyor',
        'Vessel: ' . ($info['vessel_name'] ?? ''),
        'Report No: ' . ($info['report_number'] ?? ''),
        'Client: ' . ($info['client_name'] ?? ''),
        'Port: ' . ($info['port_name'] ?? ''),
        'Survey type: ' . ($info['survey_types'] ?? ''),
    ];
    if (!empty($info['app_url'])) {
        $altLines[] = 'Assign: ' . $info['app_url'];
    }
    $alt = implode("\n", $altLines);

    $sent = 0;
    $failed = 0;
    foreach ($admins as $admin) {
        $to = trim((string)($admin['email'] ?? ''));
        if ($to === '') continue;
        if (sendHtmlEmail($to, (string)($admin['full_name'] ?? 'Admin'), $subject, $html, $alt)) {
            $sent++;
        } else {
            $failed++;
        }
    }

    if ($sent === 0) {
        $err = !empty($GLOBALS['ysms_last_mail_error']) ? $GLOBALS['ysms_last_mail_error'] : 'check SMTP';
        return 'Admin email failed: ' . $err;
    }
    $msg = 'Admin notified by email (' . $sent . ')';
    if ($failed) {
        $msg .= ', ' . $failed . ' failed';
    }
    return $msg . '.';
}

/**
 * Load survey + client/port/surveyor names for notification emails.
 */
function loadSurveyNotifyContext(PDO $db, int $surveyId): array {
    try {
        $stmt = $db->prepare("
            SELECT s.vessel_name, s.report_number, s.agent_name, s.recovery_amount,
                   s.vlsfo_recovery, s.hsfo_recovery, s.lsmgo_recovery, s.survey_completed_date,
                   s.survey_type_id, s.survey_type_ids,
                   s.surveyor_id, c.company_name, p.port_name, t.type_name, u.full_name AS surveyor_name, u.email AS surveyor_email
            FROM surveys s
            LEFT JOIN clients c ON s.client_id = c.id
            LEFT JOIN ports p ON s.port_id = p.id
            LEFT JOIN survey_types t ON s.survey_type_id = t.id
            LEFT JOIN users u ON s.surveyor_id = u.id
            WHERE s.id = ?
            LIMIT 1
        ");
        $stmt->execute([$surveyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return [];
        }
        $types = $row['type_name'] ?? '';
        if (function_exists('getCombinedSurveyTypeNames') && !empty($row['survey_type_ids'])) {
            $types = getCombinedSurveyTypeNames($db, $row['survey_type_ids'], $types);
        }
        $recoveryNA = function_exists('isRecoveryNotApplicable')
            ? isRecoveryNotApplicable($db, (string)($row['survey_type_ids'] ?? ''), (string)($row['type_name'] ?? ''))
            : false;
        return [
            'vessel_name'          => $row['vessel_name'] ?? '',
            'report_number'        => $row['report_number'] ?? '',
            'client_name'          => $row['company_name'] ?? '',
            'port_name'            => $row['port_name'] ?? '',
            'survey_types'         => $types,
            'surveyor_id'          => (int)($row['surveyor_id'] ?? 0),
            'surveyor_name'        => $row['surveyor_name'] ?? '',
            'surveyor_email'       => $row['surveyor_email'] ?? '',
            'agent_name'           => $row['agent_name'] ?? '',
            'recovery_amount'      => $row['recovery_amount'] ?? null,
            'vlsfo_recovery'       => $row['vlsfo_recovery'] ?? null,
            'hsfo_recovery'        => $row['hsfo_recovery'] ?? null,
            'lsmgo_recovery'       => $row['lsmgo_recovery'] ?? null,
            'recovery_na'          => $recoveryNA,
            'survey_completed_date' => $row['survey_completed_date'] ?? '',
        ];
    } catch (\Throwable $e) {
        error_log('loadSurveyNotifyContext: ' . $e->getMessage());
        return [];
    }
}

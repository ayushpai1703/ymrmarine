<?php
/**
 * Thin wrapper over PHP's ext-imap for the single configured incoming
 * mailbox (survey@ymrmarine.com by default). Every function here is
 * strictly READ-ONLY against the real mailbox: no imap_delete(), no
 * imap_expunge(), no flag changes (FT_PEEK is used everywhere so messages
 * are never marked \Seen by us). Requires the `imap` PHP extension — see
 * Dockerfile.
 */

/** Builds the {host:port/imap/ssl}FOLDER mailbox string ext-imap expects. */
function imapBuildMailboxString(array $account): string {
    $host = $account['imap_host'];
    $port = (int)$account['imap_port'];
    $enc = strtolower((string)($account['imap_encryption'] ?? 'ssl'));
    $flags = '/imap';
    if ($enc === 'ssl') {
        $flags .= '/ssl';
    } elseif ($enc === 'tls') {
        $flags .= '/tls';
    } else {
        $flags .= '/novalidate-cert';
    }
    $folder = $account['folder'] !== '' ? $account['folder'] : 'INBOX';
    return '{' . $host . ':' . $port . $flags . '}' . $folder;
}

/**
 * Opens the IMAP connection. Returns [ok, stream|null, error].
 * $account must already have 'imap_secret' decrypted (see getEmailAccountDecrypted()).
 */
function imapOpenConnection(array $account): array {
    if (!function_exists('imap_open')) {
        return [false, null, 'The PHP imap extension is not installed on this server.'];
    }
    if (trim((string)$account['imap_host']) === '') {
        return [false, null, 'IMAP host is not configured yet.'];
    }
    if (trim((string)$account['imap_username']) === '') {
        return [false, null, 'IMAP username is not configured yet.'];
    }
    if (trim((string)($account['imap_secret'] ?? '')) === '') {
        return [false, null, 'IMAP password / app password is not configured yet.'];
    }
    $mailbox = imapBuildMailboxString($account);
    // Suppress ext-imap's own warning noise — we surface imap_last_error() ourselves.
    $stream = @imap_open($mailbox, $account['imap_username'], $account['imap_secret'], OP_READONLY, 1);
    if ($stream === false) {
        $err = imap_last_error() ?: 'Connection failed.';
        return [false, null, $err];
    }
    return [true, $stream, ''];
}

/**
 * Test Connection: open, count messages, close (nothing altered). Returns
 * ['ok'=>bool, 'message'=>string, 'folder'=>string, 'recent'=>int|null].
 */
function imapTestConnection(array $account): array {
    [$ok, $stream, $error] = imapOpenConnection($account);
    if (!$ok || !$stream) {
        return ['ok' => false, 'message' => $error, 'folder' => $account['folder'], 'recent' => null];
    }
    $info = @imap_check($stream);
    $count = $info ? (int)$info->Nmsgs : null;
    imap_close($stream);
    return ['ok' => true, 'message' => 'Connection successful.', 'folder' => $account['folder'], 'recent' => $count];
}

/**
 * Fetches up to $limit most recent messages from the last $sinceHours,
 * newest first. Returns a list of associative arrays:
 * ['uid','message_id','from_email','from_name','subject','received_at','body_text','body_html','attachments'=>[['name','mime','data'=>raw bytes]]]
 * Never marks messages as read (FT_PEEK throughout) and never deletes anything.
 */
function imapFetchRecentMessages(array $account, int $limit, int $sinceHours): array {
    [$ok, $stream, $error] = imapOpenConnection($account);
    if (!$ok || !$stream) {
        return ['ok' => false, 'error' => $error, 'messages' => []];
    }

    $sinceDate = date('d-M-Y', time() - max(1, $sinceHours) * 3600);
    $criteria = 'SINCE "' . $sinceDate . '"';
    $uids = @imap_search($stream, $criteria, SE_UID);
    if ($uids === false) {
        // No messages match, or search failed outright — either way, nothing to return.
        imap_close($stream);
        return ['ok' => true, 'error' => '', 'messages' => []];
    }
    rsort($uids); // newest first (higher UID = more recent)
    $uids = array_slice($uids, 0, max(1, $limit));

    $messages = [];
    foreach ($uids as $uid) {
        $msgno = @imap_msgno($stream, $uid);
        if (!$msgno) continue;
        $header = @imap_headerinfo($stream, $msgno);
        if (!$header) continue;

        $messageId = trim((string)($header->message_id ?? ''));
        if ($messageId === '') {
            // Fall back to a stable synthetic id so dedupe still works for
            // servers that omit Message-ID (rare, but seen with some cPanel setups).
            $messageId = 'uid-' . $uid . '@' . $account['imap_host'];
        }
        $fromEmail = '';
        $fromName = '';
        if (!empty($header->from) && isset($header->from[0])) {
            $f = $header->from[0];
            $fromEmail = trim(($f->mailbox ?? '') . '@' . ($f->host ?? ''));
            $fromName = trim((string)($f->personal ?? ''));
        }
        $subject = isset($header->subject) ? imapDecodeMimeStr((string)$header->subject) : '';
        $receivedAt = !empty($header->udate) ? date('Y-m-d H:i:s', (int)$header->udate) : null;

        $structure = @imap_fetchstructure($stream, $msgno);
        $bodyText = '';
        $bodyHtml = '';
        $attachments = [];
        imapWalkParts($stream, $msgno, $structure, '', $bodyText, $bodyHtml, $attachments);

        $messages[] = [
            'uid'          => (string)$uid,
            'message_id'   => $messageId,
            'from_email'   => $fromEmail,
            'from_name'    => $fromName,
            'subject'      => $subject,
            'received_at'  => $receivedAt,
            'body_text'    => $bodyText,
            'body_html'    => $bodyHtml,
            'attachments'  => $attachments,
        ];
    }

    imap_close($stream);
    return ['ok' => true, 'error' => '', 'messages' => $messages];
}

function imapDecodeMimeStr(string $str): string {
    $decoded = @imap_mime_header_decode($str);
    if (!$decoded) return $str;
    $out = '';
    foreach ($decoded as $part) {
        $charset = $part->charset ?? 'default';
        $text = $part->text ?? '';
        if ($charset !== 'default' && strtolower($charset) !== 'utf-8') {
            $conv = @iconv($charset, 'UTF-8//IGNORE', $text);
            $text = $conv !== false ? $conv : $text;
        }
        $out .= $text;
    }
    return $out;
}

/** Recursively walks MIME parts collecting plain text, HTML, and attachment bytes (PDF/DOC/images). */
function imapWalkParts($stream, int $msgno, $part, string $partNum, string &$bodyText, string &$bodyHtml, array &$attachments): void {
    if (!$part) return;

    $section = $partNum === '' ? '1' : $partNum;
    $isMultipart = isset($part->parts) && is_array($part->parts) && $part->type == 1; // TYPEMULTIPART

    if ($isMultipart) {
        foreach ($part->parts as $i => $sub) {
            $childNum = $partNum === '' ? (string)($i + 1) : $partNum . '.' . ($i + 1);
            imapWalkParts($stream, $msgno, $sub, $childNum, $bodyText, $bodyHtml, $attachments);
        }
        return;
    }

    $disposition = isset($part->ifdisposition) && $part->ifdisposition ? strtolower($part->disposition ?? '') : '';
    $filename = imapExtractFilename($part);

    $raw = @imap_fetchbody($stream, $msgno, $section === '' ? '1' : $section, FT_PEEK);
    if ($raw === false) return;
    $raw = imapApplyEncoding($raw, (int)($part->encoding ?? 0));

    $subtype = strtoupper((string)($part->subtype ?? ''));
    $type = (int)($part->type ?? 0);

    if ($filename !== '' && ($disposition === 'attachment' || $disposition === 'inline' && $filename !== '')) {
        $attachments[] = [
            'name' => $filename,
            'mime' => strtolower(($type === 0 ? 'text' : 'application') . '/' . strtolower($subtype)),
            'data' => $raw,
        ];
        return;
    }

    if ($type === 0 && $subtype === 'PLAIN') {
        $bodyText .= $raw;
    } elseif ($type === 0 && $subtype === 'HTML') {
        $bodyHtml .= $raw;
    } elseif ($filename !== '') {
        // No explicit disposition but has a filename — treat as attachment (some senders omit Content-Disposition).
        $attachments[] = [
            'name' => $filename,
            'mime' => strtolower(($type === 0 ? 'text' : 'application') . '/' . strtolower($subtype)),
            'data' => $raw,
        ];
    }
}

function imapExtractFilename($part): string {
    $name = '';
    if (isset($part->ifdparameters) && $part->ifdparameters) {
        foreach ($part->dparameters as $p) {
            if (strtolower($p->attribute) === 'filename') { $name = $p->value; break; }
        }
    }
    if ($name === '' && isset($part->ifparameters) && $part->ifparameters) {
        foreach ($part->parameters as $p) {
            if (strtolower($p->attribute) === 'name') { $name = $p->value; break; }
        }
    }
    return $name !== '' ? imapDecodeMimeStr($name) : '';
}

function imapApplyEncoding(string $data, int $encoding): string {
    switch ($encoding) {
        case 3: // BASE64
            return (string)base64_decode($data);
        case 4: // QUOTED-PRINTABLE
            return quoted_printable_decode($data);
        default:
            return $data;
    }
}

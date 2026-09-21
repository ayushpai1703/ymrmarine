<?php
/**
 * Thin wrapper over webklex/php-imap for the single configured incoming
 * mailbox (survey@ymrmarine.com by default). Pure-PHP IMAP protocol client
 * (protocol => 'imap') — deliberately NOT the legacy PHP `imap` system
 * extension, which requires libc-client that Debian trixie (this app's
 * Docker base image) no longer packages.
 *
 * Every function here is strictly READ-ONLY against the real mailbox:
 * leaveUnread() (IMAP FT_PEEK) is used on every fetch so messages are never
 * marked \Seen, and nothing here ever deletes, moves, or expunges anything.
 */
require_once __DIR__ . '/../vendor/autoload.php';

use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Client;

/**
 * Runs $fn with a temporary error handler that swallows PHP-level
 * warnings/notices (e.g. stream_socket_client()'s "Connection refused"
 * warning from deep inside the IMAP socket layer) so they can never leak
 * raw text into a JSON response. Real failures still surface normally —
 * the library throws exceptions for them, which are not affected by this.
 */
function imapQuiet(callable $fn) {
    $prev = set_error_handler(static function () { return true; }, E_WARNING | E_NOTICE | E_DEPRECATED);
    try {
        return $fn();
    } finally {
        set_error_handler($prev);
    }
}

function imapBuildClient(array $account): Client {
    $encRaw = strtolower((string)($account['imap_encryption'] ?? 'ssl'));
    $encryption = in_array($encRaw, ['ssl', 'tls'], true) ? $encRaw : false;
    $cm = new ClientManager();
    return $cm->make([
        'host'          => (string)$account['imap_host'],
        'port'          => (int)$account['imap_port'],
        'encryption'    => $encryption,
        'validate_cert' => true,
        'username'      => (string)$account['imap_username'],
        'password'      => (string)($account['imap_secret'] ?? ''),
        'protocol'      => 'imap',
    ]);
}

/**
 * Test Connection: connect, count messages in the folder, disconnect
 * (nothing altered). Returns ['ok'=>bool, 'message'=>string, 'folder'=>string, 'recent'=>int|null].
 */
function imapTestConnection(array $account): array {
    $folderName = trim((string)$account['folder']) !== '' ? $account['folder'] : 'INBOX';

    if (trim((string)$account['imap_host']) === '') {
        return ['ok' => false, 'message' => 'IMAP host is not configured yet.', 'folder' => $folderName, 'recent' => null];
    }
    if (trim((string)$account['imap_username']) === '') {
        return ['ok' => false, 'message' => 'IMAP username is not configured yet.', 'folder' => $folderName, 'recent' => null];
    }
    if (trim((string)($account['imap_secret'] ?? '')) === '') {
        return ['ok' => false, 'message' => 'IMAP password / app password is not configured yet.', 'folder' => $folderName, 'recent' => null];
    }

    $client = null;
    try {
        return imapQuiet(function () use ($account, $folderName, &$client) {
            $client = imapBuildClient($account);
            $client->connect();
            $folder = $client->getFolder($folderName);
            if (!$folder) {
                return ['ok' => false, 'message' => 'Folder not found: ' . $folderName, 'folder' => $folderName, 'recent' => null];
            }
            $count = $folder->query()->whereAll()->leaveUnread()->count();
            return ['ok' => true, 'message' => 'Connection successful.', 'folder' => $folderName, 'recent' => $count];
        });
    } catch (\Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage(), 'folder' => $folderName, 'recent' => null];
    } finally {
        try { $client?->disconnect(); } catch (\Throwable $de) { /* already gone */ }
    }
}

/**
 * Fetches up to $limit most recent messages from the last $sinceHours,
 * newest first. Returns ['ok'=>bool, 'error'=>string, 'messages'=>[...]]
 * where each message is:
 * ['uid','message_id','from_email','from_name','subject','received_at','body_text','body_html','attachments'=>[['name','mime','data'=>raw bytes]]]
 */
function imapFetchRecentMessages(array $account, int $limit, int $sinceHours): array {
    $folderName = trim((string)$account['folder']) !== '' ? $account['folder'] : 'INBOX';

    $client = null;
    try {
        return imapQuiet(function () use ($account, $folderName, $limit, $sinceHours, &$client) {
            $client = imapBuildClient($account);
            $client->connect();

            $folder = $client->getFolder($folderName);
            if (!$folder) {
                return ['ok' => false, 'error' => 'Folder not found: ' . $folderName, 'messages' => []];
            }

            $since = \Carbon\Carbon::now()->subHours(max(1, $sinceHours));
            $found = $folder->query()
                ->whereSince($since)
                ->leaveUnread()
                ->setFetchOrderDesc()
                ->limit(max(1, $limit))
                ->get();

            $messages = [];
            foreach ($found as $message) {
                $messageId = trim((string)$message->getMessageId());
                $uid = (string)$message->getUid();
                if ($messageId === '') {
                    $messageId = 'uid-' . $uid . '@' . $account['imap_host'];
                }

                $fromAttr = $message->getFrom();
                $fromFirst = $fromAttr ? $fromAttr->first() : null;
                $fromEmail = $fromFirst->mail ?? '';
                $fromName = $fromFirst->personal ?? '';

                $receivedAt = null;
                try {
                    $dateAttr = $message->getDate();
                    if ($dateAttr) {
                        $receivedAt = $dateAttr->toDate()->format('Y-m-d H:i:s');
                    }
                } catch (\Throwable $de) {
                    $receivedAt = null;
                }

                $bodyText = '';
                $bodyHtml = '';
                try { if ($message->hasTextBody()) { $bodyText = $message->getTextBody(); } } catch (\Throwable $be) {}
                try { if ($message->hasHTMLBody()) { $bodyHtml = $message->getHTMLBody(); } } catch (\Throwable $be) {}

                $attachments = [];
                try {
                    if ($message->hasAttachments()) {
                        foreach ($message->getAttachments() as $att) {
                            $name = (string)($att->getName() ?: $att->getFilename() ?: 'attachment');
                            $attachments[] = [
                                'name' => $name,
                                'mime' => (string)($att->getMimeType() ?: ''),
                                'data' => (string)$att->getContent(),
                            ];
                        }
                    }
                } catch (\Throwable $ae) {
                    error_log('imapFetchRecentMessages attachment read: ' . $ae->getMessage());
                }

                $messages[] = [
                    'uid'         => $uid,
                    'message_id'  => $messageId,
                    'from_email'  => $fromEmail,
                    'from_name'   => $fromName,
                    'subject'     => (string)$message->getSubject(),
                    'received_at' => $receivedAt,
                    'body_text'   => $bodyText,
                    'body_html'   => $bodyHtml,
                    'attachments' => $attachments,
                ];
            }

            return ['ok' => true, 'error' => '', 'messages' => $messages];
        });
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage(), 'messages' => []];
    } finally {
        try { $client?->disconnect(); } catch (\Throwable $de) { /* already gone */ }
    }
}

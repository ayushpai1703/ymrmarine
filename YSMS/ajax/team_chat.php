<?php
/**
 * Team Chat API — every action requires a logged-in, active Admin/Super Admin/
 * Surveyor. Reading/sending/downloading also requires current group
 * membership; membership changes require Admin/Super Admin.
 *
 * GET  action=bootstrap                         group, me, members, settings
 * GET  action=messages&group_id[&before_id]     newest page / older page
 * GET  action=poll&group_id&after_id            messages newer than after_id
 * GET  action=members&group_id
 * GET  action=candidates&group_id               (managers)
 * GET  action=download&id[&inline=1]            streams an attachment
 * POST action=send          group_id, text, client_token, [file]
 * POST action=mark_read     group_id, message_id
 * POST action=add_member    group_id, user_id   (managers)
 * POST action=remove_member group_id, user_id   (managers)
 * POST requests need csrf_token (field) or X-CSRF-Token (header).
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/chat_helpers.php';

function tc_json(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function tc_fail(string $error, int $code, string $errCode = 'error'): void {
    tc_json(['ok' => false, 'error' => $error, 'code' => $errCode], $code);
}

if (empty($_SESSION['user_id'])) {
    tc_fail('Your session has expired. Please log in again.', 401, 'auth');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$getActions = ['bootstrap', 'messages', 'poll', 'members', 'candidates', 'download'];
$postActions = ['send', 'mark_read', 'add_member', 'remove_member'];

if ($method === 'POST') {
    // post_max_size overflow empties $_POST/$_FILES entirely — report it as a size problem, not a CSRF one.
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        tc_fail('File is too large. Maximum size is ' . (int)round(teamChatMaxUploadBytes() / 1048576) . ' MB.', 413, 'too_large');
    }
    $action = (string)($_POST['action'] ?? '');
    if (!in_array($action, $postActions, true)) tc_fail('Unknown action.', 400, 'bad_action');
    $supplied = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $expected = csrf_token();
    if ($expected === '' || $supplied === '' || !hash_equals($expected, $supplied)) {
        tc_fail('Security token expired. Please refresh the page.', 403, 'csrf');
    }
} elseif ($method === 'GET') {
    $action = (string)($_GET['action'] ?? '');
    if (!in_array($action, $getActions, true)) tc_fail('Unknown action.', 400, 'bad_action');
} else {
    tc_fail('Method not allowed.', 405, 'method');
}

if ($action === 'poll') {
    $now = microtime(true);
    $last = (float)($_SESSION['tc_last_poll'] ?? 0);
    if ($now - $last < 1.0) {
        tc_fail('Too many requests.', 429, 'rate_limited');
    }
    $_SESSION['tc_last_poll'] = $now;
}

$userId = (int)$_SESSION['user_id'];
// Release the session lock so a long upload never blocks this user's polls.
session_write_close();

try {
    $db = getDB();
    teamChatUseUtf8mb4($db);
    // Every chat screen calls bootstrap first, so polls skip the schema check.
    if ($action === 'bootstrap') ensureTeamChatTables($db);
} catch (Throwable $e) {
    error_log('ajax/team_chat.php init: ' . $e->getMessage());
    tc_fail('Chat is temporarily unavailable. Please try again shortly.', 503, 'unavailable');
}

$me = getTeamChatUser($db, $userId);
if ($me === null) {
    tc_fail('You do not have access to Team Chat.', 403, 'forbidden');
}
$canManage = teamChatCanManage($me);

function tc_group(PDO $db, int $groupId): array {
    if ($groupId <= 0) tc_fail('Invalid group.', 400, 'bad_group');
    $stmt = $db->prepare("SELECT id, group_name FROM chat_groups WHERE id = ?");
    $stmt->execute([$groupId]);
    $g = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$g) tc_fail('Group not found.', 404, 'no_group');
    return $g;
}

function tc_require_member(PDO $db, int $groupId, int $userId): void {
    if (!teamChatIsMember($db, $groupId, $userId)) {
        tc_fail('You are not a member of this group.', 403, 'not_member');
    }
}

/** Loads messages + attachments; never exposes storage keys. */
function tc_fetch_messages(PDO $db, string $whereSql, array $params, string $order, int $limit): array {
    $limit = max(1, min($limit, 200));
    $stmt = $db->prepare("SELECT m.id, m.sender_id, m.message_text, m.created_at, u.full_name
        FROM chat_messages m LEFT JOIN users u ON u.id = m.sender_id
        WHERE $whereSql ORDER BY m.id $order LIMIT $limit");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return [];

    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $att = $db->prepare("SELECT id, message_id, original_filename, mime_type, file_size, uploaded_by, created_at
        FROM chat_attachments WHERE message_id IN ($in) ORDER BY id ASC");
    $att->execute($ids);
    $byMsg = [];
    foreach ($att->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $byMsg[(int)$a['message_id']][] = [
            'id' => (int)$a['id'],
            'name' => $a['original_filename'],
            'mime' => $a['mime_type'],
            'size' => (int)$a['file_size'],
            'is_image' => in_array($a['mime_type'], ['image/jpeg', 'image/png'], true),
            'type_label' => TEAM_CHAT_FILE_TYPES[strtolower(pathinfo($a['original_filename'], PATHINFO_EXTENSION))]['label'] ?? 'File',
            'created_ts' => strtotime($a['created_at']),
        ];
    }

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int)$r['id'],
            'sender_id' => (int)$r['sender_id'],
            'sender_name' => $r['full_name'] !== null ? $r['full_name'] : 'Former member',
            'text' => (string)($r['message_text'] ?? ''),
            'ts' => strtotime($r['created_at']),
            'attachments' => $byMsg[(int)$r['id']] ?? [],
        ];
    }
    return $out;
}

/** Avatar per distinct sender — one stat each, not one per message. */
function tc_sender_avatars(PDO $db, array $messages): array {
    $ids = array_values(array_unique(array_map(fn($m) => $m['sender_id'], $messages)));
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id, avatar, profile_pic FROM users WHERE id IN ($in)");
    $stmt->execute($ids);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $map[(string)$u['id']] = teamChatAvatarUrl($u['avatar'] ?? null, $u['profile_pic'] ?? null);
    }
    return $map;
}

function tc_member_count(PDO $db, int $groupId): int {
    $stmt = $db->prepare("SELECT COUNT(*) FROM chat_group_members WHERE group_id = ?");
    $stmt->execute([$groupId]);
    return (int)$stmt->fetchColumn();
}

try {
    switch ($action) {
        case 'bootstrap': {
            $groupId = getTeamChatDefaultGroupId($db, $canManage ? $userId : 0);
            $group = tc_group($db, $groupId);
            $isMember = teamChatIsMember($db, $groupId, $userId);
            $lastRead = 0;
            if ($isMember) {
                $stmt = $db->prepare("SELECT last_read_message_id FROM chat_group_members WHERE group_id = ? AND user_id = ?");
                $stmt->execute([$groupId, $userId]);
                $lastRead = (int)$stmt->fetchColumn();
            }
            tc_json([
                'ok' => true,
                'group' => ['id' => (int)$group['id'], 'name' => $group['group_name']],
                'me' => ['id' => $userId, 'name' => $me['full_name'], 'is_member' => $isMember, 'can_manage' => $canManage],
                'members' => ($isMember || $canManage) ? getTeamChatMembers($db, $groupId) : [],
                'last_read_message_id' => $lastRead,
                'settings' => [
                    'max_upload_bytes' => teamChatMaxUploadBytes(),
                    'allowed_ext' => array_keys(TEAM_CHAT_FILE_TYPES),
                    'max_text' => TEAM_CHAT_MAX_TEXT,
                    'page_size' => TEAM_CHAT_PAGE_SIZE,
                ],
            ]);
        }

        case 'messages': {
            $groupId = (int)($_GET['group_id'] ?? 0);
            tc_group($db, $groupId);
            tc_require_member($db, $groupId, $userId);
            $beforeId = (int)($_GET['before_id'] ?? 0);
            $where = 'm.group_id = ?';
            $params = [$groupId];
            if ($beforeId > 0) { $where .= ' AND m.id < ?'; $params[] = $beforeId; }
            $msgs = tc_fetch_messages($db, $where, $params, 'DESC', TEAM_CHAT_PAGE_SIZE + 1);
            $hasMore = count($msgs) > TEAM_CHAT_PAGE_SIZE;
            if ($hasMore) array_pop($msgs);
            $msgs = array_reverse($msgs);
            tc_json(['ok' => true, 'messages' => $msgs, 'avatars' => tc_sender_avatars($db, $msgs), 'has_more' => $hasMore]);
        }

        case 'poll': {
            $groupId = (int)($_GET['group_id'] ?? 0);
            tc_group($db, $groupId);
            tc_require_member($db, $groupId, $userId);
            $afterId = max(0, (int)($_GET['after_id'] ?? 0));
            $msgs = tc_fetch_messages($db, 'm.group_id = ? AND m.id > ?', [$groupId, $afterId], 'ASC', TEAM_CHAT_POLL_LIMIT);
            tc_json([
                'ok' => true,
                'messages' => $msgs,
                'avatars' => tc_sender_avatars($db, $msgs),
                'member_count' => tc_member_count($db, $groupId),
                'more' => count($msgs) >= TEAM_CHAT_POLL_LIMIT,
            ]);
        }

        case 'members': {
            $groupId = (int)($_GET['group_id'] ?? 0);
            tc_group($db, $groupId);
            if (!$canManage) tc_require_member($db, $groupId, $userId);
            tc_json(['ok' => true, 'members' => getTeamChatMembers($db, $groupId)]);
        }

        case 'candidates': {
            if (!$canManage) tc_fail('Only administrators can manage members.', 403, 'forbidden');
            $groupId = (int)($_GET['group_id'] ?? 0);
            tc_group($db, $groupId);
            $roles = TEAM_CHAT_ALLOWED_ROLES;
            $in = implode(',', array_fill(0, count($roles), '?'));
            $stmt = $db->prepare("SELECT u.id, u.full_name, r.name AS role_name FROM users u
                JOIN roles r ON r.id = u.role_id
                WHERE u.status = 'Active' AND r.name IN ($in)
                  AND u.id NOT IN (SELECT user_id FROM chat_group_members WHERE group_id = ?)
                ORDER BY u.full_name ASC");
            $stmt->execute(array_merge($roles, [$groupId]));
            $list = array_map(fn($u) => ['user_id' => (int)$u['id'], 'name' => $u['full_name'], 'role' => $u['role_name']],
                $stmt->fetchAll(PDO::FETCH_ASSOC));
            tc_json(['ok' => true, 'candidates' => $list]);
        }

        case 'download': {
            $attId = (int)($_GET['id'] ?? 0);
            if ($attId <= 0) tc_fail('Invalid file.', 400, 'bad_file');
            $stmt = $db->prepare("SELECT a.original_filename, a.private_storage_object_key, a.mime_type, a.file_size, m.group_id
                FROM chat_attachments a JOIN chat_messages m ON m.id = a.message_id WHERE a.id = ?");
            $stmt->execute([$attId]);
            $a = $stmt->fetch(PDO::FETCH_ASSOC);
            // Same response for "missing" and "not yours" so ids can't be probed.
            if (!$a || !teamChatIsMember($db, (int)$a['group_id'], $userId)) {
                tc_fail('File not found or access denied.', 404, 'no_file');
            }
            $path = teamChatObjectPath((string)$a['private_storage_object_key']);
            if ($path === null || !is_file($path)) {
                error_log('ajax/team_chat.php download: missing object for attachment ' . $attId);
                tc_fail('This file is no longer available.', 410, 'gone');
            }
            $mime = (string)$a['mime_type'];
            $isImage = in_array($mime, ['image/jpeg', 'image/png'], true);
            $inline = $isImage && !empty($_GET['inline']);
            $name = (string)$a['original_filename'];
            $ascii = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name) ?: 'file';
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($path));
            header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
            header('X-Content-Type-Options: nosniff');
            header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
            header('Cache-Control: private, no-store, max-age=0');
            header('Referrer-Policy: no-referrer');
            readfile($path);
            exit;
        }

        case 'send': {
            $groupId = (int)($_POST['group_id'] ?? 0);
            tc_group($db, $groupId);
            tc_require_member($db, $groupId, $userId);

            $text = str_replace(["\r\n", "\r"], "\n", (string)($_POST['text'] ?? ''));
            $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
            $text = trim($text);
            if (mb_strlen($text, 'UTF-8') > TEAM_CHAT_MAX_TEXT) {
                tc_fail('Message is too long (maximum ' . TEAM_CHAT_MAX_TEXT . ' characters).', 422, 'too_long');
            }
            $token = (string)($_POST['client_token'] ?? '');
            if ($token !== '' && !preg_match('/^[A-Za-z0-9-]{8,36}$/', $token)) {
                tc_fail('Invalid request.', 400, 'bad_token');
            }
            $hasFile = isset($_FILES['file']) && (int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            if ($text === '' && !$hasFile) tc_fail('Type a message or attach a file.', 422, 'empty');

            // Duplicate submit (double click / retry after a timeout) → return the original message.
            if ($token !== '') {
                $dup = $db->prepare("SELECT id FROM chat_messages WHERE sender_id = ? AND client_token = ? AND group_id = ?");
                $dup->execute([$userId, $token, $groupId]);
                $dupId = (int)$dup->fetchColumn();
                if ($dupId > 0) {
                    $msgs = tc_fetch_messages($db, 'm.id = ?', [$dupId], 'ASC', 1);
                    tc_json(['ok' => true, 'duplicate' => true, 'message' => $msgs[0] ?? null, 'avatars' => tc_sender_avatars($db, $msgs)]);
                }
            }

            $rl = $db->prepare("SELECT COUNT(*) FROM chat_messages WHERE sender_id = ? AND created_at > (NOW() - INTERVAL 60 SECOND)");
            $rl->execute([$userId]);
            if ((int)$rl->fetchColumn() >= TEAM_CHAT_MSG_PER_MINUTE) {
                tc_fail('You are sending messages too quickly. Please wait a moment.', 429, 'rate_limited');
            }

            $file = null;
            if ($hasFile) {
                $rlf = $db->prepare("SELECT COUNT(*) FROM chat_attachments WHERE uploaded_by = ? AND created_at > (NOW() - INTERVAL 10 MINUTE)");
                $rlf->execute([$userId]);
                if ((int)$rlf->fetchColumn() >= TEAM_CHAT_UPLOADS_PER_10MIN) {
                    tc_fail('Upload limit reached. Please wait a few minutes before sharing more files.', 429, 'rate_limited');
                }
                $file = teamChatValidateUpload($_FILES['file']);
                if (!$file['ok']) tc_fail($file['error'], 422, 'bad_file');
            }

            $objectKey = null;
            try {
                if ($file) {
                    $objectKey = teamChatStoreUpload($_FILES['file']['tmp_name']);
                }
                $db->beginTransaction();
                $ins = $db->prepare("INSERT INTO chat_messages (group_id, sender_id, message_text, client_token) VALUES (?, ?, ?, ?)");
                $ins->execute([$groupId, $userId, $text !== '' ? $text : null, $token !== '' ? $token : null]);
                $msgId = (int)$db->lastInsertId();
                if ($file) {
                    $ia = $db->prepare("INSERT INTO chat_attachments (message_id, original_filename, private_storage_object_key, mime_type, file_size, uploaded_by)
                        VALUES (?, ?, ?, ?, ?, ?)");
                    $ia->execute([$msgId, $file['name'], $objectKey, $file['mime'], $file['size'], $userId]);
                }
                $db->prepare("UPDATE chat_group_members SET last_read_message_id = GREATEST(last_read_message_id, ?) WHERE group_id = ? AND user_id = ?")
                   ->execute([$msgId, $groupId, $userId]);
                $db->commit();
            } catch (PDOException $e) {
                if ($db->inTransaction()) $db->rollBack();
                if ($objectKey) teamChatDeleteObject($objectKey);
                if ($token !== '' && ($e->errorInfo[1] ?? 0) == 1062) {
                    $dup = $db->prepare("SELECT id FROM chat_messages WHERE sender_id = ? AND client_token = ?");
                    $dup->execute([$userId, $token]);
                    $msgs = tc_fetch_messages($db, 'm.id = ?', [(int)$dup->fetchColumn()], 'ASC', 1);
                    tc_json(['ok' => true, 'duplicate' => true, 'message' => $msgs[0] ?? null, 'avatars' => tc_sender_avatars($db, $msgs)]);
                }
                throw $e;
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                if ($objectKey) teamChatDeleteObject($objectKey);
                error_log('ajax/team_chat.php send: ' . $e->getMessage());
                tc_fail($file ? 'The file could not be saved. Please try again.' : 'Message could not be sent. Please try again.', 500, 'send_failed');
            }
            $msgs = tc_fetch_messages($db, 'm.id = ?', [$msgId], 'ASC', 1);
            tc_json(['ok' => true, 'message' => $msgs[0] ?? null, 'avatars' => tc_sender_avatars($db, $msgs)]);
        }

        case 'mark_read': {
            $groupId = (int)($_POST['group_id'] ?? 0);
            tc_group($db, $groupId);
            tc_require_member($db, $groupId, $userId);
            $msgId = max(0, (int)($_POST['message_id'] ?? 0));
            $max = $db->prepare("SELECT COALESCE(MAX(id), 0) FROM chat_messages WHERE group_id = ?");
            $max->execute([$groupId]);
            $msgId = min($msgId, (int)$max->fetchColumn());
            $db->prepare("UPDATE chat_group_members SET last_read_message_id = GREATEST(last_read_message_id, ?) WHERE group_id = ? AND user_id = ?")
               ->execute([$msgId, $groupId, $userId]);
            tc_json(['ok' => true]);
        }

        case 'add_member':
        case 'remove_member': {
            if (!$canManage) tc_fail('Only administrators can manage members.', 403, 'forbidden');
            $groupId = (int)($_POST['group_id'] ?? 0);
            tc_group($db, $groupId);
            $targetId = (int)($_POST['user_id'] ?? 0);
            if ($targetId <= 0) tc_fail('Select a user.', 422, 'bad_user');

            if ($action === 'add_member') {
                if (getTeamChatUser($db, $targetId) === null) {
                    tc_fail('This user cannot be added (inactive or not a staff account).', 422, 'bad_user');
                }
                $max = $db->prepare("SELECT COALESCE(MAX(id), 0) FROM chat_messages WHERE group_id = ?");
                $max->execute([$groupId]);
                $ins = $db->prepare("INSERT IGNORE INTO chat_group_members (group_id, user_id, added_by, last_read_message_id) VALUES (?, ?, ?, ?)");
                $ins->execute([$groupId, $targetId, $userId, (int)$max->fetchColumn()]);
                if ($ins->rowCount() === 0) tc_fail('This user is already a member.', 409, 'duplicate');
            } else {
                $del = $db->prepare("DELETE FROM chat_group_members WHERE group_id = ? AND user_id = ?");
                $del->execute([$groupId, $targetId]);
                if ($del->rowCount() === 0) tc_fail('This user is not a member.', 404, 'not_member');
            }
            tc_json(['ok' => true, 'members' => getTeamChatMembers($db, $groupId)]);
        }
    }
} catch (Throwable $e) {
    error_log('ajax/team_chat.php ' . $action . ': ' . $e->getMessage());
    tc_fail('Something went wrong. Please try again.', 500, 'server');
}
tc_fail('Unknown action.', 400, 'bad_action');

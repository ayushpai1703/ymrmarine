<?php
/**
 * Inbox / Team Chat links for the profile menus (includes/profile_dropdown.php — the profile
 * picture at the top — and the profile block at the bottom of the sidebar, includes/nav.php).
 * Inbox: Admin and Surveyor. Team Chat: members, plus Admin / Super Admin always (they set up
 * membership). Never Clients or Sales. Returns unread counts; null = the link is not shown.
 */
function userMenuState(): array {
    static $state = null;
    if ($state !== null) return $state;
    $state = ['inbox' => null, 'chat' => null];
    $role = $_SESSION['role'] ?? '';
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if (!$uid) return $state;
    try {
        $db = getDB();
        if (in_array($role, ['Admin', 'Surveyor'], true)) {
            if (!function_exists('getUnreadNotificationCount')) require_once __DIR__ . '/notifications.php';
            $state['inbox'] = (int)getUnreadNotificationCount($db, $uid);
        }
        if (in_array($role, ['Admin', 'Super Admin', 'Surveyor'], true)) {
            require_once __DIR__ . '/chat_helpers.php';
            $chat = getTeamChatNavState($db, $uid);
            if ($chat['member'] || in_array($role, ['Admin', 'Super Admin'], true)) $state['chat'] = (int)$chat['unread'];
        }
    } catch (Throwable $e) {
        error_log('user_menu.php: ' . $e->getMessage());
    }
    return $state;
}

function userMenuCount(?int $n): string {
    return $n ? '<b class="pm-count">' . ($n > 99 ? '99+' : $n) . '</b>' : '';
}

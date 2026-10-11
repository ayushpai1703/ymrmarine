<?php
require_once 'config/config.php';
require_once 'includes/notifications.php';
checkAuth();

const INBOX_ROLES = ['Admin', 'Surveyor'];
const INBOX_PAGE_SIZE = 30;

if (!in_array($_SESSION['role'] ?? '', INBOX_ROLES, true)) {
    header('Location: index.php');
    exit;
}

$db = getDB();
$userId = (int)$_SESSION['user_id'];

// Opening a notification marks it read, then follows its (same-app) link.
if (isset($_GET['open'])) {
    $notifId = (int)$_GET['open'];
    $stmt = $db->prepare("SELECT link FROM notifications WHERE id = ? AND user_id = ?");
    $stmt->execute([$notifId, $userId]);
    $link = $stmt->fetchColumn();
    if ($link !== false) {
        markNotificationRead($db, $userId, $notifId);
    }
    $target = $link !== false ? safeNotificationLink($link) : null;
    header('Location: ' . ($target ?? 'inbox.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_all_read') {
    markAllNotificationsRead($db, $userId);
    $_SESSION['flash_msg'] = 'All notifications marked as read';
    header('Location: inbox.php' . (($_POST['filter'] ?? '') === 'unread' ? '?filter=unread' : ''));
    exit;
}

$filter = ($_GET['filter'] ?? '') === 'unread' ? 'unread' : 'all';
$page = max(1, (int)($_GET['page'] ?? 1));
$unreadCount = getUnreadNotificationCount($db, $userId);
$totalCount = $filter === 'unread' ? $unreadCount : getNotificationCount($db, $userId);
$pages = max(1, (int)ceil($totalCount / INBOX_PAGE_SIZE));
$page = min($page, $pages);
$items = getInboxNotifications($db, $userId, INBOX_PAGE_SIZE, ($page - 1) * INBOX_PAGE_SIZE, $filter === 'unread');

function inboxUrl(string $filter, int $page): string {
    $q = [];
    if ($filter === 'unread') $q['filter'] = 'unread';
    if ($page > 1) $q['page'] = $page;
    return 'inbox.php' . ($q ? '?' . http_build_query($q) : '');
}

$typeColors = [
    'assign' => ['#1e3a8a', '#e0e7ff'], 'upload' => ['#047857', '#d1fae5'], 'report' => ['#047857', '#d1fae5'],
    'cancel' => ['#b91c1c', '#fee2e2'], 'delete' => ['#b91c1c', '#fee2e2'], 'edit' => ['#b45309', '#fef3c7'],
    'status' => ['#7c3aed', '#ede9fe'], 'format' => ['#15803d', '#dcfce7'], 'expense' => ['#0e7490', '#cffafe'],
];

include 'includes/header.php';
?>
<style>
    .inbox-wrap { padding: 16px; max-width: 860px; margin: 0 auto; }
    .inbox-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
    .inbox-tabs { display: inline-flex; background: #fff; border: 1px solid var(--border-color); border-radius: 12px; padding: 4px; gap: 4px; }
    .inbox-tab { padding: 7px 14px; border-radius: 9px; font-size: 13px; font-weight: 600; color: #475569; text-decoration: none; }
    .inbox-tab.active { background: #1e3a8a; color: #fff; }
    .inbox-tab .count { display: inline-block; min-width: 20px; padding: 0 6px; margin-left: 6px; border-radius: 999px; background: #dc2626; color: #fff; font-size: 11px; line-height: 18px; text-align: center; }
    .inbox-markall { border: 1px solid var(--border-color); background: #fff; color: #1e3a8a; border-radius: 10px; padding: 8px 14px; font-size: 13px; font-weight: 600; }
    .inbox-markall:disabled { opacity: .5; }
    .inbox-list { background: #fff; border: 1px solid var(--border-color); border-radius: 16px; overflow: hidden; }
    .inbox-item { display: flex; gap: 12px; padding: 14px 16px; border-bottom: 1px solid #f1f5f9; text-decoration: none; color: var(--text-dark); position: relative; transition: background .15s; }
    .inbox-item:last-child { border-bottom: none; }
    .inbox-item:hover, .inbox-item:focus-visible { background: #f8fafc; outline: none; }
    .inbox-item.unread { background: #f5f7ff; }
    .inbox-item.unread:hover { background: #eef2ff; }
    .inbox-icon { width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 16px; }
    .inbox-body { flex: 1; min-width: 0; }
    .inbox-title { font-size: 14px; font-weight: 600; line-height: 1.35; }
    .inbox-item.unread .inbox-title { font-weight: 700; }
    .inbox-msg { font-size: 13px; color: #475569; margin-top: 2px; line-height: 1.4; overflow-wrap: anywhere; }
    .inbox-meta { font-size: 11.5px; color: #94a3b8; margin-top: 4px; }
    .inbox-dot { width: 9px; height: 9px; border-radius: 50%; background: #2563eb; flex-shrink: 0; margin-top: 6px; }
    .inbox-empty { text-align: center; padding: 56px 20px; color: #64748b; }
    .inbox-empty i { font-size: 40px; color: #cbd5e1; margin-bottom: 12px; display: block; }
    .inbox-pager { display: flex; justify-content: space-between; align-items: center; margin-top: 14px; font-size: 13px; color: #64748b; }
    .inbox-pager a { color: #1e3a8a; font-weight: 600; text-decoration: none; }
    @media (min-width: 992px) { .inbox-wrap { padding: 24px 0; } }
</style>

<div class="scroll-content">
    <?php $page_title = 'Inbox'; $back_url = 'index.php'; $page_testid = 'inbox'; include 'includes/top_app_bar.php'; ?>

    <div class="inbox-wrap" data-testid="inbox-page">
        <div class="inbox-toolbar">
            <nav class="inbox-tabs" aria-label="Filter notifications">
                <a href="<?= inboxUrl('all', 1) ?>" class="inbox-tab <?= $filter === 'all' ? 'active' : '' ?>" data-testid="inbox-tab-all">All</a>
                <a href="<?= inboxUrl('unread', 1) ?>" class="inbox-tab <?= $filter === 'unread' ? 'active' : '' ?>" data-testid="inbox-tab-unread">Unread<?php if ($unreadCount > 0): ?><span class="count"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span><?php endif; ?></a>
            </nav>
            <form method="POST" action="inbox.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="mark_all_read">
                <input type="hidden" name="filter" value="<?= $filter ?>">
                <button type="submit" class="inbox-markall" <?= $unreadCount === 0 ? 'disabled' : '' ?> data-testid="inbox-mark-all-read"><i class="fa-solid fa-check-double me-1"></i> Mark all as read</button>
            </form>
        </div>

        <?php if (!$items): ?>
            <div class="inbox-list">
                <div class="inbox-empty" data-testid="inbox-empty">
                    <i class="fa-regular fa-bell"></i>
                    <div class="fw-semibold text-dark"><?= $filter === 'unread' ? 'No unread notifications' : 'Your inbox is empty' ?></div>
                    <div class="small mt-1"><?= $filter === 'unread' ? "You're all caught up." : 'New assignments, uploads and updates will appear here.' ?></div>
                </div>
            </div>
        <?php else: ?>
            <div class="inbox-list" role="list">
                <?php foreach ($items as $n):
                    $unread = (int)$n['is_read'] === 0;
                    $colors = $typeColors[$n['type']] ?? ['#475569', '#f1f5f9'];
                    $hasLink = safeNotificationLink($n['link']) !== null;
                    $tag = $hasLink || $unread ? 'a' : 'div';
                    $ts = strtotime((string)$n['created_at']);
                ?>
                <<?= $tag ?> class="inbox-item<?= $unread ? ' unread' : '' ?>" role="listitem"
                    <?php if ($tag === 'a'): ?>href="inbox.php?open=<?= (int)$n['id'] ?>"<?php endif; ?>
                    data-testid="inbox-item-<?= (int)$n['id'] ?>">
                    <div class="inbox-icon" style="color: <?= $colors[0] ?>; background: <?= $colors[1] ?>;" aria-hidden="true">
                        <i class="fa-solid <?= notificationIcon((string)$n['type']) ?>"></i>
                    </div>
                    <div class="inbox-body">
                        <div class="inbox-title"><?= sanitize((string)$n['title']) ?></div>
                        <?php if (trim((string)$n['message']) !== ''): ?>
                            <div class="inbox-msg"><?= sanitize((string)$n['message']) ?></div>
                        <?php endif; ?>
                        <div class="inbox-meta">
                            <?php if (!empty($n['creator_name'])): ?>By <?= sanitize($n['creator_name']) ?> · <?php endif; ?>
                            <span title="<?= $ts ? date('d M Y, h:i A', $ts) : '' ?>"><?= sanitize(notificationTimeAgo($n['created_at'])) ?></span>
                        </div>
                    </div>
                    <?php if ($unread): ?><span class="inbox-dot" title="Unread"></span><span class="visually-hidden">Unread</span><?php endif; ?>
                </<?= $tag ?>>
                <?php endforeach; ?>
            </div>

            <?php if ($pages > 1): ?>
            <div class="inbox-pager">
                <span><?php if ($page > 1): ?><a href="<?= inboxUrl($filter, $page - 1) ?>"><i class="fa-solid fa-chevron-left"></i> Newer</a><?php endif; ?></span>
                <span>Page <?= $page ?> of <?= $pages ?></span>
                <span><?php if ($page < $pages): ?><a href="<?= inboxUrl($filter, $page + 1) ?>">Older <i class="fa-solid fa-chevron-right"></i></a><?php endif; ?></span>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>

<?php
$current_page = basename($_SERVER['PHP_SELF']);
// సెషన్‌లో ఉన్న యూజర్ రోల్‌ను తెచ్చుకోవడం (Admin లేదా Surveyor)
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$sidebar_user_name = isset($_SESSION['full_name']) ? $_SESSION['full_name'] : (isset($_SESSION['username']) ? $_SESSION['username'] : 'User');

// Sidebar footer profile photo (DB → session → initial letter fallback)
$sidebar_avatar = null;
if (!empty($_SESSION['user_id'])) {
    try {
        $db_nav = getDB();
        $stmt_nav = $db_nav->prepare("SELECT avatar, profile_pic FROM users WHERE id = ? LIMIT 1");
        $stmt_nav->execute([$_SESSION['user_id']]);
        $nav_user_row = $stmt_nav->fetch(PDO::FETCH_ASSOC);
        $fresh = null;
        if (!empty($nav_user_row['avatar'])) {
            $fresh = $nav_user_row['avatar'];
        } elseif (!empty($nav_user_row['profile_pic'])) {
            $fresh = $nav_user_row['profile_pic'];
        }
        if ($fresh && strpos($fresh, 'uploads/') === 0 && file_exists(__DIR__ . '/../' . $fresh)) {
            $sidebar_avatar = $fresh;
            $_SESSION['avatar'] = $fresh;
        }
    } catch (Exception $e) {
        if (!empty($_SESSION['avatar']) && strpos($_SESSION['avatar'], 'uploads/') === 0 && file_exists(__DIR__ . '/../' . $_SESSION['avatar'])) {
            $sidebar_avatar = $_SESSION['avatar'];
        }
    }
}
// Optional company logo: place file at assets/images/logo.png to replace the anchor icon
$sidebar_logo_path = 'assets/images/logo.png';
$sidebar_has_logo = is_file(__DIR__ . '/../' . $sidebar_logo_path);
?>

<div class="bottom-nav-bar" data-testid="global-navigation">
    <!-- Brand (desktop sidebar only) — logo image if present, else anchor icon -->
    <div class="desktop-sidebar-brand" data-testid="desktop-sidebar-brand">
        <div class="desktop-sidebar-mark<?= $sidebar_has_logo ? ' has-logo' : '' ?>">
            <?php if ($sidebar_has_logo): ?>
                <img src="<?= sanitize($sidebar_logo_path) ?>" alt="YMR Survey Management Logo" class="desktop-sidebar-logo-img">
            <?php else: ?>
                <i class="fa-solid fa-anchor" aria-hidden="true"></i>
            <?php endif; ?>
        </div>
        <div class="desktop-sidebar-brand-text">
            <b>YMR</b>
            <span>SURVEY MANAGEMENT</span>
        </div>
        <button type="button" class="drawer-close-btn" id="drawerCloseBtn" aria-label="Close navigation menu" data-testid="drawer-close-button">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>

    <div class="desktop-sidebar-nav-scroll">
        <?php
        // Inbox (Admin, Surveyor): every in-app notification, with an unread badge.
        // Team Chat: members see it with an unread badge; Admin/Super Admin
        // always see it so they can set up membership. Never shown to Clients.
        $inbox_unread = null;
        if (!empty($_SESSION['user_id']) && in_array($user_role, ['Admin', 'Surveyor'], true)) {
            if (!function_exists('getUnreadNotificationCount')) {
                require_once __DIR__ . '/notifications.php';
            }
            $inbox_unread = getUnreadNotificationCount(isset($db_nav) ? $db_nav : getDB(), (int)$_SESSION['user_id']);
        }
        $team_chat_nav = null;
        if (!empty($_SESSION['user_id']) && in_array($user_role, ['Admin', 'Super Admin', 'Surveyor'], true)) {
            require_once __DIR__ . '/chat_helpers.php';
            $team_chat_nav = getTeamChatNavState(isset($db_nav) ? $db_nav : getDB(), (int)$_SESSION['user_id']);
            if (!$team_chat_nav['member'] && !in_array($user_role, ['Admin', 'Super Admin'], true)) {
                $team_chat_nav = null;
            }
        }
        $nav_unread_dot = ($inbox_unread > 0 && $current_page !== 'inbox.php')
            || ($team_chat_nav !== null && $team_chat_nav['unread'] > 0 && $current_page !== 'team_chat.php');
        if ($inbox_unread !== null || $team_chat_nav !== null):
        ?>
        <div class="desktop-sidebar-section-label desktop-only-nav drawer-reveal">Messages</div>
        <?php if ($inbox_unread !== null): ?>
        <a href="inbox.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'inbox.php') ? 'active' : '' ?>" data-testid="sidebar-inbox-link">
            <i class="fa-solid fa-inbox"></i>
            <span>Inbox</span>
            <?php if ($inbox_unread > 0): ?>
                <span id="inboxNavBadge" class="badge rounded-pill bg-danger ms-auto" aria-label="<?= (int)$inbox_unread ?> unread notifications"><?= $inbox_unread > 99 ? '99+' : (int)$inbox_unread ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>
        <?php if ($team_chat_nav !== null): ?>
        <a href="team_chat.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'team_chat.php') ? 'active' : '' ?>" data-testid="sidebar-team-chat-link">
            <i class="fa-solid fa-comments"></i>
            <span>Team Chat</span>
            <?php if ($team_chat_nav['unread'] > 0): ?>
                <span id="tcNavBadge" class="badge rounded-pill bg-danger ms-auto" aria-label="<?= (int)$team_chat_nav['unread'] ?> unread messages"><?= $team_chat_nav['unread'] > 99 ? '99+' : (int)$team_chat_nav['unread'] ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>
        <?php if ($nav_unread_dot): ?>
        <script>
        (function () {
            var b = document.querySelector('.hamburger-menu-btn');
            if (!b || b.querySelector('.tc-hb-dot')) return;
            var d = document.createElement('span');
            d.className = 'tc-hb-dot';
            d.setAttribute('aria-hidden', 'true');
            d.style.cssText = 'position:absolute;top:4px;right:4px;width:9px;height:9px;border-radius:50%;background:#dc2626;border:2px solid #fff;';
            if (getComputedStyle(b).position === 'static') b.style.position = 'relative';
            b.appendChild(d);
            b.setAttribute('aria-label', 'Open navigation menu (unread messages)');
        })();
        </script>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($user_role === 'Sales'): ?>
        <!-- Sales role (includes/sales.php): only its own pages; everything else is refused by config.php -->
        <div class="desktop-sidebar-section-label drawer-reveal">Sales</div>
        <a href="sales_dashboard.php" class="nav-item-btn <?= ($current_page == 'sales_dashboard.php') ? 'active' : '' ?>" data-testid="navigation-sales-dashboard-link">
            <i class="fa-solid fa-chart-line"></i>
            <span>Dashboard</span>
        </a>
        <a href="sales_clients.php" class="nav-item-btn <?= ($current_page == 'sales_clients.php') ? 'active' : '' ?>" data-testid="navigation-sales-clients-link">
            <i class="fa-solid fa-building"></i>
            <span>Clients</span>
        </a>
        <a href="sales_surveys.php" class="nav-item-btn <?= ($current_page == 'sales_surveys.php') ? 'active' : '' ?>" data-testid="navigation-sales-surveys-link">
            <i class="fa-solid fa-ship"></i>
            <span>Surveys</span>
        </a>
        <a href="assign_vessel.php" class="nav-item-btn <?= ($current_page == 'assign_vessel.php') ? 'active' : '' ?>" data-testid="navigation-sales-request-link">
            <i class="fa-solid fa-paper-plane"></i>
            <span>Request Survey</span>
        </a>
        <?php else: ?>
        <div class="desktop-sidebar-section-label drawer-reveal">Main</div>
        <a href="index.php" class="nav-item-btn <?= ($current_page == 'index.php') ? 'active' : '' ?>" data-testid="navigation-home-link">
            <i class="fa-solid fa-house"></i>
            <span>Home</span>
        </a>
        <a href="vessels.php" class="nav-item-btn <?= ($current_page == 'vessels.php') ? 'active' : '' ?>" data-testid="navigation-vessels-link">
            <i class="fa-solid fa-ship"></i>
            <span class="bn-long">Pending Vessels</span><span class="bn-short">Vessels</span>
        </a>

        <?php
        // Mobile only FAB — desktop uses expanded sidebar links below.
        // Admin, Super Admin and Client all get the FAB (its content in
        // includes/footer.php is role-aware per role: Super Admin sees Add
        // Client + Add Admin, Client sees Request Survey).
        if (in_array($user_role, ['Admin', 'Super Admin', 'Client'], true)):
        ?>
            <button class="center-fab-btn" id="globalFabTrigger" aria-label="Quick actions" aria-expanded="false" data-testid="global-add-menu-button">
                <i class="fa-solid fa-plus"></i>
            </button>
        <?php
        endif;
        ?>

        <a href="reports.php" class="nav-item-btn <?= ($current_page == 'reports.php') ? 'active' : '' ?>" data-testid="navigation-reports-link">
            <i class="fa-solid fa-file-invoice"></i>
            <span class="bn-long">Pending Reports</span><span class="bn-short">Reports</span>
        </a>
        <?php if ($user_role === 'Admin'): ?>
        <!-- Invoices (Admin): sidebar + drawer only, so the phone bottom bar keeps its 5 slots -->
        <a href="invoices.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'invoices.php') ? 'active' : '' ?>" data-testid="navigation-invoices-link">
            <i class="fa-solid fa-file-invoice-dollar"></i>
            <span>Invoices</span>
            <b class="badge rounded-pill ms-auto nav-tag-badge" style="background:#16A34A;font-size:9.5px;letter-spacing:.04em" data-testid="nav-invoices-new-badge">NEW</b>
        </a>
        <a href="invoices_airtable.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= in_array($current_page, ['invoices_airtable.php', 'invoice_table.php'], true) ? 'active' : '' ?>" data-testid="navigation-invoice-airtable-link">
            <i class="fa-solid fa-table-cells"></i>
            <span style="white-space:nowrap">Invoice Airtable</span>
            <b class="badge rounded-pill ms-auto nav-tag-badge" style="background:#F59E0B;color:#1f2937;font-size:8.5px;letter-spacing:.03em;padding:3px 6px" data-testid="nav-airtable-testing-badge">TESTING</b>
        </a>
        <?php endif; ?>
        <a href="completed.php" class="nav-item-btn <?= ($current_page == 'completed.php') ? 'active' : '' ?>" data-testid="navigation-completed-link">
            <i class="fa-solid fa-circle-check"></i>
            <span>Completed</span>
        </a>
        <?php
        // Admin, Super Admin and Client: hide Cancelled on mobile bottom bar
        // only — the bar there is Home / Pending Vessels / FAB / Reports /
        // Completed — while keeping it reachable via the sidebar (drawer +
        // desktop). Surveyor: always show (same as before).
        $cancelled_bn_class = in_array($user_role, ['Admin', 'Super Admin', 'Client'], true) ? ' hide-cancelled-on-mobile-bn' : '';
        ?>
        <a href="cancelled.php" class="nav-item-btn<?= $cancelled_bn_class ?> <?= ($current_page == 'cancelled.php') ? 'active' : '' ?>" data-testid="navigation-cancelled-link">
            <i class="fa-solid fa-ban"></i>
            <span>Cancelled</span>
        </a>
        <a href="recovery.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'recovery.php') ? 'active' : '' ?>" data-testid="navigation-recovery-link">
            <i class="fa-solid fa-gas-pump"></i>
            <span>Recovery</span>
        </a>

        <?php if ($user_role === 'Admin'): ?>
        <!-- Desktop sidebar + mobile drawer: FAB Quick Actions as menu links -->
        <div class="desktop-sidebar-section-label desktop-only-nav drawer-reveal">Quick Actions</div>
        <a href="assign_vessel.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'assign_vessel.php') ? 'active' : '' ?>" data-testid="sidebar-assign-vessel-link">
            <i class="fa-solid fa-ship"></i>
            <span>Assign Vessel</span>
        </a>
        <a href="add_surveyor.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'add_surveyor.php') ? 'active' : '' ?>" data-testid="sidebar-add-surveyor-link">
            <i class="fa-solid fa-user-gear"></i>
            <span>Add Surveyor</span>
        </a>
        <a href="add_client.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'add_client.php') ? 'active' : '' ?>" data-testid="sidebar-add-client-link">
            <i class="fa-solid fa-user-plus"></i>
            <span>Add Client</span>
        </a>
        <a href="admin_controls.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'admin_controls.php') ? 'active' : '' ?>" data-testid="sidebar-admin-controls-link">
            <i class="fa-solid fa-user-shield"></i>
            <span>Admin Controls</span>
        </a>
        <a href="admin_sales.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'admin_sales.php') ? 'active' : '' ?>" data-testid="sidebar-sales-team-link">
            <i class="fa-solid fa-user-tie"></i>
            <span>Sales Team</span>
        </a>

        <!-- Inside the navbar.php file, inside the <ul> tag, add this li: -->

    <a href="manage_templates.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'manage_templates.php') ? 'active' : '' ?>" data-testid="sidebar-manage-templates-link">
        <i class="fa-solid fa-file-contract"></i>
        <span>Manage Templates</span>
    </a>
        <a href="login_activity.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'login_activity.php') ? 'active' : '' ?>" data-testid="sidebar-login-activity-link">
            <i class="fa-solid fa-shield-halved"></i>
            <span>Login Activity</span>
        </a>
        <a href="possible_duplicates.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'possible_duplicates.php') ? 'active' : '' ?>" data-testid="sidebar-possible-duplicates-link">
            <i class="fa-solid fa-clone"></i>
            <span>Possible Duplicates</span>
        </a>

        <?php elseif ($user_role === 'Client'): ?>
        <!-- Desktop sidebar + mobile drawer: Client-only section. Request
             Survey mirrors the mobile FAB's "Request Survey" option so it's
             reachable from the desktop sidebar too (desktop hides the FAB
             entirely — see the "+" trigger comment below). -->
        <div class="desktop-sidebar-section-label desktop-only-nav drawer-reveal">Client</div>
        <a href="assign_vessel.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'assign_vessel.php') ? 'active' : '' ?>" data-testid="sidebar-request-survey-link">
            <i class="fa-solid fa-ship"></i>
            <span>Request Survey</span>
        </a>
        <?php elseif ($user_role === 'Super Admin'): ?>
        <!-- Desktop sidebar + mobile drawer: Super Admin Quick Actions — Add Client +
             Add Admin only (Assign Vessel / Admin Controls / Manage Templates /
             Add Surveyor stay Admin-only, both here and server-side on those pages). -->
        <div class="desktop-sidebar-section-label desktop-only-nav drawer-reveal">Quick Actions</div>
        <a href="add_client.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'add_client.php') ? 'active' : '' ?>" data-testid="sidebar-add-client-link">
            <i class="fa-solid fa-user-plus"></i>
            <span>Add Client</span>
        </a>
        <a href="add_admin.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'add_admin.php') ? 'active' : '' ?>" data-testid="sidebar-add-admin-link">
            <i class="fa-solid fa-user-shield"></i>
            <span>Add Admin</span>
        </a>
        <a href="super_admin_clients.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'super_admin_clients.php') ? 'active' : '' ?>" data-testid="sidebar-manage-clients-link">
            <i class="fa-solid fa-building"></i>
            <span>Manage Clients</span>
        </a>
        <a href="login_activity.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'login_activity.php') ? 'active' : '' ?>" data-testid="sidebar-login-activity-link">
            <i class="fa-solid fa-shield-halved"></i>
            <span>Login Activity</span>
        </a>
        <!-- 🌟 TEMPORARY — remove this link (and super_admin_controls.php) once
             go-live test-data cleanup is done. -->
        <a href="super_admin_controls.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'super_admin_controls.php') ? 'active' : '' ?>" data-testid="sidebar-data-cleanup-link">
            <i class="fa-solid fa-trash"></i>
            <span>Data Cleanup (Temp)</span>
        </a>
        <?php endif; ?>

        <?php endif; /* not Sales */ ?>

        <?php if ($user_role === 'Admin'): ?>
        <!-- Admin: sidebar "My Profile" is replaced with a shortcut to Formats
             (upload/manage the format files Surveyors see on formats_download.php). -->
        <div class="desktop-sidebar-section-label desktop-only-nav drawer-reveal">Formats</div>
        <a href="formats_download.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'formats_download.php') ? 'active' : '' ?>" data-testid="sidebar-formats-link">
            <i class="fa-solid fa-file-excel"></i>
            <span>Formats</span>
        </a>
        <?php else: ?>
        <div class="desktop-sidebar-section-label desktop-only-nav">Account</div>
        <a href="profile.php" class="nav-item-btn desktop-only-nav <?= ($current_page == 'profile.php') ? 'active' : '' ?>" data-testid="sidebar-profile-link">
            <i class="fa-solid fa-user"></i>
            <span>My Profile</span>
        </a>
        <?php endif; ?>
    </div>

    <!-- Desktop only: profile photo + name (click → profile) + logout at bottom of sidebar -->
    <div class="desktop-sidebar-footer" data-testid="desktop-sidebar-footer">
        <a href="profile.php" class="desktop-sidebar-user" data-testid="sidebar-profile-user-link" title="My Profile">
            <?php if ($sidebar_avatar): ?>
                <img src="<?= sanitize($sidebar_avatar) ?>" alt="Profile" class="desktop-sidebar-user-avatar-img">
            <?php else: ?>
                <div class="desktop-sidebar-user-avatar"><?= strtoupper(substr($sidebar_user_name, 0, 1)) ?></div>
            <?php endif; ?>
            <div class="desktop-sidebar-user-meta">
                <div class="desktop-sidebar-user-name" title="<?= htmlspecialchars($sidebar_user_name, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($sidebar_user_name, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="desktop-sidebar-user-role"><?= htmlspecialchars($user_role ?: 'User', ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </a>
        <a href="coming_soon.php?feature=Support" class="mobile-drawer-support-link" data-testid="drawer-support-link">
            <i class="fa-solid fa-circle-question"></i>
            <span>Support</span>
        </a>
        <a href="logout.php" class="desktop-sidebar-logout" data-testid="sidebar-logout-link">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
            <span>Logout</span>
        </a>
    </div>
</div>

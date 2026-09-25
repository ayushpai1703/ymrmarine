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

        <!-- ═══════════════ PROFILE (mobile drawer only) ═══════════════ -->
        <div class="drawer-section-label drawer-only-nav drawer-reveal">Profile</div>
        <a href="profile.php" class="nav-item-btn drawer-profile-row drawer-only-nav drawer-reveal" data-testid="drawer-profile-link" aria-label="User Profile: <?= htmlspecialchars($sidebar_user_name, ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars($user_role ?: 'User', ENT_QUOTES, 'UTF-8') ?>">
            <?php if ($sidebar_avatar): ?>
                <img src="<?= sanitize($sidebar_avatar) ?>" alt="" class="drawer-profile-avatar-img">
            <?php else: ?>
                <div class="drawer-profile-avatar" aria-hidden="true"><?= strtoupper(substr($sidebar_user_name, 0, 1)) ?></div>
            <?php endif; ?>
            <div class="drawer-profile-meta">
                <div class="drawer-profile-eyebrow">User Profile</div>
                <div class="drawer-profile-name"><?= htmlspecialchars($sidebar_user_name, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="drawer-profile-role"><?= htmlspecialchars($user_role ?: 'User', ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </a>

        <!-- ═══════════════ MAIN MENU ═══════════════ -->
        <div class="desktop-sidebar-section-label drawer-reveal">Main Menu</div>
        <a href="index.php" class="nav-item-btn <?= ($current_page == 'index.php') ? 'active' : '' ?>" data-testid="navigation-home-link">
            <i class="fa-solid fa-house"></i>
            <span>Dashboard</span>
        </a>
        <a href="vessels.php" class="nav-item-btn <?= ($current_page == 'vessels.php') ? 'active' : '' ?>" data-testid="navigation-vessels-link">
            <i class="fa-solid fa-ship"></i>
            <span>Pending Vessels</span>
        </a>

        <?php
        // Mobile only FAB — desktop uses expanded sidebar links below.
        // Admin, Super Admin and Client all get the FAB (its content in
        // includes/footer.php is role-aware per role: Super Admin sees Add
        // Client + Add Admin, Client sees Request Survey).
        if (in_array($user_role, ['Admin', 'Super Admin', 'Client'], true)):
        ?>
            <button class="center-fab-btn" id="globalFabTrigger" data-testid="global-add-menu-button">
                <i class="fa-solid fa-plus"></i>
            </button>
        <?php
        endif;
        ?>

        <a href="reports.php" class="nav-item-btn <?= ($current_page == 'reports.php') ? 'active' : '' ?>" data-testid="navigation-reports-link">
            <i class="fa-solid fa-file-invoice"></i>
            <span>Pending Reports</span>
        </a>
        <a href="completed.php" class="nav-item-btn <?= ($current_page == 'completed.php') ? 'active' : '' ?>" data-testid="navigation-completed-link">
            <i class="fa-solid fa-circle-check"></i>
            <span>Completed Vessels</span>
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

        <!-- Mobile drawer only — no dedicated page yet, routed to the
             existing generic "Coming Soon" placeholder (same pattern already
             used elsewhere in the app for not-yet-built features). -->
        <a href="coming_soon.php?feature=Vessel+Search" class="nav-item-btn drawer-only-nav drawer-reveal" data-testid="drawer-vessel-search-link">
            <i class="fa-solid fa-magnifying-glass"></i>
            <span>Vessel Search</span>
        </a>
        <a href="coming_soon.php?feature=Survey+Reports" class="nav-item-btn drawer-only-nav drawer-reveal" data-testid="drawer-survey-reports-link">
            <i class="fa-solid fa-chart-line"></i>
            <span>Survey Reports</span>
        </a>
        <a href="coming_soon.php?feature=Templates" class="nav-item-btn drawer-only-nav drawer-reveal" data-testid="drawer-templates-link">
            <i class="fa-solid fa-copy"></i>
            <span>Templates</span>
        </a>
        <a href="coming_soon.php?feature=Export+Data" class="nav-item-btn drawer-only-nav drawer-reveal" data-testid="drawer-export-data-link">
            <i class="fa-solid fa-file-export"></i>
            <span>Export Data</span>
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

        <!-- Inside the navbar.php file, inside the <ul> tag, add this li: -->

    <a href="manage_templates.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'manage_templates.php') ? 'active' : '' ?>" data-testid="sidebar-manage-templates-link">
        <i class="fa-solid fa-file-contract"></i>
        <span>Manage Templates</span>
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
        <!-- 🌟 TEMPORARY — remove this link (and super_admin_controls.php) once
             go-live test-data cleanup is done. -->
        <a href="super_admin_controls.php" class="nav-item-btn desktop-only-nav drawer-reveal <?= ($current_page == 'super_admin_controls.php') ? 'active' : '' ?>" data-testid="sidebar-data-cleanup-link">
            <i class="fa-solid fa-trash"></i>
            <span>Data Cleanup (Temp)</span>
        </a>
        <?php endif; ?>

        <div class="desktop-sidebar-section-label desktop-only-nav">Account</div>
        <a href="profile.php" class="nav-item-btn desktop-only-nav <?= ($current_page == 'profile.php') ? 'active' : '' ?>" data-testid="sidebar-profile-link">
            <i class="fa-solid fa-user"></i>
            <span>My Profile</span>
        </a>

        <!-- ═══════════════ GENERAL (mobile drawer only) ═══════════════ -->
        <div class="drawer-section-label drawer-only-nav drawer-reveal">General</div>
        <a href="settings.php" class="nav-item-btn drawer-only-nav drawer-reveal <?= ($current_page == 'settings.php') ? 'active' : '' ?>" data-testid="drawer-settings-link">
            <i class="fa-solid fa-gear"></i>
            <span>Settings</span>
        </a>
        <div class="nav-item-btn dm-toggle-row drawer-only-nav drawer-reveal" data-testid="drawer-dark-mode-row">
            <span class="dm-toggle-label"><i class="fa-solid fa-moon"></i><span>Dark Mode</span></span>
            <button type="button" class="ios-switch" id="darkModeToggleBtn" role="switch" aria-checked="false" aria-label="Toggle dark mode" data-testid="dark-mode-toggle-button"></button>
        </div>
        <button type="button" class="nav-item-btn drawer-only-nav drawer-reveal" id="supportTriggerBtn" data-testid="drawer-support-trigger">
            <i class="fa-solid fa-headset"></i>
            <span>Support</span>
        </button>

        <!-- ═══════════════ ACCOUNT (mobile drawer only — desktop keeps its
             own profile+logout footer below, so these aren't duplicated
             on desktop) ═══════════════ -->
        <div class="drawer-section-label drawer-only-nav drawer-reveal">Account</div>
        <a href="profile.php" class="nav-item-btn drawer-only-nav drawer-reveal" data-testid="drawer-account-profile-link">
            <i class="fa-solid fa-user"></i>
            <span>Profile</span>
        </a>
        <a href="logout.php" class="nav-item-btn drawer-only-nav drawer-reveal drawer-logout-row" data-testid="drawer-account-logout-link">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
            <span>Logout</span>
        </a>
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
        <a href="logout.php" class="desktop-sidebar-logout" data-testid="sidebar-logout-link">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
            <span>Logout</span>
        </a>
    </div>
</div>

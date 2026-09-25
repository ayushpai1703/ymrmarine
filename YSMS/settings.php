<?php
require_once 'config/config.php';
checkAuth();

$settings_user_name = isset($_SESSION['full_name']) ? $_SESSION['full_name'] : (isset($_SESSION['username']) ? $_SESSION['username'] : 'User');
$settings_user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';

include 'includes/header.php';
?>
<style>
    .settings-wrap { padding: 18px 20px 30px; max-width: 560px; margin: 0 auto; }
    .settings-group-label { font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--text-muted); padding: 0 4px; margin: 22px 0 10px; }
    .settings-group-label:first-child { margin-top: 4px; }
    .settings-card { background: var(--card-bg); border-radius: 16px; border: 1px solid var(--border-color); overflow: hidden; box-shadow: 0 2px 10px rgba(15,23,42,.04); }
    .settings-row { display: flex; align-items: center; gap: 14px; padding: 14px 16px; text-decoration: none; border-bottom: 1px solid var(--border-color); color: var(--text-dark); cursor: pointer; background: transparent; width: 100%; border-left: 0; border-right: 0; border-top: 0; font-size: 14px; font-weight: 550; min-height: 44px; }
    .settings-row:last-child { border-bottom: none; }
    .settings-row-icon { width: 34px; height: 34px; border-radius: 10px; flex: none; display: flex; align-items: center; justify-content: center; font-size: 14px; background: rgba(91,45,238,.1); color: var(--accent-purple); }
    .settings-row-main { flex: 1; min-width: 0; text-align: left; }
    .settings-row-title { font-size: 14px; font-weight: 600; color: var(--text-dark); }
    .settings-row-sub { font-size: 11.5px; color: var(--text-muted); margin-top: 1px; }
    .settings-row-chevron { color: var(--text-muted); font-size: 12px; flex: none; }
    .settings-row.logout-row { color: #dc2626; }
    .settings-row.logout-row .settings-row-icon { background: rgba(220,38,38,.1); color: #dc2626; }
    .settings-row.logout-row .settings-row-title { color: #dc2626; }
    /* .ios-switch's default off-state (rgba(255,255,255,.14)) is tuned for the
       navy drawer background — on this page's light/dark card background it
       needs its own neutral off-state color for contrast. */
    .settings-row .ios-switch:not(.is-on) { background: #CBD5E1; }
    :root[data-theme="dark"] .settings-row .ios-switch:not(.is-on) { background: #374151; }
</style>
<div class="scroll-content">
    <?php $page_title = 'Settings'; $back_url = 'index.php'; $page_testid = 'settings'; include 'includes/top_app_bar.php'; ?>
    <main class="settings-wrap" data-testid="settings-page">

        <div class="settings-group-label">Appearance</div>
        <div class="settings-card">
            <div class="settings-row dm-toggle-row" style="cursor:default;" data-testid="settings-dark-mode-row">
                <div class="settings-row-icon"><i class="fa-solid fa-moon"></i></div>
                <div class="settings-row-main">
                    <div class="settings-row-title">Dark Mode</div>
                    <div class="settings-row-sub">Switch between light and dark theme</div>
                </div>
                <button type="button" class="ios-switch" id="darkModeToggleBtn" role="switch" aria-checked="false" aria-label="Toggle dark mode" data-testid="settings-dark-mode-toggle-button"></button>
            </div>
        </div>

        <div class="settings-group-label">Account</div>
        <div class="settings-card">
            <a href="profile.php" class="settings-row" data-testid="settings-profile-link">
                <div class="settings-row-icon"><i class="fa-solid fa-user"></i></div>
                <div class="settings-row-main">
                    <div class="settings-row-title">My Profile</div>
                    <div class="settings-row-sub"><?= htmlspecialchars($settings_user_name, ENT_QUOTES, 'UTF-8') ?> &middot; <?= htmlspecialchars($settings_user_role ?: 'User', ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <i class="fa-solid fa-chevron-right settings-row-chevron"></i>
            </a>
        </div>

        <div class="settings-group-label">Help</div>
        <div class="settings-card">
            <button type="button" class="settings-row" id="supportTriggerBtn" data-testid="settings-support-trigger">
                <div class="settings-row-icon"><i class="fa-solid fa-headset"></i></div>
                <div class="settings-row-main">
                    <div class="settings-row-title">Support</div>
                    <div class="settings-row-sub">Contact YMR Marine Solutions LLP</div>
                </div>
                <i class="fa-solid fa-chevron-right settings-row-chevron"></i>
            </button>
        </div>

        <div class="settings-group-label">&nbsp;</div>
        <div class="settings-card">
            <a href="logout.php" class="settings-row logout-row" data-testid="settings-logout-link">
                <div class="settings-row-icon"><i class="fa-solid fa-arrow-right-from-bracket"></i></div>
                <div class="settings-row-main">
                    <div class="settings-row-title">Logout</div>
                </div>
            </a>
        </div>
    </main>
</div>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>

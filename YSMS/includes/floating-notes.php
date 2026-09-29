<?php
// 🌟 Floating Sticky Notes — global widget, included once via includes/footer.php
// so it appears on every authenticated page without touching any page's own
// markup, layout, or database. Purely client-side (localStorage) — see
// assets/js/floating-notes.js for the behavior and assets/css/floating-notes.css
// for styling. Safe to include unconditionally: it renders one small, inert
// DOM tree that does nothing until its own script runs.

// Restricted to Admin, Super Admin and Surveyor — Client logins never see it.
$__fnotes_role = $_SESSION['role'] ?? '';
if (!in_array($__fnotes_role, ['Admin', 'Super Admin', 'Surveyor'], true)) {
    return;
}
// Notes are private per logged-in account: the user id is namespaced into
// every localStorage key (see floating-notes.js), so on a shared computer
// one admin/surveyor never sees another's notes — each login only ever
// reads/writes its own key.
$__fnotes_user_id = (int)($_SESSION['user_id'] ?? 0);
?>
<link rel="stylesheet" href="assets/css/floating-notes.css?v=<?= @filemtime(__DIR__ . '/../assets/css/floating-notes.css') ?: time() ?>">

<div class="fnotes" id="fnotesRoot" data-state="closed" data-user-id="<?= $__fnotes_user_id ?>">
    <!-- CLOSED state: floating trigger button -->
    <button type="button" class="fnotes-fab" id="fnotesFab" title="Notes" aria-label="Open Notes" data-testid="fnotes-fab">
        <i class="fa-solid fa-note-sticky" aria-hidden="true"></i>
    </button>

    <!-- MINIMIZED state: small restorable bar -->
    <button type="button" class="fnotes-min-bar" id="fnotesMinBar" aria-label="Restore Notes" data-testid="fnotes-min-bar">
        <i class="fa-solid fa-note-sticky" aria-hidden="true"></i>
        <span class="fnotes-min-label">My Notes</span>
        <i class="fa-solid fa-chevron-up fnotes-min-chevron" aria-hidden="true"></i>
    </button>

    <!-- OPEN / MAXIMIZED state: the notepad window -->
    <div class="fnotes-window" id="fnotesWindow" role="dialog" aria-label="My Notes" aria-modal="false">
        <div class="fnotes-header" id="fnotesHeader">
            <div class="fnotes-header-title">
                <i class="fa-solid fa-note-sticky" aria-hidden="true"></i>
                <span>My Notes</span>
            </div>
            <div class="fnotes-header-actions">
                <button type="button" class="fnotes-header-btn" id="fnotesMinimizeBtn" title="Minimize" aria-label="Minimize Notes" data-testid="fnotes-minimize-button">
                    <i class="fa-solid fa-window-minimize" aria-hidden="true"></i>
                </button>
                <button type="button" class="fnotes-header-btn" id="fnotesMaximizeBtn" title="Maximize" aria-label="Maximize Notes" data-testid="fnotes-maximize-button">
                    <i class="fa-solid fa-expand" aria-hidden="true"></i>
                </button>
                <button type="button" class="fnotes-header-btn fnotes-close-btn" id="fnotesCloseBtn" title="Close" aria-label="Close Notes" data-testid="fnotes-close-button">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </div>
        </div>
        <textarea class="fnotes-textarea" id="fnotesTextarea" placeholder="Type your notes here..." aria-label="Notes content" data-testid="fnotes-textarea"></textarea>
        <div class="fnotes-status-bar">
            <span class="fnotes-status" id="fnotesStatus" aria-live="polite"></span>
        </div>
    </div>
</div>

<script src="assets/js/floating-notes.js?v=<?= @filemtime(__DIR__ . '/../assets/js/floating-notes.js') ?: time() ?>" defer></script>

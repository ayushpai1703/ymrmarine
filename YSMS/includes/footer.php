</div> <!-- .mobile-container క్లోజింగ్ ట్యాగ్ -->

<?php
// The FAB trigger button itself is only rendered for Admin, Super Admin and
// Client (see includes/nav.php), but its content here must still match each
// role's own permissions — Super Admin never gets Assign Vessel / Add
// Surveyor / Admin Controls (only Add Client + Add Admin), and Client only
// ever gets Request Survey.
$fab_user_role = $user_role ?? ($_SESSION['role'] ?? '');
?>
<link rel="stylesheet" href="assets/css/bottom-nav.css?v=<?= @filemtime(__DIR__ . '/../assets/css/bottom-nav.css') ?>">
<!-- Global Center FAB Action Drawer Overlay -->
<div class="fab-overlay" id="fabActionOverlay">
    <div class="fab-popup-sheet" id="fabPopupSheet">
        <h5 class="fw-bold mb-3 text-dark" style="font-size: 16px;">Quick Actions</h5>

        <?php if ($fab_user_role === 'Admin'): ?>
        <!-- 1. Assign Vessel -->
        <a href="assign_vessel.php" class="fab-option-item" data-testid="fab-assign-vessel-link">
            <i class="fa-solid fa-ship text-primary" style="width: 24px; text-align: center;"></i>
            <span>Assign Vessel</span>
        </a>

        <!-- 2. Add Surveyor -->
        <a href="add_surveyor.php" class="fab-option-item" data-testid="fab-add-surveyor-link">
            <i class="fa-solid fa-user-gear text-warning" style="width: 24px; text-align: center;"></i>
            <span>Add Surveyor</span>
        </a>

        <!-- 3. Add Client -->
        <a href="add_client.php" class="fab-option-item" data-testid="fab-add-client-link">
            <i class="fa-solid fa-user-plus text-success" style="width: 24px; text-align: center;"></i>
            <span>Add Client</span>
        </a>

        <!-- 4. Admin Controls -->
        <a href="admin_controls.php" class="fab-option-item" data-testid="fab-admin-controls-link">
            <i class="fa-solid fa-user-shield text-primary" style="width: 24px; text-align: center;"></i>
            <span>Admin Controls</span>
        </a>
        <?php elseif ($fab_user_role === 'Super Admin'): ?>
        <!-- Super Admin: Add Client + Add Admin only — matches the desktop
             sidebar Quick Actions in includes/nav.php. -->
        <a href="add_client.php" class="fab-option-item" data-testid="fab-add-client-link">
            <i class="fa-solid fa-user-plus text-success" style="width: 24px; text-align: center;"></i>
            <span>Add Client</span>
        </a>
        <a href="add_admin.php" class="fab-option-item" data-testid="fab-add-admin-link">
            <i class="fa-solid fa-user-shield text-primary" style="width: 24px; text-align: center;"></i>
            <span>Add Admin</span>
        </a>
        <a href="super_admin_clients.php" class="fab-option-item" data-testid="fab-manage-clients-link">
            <i class="fa-solid fa-building text-success" style="width: 24px; text-align: center;"></i>
            <span>Manage Clients</span>
        </a>
        <!-- TEMPORARY — remove once go-live test-data cleanup is done. -->
        <a href="super_admin_controls.php" class="fab-option-item" data-testid="fab-data-cleanup-link">
            <i class="fa-solid fa-trash text-danger" style="width: 24px; text-align: center;"></i>
            <span>Data Cleanup (Temp)</span>
        </a>
        <?php elseif ($fab_user_role === 'Client'): ?>
        <a href="assign_vessel.php" class="fab-option-item" data-testid="fab-request-survey-link">
            <i class="fa-solid fa-ship text-primary" style="width: 24px; text-align: center;"></i>
            <span>Request Survey</span>
        </a>
        <?php endif; ?>

        <button class="btn btn-light w-100 mt-3 rounded-3 fw-bold text-danger" id="closeFabBtn" style="font-size: 14px; padding: 12px;" data-testid="fab-close-button">Close</button>
    </div>
</div>

<!-- Scripts Loader -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js?v=<?= @filemtime(__DIR__ . '/../assets/js/app.js') ?: time() ?>"></script>
<script src="assets/js/submit-feedback.js?v=<?= @filemtime(__DIR__ . '/../assets/js/submit-feedback.js') ?: time() ?>"></script>

<?php if (!empty($_SESSION['upload_email_outbox'])): ?>
<script>
// Admin email for the upload just made (includes/upload_email_outbox.php): sent now, in its
// own request, so the upload itself didn't wait for it. The toast reports what the mail
// transport answered; anything not sent is retried automatically.
(function () {
    function toast(text, ok) {
        var t = document.createElement('div');
        t.setAttribute('role', ok ? 'status' : 'alert');
        t.setAttribute('data-testid', 'upload-email-toast');
        t.style.cssText = 'position:fixed;bottom:96px;left:50%;transform:translateX(-50%);width:min(520px,calc(100vw - 32px));z-index:100002;' +
            'padding:12px 16px;border-radius:14px;color:#fff;font-size:13.5px;font-weight:600;line-height:1.4;box-shadow:0 14px 30px -6px rgba(15,23,42,.45);' +
            'background:' + (ok ? '#166534' : '#b45309');
        t.textContent = text;
        document.body.appendChild(t);
        setTimeout(function () { t.style.transition = 'opacity .4s'; t.style.opacity = '0'; setTimeout(function () { t.remove(); }, 400); }, ok ? 6000 : 10000);
    }
    fetch('ajax/upload_email_send.php', { method: 'POST', keepalive: true, credentials: 'same-origin', headers: { 'X-CSRF-Token': <?= json_encode(csrf_token()) ?> } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.success) { toast(d.message || 'Admin email will be retried automatically.', false); return; }
            (d.results || []).forEach(function (r) {
                if (r.status === 'sent') toast(r.message, true);
                else if (r.status === 'failed') toast(r.message + ' — it will be retried automatically.', false);
            });
        })
        .catch(function () {});
})();
</script>
<?php endif; ?>

<?php if (!empty($_SESSION['user_id'])): ?>
<script>
// Daily automatic emails (Admin Controls → Daily Emails): ask the server to send any that are
// due — at most every 10 minutes per browser; the server sends each one only once a day.
(function () {
    try {
        var k = 'ysmsDailyEmailCheck', last = +localStorage.getItem(k) || 0, now = Date.now();
        if (now - last < 600000) return;
        localStorage.setItem(k, String(now));
    } catch (e) { return; }
    fetch('ajax/daily_emails_run.php', { method: 'POST', keepalive: true, headers: { 'X-CSRF-Token': <?= json_encode(csrf_token()) ?> } }).catch(function () {});
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/floating-notes.php'; ?>

</body>
</html>
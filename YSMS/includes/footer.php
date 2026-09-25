</div> <!-- .mobile-container క్లోజింగ్ ట్యాగ్ -->

<?php
// The FAB trigger button itself is only rendered for Admin, Super Admin and
// Client (see includes/nav.php), but its content here must still match each
// role's own permissions — Super Admin never gets Assign Vessel / Add
// Surveyor / Admin Controls (only Add Client + Add Admin), and Client only
// ever gets Request Survey.
$fab_user_role = $user_role ?? ($_SESSION['role'] ?? '');
?>
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

<!-- 🌟 Support modal — triggered from the hamburger drawer's GENERAL section
     (includes/nav.php #supportTriggerBtn). Exact contact details per spec;
     functional mailto:/tel: links with copy-to-clipboard. -->
<div class="support-modal-overlay" id="supportModalOverlay" data-testid="support-modal-overlay">
    <div class="support-modal-card" role="dialog" aria-modal="true" aria-labelledby="supportModalTitle" data-testid="support-modal-card">
        <div class="support-modal-header">
            <div class="support-modal-icon"><i class="fa-solid fa-headset" aria-hidden="true"></i></div>
            <h2 class="support-modal-title" id="supportModalTitle">Support</h2>
            <button type="button" class="support-modal-close" id="supportModalCloseX" aria-label="Close support" data-testid="support-modal-close-x">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="support-modal-body">
            <p class="support-modal-company">YMR Marine Solutions LLP</p>

            <div class="support-contact-row">
                <div class="support-contact-icon"><i class="fa-solid fa-envelope" aria-hidden="true"></i></div>
                <div class="support-contact-main">
                    <p class="support-contact-label">Email</p>
                    <a href="mailto:ashok@ymrmarine.com" class="support-contact-value" id="supportEmailLink" data-testid="support-email-link">ashok@ymrmarine.com</a>
                </div>
                <button type="button" class="support-copy-btn" data-copy-value="ashok@ymrmarine.com" aria-label="Copy email address" data-testid="support-email-copy-button">
                    <i class="fa-regular fa-copy"></i>
                </button>
            </div>

            <div class="support-contact-row">
                <div class="support-contact-icon"><i class="fa-solid fa-phone" aria-hidden="true"></i></div>
                <div class="support-contact-main">
                    <p class="support-contact-label">Phone</p>
                    <a href="tel:+917842261230" class="support-contact-value" id="supportPhoneLink" data-testid="support-phone-link">+91 7842261230</a>
                </div>
                <button type="button" class="support-copy-btn" data-copy-value="+917842261230" aria-label="Copy phone number" data-testid="support-phone-copy-button">
                    <i class="fa-regular fa-copy"></i>
                </button>
            </div>
        </div>
        <div class="support-modal-actions">
            <a href="mailto:ashok@ymrmarine.com" class="support-action-btn email-btn" data-testid="support-email-action-button">
                <i class="fa-solid fa-envelope"></i> Email Support
            </a>
            <a href="tel:+917842261230" class="support-action-btn call-btn" data-testid="support-call-action-button">
                <i class="fa-solid fa-phone"></i> Call Support
            </a>
        </div>
        <button type="button" class="support-modal-close-full" id="supportModalCloseBtn" data-testid="support-modal-close-button">Close</button>
    </div>
</div>

<!-- Scripts Loader -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js?v=<?= @filemtime(__DIR__ . '/../assets/js/app.js') ?: time() ?>"></script>

</body>
</html>
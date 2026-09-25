<?php
/**
 * Request Callback — floating button + popup form.
 * Included near the end of every public page's <body> (index.php,
 * service.php, and includes/public_layout_end.php for
 * about-us.php/contact.php/ports.php).
 *
 * Defensive session/CSRF bootstrap: every page that includes this already
 * starts a session and loads csrf.php on its own, but this guards the
 * include working correctly even if called from somewhere that hasn't.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!function_exists('csrf_field')) {
    require_once __DIR__ . '/csrf.php';
}
?>
<button type="button" class="callback-fab" id="callbackFabBtn" data-testid="callback-fab-button" aria-haspopup="dialog">
    <i class="fa-solid fa-phone-volume"></i>
    <span class="callback-fab-text">Request Callback</span>
</button>

<div class="callback-modal-overlay" id="callbackModalOverlay" role="dialog" aria-modal="true" aria-labelledby="callbackModalTitle">
    <div class="callback-modal">
        <button type="button" class="callback-modal-close" id="callbackModalClose" aria-label="Close">
            <i class="fa-solid fa-xmark"></i>
        </button>

        <div class="callback-modal-body" id="callbackModalBody">
            <div class="callback-modal-icon"><i class="fa-solid fa-phone-volume"></i></div>
            <h3 id="callbackModalTitle">Request a Callback</h3>
            <p class="callback-modal-sub">Share your number and our team will call you back shortly.</p>

            <div class="callback-form-error-box" id="callbackFormError"></div>

            <form id="callbackForm" novalidate>
                <?= csrf_field() ?>
                <div class="form-group">
                    <label class="form-label" for="cbFullName">Full Name *</label>
                    <input type="text" id="cbFullName" name="full_name" class="form-input" placeholder="Your full name" required maxlength="100" data-testid="callback-field-name">
                    <div class="callback-field-error" data-error-for="cbFullName">Please enter your full name.</div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="cbCompanyName">Company Name</label>
                    <input type="text" id="cbCompanyName" name="company_name" class="form-input" placeholder="Your company (optional)" maxlength="150" data-testid="callback-field-company">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="cbMobile1">Mobile Number *</label>
                        <input type="tel" id="cbMobile1" name="mobile_number" class="form-input" placeholder="e.g. 98765 43210" required inputmode="tel" autocomplete="tel" data-testid="callback-field-mobile">
                        <div class="callback-field-error" data-error-for="cbMobile1">Please enter a valid mobile number.</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="cbMobile2">Alternate Mobile Number</label>
                        <input type="tel" id="cbMobile2" name="alternate_mobile_number" class="form-input" placeholder="Optional" inputmode="tel" autocomplete="tel" data-testid="callback-field-alt-mobile">
                        <div class="callback-field-error" data-error-for="cbMobile2">Please enter a valid mobile number.</div>
                    </div>
                </div>
                <button type="submit" class="btn-submit" id="callbackSubmitBtn" data-testid="callback-submit-button">
                    <span id="callbackSubmitLabel">Request Callback</span>
                </button>
            </form>
        </div>

        <div class="callback-success" id="callbackSuccess" data-testid="callback-success-message">
            <div class="callback-success-icon"><i class="fa-solid fa-circle-check"></i></div>
            <h3>Callback Request Submitted Successfully</h3>
            <p>Thank you for contacting us. Our team will get in touch with you shortly.</p>
        </div>
    </div>
</div>

<script>
(function () {
    var fab = document.getElementById('callbackFabBtn');
    var overlay = document.getElementById('callbackModalOverlay');
    var closeBtn = document.getElementById('callbackModalClose');
    var form = document.getElementById('callbackForm');
    var body = document.getElementById('callbackModalBody');
    var successEl = document.getElementById('callbackSuccess');
    var errorBox = document.getElementById('callbackFormError');
    var submitBtn = document.getElementById('callbackSubmitBtn');
    var submitLabel = document.getElementById('callbackSubmitLabel');
    if (!fab || !overlay || !form) return;

    var submitting = false;
    var autoCloseTimer = null;

    function openModal() {
        overlay.classList.add('open');
        document.body.style.overflow = 'hidden';
    }
    function closeModal() {
        overlay.classList.remove('open');
        document.body.style.overflow = '';
        if (autoCloseTimer) { clearTimeout(autoCloseTimer); autoCloseTimer = null; }
        // Reset to the form view for next time, after the close animation.
        setTimeout(function () {
            body.classList.remove('hide');
            successEl.classList.remove('show');
            form.reset();
            clearFieldErrors();
            hideFormError();
        }, 200);
    }

    fab.addEventListener('click', openModal);
    closeBtn.addEventListener('click', closeModal);
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closeModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('open')) closeModal();
    });

    function showFormError(msg) {
        errorBox.textContent = msg;
        errorBox.classList.add('show');
    }
    function hideFormError() {
        errorBox.classList.remove('show');
        errorBox.textContent = '';
    }
    function clearFieldErrors() {
        form.querySelectorAll('.form-input.invalid').forEach(function (el) { el.classList.remove('invalid'); });
        form.querySelectorAll('.callback-field-error.show').forEach(function (el) { el.classList.remove('show'); });
    }
    function markInvalid(input, message) {
        input.classList.add('invalid');
        var err = form.querySelector('.callback-field-error[data-error-for="' + input.id + '"]');
        if (err) {
            if (message) err.textContent = message;
            err.classList.add('show');
        }
    }

    // Basic client-side check — the server re-validates everything, this is
    // just for fast feedback before a round trip.
    function isLikelyMobile(value) {
        var digits = value.replace(/\D/g, '');
        return digits.length >= 7 && digits.length <= 15;
    }

    function validate() {
        clearFieldErrors();
        hideFormError();
        var ok = true;

        var nameEl = document.getElementById('cbFullName');
        if (!nameEl.value.trim()) {
            markInvalid(nameEl);
            ok = false;
        }

        var m1 = document.getElementById('cbMobile1');
        if (!m1.value.trim() || !isLikelyMobile(m1.value)) {
            markInvalid(m1);
            ok = false;
        }

        var m2 = document.getElementById('cbMobile2');
        if (m2.value.trim() && !isLikelyMobile(m2.value)) {
            markInvalid(m2);
            ok = false;
        }

        return ok;
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (submitting) return;
        if (!validate()) return;

        submitting = true;
        submitBtn.disabled = true;
        submitLabel.textContent = 'Submitting…';

        fetch('ajax/callback_request.php', {
            method: 'POST',
            body: new FormData(form),
        })
            .then(function (res) { return res.json().catch(function () { return { success: false, message: 'Something went wrong. Please try again.' }; }); })
            .then(function (data) {
                if (data.success) {
                    body.classList.add('hide');
                    successEl.classList.add('show');
                    autoCloseTimer = setTimeout(closeModal, 4000);
                } else {
                    showFormError(data.message || 'Something went wrong. Please try again.');
                }
            })
            .catch(function () {
                showFormError('Network error. Please check your connection and try again.');
            })
            .finally(function () {
                submitting = false;
                submitBtn.disabled = false;
                submitLabel.textContent = 'Request Callback';
            });
    });
})();
</script>

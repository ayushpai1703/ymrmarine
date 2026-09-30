/**
 * 🌟 Floating Sticky Notes — global widget (see includes/floating-notes.php).
 * Note CONTENT is synced through ajax/floating_notes.php (one row per user
 * in the floating_notes table) so the same note shows up on every device —
 * a localStorage-only note never leaves the device/browser it was typed on.
 * localStorage is still used, but only as a same-device instant-paint cache
 * and for the window's own open/minimized/maximized state and drag position
 * (those are per-device UI preferences, not part of the note itself).
 */
(function () {
    'use strict';

    // Guard against this script (or the include it lives in) ever running
    // twice on the same page — never create a second widget instance.
    if (window.__ysmsFloatingNotesInitialized) { return; }

    var root = document.getElementById('fnotesRoot');
    if (!root) { return; }
    window.__ysmsFloatingNotesInitialized = true;

    // Namespaced per logged-in user id (see includes/floating-notes.php) so
    // that on a shared computer, one admin/surveyor's notes never show up
    // for a different account that logs in afterward — each account only
    // ever reads/writes its own key.
    var userId = root.getAttribute('data-user-id') || '0';
    var STORAGE_KEY = 'ysms_floating_notes_' + userId;
    var STATE_KEY = 'ysms_floating_notes_state_' + userId;
    var POS_KEY = 'ysms_floating_notes_pos_' + userId;
    var VALID_STATES = ['closed', 'open', 'minimized', 'maximized'];

    var fab = document.getElementById('fnotesFab');
    var minBar = document.getElementById('fnotesMinBar');
    var win = document.getElementById('fnotesWindow');
    var header = document.getElementById('fnotesHeader');
    var textarea = document.getElementById('fnotesTextarea');
    var statusEl = document.getElementById('fnotesStatus');
    var minimizeBtn = document.getElementById('fnotesMinimizeBtn');
    var maximizeBtn = document.getElementById('fnotesMaximizeBtn');
    var closeBtn = document.getElementById('fnotesCloseBtn');
    var maximizeIcon = maximizeBtn ? maximizeBtn.querySelector('i') : null;

    if (!fab || !minBar || !win || !header || !textarea || !statusEl) { return; }

    function isDesktop() {
        return window.matchMedia('(min-width: 768px)').matches;
    }

    function safeGet(key) {
        try { return window.localStorage.getItem(key); } catch (e) { return null; }
    }
    function safeSet(key, value) {
        try { window.localStorage.setItem(key, value); } catch (e) { /* storage unavailable/full — fail silently */ }
    }

    function setStatus(kind) {
        statusEl.classList.remove('is-saved', 'is-saving', 'is-error');
        if (kind === 'saving') {
            statusEl.classList.add('is-saving');
            statusEl.textContent = 'Saving…';
        } else if (kind === 'saved') {
            statusEl.classList.add('is-saved');
            statusEl.textContent = 'Saved ✓';
        } else if (kind === 'error') {
            statusEl.classList.add('is-error');
            statusEl.textContent = 'Not saved — check connection';
        } else {
            statusEl.textContent = '';
        }
    }

    // Fast local paint from the same-device cache (avoids a blank flash
    // while the server request below is in flight); the server fetch then
    // overwrites it with the authoritative copy, so the same note shows on
    // every device the user opens it from.
    var cachedNote = safeGet(STORAGE_KEY);
    if (cachedNote !== null) {
        textarea.value = cachedNote;
        setStatus('saved');
    }

    // Guards a fetch that resolves late from clobbering text the user has
    // already started typing since the page loaded.
    var userHasEdited = false;

    function loadNoteFromServer() {
        fetch('ajax/floating_notes.php?action=get', { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.ok && typeof data.note === 'string' && !userHasEdited) {
                    textarea.value = data.note;
                    safeSet(STORAGE_KEY, data.note);
                    setStatus(data.note !== '' ? 'saved' : '');
                }
            })
            .catch(function (err) {
                console.error('Floating Notes: could not load from server, showing local copy', err);
            });
    }
    loadNoteFromServer();

    function saveNoteToServer(text) {
        fetch('ajax/floating_notes.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: 'action=save&note=' + encodeURIComponent(text),
        })
            .then(function (res) {
                if (!res.ok) { throw new Error('HTTP ' + res.status); }
                return res.json();
            })
            .then(function (data) {
                if (!data || !data.ok) { throw new Error('save rejected'); }
                setStatus('saved');
            })
            .catch(function (err) {
                console.error('Floating Notes: save failed', err);
                setStatus('error');
            });
    }

    // ── Autosave with a 400ms debounce ──
    var saveTimer = null;
    textarea.addEventListener('input', function () {
        userHasEdited = true;
        setStatus('saving');
        if (saveTimer) { clearTimeout(saveTimer); }
        saveTimer = setTimeout(function () {
            var text = textarea.value;
            safeSet(STORAGE_KEY, text);
            saveNoteToServer(text);
        }, 400);
    });

    // ── State management (CLOSED / OPEN / MINIMIZED / MAXIMIZED) ──
    function updateMaximizeIcon(state) {
        if (!maximizeIcon || !maximizeBtn) { return; }
        if (state === 'maximized') {
            maximizeIcon.classList.remove('fa-expand');
            maximizeIcon.classList.add('fa-compress');
            maximizeBtn.title = 'Restore';
            maximizeBtn.setAttribute('aria-label', 'Restore Notes');
        } else {
            maximizeIcon.classList.remove('fa-compress');
            maximizeIcon.classList.add('fa-expand');
            maximizeBtn.title = 'Maximize';
            maximizeBtn.setAttribute('aria-label', 'Maximize Notes');
        }
    }

    function setState(next, opts) {
        opts = opts || {};
        if (VALID_STATES.indexOf(next) === -1) { next = 'closed'; }
        var prev = root.getAttribute('data-state');
        root.setAttribute('data-state', next);
        safeSet(STATE_KEY, next);
        updateMaximizeIcon(next);

        if ((next === 'open' || next === 'maximized') && prev !== 'open' && prev !== 'maximized') {
            win.classList.add('fnotes-animate-open');
            setTimeout(function () { win.classList.remove('fnotes-animate-open'); }, 260);
        }
        if ((next === 'open' || next === 'maximized') && opts.focus !== false) {
            setTimeout(function () { try { textarea.focus(); } catch (e) {} }, 30);
        }
    }

    fab.addEventListener('click', function () { setState('open'); });
    minBar.addEventListener('click', function () { setState('open'); });
    minBar.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setState('open'); }
    });
    if (minimizeBtn) { minimizeBtn.addEventListener('click', function () { setState('minimized'); }); }
    if (closeBtn) { closeBtn.addEventListener('click', function () { setState('closed'); }); }
    if (maximizeBtn) {
        maximizeBtn.addEventListener('click', function () {
            var current = root.getAttribute('data-state');
            win.classList.add('fnotes-transition');
            if (current === 'maximized') {
                setState('open', { focus: false });
            } else {
                setState('maximized', { focus: false });
            }
            setTimeout(function () { win.classList.remove('fnotes-transition'); }, 300);
        });
    }

    // Escape only acts while focus is inside the notes window itself (this
    // listener is on the window element, not document, so it never fires
    // for Escape presses elsewhere on the page — e.g. an existing Bootstrap
    // modal closing).
    win.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            var current = root.getAttribute('data-state');
            if (current === 'open' || current === 'maximized') {
                e.preventDefault();
                setState('minimized');
            }
        }
    });

    // Global shortcut: Ctrl+Alt+N opens/restores Notes. Scoped to a specific
    // key combination that no existing app shortcut uses.
    document.addEventListener('keydown', function (e) {
        if (e.ctrlKey && e.altKey && (e.key === 'n' || e.key === 'N')) {
            var current = root.getAttribute('data-state');
            if (current !== 'open' && current !== 'maximized') {
                e.preventDefault();
                setState('open');
            }
        }
    });

    // ── Restore last state on load ──
    var savedState = safeGet(STATE_KEY);
    setState(savedState || 'closed', { focus: false });

    // ── Dragging (desktop only, disabled while maximized) ──
    var drag = null;

    function clampPosition(left, top) {
        var vw = window.innerWidth;
        var vh = window.innerHeight;
        var w = win.offsetWidth || 420;
        var minLeft = -(w - 80);
        var maxLeft = vw - 80;
        var minTop = 0;
        var maxTop = vh - 44;
        return {
            left: Math.min(Math.max(left, minLeft), Math.max(maxLeft, minLeft)),
            top: Math.min(Math.max(top, minTop), Math.max(maxTop, minTop)),
        };
    }

    function applyPosition(left, top) {
        var clamped = clampPosition(left, top);
        win.style.left = clamped.left + 'px';
        win.style.top = clamped.top + 'px';
        win.style.right = 'auto';
        win.style.bottom = 'auto';
    }

    header.addEventListener('pointerdown', function (e) {
        if (!isDesktop()) { return; }
        if (root.getAttribute('data-state') === 'maximized') { return; }
        if (e.target.closest('.fnotes-header-btn')) { return; }
        var rect = win.getBoundingClientRect();
        drag = {
            startX: e.clientX,
            startY: e.clientY,
            startLeft: rect.left,
            startTop: rect.top,
            pointerId: e.pointerId,
        };
        header.classList.add('fnotes-dragging');
        win.classList.remove('fnotes-transition');
        try { header.setPointerCapture(e.pointerId); } catch (err) {}
    });

    header.addEventListener('pointermove', function (e) {
        if (!drag || drag.pointerId !== e.pointerId) { return; }
        var dx = e.clientX - drag.startX;
        var dy = e.clientY - drag.startY;
        applyPosition(drag.startLeft + dx, drag.startTop + dy);
    });

    function endDrag(e) {
        if (!drag) { return; }
        if (e && drag.pointerId !== e.pointerId) { return; }
        header.classList.remove('fnotes-dragging');
        var rect = win.getBoundingClientRect();
        safeSet(POS_KEY, JSON.stringify({ left: rect.left, top: rect.top }));
        drag = null;
    }
    header.addEventListener('pointerup', endDrag);
    header.addEventListener('pointercancel', endDrag);

    // ── Restore last dragged position (desktop only) ──
    if (isDesktop()) {
        var savedPos = safeGet(POS_KEY);
        if (savedPos) {
            try {
                var pos = JSON.parse(savedPos);
                if (typeof pos.left === 'number' && typeof pos.top === 'number') {
                    applyPosition(pos.left, pos.top);
                }
            } catch (e) { /* ignore malformed saved position */ }
        }
    }

    // Re-clamp an already-dragged position if the viewport is resized (e.g.
    // browser window shrunk) so the popup never ends up stranded off-screen.
    window.addEventListener('resize', function () {
        if (!isDesktop()) { return; }
        if (root.getAttribute('data-state') === 'maximized') { return; }
        if (win.style.left && win.style.top) {
            applyPosition(parseFloat(win.style.left), parseFloat(win.style.top));
        }
    });
})();

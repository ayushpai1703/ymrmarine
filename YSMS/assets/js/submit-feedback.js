/*
 * App-wide "something is happening" feedback.
 *  - Any POST form: the pressed button shows a spinner + "Please wait…" and
 *    further submits are blocked; forms with files get a full-screen
 *    "Uploading…" overlay; download forms reset themselves after a few seconds.
 *  - Any POST made with fetch / XMLHttpRequest (jQuery included): a thin
 *    progress bar across the top of the screen while it runs.
 *  - Report uploads (forms posting files to ajax/upload_handler.php) go up with
 *    XMLHttpRequest and show a progress bar with the % sent, then follow the
 *    handler's redirect (it answers {"redirect": …} for these requests).
 * Opt out per form with data-no-loading.
 */
(function () {
    'use strict';
    if (window.__ysmsSubmitFeedback) return;
    window.__ysmsSubmitFeedback = true;

    var css = '' +
        '#ysmsBusyBar{position:fixed;top:0;left:0;height:3px;width:0;z-index:100000;background:linear-gradient(90deg,#2563eb,#5b2dee);box-shadow:0 0 8px rgba(37,99,235,.6);opacity:0;transition:width .3s ease,opacity .25s ease;pointer-events:none}' +
        'html.ysms-busy #ysmsBusyBar{opacity:1;width:85%;transition:width 8s cubic-bezier(.1,.6,.3,1),opacity .2s}' +
        'html.ysms-busy-done #ysmsBusyBar{opacity:0;width:100%;transition:width .2s ease,opacity .4s ease .2s}' +
        '.ysms-btn-loading{cursor:progress!important;opacity:.85;pointer-events:none}' +
        '.ysms-btn-loading .ysms-spin{display:inline-block;width:1em;height:1em;margin-right:.45em;vertical-align:-.15em;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:ysmsSpin .7s linear infinite}' +
        '@keyframes ysmsSpin{to{transform:rotate(360deg)}}' +
        '.ysms-submit-overlay{position:fixed;inset:0;z-index:99999;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:20px}' +
        '.ysms-submit-overlay-box{background:#fff;border-radius:16px;padding:26px 24px;max-width:320px;text-align:center;font-size:14px;font-weight:600;color:#0b1e46;box-shadow:0 20px 50px rgba(0,0,0,.25);font-family:inherit}' +
        '.ysms-submit-overlay-box .ysms-spin{display:block;width:34px;height:34px;margin:0 auto 14px;border:3px solid #1e3a8a;border-right-color:transparent;border-radius:50%;animation:ysmsSpin .7s linear infinite}' +
        '.ysms-submit-overlay-box small{display:block;font-weight:500;color:#64748b;margin-top:6px}' +
        '.ysms-up-pct{font-size:30px;font-weight:800;color:#1e3a8a;line-height:1;margin:2px 0 10px;font-variant-numeric:tabular-nums}' +
        '.ysms-up-track{height:10px;border-radius:999px;background:#e2e8f0;overflow:hidden;margin:12px 0 4px}' +
        '.ysms-up-fill{height:100%;width:0;border-radius:999px;background:linear-gradient(90deg,#2563eb,#5b2dee);transition:width .2s ease}' +
        '.ysms-up-err{color:#b91c1c}' +
        '.ysms-up-close{margin-top:14px;border:0;border-radius:10px;padding:9px 18px;background:#1e3a8a;color:#fff;font-weight:700;font-size:13px}';
    var style = document.createElement('style');
    style.textContent = css;
    (document.head || document.documentElement).appendChild(style);

    // ---------------------------------------------------------- top bar
    var active = 0, doneTimer = null;
    function ensureBar() {
        if (!document.getElementById('ysmsBusyBar') && document.body) {
            var b = document.createElement('div');
            b.id = 'ysmsBusyBar';
            b.setAttribute('aria-hidden', 'true');
            document.body.appendChild(b);
        }
    }
    function busy(delta) {
        ensureBar();
        active = Math.max(0, active + delta);
        var root = document.documentElement;
        clearTimeout(doneTimer);
        if (active > 0) {
            root.classList.remove('ysms-busy-done');
            root.classList.add('ysms-busy');
        } else {
            root.classList.remove('ysms-busy');
            root.classList.add('ysms-busy-done');
            doneTimer = setTimeout(function () { root.classList.remove('ysms-busy-done'); }, 700);
        }
    }
    // Background traffic that should never flash the bar.
    var QUIET = /floating_notes\.php|team_chat\.php|notifications\.php|global_search|search_handler|preview_report_number|check_pending_vessel_duplicate/;
    function tracked(method, url) {
        return String(method || 'GET').toUpperCase() !== 'GET' && !QUIET.test(String(url || ''));
    }

    if (window.fetch) {
        var origFetch = window.fetch;
        window.fetch = function (input, init) {
            var method = (init && init.method) || (input && input.method) || 'GET';
            var url = typeof input === 'string' ? input : (input && input.url) || '';
            if (!tracked(method, url)) return origFetch.apply(this, arguments);
            busy(1);
            return origFetch.apply(this, arguments).then(
                function (r) { busy(-1); return r; },
                function (e) { busy(-1); throw e; }
            );
        };
    }
    var XHR = window.XMLHttpRequest && window.XMLHttpRequest.prototype;
    if (XHR) {
        var origOpen = XHR.open, origSend = XHR.send;
        XHR.open = function (method, url) {
            this.__ysmsTrack = tracked(method, url);
            return origOpen.apply(this, arguments);
        };
        XHR.send = function () {
            if (this.__ysmsTrack && !this.__ysmsCounted) {
                this.__ysmsCounted = true;
                busy(1);
                var self = this;
                this.addEventListener('loadend', function () { busy(-1); self.__ysmsCounted = false; });
            }
            return origSend.apply(this, arguments);
        };
    }

    // ---------------------------------------------------------- forms
    var DOWNLOAD_FORM = /generate_invoice\.php|generate_expense\.php|export_[a-z_]+\.php/i;

    function isDownloadForm(form) {
        return DOWNLOAD_FORM.test(form.getAttribute('action') || '') || form.id === 'permissionCopyForm';
    }
    function hasFiles(form) {
        var inputs = form.querySelectorAll('input[type=file]');
        for (var i = 0; i < inputs.length; i++) {
            if (inputs[i].files && inputs[i].files.length) return true;
        }
        return false;
    }
    function submitButtons(form, submitter) {
        var list = [];
        if (submitter && submitter.form === form) list.push(submitter);
        var all = form.querySelectorAll('button[type=submit], button:not([type]), input[type=submit]');
        for (var i = 0; i < all.length; i++) if (list.indexOf(all[i]) === -1) list.push(all[i]);
        if (form.id) {
            var outside = document.querySelectorAll('[form="' + form.id + '"][type=submit]');
            for (var j = 0; j < outside.length; j++) if (list.indexOf(outside[j]) === -1) list.push(outside[j]);
        }
        return list;
    }
    // Buttons are never set `disabled` while a submit is in flight: a disabled
    // submitter is left out of the posted data, and several pages check the
    // pressed button's name on the server. They are locked visually instead,
    // and repeat submits are blocked by the form's data-ysms-submitting flag.
    function setLoading(btn, label) {
        if (btn.dataset.ysmsLoading === '1') return;
        btn.dataset.ysmsLoading = '1';
        btn.setAttribute('aria-busy', 'true');
        btn.setAttribute('aria-disabled', 'true');
        btn.classList.add('ysms-btn-loading');
        if (btn.tagName !== 'INPUT') {
            btn.dataset.ysmsOrig = btn.innerHTML;
            btn.innerHTML = '<span class="ysms-spin" aria-hidden="true"></span>' + label;
        }
    }
    function clearLoading(btn) {
        if (btn.dataset.ysmsLoading !== '1') return;
        if (btn.tagName !== 'INPUT') btn.innerHTML = btn.dataset.ysmsOrig;
        btn.classList.remove('ysms-btn-loading');
        btn.removeAttribute('aria-busy');
        btn.removeAttribute('aria-disabled');
        delete btn.dataset.ysmsLoading;
    }
    function showOverlay(text, sub) {
        hideOverlay();
        var o = document.createElement('div');
        o.className = 'ysms-submit-overlay';
        o.id = 'ysmsSubmitOverlay';
        o.setAttribute('role', 'alert');
        o.setAttribute('aria-live', 'assertive');
        var box = document.createElement('div');
        box.className = 'ysms-submit-overlay-box';
        box.innerHTML = '<span class="ysms-spin" aria-hidden="true"></span>';
        box.appendChild(document.createTextNode(text));
        var s = document.createElement('small');
        s.textContent = sub;
        box.appendChild(s);
        o.appendChild(box);
        document.body.appendChild(o);
    }
    function hideOverlay() {
        var o = document.getElementById('ysmsSubmitOverlay');
        if (o) o.remove();
    }
    // ---------------------------------------------------------- report uploads with progress
    var UPLOAD_ACTION = /(^|\/)ajax\/upload_handler\.php(\?|$)/;
    function isProgressUpload(form) {
        return UPLOAD_ACTION.test(form.getAttribute('action') || '') && hasFiles(form)
            && !form.hasAttribute('data-no-loading') && !!window.FormData && !!window.XMLHttpRequest;
    }
    function mbText(b) { return (b / 1048576).toFixed(b < 10485760 ? 1 : 0) + ' MB'; }
    function uploadWithProgress(form, submitter) {
        if (form.dataset.ysmsSubmitting === '1') return;
        form.dataset.ysmsSubmitting = '1';
        submitButtons(form, submitter).forEach(function (b) { setLoading(b, 'Uploading…'); });

        hideOverlay();
        var o = document.createElement('div');
        o.className = 'ysms-submit-overlay';
        o.id = 'ysmsSubmitOverlay';
        o.setAttribute('role', 'alert');
        o.setAttribute('data-testid', 'upload-progress');
        o.innerHTML = '<div class="ysms-submit-overlay-box" style="min-width:260px">' +
            '<div data-up-title>Uploading files…</div>' +
            '<div class="ysms-up-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="ysms-up-fill"></div></div>' +
            '<div class="ysms-up-pct" data-up-pct>0%</div>' +
            '<small data-up-sub>Please don\'t close this page.</small></div>';
        document.body.appendChild(o);
        var title = o.querySelector('[data-up-title]'), pct = o.querySelector('[data-up-pct]'), sub = o.querySelector('[data-up-sub]');
        var track = o.querySelector('.ysms-up-track'), fill = o.querySelector('.ysms-up-fill');
        function setPct(p) {
            p = Math.max(0, Math.min(100, Math.floor(p)));
            fill.style.width = p + '%';
            pct.textContent = p + '%';
            track.setAttribute('aria-valuenow', String(p));
        }
        function leaveGuard(e) { e.preventDefault(); e.returnValue = ''; }
        window.addEventListener('beforeunload', leaveGuard);
        function fail(msg) {
            window.removeEventListener('beforeunload', leaveGuard);
            delete form.dataset.ysmsSubmitting;
            submitButtons(form, null).forEach(clearLoading);
            title.textContent = 'Upload failed';
            title.className = 'ysms-up-err';
            pct.style.display = 'none';
            track.style.display = 'none';
            sub.textContent = msg;
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'ysms-up-close';
            btn.textContent = 'Close';
            btn.addEventListener('click', hideOverlay);
            o.firstChild.appendChild(btn);
        }

        var fd;
        try { fd = new FormData(form, submitter || undefined); } catch (err) { fd = new FormData(form); }
        if (submitter && submitter.name && !fd.has(submitter.name)) fd.append(submitter.name, submitter.value || '');
        var url = form.action;
        var xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        xhr.setRequestHeader('X-YSMS-Upload', '1');
        xhr.upload.addEventListener('progress', function (e) {
            if (!e.lengthComputable) return;
            setPct(e.loaded / e.total * 100);
            sub.textContent = mbText(e.loaded) + ' of ' + mbText(e.total) + ' — please don\'t close this page.';
        });
        xhr.upload.addEventListener('load', function () {
            setPct(100);
            title.textContent = 'Upload complete';
            sub.textContent = 'Saving files…';
        });
        xhr.addEventListener('load', function () {
            var data = null;
            try { data = JSON.parse(xhr.responseText); } catch (err) {}
            if (xhr.status === 200 && data && data.redirect) {
                window.removeEventListener('beforeunload', leaveGuard);
                var next = new URL(data.redirect, url);
                var here = new URL(window.location.href);
                window.location.href = next.href;
                // Same page with only a #section: assigning href just scrolls — reload to show the result
                if (next.hash && next.origin + next.pathname + next.search === here.origin + here.pathname + here.search) window.location.reload();
                return;
            }
            var text = (xhr.responseText || '').trim();
            fail(text && text.length < 300 && text.charAt(0) !== '<' ? text : 'The server could not save the files (error ' + xhr.status + '). Please try again.');
        });
        xhr.addEventListener('error', function () { fail('No connection to the server. Check your internet and try again.'); });
        xhr.addEventListener('abort', function () { fail('The upload was interrupted. Please try again.'); });
        xhr.send(fd);
    }

    function reset(form) {
        delete form.dataset.ysmsSubmitting;
        submitButtons(form, null).forEach(clearLoading);
        hideOverlay();
        busy(-1);
    }

    function start(form, submitter) {
        if (!form || form.dataset.ysmsSubmitting === '1') return;
        if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
        if (form.hasAttribute('data-no-loading')) return;
        var target = form.getAttribute('target');
        if (target && target !== '_self') return;

        form.dataset.ysmsSubmitting = '1';
        var download = isDownloadForm(form);
        var uploading = !download && hasFiles(form);
        var label = download ? 'Preparing file…' : (uploading ? 'Uploading…' : 'Please wait…');
        submitButtons(form, submitter).forEach(function (b) { setLoading(b, label); });
        busy(1);
        if (uploading) {
            showOverlay('Uploading files…', "This can take a few seconds — please don't close this page.");
        }
        if (download) {
            setTimeout(function () { reset(form); }, 5000);
        }
    }

    // Registered on window (bubble phase) so it runs after the page's own
    // submit handlers: anything they cancel (validation, confirm dialogs,
    // AJAX forms) is left alone. Done synchronously — once the browser starts
    // a form navigation, timers queued here may never run.
    window.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.dataset) return;
        if (form.dataset.ysmsSubmitting === '1') {
            e.preventDefault();
            return;
        }
        if (e.defaultPrevented) return;
        if (isProgressUpload(form)) {
            e.preventDefault();
            uploadWithProgress(form, e.submitter || null);
            return;
        }
        start(form, e.submitter || null);
    });

    // form.submit() (used after confirm dialogs) never fires a submit event.
    var origSubmit = HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit = function () {
        if (isProgressUpload(this)) return uploadWithProgress(this, null);
        start(this, null);
        return origSubmit.apply(this, arguments);
    };

    // Coming back with the browser's Back button can restore the page from
    // cache with the spinner still showing — reset everything then.
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        var forms = document.querySelectorAll('form[data-ysms-submitting]');
        for (var i = 0; i < forms.length; i++) reset(forms[i]);
        active = 0;
        document.documentElement.classList.remove('ysms-busy', 'ysms-busy-done');
        hideOverlay();
    });
})();

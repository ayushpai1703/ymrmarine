/*
 * YSMS Android app behaviour (loaded only inside the app — see includes/app_shell.php).
 *  - launch splash on the first page of an app session
 *  - page transitions (CSS view transitions; this file only tags back / tab navigations)
 *  - top loading bar, touch ripple, card entrance, number count-up
 *  - pull down to refresh
 *  - Android back button: closes popups / menus first, then goes back
 *  - files made in the page (blob: links, e.g. Word report) are saved through the native app
 *  - "No internet" banner
 */
(function () {
    'use strict';

    var cap = window.Capacitor;
    var isNative = !!(cap && cap.isNativePlatform && cap.isNativePlatform());
    var plugins = (cap && cap.Plugins) || {};
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var page = (location.pathname.split('/').pop() || 'index.php').toLowerCase();

    function storage(key, value) {
        try {
            if (value === undefined) return sessionStorage.getItem(key);
            if (value === null) sessionStorage.removeItem(key); else sessionStorage.setItem(key, value);
        } catch (e) { return null; }
        return null;
    }
    function haptic() {
        try { if (isNative && plugins.Haptics) plugins.Haptics.impact({ style: 'LIGHT' }); } catch (e) { /* no haptics */ }
    }
    function el(tag, attrs, html) {
        var n = document.createElement(tag);
        for (var k in attrs) if (Object.prototype.hasOwnProperty.call(attrs, k)) n.setAttribute(k, attrs[k]);
        if (html) n.innerHTML = html;
        return n;
    }

    // Page transition types (back / tab) are tagged by the inline script in includes/app_shell.php,
    // which has to run before the page's first paint.
    var NAV_KEY = 'ysmsNavType';

    /* ---------- Launch splash ---------- */
    function splash() {
        var first = !storage('ysmsSplashDone');
        storage('ysmsSplashDone', '1');
        if (!first || reduceMotion) { hideNativeSplash(); return; }
        var logo = 'assets/images/logo.png';
        var s = el('div', { id: 'ysms-splash', 'aria-hidden': 'true' },
            '<svg class="ysms-splash-waves" viewBox="0 0 390 260" preserveAspectRatio="none" fill="none">' +
            '<path d="M-30 120 C 70 95, 130 145, 195 120 S 320 95, 420 120" stroke="#fff" stroke-opacity=".08" stroke-width="2"/>' +
            '<path d="M-30 170 C 70 145, 130 195, 195 170 S 320 145, 420 170" stroke="#fff" stroke-opacity=".06" stroke-width="2"/>' +
            '<path d="M-30 220 C 70 195, 130 245, 195 220 S 320 195, 420 220" stroke="#fff" stroke-opacity=".05" stroke-width="2"/></svg>' +
            '<div class="ysms-splash-mark"><img src="' + logo + '" alt=""></div>' +
            '<div class="ysms-splash-text"><b>YMR MARINE</b><span>Survey Management System</span></div>' +
            '<div class="ysms-splash-bar"><i></i></div>');
        document.body.appendChild(s);
        hideNativeSplash();
        var started = Date.now();
        function out() {
            setTimeout(function () {
                s.classList.add('ysms-splash-out');
                setTimeout(function () { s.remove(); }, 600);
            }, Math.max(0, 1100 - (Date.now() - started)));
        }
        if (document.readyState === 'complete') out(); else window.addEventListener('load', out, { once: true });
        setTimeout(function () { if (s.isConnected) s.remove(); }, 6000);
    }
    function hideNativeSplash() {
        try { if (isNative && plugins.SplashScreen) plugins.SplashScreen.hide({ fadeOutDuration: 200 }); } catch (e) { /* none */ }
    }

    /* ---------- Top loading bar ---------- */
    var bar;
    function progressOn() {
        if (!bar) { bar = el('div', { id: 'ysms-progress', 'aria-hidden': 'true' }, '<i></i>'); document.body.appendChild(bar); }
        bar.classList.remove('on'); void bar.offsetWidth; bar.classList.add('on');
        clearTimeout(progressOn.t);
        progressOn.t = setTimeout(progressOff, 15000);
    }
    function progressOff() { if (bar) bar.classList.remove('on'); }
    window.addEventListener('pageshow', progressOff);

    function isPageLink(a, e) {
        if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) return false;
        var href = a.getAttribute('href');
        if (!href || href.charAt(0) === '#' || /^(javascript|mailto|tel|blob|data):/i.test(href)) return false;
        if (a.hasAttribute('download') || a.hasAttribute('data-bs-toggle')) return false;
        if (a.origin !== location.origin) return false;
        if (/download\.php|export_|formats_download\.php\?|survey_form_file\.php|_download|print/i.test(a.pathname + a.search)) return false;
        return true;
    }
    document.addEventListener('click', function (e) {
        var a = e.target.closest && e.target.closest('a[href]');
        if (a && isPageLink(a, e)) {
            if (a.closest('.bottom-nav-bar') && !a.closest('.bottom-nav-bar.drawer-mode')) { storage(NAV_KEY, 'tab'); haptic(); }
            setTimeout(function () { if (!e.defaultPrevented) progressOn(); }, 0);
        }
    });
    document.addEventListener('submit', function (e) {
        var f = e.target;
        setTimeout(function () {
            if (e.defaultPrevented || (f.getAttribute('target') || '') === '_blank') return;
            progressOn();
        }, 0);
    });

    /* ---------- Touch ripple ---------- */
    var RIPPLE = '.btn, .nav-item-btn, .ov-card, .stat-card, .vessel-card, .recovery-row-card, .fab-option-item, ' +
        '.login-submit-btn, .btn-primary-glow, .center-fab-btn, .profile-dropdown-item, .sf-entry';
    document.addEventListener('pointerdown', function (e) {
        if (reduceMotion || e.button !== 0) return;
        var t = e.target.closest && e.target.closest(RIPPLE);
        if (!t || t.disabled) return;
        if (getComputedStyle(t).position === 'static') t.style.position = 'relative';
        var host = t.querySelector(':scope > .ysms-ripple-host');
        if (!host) { host = el('span', { 'class': 'ysms-ripple-host', 'aria-hidden': 'true' }); t.appendChild(host); }
        var r = t.getBoundingClientRect();
        var size = Math.max(r.width, r.height) * 2.2;
        var dot = el('span', { 'class': 'ysms-ripple' });
        dot.style.width = dot.style.height = size + 'px';
        dot.style.left = (e.clientX - r.left - size / 2) + 'px';
        dot.style.top = (e.clientY - r.top - size / 2) + 'px';
        host.appendChild(dot);
        setTimeout(function () { dot.remove(); }, 650);
        if (t.matches('.center-fab-btn')) haptic();
    }, { passive: true });

    /* ---------- Card entrance as cards come into view ---------- */
    var ENTER = '.ov-card, .stat-card, .recovery-row-card, .vessel-card, .overview-section > .search-input-wrapper, ' +
        '.detail-header-card, .info-card, .vd-table-card, .pv-card, .sf-entry, .vh-card, .inbox-item, .doc-card';
    function entrance() {
        if (reduceMotion || !('IntersectionObserver' in window)) return;
        var items = Array.prototype.slice.call(document.querySelectorAll(ENTER));
        if (!items.length) return;
        var batch = 0, batchTimer = null;
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) {
                if (!en.isIntersecting) return;
                var n = en.target;
                io.unobserve(n);
                n.style.setProperty('--ysms-i', String(Math.min(batch++, 10)));
                n.classList.add('ysms-in');
                n.classList.remove('ysms-pre');
                n.addEventListener('animationend', function done(ev) {
                    if (ev.target !== n) return;
                    n.removeEventListener('animationend', done);
                    n.classList.remove('ysms-in');
                    n.style.removeProperty('--ysms-i');
                });
            });
            clearTimeout(batchTimer);
            batchTimer = setTimeout(function () { batch = 0; }, 120);
        }, { rootMargin: '0px 0px -24px 0px' });
        items.slice(0, 400).forEach(function (n) {
            if (n.closest('.modal, .bottom-nav-bar, #ysms-splash')) return;
            n.classList.add('ysms-pre');
            io.observe(n);
        });
        // Never leave anything hidden (e.g. a card inside a container that is not shown).
        setTimeout(function () {
            document.querySelectorAll('.ysms-pre').forEach(function (n) {
                var r = n.getBoundingClientRect();
                if (r.width === 0 && r.height === 0) { n.classList.remove('ysms-pre'); io.unobserve(n); }
            });
        }, 1500);
    }

    /* ---------- Dashboard numbers count up ---------- */
    function countUp() {
        if (reduceMotion || page !== 'index.php' && page !== '') return;
        document.querySelectorAll('.ov-count, .stat-val').forEach(function (n) {
            if (n.children.length) return;
            var text = n.textContent;
            var m = text.match(/^(\s*)(\d[\d,]*)(\.\d+)?(.*)$/);
            if (!m) return;
            var target = parseFloat((m[2] + (m[3] || '')).replace(/,/g, ''));
            if (!isFinite(target) || target === 0) return;
            var decimals = m[3] ? m[3].length - 1 : 0;
            var comma = m[2].indexOf(',') !== -1;
            var start = null, dur = 900;
            n.setAttribute('aria-label', text.trim());
            function fmt(v) {
                var s = v.toFixed(decimals);
                if (comma) { var p = s.split('.'); p[0] = p[0].replace(/\B(?=(\d{3})+(?!\d))/g, ','); s = p.join('.'); }
                return m[1] + s + m[4];
            }
            function step(ts) {
                if (start === null) start = ts;
                var k = Math.min(1, (ts - start) / dur);
                var eased = 1 - Math.pow(1 - k, 3);
                n.textContent = k < 1 ? fmt(target * eased) : text;
                if (k < 1) requestAnimationFrame(step); else n.removeAttribute('aria-label');
            }
            n.textContent = fmt(0);
            requestAnimationFrame(step);
        });
    }

    /* ---------- Pull down to refresh ---------- */
    function pullToRefresh() {
        var ptr = el('div', { id: 'ysms-ptr', 'aria-hidden': 'true' },
            '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" style="color:#1e3a8a">' +
            '<path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6"/></svg>');
        document.body.appendChild(ptr);
        var icon = ptr.firstChild;
        var startY = null, pull = 0, MAX = 130, TRIGGER = 80, busy = false;

        function blocked(target) {
            if (busy) return true;
            if ((window.scrollY || document.documentElement.scrollTop) > 0) return true;
            if (document.querySelector('.modal.show, .swal2-container, .bottom-nav-bar.open, #ysms-splash, .offcanvas.show')) return true;
            var fab = document.getElementById('fabActionOverlay');
            if (fab && getComputedStyle(fab).display !== 'none') return true;
            for (var n = target; n && n !== document.body; n = n.parentElement) {
                if (n.scrollTop > 0) return true;
                if (n.matches && n.matches('input, textarea, select, [contenteditable], .table-responsive, .vd-table-wrap')) return true;
            }
            return false;
        }
        function render() {
            var y = Math.min(pull, MAX);
            ptr.style.transform = 'translateY(' + (y * .7 - 50) + 'px)';
            ptr.style.opacity = String(Math.min(1, y / 50));
            icon.style.transform = 'rotate(' + (y * 2.6) + 'deg)';
            ptr.classList.toggle('ready', y >= TRIGGER);
        }
        document.addEventListener('touchstart', function (e) {
            startY = (e.touches.length === 1 && !blocked(e.target)) ? e.touches[0].clientY : null;
            pull = 0;
            ptr.classList.remove('back');
        }, { passive: true });
        document.addEventListener('touchmove', function (e) {
            if (startY === null) return;
            var dy = e.touches[0].clientY - startY;
            if (dy <= 0 || (window.scrollY || document.documentElement.scrollTop) > 0) { if (pull) { pull = 0; render(); } return; }
            pull = dy * .55;
            render();
        }, { passive: true });
        document.addEventListener('touchend', function () {
            if (startY === null) return;
            startY = null;
            ptr.classList.add('back');
            if (pull >= TRIGGER) {
                busy = true;
                haptic();
                ptr.classList.add('loading');
                ptr.style.transform = 'translateY(' + (TRIGGER * .7 - 40) + 'px)';
                progressOn();
                setTimeout(function () { location.reload(); }, 250);
            } else {
                pull = 0;
                ptr.style.transform = 'translateY(-60px)';
                ptr.style.opacity = '0';
            }
        }, { passive: true });
    }

    /* ---------- Android back button ---------- */
    function closeTopLayer() {
        if (window.Swal && Swal.isVisible && Swal.isVisible()) { Swal.close(); return true; }
        var modal = document.querySelector('.modal.show');
        if (modal && window.bootstrap) { bootstrap.Modal.getOrCreateInstance(modal).hide(); return true; }
        var off = document.querySelector('.offcanvas.show');
        if (off && window.bootstrap) { bootstrap.Offcanvas.getOrCreateInstance(off).hide(); return true; }
        var fab = document.getElementById('fabActionOverlay');
        if (fab && getComputedStyle(fab).display !== 'none') { var c = document.getElementById('closeFabBtn'); if (c) c.click(); return true; }
        if (document.querySelector('.bottom-nav-bar.open')) {
            var x = document.querySelector('.drawer-close-btn') || document.querySelector('.sidebar-screen-overlay');
            if (x) x.click(); return true;
        }
        var prof = document.querySelector('.profile-menu-wrap.open .profile-menu-trigger');
        if (prof) { prof.click(); return true; }
        var dd = document.querySelector('.dropdown-menu.show');
        if (dd && window.bootstrap) {
            var tg = dd.parentElement && dd.parentElement.querySelector('[data-bs-toggle="dropdown"]');
            if (tg) { bootstrap.Dropdown.getOrCreateInstance(tg).hide(); return true; }
        }
        return false;
    }
    function backButton() {
        if (!isNative || !plugins.App) return;
        plugins.App.addListener('backButton', function (ev) {
            if (closeTopLayer()) return;
            if (page === 'index.php' || page === 'login.php' || page === '') { plugins.App.minimizeApp(); return; }
            if (ev && ev.canGoBack) { history.back(); return; }
            location.href = 'index.php';
        });
    }

    /* ---------- Files made in the page (blob:/data: links) → saved by the app ---------- */
    function nativeDownloads() {
        if (!isNative || !plugins.FileSaver) return;
        var blobs = {};
        var create = URL.createObjectURL;
        URL.createObjectURL = function (obj) {
            var url = create.apply(URL, arguments);
            if (obj instanceof Blob) {
                blobs[url] = obj;
                setTimeout(function () { delete blobs[url]; }, 120000);
            }
            return url;
        };
        var EXT_MIME = {
            docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            pdf: 'application/pdf', zip: 'application/zip', csv: 'text/csv',
            jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', txt: 'text/plain'
        };
        function save(a) {
            var href = a.href, name = a.getAttribute('download') || 'download';
            var blob = blobs[href];
            var got = blob ? Promise.resolve(blob) : fetch(href).then(function (r) { return r.blob(); });
            got.then(function (b) {
                return new Promise(function (resolve, reject) {
                    var fr = new FileReader();
                    fr.onload = function () { resolve({ b: b, data: String(fr.result).split(',')[1] || '' }); };
                    fr.onerror = reject;
                    fr.readAsDataURL(b);
                });
            }).then(function (r) {
                var ext = (name.split('.').pop() || '').toLowerCase();
                var mime = (r.b.type && r.b.type !== 'application/octet-stream') ? r.b.type : (EXT_MIME[ext] || 'application/octet-stream');
                return plugins.FileSaver.save({ name: name, mime: mime, data: r.data });
            }).catch(function () {
                alert('Could not save the file on this phone.');
            });
        }
        function wanted(a) {
            return a && a.hasAttribute && a.hasAttribute('download') && /^(blob|data):/i.test(a.getAttribute('href') || '');
        }
        var click = HTMLAnchorElement.prototype.click;
        HTMLAnchorElement.prototype.click = function () {
            if (wanted(this)) { save(this); return; }
            return click.apply(this, arguments);
        };
        document.addEventListener('click', function (e) {
            var a = e.target.closest && e.target.closest('a[download]');
            if (wanted(a)) { e.preventDefault(); save(a); }
        }, true);
    }

    /* ---------- Connection banner ---------- */
    function network() {
        var banner = null, hideT = null;
        function show(text, ok) {
            if (!banner) { banner = el('div', { id: 'ysms-net', role: 'status', 'aria-live': 'polite' }); document.body.appendChild(banner); }
            banner.textContent = text;
            banner.classList.toggle('ok', !!ok);
            void banner.offsetWidth;
            banner.classList.add('show');
            clearTimeout(hideT);
            if (ok) hideT = setTimeout(function () { banner.classList.remove('show'); }, 2500);
        }
        window.addEventListener('offline', function () { show('No internet connection — changes cannot be saved right now.'); });
        window.addEventListener('online', function () { show('Back online', true); });
    }

    function init() {
        splash();
        entrance();
        countUp();
        pullToRefresh();
        backButton();
        network();
    }
    nativeDownloads();
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();

/*
 * YSMS Android app screens (app.php). A small single-page app:
 *   #/login, #/home, #/vessels, #/reports, #/completed, #/profile (tabs), #/v/<id>, #/search
 * Data: app_api.php (read-only). Sign in / out, the latest-update form and the full tools
 * (uploads, survey form, report generator, admin pages) use the existing pages, so every
 * server check stays where it is.
 */
(function () {
    'use strict';

    var cap = window.Capacitor;
    var NATIVE = !!(cap && cap.isNativePlatform && cap.isNativePlatform());
    var P = (cap && cap.Plugins) || {};
    var REDUCE = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
    var appEl = document.getElementById('app');
    var tabbar = document.getElementById('tabbar');
    var THUMB = 'assets/app/vessel-thumb.jpg';
    var HERO = 'assets/app/vessel-hero.jpg';
    var LOGO = 'assets/images/logo.png';

    /* ---------------- helpers ---------------- */
    var ICONS = {
        home: '<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
        ship: '<path d="M2 21c.6.5 1.2 1 2.5 1 2.5 0 2.5-2 5-2 1.3 0 1.9.5 2.5 1 .6.5 1.2 1 2.5 1 2.5 0 2.5-2 5-2 1.3 0 1.9.5 2.5 1"/><path d="M19.4 17.9 21 12l-9-4-9 4 1.6 5.9"/><path d="M12 2v6"/>',
        file: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h5"/>',
        done: '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
        check: '<path d="M20 6 9 17l-5-5"/>',
        user: '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
        bell: '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        back: '<path d="m15 18-6-6 6-6"/>',
        chev: '<path d="m9 18 6-6-6-6"/>',
        pin: '<path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
        clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        alert: '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
        camera: '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3z"/><circle cx="12" cy="13" r="3"/>',
        upload: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
        download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
        chat: '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        chart: '<path d="M3 3v18h18"/><path d="m7 15 4-4 3 3 5-6"/>',
        history: '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
        plus: '<path d="M12 5v14M5 12h14"/>',
        edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        x: '<path d="M18 6 6 18M6 6l12 12"/>',
        eye: '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        eyeOff: '<path d="m3 3 18 18"/><path d="M10.6 5.1A10 10 0 0 1 12 5c6 0 10 7 10 7a17 17 0 0 1-3.2 3.9M6.6 6.6C3.9 8.3 2 12 2 12s4 7 10 7a9.7 9.7 0 0 0 5.4-1.6"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
        lock: '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        refresh: '<path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6"/>',
        sparkle: '<path d="M12 3l1.9 5.8L20 10l-6.1 1.2L12 17l-1.9-5.8L4 10l6.1-1.2z"/>',
        ban: '<circle cx="12" cy="12" r="9"/><path d="m5.7 5.7 12.6 12.6"/>',
        folder: '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        shield: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        users: '<circle cx="9" cy="8" r="4"/><path d="M2 21c0-4 3-6 7-6s7 2 7 6"/><path d="M16 4a4 4 0 0 1 0 8M22 21c0-3-2-5-5-6"/>',
        inbox: '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5h13L22 12v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-6z"/>',
        fuel: '<path d="M3 22V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v17"/><path d="M2 22h14"/><path d="M15 10h2a2 2 0 0 1 2 2v4a2 2 0 0 0 4 0V8l-3-3"/><path d="M6 8h6"/>',
        calendar: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
        globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
        more: '<circle cx="12" cy="5" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="12" cy="19" r="1.4"/>'
    };
    function icon(name, extra) {
        return '<svg class="i' + (extra ? ' ' + extra : '') + '" viewBox="0 0 24 24" aria-hidden="true">' + (ICONS[name] || '') + '</svg>';
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function el(html) { var t = document.createElement('template'); t.innerHTML = html.trim(); return t.content.firstElementChild; }
    function ss(key, val) {
        try { if (val === undefined) return sessionStorage.getItem(key); sessionStorage.setItem(key, val); } catch (e) { return null; }
        return null;
    }
    function haptic(style) { try { if (NATIVE && P.Haptics) P.Haptics.impact({ style: style || 'LIGHT' }); } catch (e) { /* none */ } }
    function fmtMt(v) { return Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 3, maximumFractionDigits: 3 }); }
    function toast(msg, kind) {
        var t = el('<div class="toast ' + (kind || '') + '" role="status">' + esc(msg) + '</div>');
        document.body.appendChild(t);
        setTimeout(function () { t.classList.add('out'); setTimeout(function () { t.remove(); }, 320); }, 2600);
    }

    var state = { me: null };

    function api(action, params) {
        var q = new URLSearchParams(Object.assign({ a: action }, params || {}));
        return fetch('app_api.php?' + q.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
            .then(function (r) {
                if (r.status === 401) { state.me = null; go('login', { replace: true, anim: 'fade' }); throw new Error('login'); }
                return r.json().then(function (d) { if (!r.ok) { var e = new Error(d.error || 'error'); e.status = r.status; throw e; } return d; });
            });
    }
    function loadMe() { return api('me').then(function (m) { state.me = m; return m; }); }

    // Full tools open the existing pages inside the app; Back returns here.
    function openWeb(url) {
        haptic();
        document.body.appendChild(el('<div class="sheet-back" style="background:rgba(244,246,251,.6)"></div>'));
        location.href = url;
    }

    /* ---------------- touch: ripple ---------------- */
    document.addEventListener('pointerdown', function (e) {
        if (REDUCE || e.button !== 0) return;
        var t = e.target.closest && e.target.closest('.press');
        if (!t) return;
        var r = t.getBoundingClientRect(), size = Math.max(r.width, r.height) * 2.2;
        var d = document.createElement('span');
        d.className = 'ripple';
        d.style.cssText = 'width:' + size + 'px;height:' + size + 'px;left:' + (e.clientX - r.left - size / 2) + 'px;top:' + (e.clientY - r.top - size / 2) + 'px';
        t.appendChild(d);
        setTimeout(function () { d.remove(); }, 600);
    }, { passive: true });

    /* ---------------- pull to refresh (per screen body) ---------------- */
    function pullToRefresh(scr, body, reload) {
        var ptr = el('<div class="ptr" aria-hidden="true">' + icon('refresh') + '</div>');
        scr.appendChild(ptr);
        var startY = null, pull = 0, busy = false, TRIGGER = 80;
        function draw() {
            var y = Math.min(pull, 130);
            ptr.style.transform = 'translateY(' + (y * .7 - 50 + body.offsetTop) + 'px)';
            ptr.style.opacity = String(Math.min(1, y / 50));
            ptr.firstChild.style.transform = 'rotate(' + (y * 2.6) + 'deg)';
            ptr.classList.toggle('ready', y >= TRIGGER);
        }
        body.addEventListener('touchstart', function (e) {
            startY = (!busy && body.scrollTop <= 0 && e.touches.length === 1 && !document.querySelector('.bsheet')) ? e.touches[0].clientY : null;
            pull = 0; ptr.classList.remove('back');
        }, { passive: true });
        body.addEventListener('touchmove', function (e) {
            if (startY === null) return;
            var dy = e.touches[0].clientY - startY;
            if (dy <= 0 || body.scrollTop > 0) { if (pull) { pull = 0; draw(); } return; }
            pull = dy * .55; draw();
        }, { passive: true });
        body.addEventListener('touchend', function () {
            if (startY === null) return;
            startY = null; ptr.classList.add('back');
            if (pull < TRIGGER) { pull = 0; ptr.style.opacity = '0'; ptr.style.transform = 'translateY(-60px)'; return; }
            busy = true; haptic('MEDIUM'); ptr.classList.add('loading');
            ptr.style.transform = 'translateY(' + (16 + body.offsetTop) + 'px)';
            Promise.resolve(reload()).catch(function () {}).then(function () {
                busy = false; pull = 0; ptr.classList.remove('loading');
                ptr.style.opacity = '0'; ptr.style.transform = 'translateY(-60px)';
            });
        }, { passive: true });
    }

    function countUp(node, value, decimals, suffix) {
        var target = Number(value) || 0;
        var fmt = function (v) { return (decimals ? fmtMt(v) : String(Math.round(v))) + (suffix || ''); };
        if (REDUCE || target === 0) { node.innerHTML = fmt(target); return; }
        var start = null;
        (function step(ts) {
            if (start === null) start = ts;
            var k = Math.min(1, (ts - start) / 900), e = 1 - Math.pow(1 - k, 3);
            node.innerHTML = fmt(target * e);
            if (k < 1) requestAnimationFrame(step);
        })(performance.now());
    }
    function stagger(root) {
        if (REDUCE) return;
        var n = 0;
        root.querySelectorAll('[data-rise]').forEach(function (x) {
            x.style.setProperty('--i', String(Math.min(n++, 10)));
            x.classList.add('rise');
            x.addEventListener('animationend', function () { x.classList.remove('rise'); }, { once: true });
        });
    }

    /* ---------------- router ---------------- */
    var TABS = ['home', 'vessels', 'reports', 'completed', 'profile'];
    var current = null; // { route, depth, node }
    function parse(hash) {
        var h = (hash || location.hash || '').replace(/^#\/?/, '');
        var m = h.match(/^v\/(\d+)$/);
        if (m) return { name: 'vessel', id: +m[1] };
        if (h === 'search') return { name: 'search' };
        if (h === 'login') return { name: 'login' };
        if (TABS.indexOf(h) !== -1) return { name: h };
        return { name: 'home' };
    }
    function hashFor(name, id) { return '#/' + (name === 'vessel' ? 'v/' + id : name); }

    // go('vessel', {id}) pushes a screen; tabs replace; login/home after sign-in replace.
    function go(name, opt) {
        opt = opt || {};
        var route = { name: name, id: opt.id };
        var tab = TABS.indexOf(name) !== -1;
        var depth = (history.state && history.state.d) || 0;
        if (opt.replace || tab || name === 'login') {
            history.replaceState({ d: tab || name === 'login' ? 0 : depth }, '', hashFor(name, opt.id));
            show(route, opt.anim || (tab ? 'tab' : 'fade'));
        } else {
            history.pushState({ d: depth + 1 }, '', hashFor(name, opt.id));
            show(route, 'push');
        }
    }
    window.addEventListener('popstate', function (e) {
        closeSheet(true);
        var route = parse(location.hash);
        var d = (e.state && e.state.d) || 0;
        var anim = current && d < current.depth ? 'pop' : 'push';
        show(route, anim, d);
    });

    function show(route, anim, depthOverride) {
        if (route.name !== 'login' && !state.me) {
            return loadMe().then(function () { show(route, anim, depthOverride); }, function () {});
        }
        var builder = SCREENS[route.name] || SCREENS.home;
        var node = builder(route);
        var prev = current && current.node;
        appEl.appendChild(node);
        var tab = TABS.indexOf(route.name) !== -1;
        renderTabbar(tab ? route.name : null);
        if (prev && !REDUCE) {
            node.classList.add('enter-' + anim);
            prev.classList.add('leave-' + anim);
            setTimeout(function () { prev.remove(); node.classList.remove('enter-' + anim); }, 380);
        } else if (prev) {
            prev.remove();
        } else if (anim === 'fade') {
            node.classList.add('enter-fade');
        }
        current = { route: route, depth: depthOverride != null ? depthOverride : ((history.state && history.state.d) || 0), node: node };
        stagger(node);
        return node;
    }

    function renderTabbar(active) {
        if (!active || !state.me) { tabbar.classList.add('away'); return; }
        tabbar.hidden = false;
        tabbar.classList.remove('away');
        var items = [['home', 'home', 'Home'], ['vessels', 'ship', 'Vessels'], ['reports', 'file', 'Reports'], ['completed', 'done', 'Completed'], ['profile', 'user', 'Profile']];
        tabbar.innerHTML = items.map(function (t) {
            var dot = t[0] === 'profile' && state.me && state.me.unread > 0 ? '<span class="badge-dot"></span>' : '';
            return '<button type="button" class="tab press" data-tab="' + t[0] + '"' + (t[0] === active ? ' aria-current="page"' : '') + '>' +
                '<span class="pill"></span>' + icon(t[1]) + t[2] + dot + '</button>';
        }).join('');
    }
    tabbar.addEventListener('click', function (e) {
        var b = e.target.closest('[data-tab]');
        if (!b || b.getAttribute('aria-current')) return;
        haptic();
        go(b.getAttribute('data-tab'));
    });

    /* ---------------- bottom sheet ---------------- */
    var sheetOpen = null;
    function openSheet(html, onReady) {
        closeSheet(true);
        var back = el('<div class="sheet-back"></div>');
        var sh = el('<div class="bsheet" role="dialog" aria-modal="true"><div class="grab"></div>' + html + '</div>');
        document.body.appendChild(back); document.body.appendChild(sh);
        back.addEventListener('click', function () { closeSheet(); });
        sheetOpen = { back: back, sh: sh };
        if (onReady) onReady(sh);
        return sh;
    }
    function closeSheet(now) {
        if (!sheetOpen) return false;
        var s = sheetOpen; sheetOpen = null;
        if (now || REDUCE) { s.back.remove(); s.sh.remove(); return true; }
        s.back.classList.add('closing'); s.sh.classList.add('closing');
        setTimeout(function () { s.back.remove(); s.sh.remove(); }, 260);
        return true;
    }

    /* ---------------- screens ---------------- */
    var SCREENS = {};

    SCREENS.login = function () {
        var scr = el('<section class="scr login" aria-label="Sign in"><div class="scr-body">' +
            '<div class="login-top">' +
            '<svg class="waves" viewBox="0 0 390 160" preserveAspectRatio="none" fill="none" aria-hidden="true">' +
            '<path d="M-30 70 C 70 45, 130 95, 195 70 S 320 45, 420 70" stroke="#fff" stroke-opacity=".07" stroke-width="2"/>' +
            '<path d="M-30 115 C 70 90, 130 140, 195 115 S 320 90, 420 115" stroke="#fff" stroke-opacity=".05" stroke-width="2"/></svg>' +
            '<div class="logo-tile"><img src="' + LOGO + '" alt="YMR Marine logo"></div>' +
            '<div><h1>Welcome back</h1><p>Sign in to YMR Marine YSMS</p></div></div>' +
            '<form class="login-card" novalidate>' +
            '<div class="err" hidden role="alert">' + icon('alert') + '<span></span></div>' +
            '<div class="field"><label for="lg-user">Username</label><div class="inp">' + icon('user') +
            '<input id="lg-user" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" required></div></div>' +
            '<div class="field"><label for="lg-pass">Password</label><div class="inp">' + icon('lock') +
            '<input id="lg-pass" name="password" type="password" autocomplete="current-password" required>' +
            '<button type="button" class="icon-btn plain" data-eye aria-label="Show password">' + icon('eye') + '</button></div></div>' +
            '<div class="opts"><label class="check"><input type="checkbox" name="remember" checked> Keep me signed in</label>' +
            '<a href="forgot_password.php" data-web>Forgot password?</a></div>' +
            '<button type="submit" class="btn primary press">Sign in</button>' +
            '</form><p class="foot">YMR Marine · Survey Management System</p></div></section>');
        var form = scr.querySelector('form'), err = scr.querySelector('.err'), btn = form.querySelector('[type=submit]');
        scr.querySelector('[data-eye]').addEventListener('click', function () {
            var p = form.password, show = p.type === 'password';
            p.type = show ? 'text' : 'password';
            this.innerHTML = icon(show ? 'eyeOff' : 'eye');
            this.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var u = form.username.value.trim(), p = form.password.value;
            if (!u || !p) { fail('Enter your username and password.'); return; }
            btn.disabled = true; btn.innerHTML = '<span class="spin"></span> Signing in…';
            err.hidden = true;
            fetch('login.php', {
                method: 'POST', credentials: 'same-origin', redirect: 'manual',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ username: u, password: p }).toString()
            }).then(function (r) {
                // login.php answers a correct sign-in with a redirect to index.php.
                if (r.type === 'opaqueredirect' || r.redirected || (r.status >= 300 && r.status < 400)) return loadMe();
                throw new Error('bad');
            }).then(function () {
                haptic('MEDIUM');
                go('home', { replace: true, anim: 'fade' });
            }).catch(function (x) {
                fail(x && x.message === 'bad' ? 'Wrong username or password.' : 'Could not connect. Check the internet and try again.');
            });
        });
        function fail(msg) {
            btn.disabled = false; btn.textContent = 'Sign in';
            err.hidden = false; err.querySelector('span').textContent = msg;
            err.style.animation = 'none'; void err.offsetWidth; err.style.animation = '';
            haptic('HEAVY');
        }
        return scr;
    };

    function statTile(key, ic, cls, label) {
        return '<button type="button" class="stat press" data-rise data-go="' + key + '">' +
            '<span class="ic ' + cls + '">' + icon(ic) + '</span><span class="n" data-n="' + key + '">–</span><span class="l">' + label + '</span></button>';
    }

    SCREENS.home = function () {
        var me = state.me;
        var avatar = me.avatar ? '<img src="' + esc(me.avatar) + '" alt="">' : esc(me.initials);
        var scr = el('<section class="scr has-tabs" aria-label="Home"><div class="scr-body">' +
            '<header class="hdr-navy"><div class="hdr-row">' +
            '<button type="button" class="who press" data-go="profile" style="border:0;background:transparent;color:inherit;padding:0;text-align:left">' +
            '<span class="avatar">' + avatar + '</span><span style="min-width:0"><small>' + esc(me.greeting) + '</small><b>' + esc(me.name) + '</b></span></button>' +
            (me.can.inbox ? '<button type="button" class="icon-btn glass press" data-web="inbox.php" aria-label="Inbox' + (me.unread ? ', ' + me.unread + ' unread' : '') + '">' + icon('bell') + (me.unread ? '<span class="dot"></span>' : '') + '</button>' : '') +
            '</div></header>' +
            '<button type="button" class="search lift press" data-go="search" style="border:0;text-align:left;width:calc(100% - 32px)">' + icon('search') + '<span style="flex:1">Search vessel, port, client…</span></button>' +
            '<div class="scr-pad">' +
            '<div class="stats">' +
            statTile('vessels', 'ship', 'blue', 'Pending Vessels') + statTile('reports', 'file', 'orange', 'Pending Reports') +
            statTile('completed', 'check', 'green', 'Completed') +
            '<button type="button" class="stat press" data-rise' + (me.can.recovery ? ' data-web="recovery.php"' : '') + '><span class="ic sky">' + icon('chart') + '</span><span class="n" data-n="rec">–</span><span class="l">Recovery this month (MT)</span></button>' +
            '</div><div data-attention></div>' +
            '<div class="section-title" data-rise>Quick actions</div><div class="quick" data-rise>' + quickActions(me) + '</div>' +
            '<div data-month></div>' +
            '</div></div></section>');
        var body = scr.querySelector('.scr-body');
        function load() {
            return api('dashboard').then(function (d) {
                countUp(scr.querySelector('[data-n=vessels]'), d.counts.pending_vessel);
                countUp(scr.querySelector('[data-n=reports]'), d.counts.pending_report);
                countUp(scr.querySelector('[data-n=completed]'), d.counts.completed);
                countUp(scr.querySelector('[data-n=rec]'), d.recovery_month, 3);
                var att = scr.querySelector('[data-attention]');
                att.innerHTML = d.attention.length ? '<section class="attention" data-rise><div class="attention-head">' + icon('clock') + 'Needs attention</div>' +
                    d.attention.map(function (a) {
                        return '<button type="button" class="att-row press ' + a.kind + '" data-vessel="' + a.id + '"><span><b>' + esc(a.vessel) + '</b><small>' +
                            (a.kind === 'survey' ? 'Survey pending · ' + a.days + ' days since appointment' : 'Report overdue · ' + a.days + ' days since survey') +
                            '</small></span>' + icon('chev', 'chev') + '</button>';
                    }).join('') + '</section>' : '';
                scr.querySelector('[data-month]').innerHTML = '<div class="month-card" data-rise><div class="h">' + icon('calendar') + esc(d.month) + '</div>' +
                    '<div><div class="n">' + d.ships_month + '</div><div class="l">Ships surveyed</div></div>' +
                    '<div><div class="n">' + fmtMt(d.recovery_month) + ' MT</div><div class="l">Recovery</div></div>' +
                    '<div><div class="n">' + fmtMt(d.recovery_total) + ' MT</div><div class="l">Total recovery</div></div></div>';
                stagger(scr);
            });
        }
        load().catch(function (e) { if (e.message !== 'login') toast('Could not load the dashboard', 'bad'); });
        pullToRefresh(scr, body, function () { return Promise.all([loadMe(), load()]); });
        return scr;
    };

    function quickActions(me) {
        var all = [
            me.can.assign && [me.role === 'Client' ? 'Request survey' : 'Assign vessel', 'plus', 'assign_vessel.php', true],
            me.can.report_generator && ['Word report', 'file', 'report_generator.php'],
            me.can.recovery && ['Recovery', 'fuel', 'recovery.php'],
            me.can.chat && ['Team chat', 'chat', 'team_chat.php'],
            me.role !== 'Client' && ['Formats', 'folder', 'formats_download.php'],
            ['Cancelled', 'ban', 'cancelled.php']
        ].filter(Boolean).slice(0, 4);
        return all.map(function (a, i) {
            return '<button type="button" class="qa" data-web="' + a[2] + '"><span class="q press' + (i === 0 ? ' solid' : '') + '">' + icon(a[1]) + '</span>' + esc(a[0]) + '</button>';
        }).join('');
    }

    var LISTS = {
        vessels: { status: 'pending_vessel', title: 'Pending Vessels', empty: 'No pending vessels', overdue: 'Overdue' },
        reports: { status: 'pending_report', title: 'Pending Reports', empty: 'No pending reports', overdue: 'Report overdue' },
        completed: { status: 'completed', title: 'Completed', empty: 'No completed vessels' }
    };

    function vcard(v) {
        var over = v.overdue;
        var tag = over ? '<span class="tag ' + (over.kind === 'report' ? 'red' : 'orange') + '">' + (over.kind === 'report' ? 'Report overdue' : 'Overdue') + '</span>'
            : '<span class="tag ' + (v.status === 'Completed' ? 'green' : 'blue') + '">' + esc(v.type || v.status) + '</span>';
        var when = v.status === 'Completed' ? (v.report_on || v.completed_on) : v.status === 'Pending Report' ? v.completed_on : v.appointment;
        var whenLabel = v.status === 'Completed' ? 'Report' : v.status === 'Pending Report' ? 'Surveyed' : 'Appointment';
        return '<button type="button" class="vcard press' + (over ? ' overdue ' + over.kind : '') + '" data-rise data-vessel="' + v.id + '">' +
            '<span class="vtop"><img src="' + THUMB + '" alt="" loading="lazy"><span class="t"><b>' + esc(v.vessel) + '</b><span>' + esc(v.client) + '</span></span>' + tag + '</span>' +
            '<span class="meta"><span>' + icon('pin') + esc(v.port || '—') + '</span>' +
            (v.surveyors ? '<span>' + icon('user') + esc(v.surveyors) + '</span>' : '') +
            (when ? '<span>' + icon('calendar') + whenLabel + ' ' + esc(when) + '</span>' : '') + '</span>' +
            (v.update ? '<span class="upd"><span>Latest update · </span>' + esc(v.update) + '</span>' : '') +
            '</button>';
    }
    function skeletons(n) {
        var s = '';
        for (var i = 0; i < n; i++) s += '<div class="skel-card"><div style="display:flex;gap:12px;align-items:center"><div class="skel" style="width:56px;height:56px;border-radius:14px"></div><div style="flex:1;display:flex;flex-direction:column;gap:8px"><div class="skel" style="height:14px;width:70%"></div><div class="skel" style="height:12px;width:45%"></div></div></div><div class="skel" style="height:12px;width:60%"></div></div>';
        return s;
    }

    function listScreen(key) {
        var cfg = LISTS[key], me = state.me;
        var showOverdue = cfg.overdue && ['Admin', 'Super Admin', 'Surveyor'].indexOf(me.role) !== -1;
        var scr = el('<section class="scr has-tabs" aria-label="' + cfg.title + '">' +
            '<header class="hdr-white"><div class="hdr-title-row"><div><h1>' + cfg.title + '</h1><div class="hdr-sub" data-total>&nbsp;</div></div></div>' +
            '<label class="search flat">' + icon('search') + '<span class="sr">Search</span><input type="search" placeholder="Search vessel, port, client" enterkeyhint="search"></label>' +
            (showOverdue ? '<div class="chips"><button type="button" class="chip" aria-pressed="true" data-f="">All</button><button type="button" class="chip warn" aria-pressed="false" data-f="1">' + cfg.overdue + '</button></div>' : '') +
            '</header><div class="scr-body"><div class="scr-pad"><div class="list">' + skeletons(4) + '</div><div class="more-loader" hidden><span class="spin dark"></span></div></div></div>' +
            (key === 'vessels' && me.can.assign ? '<button type="button" class="fab press" data-web="assign_vessel.php" aria-label="' + (me.role === 'Client' ? 'Request survey' : 'Assign vessel') + '">' + icon('plus') + '</button>' : '') +
            '</section>');
        var body = scr.querySelector('.scr-body'), list = scr.querySelector('.list'), loader = scr.querySelector('.more-loader');
        var input = scr.querySelector('input'), totalEl = scr.querySelector('[data-total]');
        var q = '', overdue = '', page = 1, more = false, loading = false, seq = 0;
        function load(reset) {
            if (reset) { page = 1; more = false; }
            var my = ++seq;
            loading = true;
            var params = { status: cfg.status, page: page };
            if (q) params.q = q;
            if (overdue) params.overdue = 1;
            return api('list', params).then(function (d) {
                if (my !== seq) return;
                totalEl.textContent = d.total + (d.total === 1 ? ' vessel' : ' vessels');
                var html = d.items.map(vcard).join('');
                if (reset) list.innerHTML = html || '<div class="empty"><span class="ic blue">' + icon(key === 'completed' ? 'done' : 'ship') + '</span><b>' + (q || overdue ? 'Nothing matches' : cfg.empty) + '</b></div>';
                else list.insertAdjacentHTML('beforeend', html);
                more = d.more; loader.hidden = !more;
                stagger(list);
            }).catch(function (e) { if (e.message !== 'login') toast('Could not load the list', 'bad'); })
              .then(function () { if (my === seq) loading = false; });
        }
        var t = null;
        input.addEventListener('input', function () {
            clearTimeout(t);
            t = setTimeout(function () { q = input.value.trim(); list.innerHTML = skeletons(3); body.scrollTop = 0; load(true); }, 300);
        });
        scr.querySelectorAll('[data-f]').forEach(function (c) {
            c.addEventListener('click', function () {
                haptic();
                scr.querySelectorAll('[data-f]').forEach(function (x) { x.setAttribute('aria-pressed', x === c ? 'true' : 'false'); });
                overdue = c.getAttribute('data-f'); list.innerHTML = skeletons(3); body.scrollTop = 0; load(true);
            });
        });
        if ('IntersectionObserver' in window) {
            new IntersectionObserver(function (en) {
                if (en[0].isIntersecting && more && !loading) { page++; load(false); }
            }, { root: body, rootMargin: '300px' }).observe(loader);
        }
        pullToRefresh(scr, body, function () { return load(true); });
        load(true);
        return scr;
    }
    SCREENS.vessels = function () { return listScreen('vessels'); };
    SCREENS.reports = function () { return listScreen('reports'); };
    SCREENS.completed = function () { return listScreen('completed'); };

    SCREENS.search = function () {
        var scr = el('<section class="scr" aria-label="Search">' +
            '<header class="hdr-bar"><button type="button" class="icon-btn plain press" data-back aria-label="Back">' + icon('back') + '</button>' +
            '<label class="search flat" style="flex:1">' + icon('search') + '<span class="sr">Search all vessels</span><input type="search" placeholder="Vessel, port, client, agent" enterkeyhint="search"></label></header>' +
            '<div class="scr-body"><div class="scr-pad"><div class="list"><div class="empty"><span class="ic blue">' + icon('search') + '</span>Search pending, report and completed vessels</div></div></div></div></section>');
        var input = scr.querySelector('input'), list = scr.querySelector('.list'), t = null, seq = 0;
        input.addEventListener('input', function () {
            clearTimeout(t);
            var q = input.value.trim();
            if (q.length < 2) return;
            t = setTimeout(function () {
                var my = ++seq;
                list.innerHTML = skeletons(3);
                Promise.all(['pending_vessel', 'pending_report', 'completed'].map(function (s) { return api('list', { status: s, q: q }); })).then(function (r) {
                    if (my !== seq) return;
                    var labels = ['Pending Vessels', 'Pending Reports', 'Completed'], html = '';
                    r.forEach(function (d, i) {
                        if (!d.items.length) return;
                        html += '<div class="section-title">' + labels[i] + '<span>' + d.total + '</span></div>' + d.items.slice(0, 10).map(vcard).join('');
                    });
                    list.innerHTML = html || '<div class="empty"><span class="ic blue">' + icon('search') + '</span>Nothing matches “' + esc(q) + '”</div>';
                    stagger(list);
                }).catch(function () {});
            }, 300);
        });
        setTimeout(function () { input.focus(); }, 400);
        return scr;
    };

    function docType(d) {
        var n = (d.name || '').toLowerCase();
        if (/\.pdf$/.test(n)) return ['pdf', 'PDF'];
        if (/\.xlsx?$|\.csv$/.test(n)) return ['xls', 'XLS'];
        if (/\.docx?$/.test(n)) return ['doc', 'DOC'];
        return ['other', (n.split('.').pop() || 'FILE').slice(0, 4).toUpperCase()];
    }

    SCREENS.vessel = function (route) {
        var scr = el('<section class="scr" aria-label="Vessel details"><div class="scr-body">' +
            '<div class="hero"><img src="' + HERO + '" alt=""><div class="bar">' +
            '<button type="button" class="icon-btn white press" data-back aria-label="Back">' + icon('back') + '</button>' +
            '<button type="button" class="icon-btn white press" data-more aria-label="More actions" hidden><svg class="i" viewBox="0 0 24 24" aria-hidden="true" style="fill:currentColor">' + ICONS.more + '</svg></button></div></div>' +
            '<div class="sheet"><div class="skel" style="height:26px;width:60%"></div><div class="skel" style="height:14px;width:45%"></div><div class="skel-card" style="height:220px"></div></div>' +
            '</div></section>');
        var body = scr.querySelector('.scr-body');
        function load() {
            return api('survey', { id: route.id }).then(function (v) { render(v); }).catch(function (e) {
                if (e.message === 'login') return;
                scr.querySelector('.sheet').innerHTML = '<div class="empty"><span class="ic red">' + icon('alert') + '</span><b>' +
                    (e.status === 404 ? 'This vessel is not available to you' : 'Could not load this vessel') + '</b></div>';
            });
        }
        function render(v) {
            var over = v.overdue;
            var statusTag = { 'Pending Vessel': ['blue', 'Pending Vessel'], 'Pending Report': ['orange', 'Pending Report'], 'Completed': ['green', 'Completed'] }[v.status] || ['blue', v.status];
            var rows = [
                ['Appointment', v.appointment], ['Survey type', v.type], ['Surveyor', v.surveyors], ['Agent', v.agent],
                ['Report no.', v.report_no], ['IMO', v.imo], ['Assigned on', v.assigned_on], ['Survey completed', v.completed_on], ['Report completed', v.report_on]
            ].filter(function (r) { return r[1]; });
            var html =
                '<div data-rise><div class="title-row"><h1>' + esc(v.vessel) + '</h1><span class="tag ' + statusTag[0] + '">' + statusTag[1] + '</span></div>' +
                '<div class="subline">' + esc(v.client) + (v.port ? ' · ' + esc(v.port) : '') + '</div></div>' +
                (over ? '<div class="alert ' + over.kind + '" role="status" data-rise>' + icon('alert') + '<div><b>' + (over.kind === 'survey' ? 'Survey pending.' : 'Report overdue.') + '</b> ' +
                    over.days + ' days since the ' + (over.kind === 'survey' ? 'appointment date' : 'survey was completed') + '.</div></div>' : '') +
                '<section class="card rows" data-rise>' + rows.map(function (r) { return '<div class="kv"><span>' + r[0] + '</span><b>' + esc(r[1]) + '</b></div>'; }).join('') +
                (v.remarks ? '<div class="kv"><span style="color:#c2410c">Admin remarks</span><b>' + esc(v.remarks) + '</b></div>' : '') + '</section>' +
                '<section class="card card-pad" data-rise><div class="card-h">Latest update' + (v.can_update ? '<button type="button" class="link-btn" data-update>' + (v.update ? 'Edit' : 'Add') + '</button>' : '') + '</div>' +
                (v.update ? '<div style="font-size:14px">' + esc(v.update) + '</div><div style="font-size:12px;color:#64748b">' + esc(v.update_when) + (v.update_by ? ' · by ' + esc(v.update_by) : '') + '</div>'
                    : '<div style="font-size:13px;color:#64748b">No updates recorded yet.</div>') + '</section>' +
                '<section class="card card-pad" data-rise><div class="card-h">Documents<span style="font-size:12px;color:#64748b;font-weight:500">' + v.docs.length + '</span></div>' +
                (v.docs.length ? v.docs.map(function (d) {
                    var t = docType(d);
                    return '<a class="doc press" href="download.php?upload_id=' + d.id + '"><span class="ft ' + t[0] + '">' + t[1] + '</span><span class="t"><b>' + esc(d.name) + '</b><small>' + esc(d.type) + (d.when ? ' · ' + esc(d.when) : '') + '</small></span>' + icon('download', 'chev') + '</a>';
                }).join('') : '<div style="font-size:13px;color:#64748b">No documents uploaded yet.</div>') + '</section>' +
                (v.can_history ? '<button type="button" class="row-link press" data-rise data-web="' + esc(v.web) + '#vessel-history"><span class="ic purple" style="width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center">' + icon('history') + '</span><span class="t"><b>Previous surveys of this vessel</b><small>Documents and recovery</small></span>' + icon('chev', 'chev') + '</button>' : '') +
                '<button type="button" class="row-link press" data-rise data-web="' + esc(v.web) + '"><span class="ic blue" style="width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center">' + icon('globe') + '</span><span class="t"><b>Open full page</b><small>Every detail and option of this vessel</small></span>' + icon('chev', 'chev') + '</button>';
            var sheet = scr.querySelector('.sheet');
            sheet.innerHTML = html;
            var acts = [];
            if (v.can_survey_form) acts.push('<button type="button" class="btn outline press" data-web="survey_form.php?survey_id=' + v.id + '">' + icon('camera') + 'Survey form</button>');
            if (v.can_upload) acts.push('<button type="button" class="btn primary press" data-web="' + esc(v.web) + '#upload">' + icon('upload') + 'Upload report</button>');
            var old = scr.querySelector('.actions'); if (old) old.remove();
            if (acts.length) scr.appendChild(el('<div class="actions' + (acts.length === 1 ? ' one' : '') + '">' + acts.join('') + '</div>'));
            else sheet.style.paddingBottom = '32px';
            var more = scr.querySelector('[data-more]');
            more.hidden = false;
            more.onclick = function () {
                openSheet('<h2>' + esc(v.vessel) + '</h2>' +
                    '<button type="button" class="menu-item press" data-web="' + esc(v.web) + '">' + icon('globe') + 'Open full page</button>' +
                    (v.can_survey_form ? '<button type="button" class="menu-item press" data-web="survey_form.php?survey_id=' + v.id + '">' + icon('camera') + 'Bunker survey form from photos</button>' : '') +
                    (state.me.can.report_generator && v.status !== 'Pending Vessel' ? '<button type="button" class="menu-item press" data-web="report_generator.php?id=' + v.id + '">' + icon('file') + 'Word report generator</button>' : '') +
                    '<button type="button" class="menu-item press" data-refresh>' + icon('refresh') + 'Refresh</button>');
            };
            var up = scr.querySelector('[data-update]');
            if (up) up.onclick = function () { updateSheet(v); };
            stagger(scr);
        }
        function updateSheet(v) {
            openSheet('<h2>Latest update</h2><label class="sr" for="lu">Latest update</label>' +
                '<textarea id="lu" maxlength="1000" placeholder="e.g. ETA 22nd 16:00 hours">' + esc(v.update) + '</textarea>' +
                '<div class="sheet-actions"><button type="button" class="btn primary press" data-save>Save update</button><button type="button" class="btn ghost" data-cancel>Cancel</button></div>',
            function (sh) {
                var ta = sh.querySelector('textarea');
                setTimeout(function () { ta.focus(); }, 350);
                sh.querySelector('[data-cancel]').onclick = function () { closeSheet(); };
                sh.querySelector('[data-save]').onclick = function () {
                    var text = ta.value.trim(), b = this;
                    if (!text) { ta.focus(); return; }
                    b.disabled = true; b.innerHTML = '<span class="spin"></span> Saving…';
                    // The same form vessel_detail.php shows, so its checks and notifications apply.
                    fetch('vessel_detail.php?id=' + v.id, {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({ csrf_token: state.me.csrf, latest_status: text, update_latest_status: '1' }).toString()
                    }).then(function (r) {
                        if (!r.ok) throw new Error('save');
                        return r.text();
                    }).then(function (html) {
                        if (!/Latest update registered successfully/.test(html)) throw new Error('save');
                        closeSheet(); haptic('MEDIUM'); toast('Latest update saved', 'ok'); load();
                    }).catch(function () {
                        b.disabled = false; b.textContent = 'Save update';
                        toast('Could not save the update', 'bad');
                    });
                };
            });
        }
        pullToRefresh(scr, body, load);
        load();
        return scr;
    };

    SCREENS.profile = function () {
        var me = state.me;
        var avatar = me.avatar ? '<img src="' + esc(me.avatar) + '" alt="">' : esc(me.initials);
        function item(label, ic, cls, url, extra) {
            return '<button type="button" class="menu-item press" data-web="' + url + '"><span class="ic ' + cls + '">' + icon(ic) + '</span><span class="grow">' + label + '</span>' + (extra || '') + icon('chev', 'chev') + '</button>';
        }
        var work = [
            me.can.inbox && item('Inbox', 'inbox', 'blue', 'inbox.php', me.unread ? '<span class="count">' + (me.unread > 99 ? '99+' : me.unread) + '</span>' : ''),
            me.can.chat && item('Team chat', 'chat', 'purple', 'team_chat.php'),
            me.can.recovery && item('Recovery', 'fuel', 'sky', 'recovery.php'),
            me.can.report_generator && item('Word report generator', 'file', 'orange', 'report_generator.php'),
            me.role !== 'Client' && item('Formats', 'folder', 'green', 'formats_download.php'),
            item('Cancelled vessels', 'ban', 'red', 'cancelled.php')
        ].filter(Boolean).join('');
        var admin = me.role === 'Admin' ? [
            item('Assign vessel', 'plus', 'blue', 'assign_vessel.php'), item('Add surveyor', 'user', 'orange', 'add_surveyor.php'),
            item('Add client', 'users', 'green', 'add_client.php'), item('Admin controls', 'shield', 'purple', 'admin_controls.php'),
            item('Manage templates', 'file', 'sky', 'manage_templates.php'), item('Login activity', 'shield', 'red', 'login_activity.php')
        ].join('') : me.role === 'Super Admin' ? [
            item('Add client', 'users', 'green', 'add_client.php'), item('Add admin', 'shield', 'purple', 'add_admin.php'),
            item('Manage clients', 'users', 'blue', 'super_admin_clients.php'), item('Login activity', 'shield', 'red', 'login_activity.php')
        ].join('') : me.role === 'Client' ? item('Request survey', 'plus', 'blue', 'assign_vessel.php') : '';
        var scr = el('<section class="scr has-tabs" aria-label="Profile"><div class="scr-body">' +
            '<header class="profile-hdr"><span class="avatar">' + avatar + '</span><b>' + esc(me.name) + '</b><small>' + esc(me.role) + '</small></header>' +
            '<div class="scr-pad">' +
            '<div class="section-title" data-rise>Work</div><div class="menu-card" data-rise>' + work + '</div>' +
            (admin ? '<div class="section-title" data-rise>' + (me.role === 'Client' ? 'Client' : 'Admin') + '</div><div class="menu-card" data-rise>' + admin + '</div>' : '') +
            '<div class="section-title" data-rise>Account</div><div class="menu-card" data-rise>' + item('My profile', 'user', 'blue', 'profile.php') +
            '<button type="button" class="menu-item press" data-logout style="color:#b91c1c"><span class="ic red">' + icon('logout') + '</span><span class="grow">Log out</span></button></div>' +
            '</div></div></section>');
        scr.querySelector('[data-logout]').addEventListener('click', function () {
            openSheet('<h2>Log out?</h2><p style="margin:0 0 6px;color:#64748b">You will need your username and password to sign in again.</p>' +
                '<div class="sheet-actions"><button type="button" class="btn danger press" data-yes>Log out</button><button type="button" class="btn ghost" data-no>Cancel</button></div>',
            function (sh) {
                sh.querySelector('[data-no]').onclick = function () { closeSheet(); };
                sh.querySelector('[data-yes]').onclick = function () {
                    fetch('logout.php', { credentials: 'same-origin', redirect: 'manual' }).catch(function () {}).then(function () {
                        closeSheet(true); state.me = null; go('login', { anim: 'fade' });
                    });
                };
            });
        });
        return scr;
    };

    /* ---------------- global taps ---------------- */
    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-web],[data-go],[data-vessel],[data-back],[data-refresh]');
        if (!t) return;
        if (t.hasAttribute('data-web')) {
            e.preventDefault();
            var url = t.getAttribute('data-web') || t.getAttribute('href');
            closeSheet(true); openWeb(url); return;
        }
        if (t.hasAttribute('data-vessel')) { haptic(); go('vessel', { id: +t.getAttribute('data-vessel') }); return; }
        if (t.hasAttribute('data-go')) { haptic(); go(t.getAttribute('data-go')); return; }
        if (t.hasAttribute('data-back')) { back(); return; }
        if (t.hasAttribute('data-refresh')) { closeSheet(); location.reload(); }
    });

    function back() {
        if (closeSheet()) return;
        var r = current && current.route;
        if (current && current.depth > 0) { history.back(); return; }
        if (r && r.name !== 'home' && r.name !== 'login' && TABS.indexOf(r.name) !== -1) { go('home'); return; }
        if (r && (r.name === 'vessel' || r.name === 'search')) { go('home'); return; }
        if (NATIVE && P.App) P.App.minimizeApp();
    }
    if (NATIVE && P.App) P.App.addListener('backButton', function () { back(); });
    // Coming back from a full page (Back): drop the dimmer left from leaving.
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) document.querySelectorAll('.sheet-back').forEach(function (n) { n.remove(); });
    });

    /* ---------------- start ---------------- */
    function splash(ready) {
        var first = !ss('ysmsSplashDone');
        ss('ysmsSplashDone', '1');
        try { if (NATIVE && P.SplashScreen) P.SplashScreen.hide({ fadeOutDuration: 150 }); } catch (e) { /* none */ }
        if (!first || REDUCE) return;
        var s = el('<div id="splash" aria-hidden="true">' +
            '<svg class="waves" viewBox="0 0 390 260" preserveAspectRatio="none" fill="none">' +
            '<path d="M-30 120 C 70 95, 130 145, 195 120 S 320 95, 420 120" stroke="#fff" stroke-opacity=".08" stroke-width="2"/>' +
            '<path d="M-30 170 C 70 145, 130 195, 195 170 S 320 145, 420 170" stroke="#fff" stroke-opacity=".06" stroke-width="2"/>' +
            '<path d="M-30 220 C 70 195, 130 245, 195 220 S 320 195, 420 220" stroke="#fff" stroke-opacity=".05" stroke-width="2"/></svg>' +
            '<div class="mark"><img src="' + LOGO + '" alt=""></div><div class="txt"><b>YMR MARINE</b><span>Survey Management System</span></div><div class="bar"><i></i></div></div>');
        document.body.appendChild(s);
        var t0 = Date.now();
        Promise.resolve(ready).catch(function () {}).then(function () {
            setTimeout(function () { s.classList.add('out'); setTimeout(function () { s.remove(); }, 650); }, Math.max(0, 1200 - (Date.now() - t0)));
        });
        setTimeout(function () { if (s.isConnected) s.remove(); }, 7000);
    }

    var route = parse(location.hash);
    var ready;
    if (!window.YSMS_APP.loggedIn) {
        ready = Promise.resolve();
        history.replaceState({ d: 0 }, '', '#/login');
        show({ name: 'login' }, 'fade');
    } else {
        ready = loadMe().then(function () {
            if (route.name === 'login') route = { name: 'home' };
            var d = (history.state && history.state.d) || 0;
            if (!history.state) history.replaceState({ d: route.name === 'vessel' || route.name === 'search' ? 1 : 0 }, '', hashFor(route.name, route.id));
            show(route, 'fade', d);
        }).catch(function () {});
    }
    splash(ready);
})();

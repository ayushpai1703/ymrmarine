/*
 * YSMS Android app screens (app.php). A small single-page app:
 *   #/login, #/home, #/vessels, #/reports, #/completed, #/profile (tabs), #/v/<id>, #/up/<id> (upload),
 *   #/search, #/inbox, #/recovery, #/cancelled, #/formats, #/me (edit profile)
 * Data: app_api.php (read-only). Sign in / out, the latest-update form and the full tools
 * (team chat, report generator, admin pages) use the existing pages, so every
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
        var m = h.match(/^(v|up|h|c|e|sv)\/(\d+)$/);
        if (m) return { name: ROUTE_IDS[m[1]], id: +m[2] };
        m = h.match(/^m\/([a-z_]+)$/);
        if (m) return { name: 'module', id: m[1] };
        if (PUSHED.indexOf(h) !== -1 || h === 'login') return { name: h };
        if (TABS.indexOf(h) !== -1) return { name: h };
        return { name: 'home' };
    }
    var PUSHED = ['search', 'inbox', 'recovery', 'cancelled', 'formats', 'me', 'chat', 'assign', 'admin', 'addclient', 'addadmin', 'surveyor'];
    var ROUTE_IDS = { v: 'vessel', up: 'upload', h: 'history', c: 'room', e: 'assign', sv: 'surveyor', m: 'module' };
    function hashFor(name, id) {
        if (id) for (var k in ROUTE_IDS) if (ROUTE_IDS[k] === name) return '#/' + k + '/' + id;
        return '#/' + name;
    }

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
            (me.can.inbox ? '<button type="button" class="icon-btn glass press" data-go="inbox" aria-label="Inbox' + (me.unread ? ', ' + me.unread + ' unread' : '') + '">' + icon('bell') + (me.unread ? '<span class="dot"></span>' : '') + '</button>' : '') +
            '</div></header>' +
            '<button type="button" class="search lift press" data-go="search" style="border:0;text-align:left;width:calc(100% - 32px)">' + icon('search') + '<span style="flex:1">Search vessel, port, client…</span></button>' +
            '<div class="scr-pad">' +
            '<div class="stats">' +
            statTile('vessels', 'ship', 'blue', 'Pending Vessels') + statTile('reports', 'file', 'orange', 'Pending Reports') +
            statTile('completed', 'check', 'green', 'Completed') +
            '<button type="button" class="stat press" data-rise' + (me.can.recovery ? ' data-go="recovery"' : '') + '><span class="ic sky">' + icon('chart') + '</span><span class="n" data-n="rec">–</span><span class="l">Recovery this month (MT)</span></button>' +
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
            me.can.assign && [me.role === 'Client' ? 'Request survey' : 'Assign vessel', 'plus', '#assign', true],
            me.can.report_generator && ['Word report', 'file', 'report_generator.php'],
            me.can.recovery && ['Recovery', 'fuel', '#recovery'],
            me.can.chat && ['Team chat', 'chat', '#chat'],
            me.role !== 'Client' && ['Formats', 'folder', '#formats'],
            ['Cancelled', 'ban', '#cancelled']
        ].filter(Boolean).slice(0, 4);
        return all.map(function (a, i) {
            var target = a[2].charAt(0) === '#' ? 'data-go="' + a[2].slice(1) + '"' : 'data-web="' + a[2] + '"';
            return '<button type="button" class="qa" ' + target + '><span class="q press' + (i === 0 ? ' solid' : '') + '">' + icon(a[1]) + '</span>' + esc(a[0]) + '</button>';
        }).join('');
    }

    var LISTS = {
        vessels: { status: 'pending_vessel', title: 'Pending Vessels', empty: 'No pending vessels', overdue: 'Overdue' },
        reports: { status: 'pending_report', title: 'Pending Reports', empty: 'No pending reports', overdue: 'Report overdue' },
        completed: { status: 'completed', title: 'Completed', empty: 'No completed vessels' },
        cancelled: { status: 'cancelled', title: 'Cancelled', empty: 'No cancelled vessels', pushed: true }
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
        var scr = el('<section class="scr' + (cfg.pushed ? '' : ' has-tabs') + '" aria-label="' + cfg.title + '">' +
            '<header class="hdr-white"><div class="hdr-title-row">' + (cfg.pushed ? '<button type="button" class="icon-btn plain press" data-back aria-label="Back" style="margin-left:-8px">' + icon('back') + '</button>' : '') +
            '<div style="flex:1"><h1>' + cfg.title + '</h1><div class="hdr-sub" data-total>&nbsp;</div></div></div>' +
            '<label class="search flat">' + icon('search') + '<span class="sr">Search</span><input type="search" placeholder="Search vessel, port, client" enterkeyhint="search"></label>' +
            (showOverdue ? '<div class="chips"><button type="button" class="chip" aria-pressed="true" data-f="">All</button><button type="button" class="chip warn" aria-pressed="false" data-f="1">' + cfg.overdue + '</button></div>' : '') +
            '</header><div class="scr-body"><div class="scr-pad"><div class="list">' + skeletons(4) + '</div><div class="more-loader" hidden><span class="spin dark"></span></div></div></div>' +
            (key === 'vessels' && me.can.assign ? '<button type="button" class="fab press" data-go="assign" aria-label="' + (me.role === 'Client' ? 'Request survey' : 'Assign vessel') + '">' + icon('plus') + '</button>' : '') +
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
    SCREENS.cancelled = function () { return listScreen('cancelled'); };

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
                (v.can_history ? '<button type="button" class="row-link press" data-rise data-history="' + v.id + '"><span class="ic purple" style="width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center">' + icon('history') + '</span><span class="t"><b>Previous surveys of this vessel</b><small>Documents and recovery</small></span>' + icon('chev', 'chev') + '</button>' : '') +
                '<button type="button" class="row-link press" data-rise data-web="' + esc(v.web) + '"><span class="ic blue" style="width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center">' + icon('globe') + '</span><span class="t"><b>Open full page</b><small>Every detail and option of this vessel</small></span>' + icon('chev', 'chev') + '</button>';
            var sheet = scr.querySelector('.sheet');
            sheet.innerHTML = html;
            var acts = [];
            if (v.can_upload) acts.push('<button type="button" class="btn primary press" data-upload="' + v.id + '">' + icon('upload') + (v.upload.kind === 'report' ? 'Upload final report' : 'Upload report') + '</button>');
            var old = scr.querySelector('.actions'); if (old) old.remove();
            if (acts.length) scr.appendChild(el('<div class="actions' + (acts.length === 1 ? ' one' : '') + '">' + acts.join('') + '</div>'));
            else sheet.style.paddingBottom = '32px';
            var more = scr.querySelector('[data-more]');
            more.hidden = false;
            more.onclick = function () {
                openSheet('<h2>' + esc(v.vessel) + '</h2>' +
                    (state.me.role === 'Admin' && v.status === 'Pending Vessel' ? '<button type="button" class="menu-item press" data-edit="' + v.id + '">' + icon('edit') + 'Edit vessel</button>' : '') +
                    '<button type="button" class="menu-item press" data-web="' + esc(v.web) + '">' + icon('globe') + 'Open full page</button>' +
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
            var target = url.charAt(0) === '#' ? 'data-go="' + url.slice(1) + '"' : 'data-web="' + url + '"';
            return '<button type="button" class="menu-item press" ' + target + '><span class="ic ' + cls + '">' + icon(ic) + '</span><span class="grow">' + label + '</span>' + (extra || '') + icon('chev', 'chev') + '</button>';
        }
        var work = [
            me.can.inbox && item('Inbox', 'inbox', 'blue', '#inbox', me.unread ? '<span class="count">' + (me.unread > 99 ? '99+' : me.unread) + '</span>' : ''),
            me.can.chat && item('Team chat', 'chat', 'purple', '#chat'),
            me.can.recovery && item('Recovery', 'fuel', 'sky', '#recovery'),
            me.can.report_generator && item('Word report generator', 'file', 'orange', 'report_generator.php'),
            me.role !== 'Client' && item('Formats', 'folder', 'green', '#formats'),
            item('Cancelled vessels', 'ban', 'red', '#cancelled')
        ].filter(Boolean).join('');
        var admin = me.role === 'Admin' ? [
            item('Assign vessel', 'plus', 'blue', '#assign'), item('Add surveyor', 'user', 'orange', '#surveyor'),
            item('Add client', 'users', 'green', '#addclient'), item('Admin controls', 'shield', 'purple', '#admin'),
            item('Manage templates', 'file', 'sky', 'manage_templates.php'), item('Login activity', 'shield', 'red', 'login_activity.php')
        ].join('') : me.role === 'Super Admin' ? [
            item('Add client', 'users', 'green', '#addclient'), item('Add admin', 'shield', 'purple', '#addadmin'),
            item('Manage clients', 'users', 'blue', '#admin'), item('Login activity', 'shield', 'red', 'login_activity.php')
        ].join('') : me.role === 'Client' ? item('Request survey', 'plus', 'blue', '#assign') : '';
        var scr = el('<section class="scr has-tabs" aria-label="Profile"><div class="scr-body">' +
            '<header class="profile-hdr"><span class="avatar">' + avatar + '</span><b>' + esc(me.name) + '</b><small>' + esc(me.role) + '</small></header>' +
            '<div class="scr-pad">' +
            '<div class="section-title" data-rise>Work</div><div class="menu-card" data-rise>' + work + '</div>' +
            (admin ? '<div class="section-title" data-rise>' + (me.role === 'Client' ? 'Client' : 'Admin') + '</div><div class="menu-card" data-rise>' + admin + '</div>' : '') +
            '<div class="section-title" data-rise>Account</div><div class="menu-card" data-rise>' + item('My profile', 'user', 'blue', '#me') +
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

    /* ---------------- shared: pushed screen header, posting to the existing pages ---------------- */
    function barHeader(title, sub, right) {
        return '<header class="hdr-bar"><button type="button" class="icon-btn plain press" data-back aria-label="Back">' + icon('back') + '</button>' +
            '<div class="t"><b>' + esc(title) + '</b>' + (sub ? '<small>' + esc(sub) + '</small>' : '') + '</div>' + (right || '') + '</header>';
    }
    // POST a form to an existing page; resolves with the page's own success / error message.
    function postPage(url, data) {
        var body = data instanceof FormData ? data : new URLSearchParams(data);
        if (body.set) body.set('csrf_token', state.me.csrf);
        return fetch(url, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) {
            if (r.status === 403) throw new Error('Your session expired. Please sign in again.');
            return r.text();
        }).then(function (html) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            // The page's own message: an inline alert, or the flash toast after a save-and-redirect.
            var ok = doc.querySelector('.alert-success, .vdd-alert-success, .ysms-toast-success'), bad = doc.querySelector('.alert-danger, .vdd-alert-danger, .ysms-toast-error');
            var box = bad || ok, text = box ? (box.querySelector('.ysms-toast-text') || box).textContent : '';
            return { ok: !!ok && !bad, message: text.replace(/\s+/g, ' ').replace(/\s*×\s*$/, '').trim() };
        });
    }
    function mb(b) { return b < 1048576 ? Math.max(1, Math.round(b / 1024)) + ' KB' : (b / 1048576).toFixed(1) + ' MB'; }

    /* ---------------- Upload (Pending Vessel → Pending Report, Pending Report → Completed) ---------------- */
    var MAX_TOTAL = 31 * 1024 * 1024, MAX_ZIP = 30 * 1024 * 1024;
    function picker(key, label, hint, accept, multiple, required) {
        return '<div class="pick" data-pick="' + key + '">' +
            '<input type="file" id="pk-' + key + '" accept="' + accept + '"' + (multiple ? ' multiple' : '') + ' hidden>' +
            '<label class="pick-box press" for="pk-' + key + '"><span class="pick-ic">' + icon('upload') + '</span><span class="t"><b>' + label + (required ? ' <i class="req">*</i>' : '') + '</b><small>' + hint + '</small></span>' + icon('plus', 'chev') + '</label>' +
            '<div class="pick-files"></div></div>';
    }
    SCREENS.upload = function (route) {
        var scr = el('<section class="scr" aria-label="Upload">' + barHeader('Upload', '') +
            '<div class="scr-body"><div class="scr-pad" data-form><div class="skel-card" style="height:300px"></div></div></div></section>');
        var pad = scr.querySelector('[data-form]');
        api('survey', { id: route.id }).then(function (v) {
            if (!v.upload) { pad.innerHTML = '<div class="empty"><span class="ic red">' + icon('alert') + '</span><b>Uploading is not available for this vessel.</b></div>'; return; }
            build(v);
        }).catch(function (e) { if (e.message !== 'login') pad.innerHTML = '<div class="empty"><span class="ic red">' + icon('alert') + '</span><b>Could not load this vessel</b></div>'; });

        function build(v) {
            var u = v.upload, isVessel = u.kind === 'vessel';
            scr.querySelector('.hdr-bar .t').innerHTML = '<b>' + (isVessel ? 'Upload report' : 'Upload final report') + '</b><small>' + esc(v.vessel) + '</small>';
            var files = { main: [], second: [], extra: [] };
            pad.innerHTML =
                '<div class="info-note" data-rise>' + icon(isVessel ? 'file' : 'done') + '<span>' + (isVessel
                    ? 'The PDF report moves this vessel to <b>Pending Reports</b>' + (u.has_pdf ? ' (a PDF is already uploaded).' : '.')
                    : 'The final PDF moves this vessel to <b>Completed</b>.') + '</span></div>' +
                (isVessel
                    ? picker('main', '1. Formal report (PDF)', u.has_pdf ? 'Already uploaded — add another only if needed' : 'Required · PDF', '.pdf,application/pdf', false, !u.has_pdf) +
                      picker('second', '2. Calculation sheet (Excel)', u.has_excel ? 'Already uploaded · optional' : 'Optional · .xlsx / .xls', '.xlsx,.xls,.xlsm', false, false)
                    : picker('main', '1. Final report (PDF)', 'PDF', '.pdf,application/pdf', false, false) +
                      picker('second', '2. ZIP folder', 'Optional · photos / supporting documents, max 30 MB', '.zip,application/zip,application/x-zip-compressed', false, false)) +
                picker('extra', 'Additional files', 'Optional · Excel, Word, PDF — you can pick several', '.pdf,.doc,.docx,.xls,.xlsx,.xlsm,.csv,.txt,.ppt,.pptx,.zip,.rar,.7z,image/*', true, false) +
                '<section class="card card-pad" data-rise><div class="field"><label for="up-date">' + (isVessel ? 'Survey completed date' : 'Report completed date') + ' <i class="req">*</i></label>' +
                '<div class="inp"><input type="date" id="up-date" value="' + u.today + '" max="' + u.today + '"' + (u.min_date ? ' min="' + u.min_date + '"' : '') + '></div>' +
                (u.min_date ? '<small class="hint">Survey completed on ' + esc(v.completed_on) + '.</small>' : '') + '</div>' +
                (isVessel && !u.recovery_na ? '<div data-rec' + (u.has_excel ? ' hidden' : '') + '><div class="field-h">Recovery (MT) <i class="req">*</i></div><div class="rec-grid">' +
                    ['VLSFO', 'HSFO', 'LSMGO'].map(function (k) { return '<label class="mini"><span>' + k + '</span><input type="number" step="0.001" inputmode="decimal" data-part="' + k.toLowerCase() + '"></label>'; }).join('') +
                    '<label class="mini total"><span>Total</span><input type="number" step="0.001" inputmode="decimal" data-total></label></div>' +
                    '<small class="hint">Total fills in from VLSFO + HSFO + LSMGO; enter 0 if there was no recovery.</small></div>' +
                    '<div class="ok-note" data-excel-note' + (u.has_excel ? '' : ' hidden') + '>' + icon('check') + 'Recovery will be read from the Calculation Sheet.</div>' : '') +
                '</section><div class="err" hidden role="alert">' + icon('alert') + '<span></span></div>' +
                '<div style="height:84px"></div>';
            scr.appendChild(el('<div class="actions one"><button type="button" class="btn primary press" data-send>' + icon('upload') + (isVessel ? 'Submit &amp; Send' : 'Complete &amp; Move') + '</button></div>'));
            stagger(scr);

            var rec = pad.querySelector('[data-rec]'), exNote = pad.querySelector('[data-excel-note]'), err = pad.querySelector('.err');
            var totalIn = pad.querySelector('[data-total]'), totalTouched = false;
            if (totalIn) {
                pad.querySelectorAll('[data-part]').forEach(function (p) {
                    p.addEventListener('input', function () {
                        if (totalTouched) return;
                        var any = false, sum = 0;
                        pad.querySelectorAll('[data-part]').forEach(function (x) { if (x.value !== '') { any = true; sum += parseFloat(x.value) || 0; } });
                        totalIn.value = any ? String(Math.round(sum * 1000) / 1000) : '';
                    });
                });
                totalIn.addEventListener('input', function () { totalTouched = totalIn.value !== ''; });
            }
            function showErr(m) { err.hidden = !m; err.querySelector('span').textContent = m || ''; if (m) { haptic('HEAVY'); err.scrollIntoView({ behavior: 'smooth', block: 'center' }); } }
            function renderFiles(key) {
                var box = pad.querySelector('[data-pick="' + key + '"] .pick-files');
                box.innerHTML = files[key].map(function (f, i) {
                    return '<div class="pf"><span class="ft ' + docType({ name: f.name })[0] + '">' + docType({ name: f.name })[1] + '</span><span class="t"><b>' + esc(f.name) + '</b><small>' + mb(f.size) + '</small></span>' +
                        '<button type="button" class="icon-btn plain" data-rm="' + key + ':' + i + '" aria-label="Remove ' + esc(f.name) + '">' + icon('x') + '</button></div>';
                }).join('');
                pad.querySelector('[data-pick="' + key + '"]').classList.toggle('has', files[key].length > 0);
                if (rec) {
                    var excel = u.has_excel || (isVessel && files.second.length > 0);
                    rec.hidden = excel; exNote.hidden = !excel;
                }
            }
            pad.querySelectorAll('[data-pick] input[type=file]').forEach(function (inp) {
                inp.addEventListener('change', function () {
                    var key = inp.closest('[data-pick]').getAttribute('data-pick');
                    var picked = Array.prototype.slice.call(inp.files);
                    inp.value = '';
                    showErr('');
                    if (key === 'main' && picked[0] && !/\.pdf$/i.test(picked[0].name)) return showErr('The report must be a PDF (.pdf) file.');
                    if (key === 'second' && picked[0] && isVessel && !/\.(xlsx|xls|xlsm)$/i.test(picked[0].name)) return showErr('The Calculation Sheet must be an Excel file (.xlsx / .xls).');
                    if (key === 'second' && picked[0] && !isVessel) {
                        if (!/\.zip$/i.test(picked[0].name)) return showErr('Please choose a .zip file for the ZIP folder.');
                        if (picked[0].size > MAX_ZIP) return showErr(picked[0].name + ' is ' + mb(picked[0].size) + '. The maximum is 30 MB.');
                    }
                    files[key] = key === 'extra' ? files.extra.concat(picked) : picked.slice(0, 1);
                    haptic();
                    renderFiles(key);
                });
            });
            pad.addEventListener('click', function (e) {
                var b = e.target.closest('[data-rm]');
                if (!b) return;
                var p = b.getAttribute('data-rm').split(':');
                files[p[0]].splice(+p[1], 1);
                renderFiles(p[0]);
            });

            scr.querySelector('[data-send]').addEventListener('click', function () {
                showErr('');
                var date = pad.querySelector('#up-date').value;
                if (!date) return showErr('Please choose the ' + (isVessel ? 'Survey' : 'Report') + ' Completed date.');
                if (date > u.today) return showErr('The date cannot be in the future.');
                if (u.min_date && date < u.min_date) return showErr('Report Completed date cannot be before the Survey Completed date.');
                if (isVessel && !u.has_pdf && !files.main.length) return showErr('Please choose the PDF report.');
                var needRec = rec && !rec.hidden;
                if (needRec && totalIn.value === '') return showErr('Please enter the recovery (enter 0 if none).');
                var total = 0;
                ['main', 'second', 'extra'].forEach(function (k) { files[k].forEach(function (f) { total += f.size; }); });
                if (total > MAX_TOTAL) return showErr('The files add up to ' + mb(total) + ' (limit 31 MB per upload). Please upload them in smaller batches.');

                var fd = new FormData();
                fd.append('csrf_token', state.me.csrf);
                fd.append('survey_id', String(v.id));
                fd.append('current_status', v.status);
                if (isVessel) {
                    if (files.main[0]) fd.append('pdf_report', files.main[0]);
                    if (files.second[0]) fd.append('excel_report', files.second[0]);
                    files.extra.forEach(function (f) { fd.append('additional_files[]', f); });
                    fd.append('survey_completed_date', date);
                    if (needRec) {
                        fd.append('manual_recovery', '1');
                        pad.querySelectorAll('[data-part]').forEach(function (x) { fd.append('manual_' + x.getAttribute('data-part'), x.value); });
                        fd.append('manual_total', totalIn.value);
                    }
                    send(fd);
                } else {
                    if (files.main[0]) fd.append('final_report_pdf', files.main[0]);
                    if (files.second[0]) fd.append('zip_folder', files.second[0]);
                    files.extra.forEach(function (f) { fd.append('extra_files[]', f); });
                    fd.append('report_completed_date', date);
                    if (files.main[0]) { fd.append('no_report_confirmed', '0'); send(fd); return; }
                    // Same question report_detail.php asks when no Final Report is attached.
                    openSheet('<h2>Complete without the final report?</h2><p class="sheet-p">No Final Report (PDF) is attached. The vessel will move to Completed without it.</p>' +
                        '<div class="sheet-actions"><button type="button" class="btn primary press" data-yes>Continue without report</button><button type="button" class="btn ghost" data-no>Go back</button></div>',
                    function (sh) {
                        sh.querySelector('[data-no]').onclick = function () { closeSheet(); };
                        sh.querySelector('[data-yes]').onclick = function () { closeSheet(true); fd.append('no_report_confirmed', '1'); send(fd); };
                    });
                }
            });

            function send(fd) {
                var R = 52, C = 2 * Math.PI * R;
                var ov = el('<div class="upload-ov" role="status" aria-live="polite"><div class="upload-card">' +
                    '<svg viewBox="0 0 120 120" class="ring" aria-hidden="true"><circle cx="60" cy="60" r="' + R + '" class="bg"/><circle cx="60" cy="60" r="' + R + '" class="fg" stroke-dasharray="' + C + '" stroke-dashoffset="' + C + '"/></svg>' +
                    '<b data-pct>0%</b><span data-msg>Uploading…</span></div></div>');
                document.body.appendChild(ov);
                var fg = ov.querySelector('.fg'), pct = ov.querySelector('[data-pct]'), msg = ov.querySelector('[data-msg]');
                var xhr = new XMLHttpRequest();
                xhr.open('POST', 'ajax/upload_handler.php');
                xhr.setRequestHeader('X-YSMS-Upload', '1');
                xhr.upload.onprogress = function (e) {
                    if (!e.lengthComputable) return;
                    var k = e.loaded / e.total;
                    fg.setAttribute('stroke-dashoffset', String(C * (1 - k)));
                    pct.textContent = Math.round(k * 100) + '%';
                    if (k >= 1) msg.textContent = 'Saving and sending the email…';
                };
                xhr.onload = function () {
                    if (xhr.status === 403) { finish(false, 'Your session expired. Please sign in again.'); return; }
                    if (xhr.status === 413) { finish(false, 'The files are too large to upload together.'); return; }
                    if (xhr.status >= 400) { finish(false, 'The upload did not go through (error ' + xhr.status + ').'); return; }
                    api('flash').then(function (f) {
                        if (f.email_queued) sendQueuedEmail();
                        finish(f.type !== 'error', f.message || 'Uploaded.', f.type === 'warning');
                    }).catch(function () { finish(true, 'Uploaded.'); });
                };
                xhr.onerror = function () { finish(false, 'No connection — the files were not uploaded. Try again.'); };
                xhr.send(fd);
                function finish(ok, text, warn) {
                    ov.classList.add(ok ? 'done' : 'fail');
                    pct.innerHTML = ok ? icon('check') : icon('x');
                    msg.textContent = text;
                    haptic(ok ? 'MEDIUM' : 'HEAVY');
                    setTimeout(function () {
                        ov.remove();
                        if (ok) { toast(text, warn ? '' : 'ok'); history.back(); }
                        else showErr(text);
                    }, ok ? 1300 : 1800);
                }
            }
        }
        return scr;
    };

    // Admin email for the upload just made: sent in its own request (same as includes/footer.php);
    // the toast says what the mail transport answered.
    function sendQueuedEmail() {
        fetch('ajax/upload_email_send.php', { method: 'POST', keepalive: true, credentials: 'same-origin', headers: { 'X-CSRF-Token': state.me.csrf } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                setTimeout(function () {
                    if (!d.success) { toast(d.message || 'Admin email will be retried automatically.', 'bad'); return; }
                    (d.results || []).forEach(function (r) {
                        if (r.status === 'sent') toast(r.message, 'ok');
                        else if (r.status === 'failed') toast(r.message + ' — it will be retried automatically.', 'bad');
                    });
                }, 3000);
            }).catch(function () {});
    }

    /* ---------------- Inbox ---------------- */
    var NOTIF_IC = { assign: 'ship', edit: 'edit', cancel: 'ban', delete: 'x', format: 'folder', surveyor: 'user', status: 'chat', card: 'user', upload: 'upload', report: 'file', expense: 'file' };
    SCREENS.inbox = function () {
        var scr = el('<section class="scr" aria-label="Inbox">' + barHeader('Inbox', '', '<button type="button" class="link-btn" data-allread>Mark all read</button>') +
            '<div class="hdr-white" style="padding-top:8px"><div class="chips"><button type="button" class="chip" aria-pressed="true" data-f="">All</button><button type="button" class="chip" aria-pressed="false" data-f="1">Unread</button></div></div>' +
            '<div class="scr-body"><div class="scr-pad"><div class="list">' + skeletons(4) + '</div><div class="more-loader" hidden><span class="spin dark"></span></div></div></div></section>');
        var body = scr.querySelector('.scr-body'), list = scr.querySelector('.list'), loader = scr.querySelector('.more-loader');
        var unread = '', page = 1, more = false, loading = false, seq = 0;
        function row(n) {
            return '<button type="button" class="notif press' + (n.read ? '' : ' unread') + '" data-rise data-notif="' + n.id + '" data-sid="' + n.survey_id + '" data-link="' + esc(n.link || '') + '">' +
                '<span class="ic ' + (n.read ? 'blue' : 'purple') + '">' + icon(NOTIF_IC[n.type] || 'bell') + '</span>' +
                '<span class="t"><b>' + esc(n.title) + '</b><span>' + esc(n.message) + '</span><small>' + esc(n.from ? n.from + ' · ' : '') + esc(n.ago) + '</small></span>' +
                (n.read ? '' : '<i class="udot" aria-label="Unread"></i>') + '</button>';
        }
        function load(reset) {
            if (reset) { page = 1; more = false; }
            var my = ++seq; loading = true;
            var params = { page: page };
            if (unread) params.unread = 1;
            return api('inbox', params).then(function (d) {
                if (my !== seq) return;
                scr.querySelector('.hdr-bar .t').innerHTML = '<b>Inbox</b><small>' + (d.unread ? d.unread + ' unread' : 'All caught up') + '</small>';
                if (state.me) state.me.unread = d.unread;
                var html = d.items.map(row).join('');
                if (reset) list.innerHTML = html || '<div class="empty"><span class="ic blue">' + icon('inbox') + '</span><b>' + (unread ? 'No unread notifications' : 'No notifications yet') + '</b></div>';
                else list.insertAdjacentHTML('beforeend', html);
                more = d.more; loader.hidden = !more;
                stagger(list);
            }).catch(function (e) { if (e.message !== 'login') toast('Could not load the inbox', 'bad'); })
              .then(function () { if (my === seq) loading = false; });
        }
        scr.querySelectorAll('[data-f]').forEach(function (c) {
            c.addEventListener('click', function () {
                haptic();
                scr.querySelectorAll('[data-f]').forEach(function (x) { x.setAttribute('aria-pressed', x === c ? 'true' : 'false'); });
                unread = c.getAttribute('data-f'); list.innerHTML = skeletons(3); body.scrollTop = 0; load(true);
            });
        });
        scr.querySelector('[data-allread]').addEventListener('click', function () {
            postPage('inbox.php', { action: 'mark_all_read' }).then(function () { toast('All notifications marked as read', 'ok'); load(true); })
                .catch(function () { toast('Could not mark them read', 'bad'); });
        });
        list.addEventListener('click', function (e) {
            var b = e.target.closest('[data-notif]');
            if (!b) return;
            haptic();
            // inbox.php?open= marks it read (same as tapping it on the website).
            fetch('inbox.php?open=' + b.getAttribute('data-notif'), { credentials: 'same-origin', redirect: 'manual' }).catch(function () {});
            b.classList.remove('unread');
            var sid = +b.getAttribute('data-sid'), link = b.getAttribute('data-link');
            if (sid) go('vessel', { id: sid });
            else if (/^recovery\.php/.test(link)) go('recovery');
            else if (/^formats_download\.php/.test(link)) go('formats');
            else if (/^cancelled\.php/.test(link)) go('cancelled');
            else if (link) openWeb(link);
        });
        if ('IntersectionObserver' in window) {
            new IntersectionObserver(function (en) { if (en[0].isIntersecting && more && !loading) { page++; load(false); } }, { root: body, rootMargin: '300px' }).observe(loader);
        }
        pullToRefresh(scr, body, function () { return load(true); });
        load(true);
        return scr;
    };

    /* ---------------- Recovery ---------------- */
    SCREENS.recovery = function () {
        var scr = el('<section class="scr" aria-label="Recovery">' + barHeader('Recovery', 'Pending Report + Completed') +
            '<div class="scr-body"><div class="scr-pad">' +
            '<section class="rec-hero" data-rise><div class="l">Total recovery</div><div class="n" data-tot>–</div><div class="rec-split">' +
            '<div><small>VLSFO</small><b data-k="vlsfo">–</b></div><div><small>LSMGO</small><b data-k="lsmgo">–</b></div><div><small>HSFO</small><b data-k="hsfo">–</b></div><div><small>Vessels</small><b data-k="count">–</b></div></div></section>' +
            '<label class="search flat">' + icon('search') + '<span class="sr">Search</span><input type="search" placeholder="Search vessel, report no., port" enterkeyhint="search"></label>' +
            '<div class="chips" data-months><button type="button" class="chip" aria-pressed="true" data-m="">All months</button></div>' +
            '<div class="list">' + skeletons(4) + '</div><div class="more-loader" hidden><span class="spin dark"></span></div></div></div></section>');
        var body = scr.querySelector('.scr-body'), list = scr.querySelector('.list'), loader = scr.querySelector('.more-loader');
        var input = scr.querySelector('input'), monthsEl = scr.querySelector('[data-months]');
        var q = '', month = '', page = 1, more = false, loading = false, seq = 0, monthsDone = false;
        function card(r) {
            return '<button type="button" class="rcard press" data-rise data-vessel="' + r.id + '"><span class="t"><b>' + esc(r.vessel) + '</b>' +
                '<span>' + esc([r.client, r.port].filter(Boolean).join(' · ')) + '</span><small>' + esc(r.type) + (r.completed ? ' · ' + esc(r.completed) : '') + '</small></span>' +
                '<span class="amt' + (r.na || r.total === null ? ' na' : '') + '"><b>' + esc(r.total_text) + '</b>' + (r.na || r.total === null ? '' : '<small>MT</small>') + '</span></button>';
        }
        function load(reset) {
            if (reset) { page = 1; more = false; }
            var my = ++seq; loading = true;
            var params = { page: page };
            if (q) params.q = q;
            if (month) params.month = month;
            return api('recovery', params).then(function (d) {
                if (my !== seq) return;
                countUp(scr.querySelector('[data-tot]'), d.total, 3, ' <small>MT</small>');
                ['vlsfo', 'lsmgo', 'hsfo'].forEach(function (k) { scr.querySelector('[data-k=' + k + ']').textContent = fmtMt(d[k]); });
                scr.querySelector('[data-k=count]').textContent = d.count;
                if (!monthsDone) {
                    monthsDone = true;
                    monthsEl.insertAdjacentHTML('beforeend', d.months.map(function (m) { return '<button type="button" class="chip" aria-pressed="false" data-m="' + m.value + '">' + esc(m.label) + '</button>'; }).join(''));
                }
                var html = d.items.map(card).join('');
                if (reset) list.innerHTML = html || '<div class="empty"><span class="ic sky">' + icon('fuel') + '</span><b>Nothing matches</b></div>';
                else list.insertAdjacentHTML('beforeend', html);
                more = d.more; loader.hidden = !more;
                stagger(list);
            }).catch(function (e) { if (e.message !== 'login') toast('Could not load recovery', 'bad'); })
              .then(function () { if (my === seq) loading = false; });
        }
        var t = null;
        input.addEventListener('input', function () {
            clearTimeout(t);
            t = setTimeout(function () { q = input.value.trim(); list.innerHTML = skeletons(3); load(true); }, 300);
        });
        monthsEl.addEventListener('click', function (e) {
            var c = e.target.closest('[data-m]');
            if (!c) return;
            haptic();
            monthsEl.querySelectorAll('[data-m]').forEach(function (x) { x.setAttribute('aria-pressed', x === c ? 'true' : 'false'); });
            month = c.getAttribute('data-m'); list.innerHTML = skeletons(3); load(true);
        });
        if ('IntersectionObserver' in window) {
            new IntersectionObserver(function (en) { if (en[0].isIntersecting && more && !loading) { page++; load(false); } }, { root: body, rootMargin: '300px' }).observe(loader);
        }
        pullToRefresh(scr, body, function () { return load(true); });
        load(true);
        return scr;
    };

    /* ---------------- Formats ---------------- */
    SCREENS.formats = function () {
        var scr = el('<section class="scr" aria-label="Formats">' + barHeader('Formats', 'Approved survey formats') +
            '<div class="scr-body"><div class="scr-pad"><div class="list">' + skeletons(3) + '</div></div></div></section>');
        var list = scr.querySelector('.list');
        function load() {
            return api('formats').then(function (d) {
                list.innerHTML = d.items.length ? d.items.map(function (f) {
                    return '<a class="fcard press" data-rise href="' + esc(f.path) + '" download><span class="ft xls">XLS</span><span class="t"><b>' + esc(f.name) + '</b><small>Updated ' + esc(f.updated) + '</small></span>' +
                        (f.recent ? '<span class="tag green">New</span>' : '') + '<span class="dl">' + icon('download') + '</span></a>';
                }).join('') : '<div class="empty"><span class="ic green">' + icon('folder') + '</span><b>No formats uploaded yet</b></div>';
                stagger(list);
            }).catch(function (e) { if (e.message !== 'login') toast('Could not load the formats', 'bad'); });
        }
        pullToRefresh(scr, scr.querySelector('.scr-body'), load);
        load();
        return scr;
    };

    /* ---------------- My profile (edit) ---------------- */
    SCREENS.me = function () {
        var scr = el('<section class="scr" aria-label="My profile">' + barHeader('My profile', '') +
            '<div class="scr-body"><div class="scr-pad" data-form><div class="skel-card" style="height:320px"></div></div></div></section>');
        var pad = scr.querySelector('[data-form]');
        function field(id, label, value, type, extra) {
            return '<div class="field"><label for="' + id + '">' + label + '</label><div class="inp"><input id="' + id + '" name="' + id + '" type="' + (type || 'text') + '" value="' + esc(value || '') + '"' + (extra || '') + '></div></div>';
        }
        function load() {
            return api('profile').then(function (p) {
                var avatar = p.avatar ? '<img src="' + esc(p.avatar) + '?t=' + Date.now() + '" alt="">' : esc(state.me.initials);
                pad.innerHTML =
                    '<div class="me-photo" data-rise><span class="avatar big">' + avatar + '</span>' +
                    '<label class="cam press" for="me-photo" aria-label="Change photo">' + icon('camera') + '</label><input type="file" id="me-photo" accept="image/jpeg,image/png,image/webp" hidden>' +
                    '<b>' + esc(p.full_name) + '</b><small>@' + esc(p.username) + '</small></div>' +
                    '<form class="card card-pad" data-rise data-details><div class="card-h">Details</div>' +
                    (p.surveyor_form
                        ? field('first_name', 'First name', p.first_name, 'text', ' required autocomplete="given-name"') + field('last_name', 'Last name', p.last_name, 'text', ' required autocomplete="family-name"') + field('dob', 'Date of birth', p.dob, 'date')
                        : field('full_name', 'Full name', p.full_name, 'text', ' required autocomplete="name"') + field('email', 'Email', p.email, 'email', ' required autocomplete="email"') + field('phone', 'Mobile number', p.phone, 'tel', ' autocomplete="tel"')) +
                    '<button type="submit" class="btn primary press">Save details</button></form>' +
                    '<form class="card card-pad" data-rise data-pass><div class="card-h">Change password</div>' +
                    field('current_password', 'Current password', '', 'password', ' required autocomplete="current-password"') +
                    field('new_password', 'New password', '', 'password', ' required minlength="6" autocomplete="new-password"') +
                    field('confirm_password', 'Confirm new password', '', 'password', ' required autocomplete="new-password"') +
                    '<button type="submit" class="btn outline press">Update password</button></form>';
                stagger(pad);
                var details = pad.querySelector('[data-details]');
                details.addEventListener('submit', function (e) {
                    e.preventDefault();
                    var data = { update_profile: '1' };
                    Array.prototype.forEach.call(details.querySelectorAll('input'), function (i) { data[i.name] = i.value.trim(); });
                    if (p.surveyor_form && (!data.first_name || !data.last_name)) { toast('First name and last name are required.', 'bad'); return; }
                    if (!p.surveyor_form && !data.full_name) { toast('Full name is required.', 'bad'); return; }
                    busy(details, true);
                    postPage('profile.php', data).then(function (r) {
                        toast(r.message || (r.ok ? 'Saved' : 'Could not save'), r.ok ? 'ok' : 'bad');
                        if (r.ok) return loadMe().then(load);
                    }).catch(function (x) { toast(x.message || 'Could not save', 'bad'); }).then(function () { busy(details, false); });
                });
                var pass = pad.querySelector('[data-pass]');
                pass.addEventListener('submit', function (e) {
                    e.preventDefault();
                    var data = { change_password: '1' };
                    Array.prototype.forEach.call(pass.querySelectorAll('input'), function (i) { data[i.name] = i.value; });
                    if (data.new_password.length < 6) { toast('The new password needs at least 6 characters.', 'bad'); return; }
                    if (data.new_password !== data.confirm_password) { toast('New password and confirm password do not match.', 'bad'); return; }
                    busy(pass, true);
                    postPage('profile.php', data).then(function (r) {
                        toast(r.message || (r.ok ? 'Password updated' : 'Could not update the password'), r.ok ? 'ok' : 'bad');
                        if (r.ok) pass.reset();
                    }).catch(function (x) { toast(x.message || 'Could not update the password', 'bad'); }).then(function () { busy(pass, false); });
                });
                pad.querySelector('#me-photo').addEventListener('change', function () {
                    var f = this.files[0];
                    this.value = '';
                    if (!f) return;
                    var fd = new FormData();
                    fd.append('update_profile', '1');
                    fd.append('profile_image', f);
                    // profile.php's own photo form: Surveyors send the photo alone; others re-send their details.
                    if (!p.surveyor_form) { fd.append('full_name', p.full_name); fd.append('email', p.email); fd.append('phone', p.phone); }
                    toast('Uploading photo…');
                    postPage('profile.php', fd).then(function (r) {
                        toast(r.message || (r.ok ? 'Photo updated' : 'Could not update the photo'), r.ok ? 'ok' : 'bad');
                        if (r.ok) return loadMe().then(load);
                    }).catch(function (x) { toast(x.message || 'Could not update the photo', 'bad'); });
                });
            }).catch(function (e) { if (e.message !== 'login') toast('Could not load your profile', 'bad'); });
        }
        function busy(form, on) {
            var b = form.querySelector('[type=submit]');
            if (on) { b.dataset.label = b.textContent; b.disabled = true; b.innerHTML = '<span class="spin' + (b.classList.contains('outline') ? ' dark' : '') + '"></span> Saving…'; }
            else { b.disabled = false; b.textContent = b.dataset.label || 'Save'; }
        }
        load();
        return scr;
    };

    /* ---------------- Previous surveys of the same vessel ---------------- */
    SCREENS.history = function (route) {
        var scr = el('<section class="scr" aria-label="Previous surveys">' + barHeader('Previous surveys', '') +
            '<div class="scr-body"><div class="scr-pad"><div class="list">' + skeletons(3) + '</div></div></div></section>');
        var list = scr.querySelector('.list');
        function docRow(d) {
            var t = docType(d);
            return '<a class="doc press" href="download.php?upload_id=' + d.id + '"><span class="ft ' + t[0] + '">' + t[1] + '</span><span class="t"><b>' + esc(d.name) + '</b><small>' + esc(d.type) + (d.when ? ' · ' + esc(d.when) : '') + '</small></span>' + icon('download', 'chev') + '</a>';
        }
        function load() {
            return api('history', { id: route.id }).then(function (d) {
                scr.querySelector('.hdr-bar .t').innerHTML = '<b>Previous surveys</b><small>' + esc(d.vessel) + '</small>';
                list.innerHTML = d.items.length ? '<div class="info-note" data-rise>' + icon('history') + '<span>Earlier surveys of this vessel (same name or IMO, any client), for reference.</span></div>' +
                    d.items.map(function (h) {
                        var r = h.recovery;
                        return '<section class="card card-pad hist" data-rise>' +
                            '<div class="title-row"><div><b class="hn">' + esc(h.vessel) + '</b><div class="subline">' + esc([h.client, h.port].filter(Boolean).join(' · ')) + '</div></div>' +
                            '<span class="tag ' + (h.status === 'Completed' ? 'green' : 'orange') + '">' + esc(h.status) + '</span></div>' +
                            '<div class="meta">' + (h.completed_on ? '<span>' + icon('calendar') + 'Surveyed ' + esc(h.completed_on) + '</span>' : '') +
                            (h.surveyors ? '<span>' + icon('user') + esc(h.surveyors) + '</span>' : '') + (h.report_no ? '<span>' + icon('file') + esc(h.report_no) + '</span>' : '') + '</div>' +
                            '<div class="hist-rec"><div><small>Total</small><b>' + esc(r.total) + '</b></div><div><small>VLSFO</small><b>' + esc(r.vlsfo) + '</b></div><div><small>LSMGO</small><b>' + esc(r.lsmgo) + '</b></div><div><small>HSFO</small><b>' + esc(r.hsfo) + '</b></div></div>' +
                            (h.type ? '<small class="hint">' + esc(h.type) + '</small>' : '') +
                            (h.docs.length ? '<div class="hist-docs">' + h.docs.map(docRow).join('') + '</div>' : '<small class="hint">No documents on this survey.</small>') +
                            '</section>';
                    }).join('') : '<div class="empty"><span class="ic purple">' + icon('history') + '</span><b>No earlier surveys of this vessel</b></div>';
                stagger(list);
            }).catch(function (e) { if (e.message !== 'login') list.innerHTML = '<div class="empty"><span class="ic red">' + icon('alert') + '</span><b>Not available</b></div>'; });
        }
        pullToRefresh(scr, scr.querySelector('.scr-body'), load);
        load();
        return scr;
    };

    /* ---------------- Team chat (ajax/team_chat.php — its own checks: staff only, members only) ---------------- */
    function tc(action, params) {
        var q = new URLSearchParams(Object.assign({ action: action }, params || {}));
        return fetch('ajax/team_chat.php?' + q.toString(), { credentials: 'same-origin', cache: 'no-store' }).then(tcResult);
    }
    function tcPost(action, data) {
        var fd = data instanceof FormData ? data : new FormData();
        if (!(data instanceof FormData)) Object.keys(data || {}).forEach(function (k) {
            [].concat(data[k]).forEach(function (v) { fd.append(k, v); });
        });
        fd.append('action', action);
        fd.append('csrf_token', state.me.csrf);
        return fetch('ajax/team_chat.php', { method: 'POST', credentials: 'same-origin', body: fd }).then(tcResult);
    }
    function tcResult(r) {
        return r.json().catch(function () { return { ok: false, error: 'Chat is not available right now.' }; }).then(function (d) {
            if (r.status === 401) { state.me = null; go('login', { replace: true, anim: 'fade' }); }
            if (!d.ok) { var e = new Error(d.error || 'error'); e.code = d.code; e.status = r.status; throw e; }
            return d;
        });
    }
    function initials(name) {
        return String(name || '?').trim().split(/\s+/).slice(0, 2).map(function (w) { return w.charAt(0).toUpperCase(); }).join('') || '?';
    }

    SCREENS.chat = function () {
        var scr = el('<section class="scr" aria-label="Team chat">' + barHeader('Team chat', '', '') +
            '<div class="scr-body"><div class="scr-pad"><div class="list">' + skeletons(3) + '</div></div></div></section>');
        var list = scr.querySelector('.list'), canManage = false;
        function load() {
            return tc('bootstrap').then(function (d) {
                canManage = d.me.can_manage;
                if (canManage && !scr.querySelector('[data-newgroup]')) {
                    scr.querySelector('.hdr-bar').insertAdjacentHTML('beforeend', '<button type="button" class="icon-btn plain press" data-newgroup aria-label="New group">' + icon('plus') + '</button>');
                    scr.querySelector('[data-newgroup]').onclick = newGroup;
                }
                list.innerHTML = d.groups.length ? d.groups.map(function (g) {
                    return '<button type="button" class="grp press" data-rise data-room="' + g.id + '"><span class="gav">' + esc(initials(g.name)) + '</span>' +
                        '<span class="t"><b>' + esc(g.name) + '</b><small>' + g.member_count + ' member' + (g.member_count === 1 ? '' : 's') + (g.is_member ? '' : ' · you are not in this group') + '</small></span>' +
                        (g.unread ? '<span class="count">' + (g.unread > 99 ? '99+' : g.unread) + '</span>' : '') + icon('chev', 'chev') + '</button>';
                }).join('') : '<div class="empty"><span class="ic purple">' + icon('chat') + '</span><b>No groups yet</b></div>';
                stagger(list);
            }).catch(function (e) { list.innerHTML = '<div class="empty"><span class="ic red">' + icon('alert') + '</span><b>' + esc(e.message) + '</b></div>'; });
        }
        list.addEventListener('click', function (e) {
            var b = e.target.closest('[data-room]');
            if (b) { haptic(); go('room', { id: +b.getAttribute('data-room') }); }
        });
        function newGroup() {
            tc('staff').then(function (d) {
                openSheet('<h2>New group</h2><div class="field"><label for="ng-name">Group name</label><div class="inp"><input id="ng-name" maxlength="60" placeholder="e.g. Vizag surveyors"></div></div>' +
                    '<div class="field-h">Members</div><div class="pick-list">' + d.staff.map(function (u) {
                        return '<label class="pick-row"><input type="checkbox" value="' + u.user_id + '"><span class="gav sm">' + esc(initials(u.name)) + '</span><span class="t"><b>' + esc(u.name) + '</b><small>' + esc(u.role) + '</small></span></label>';
                    }).join('') + '</div><div class="sheet-actions"><button type="button" class="btn primary press" data-create>Create group</button><button type="button" class="btn ghost" data-no>Cancel</button></div>',
                function (sh) {
                    sh.querySelector('[data-no]').onclick = function () { closeSheet(); };
                    sh.querySelector('[data-create]').onclick = function () {
                        var ids = Array.prototype.map.call(sh.querySelectorAll('.pick-row input:checked'), function (c) { return c.value; });
                        tcPost('create_group', { name: sh.querySelector('#ng-name').value, 'member_ids[]': ids }).then(function (r) {
                            closeSheet(true); toast('Group created', 'ok'); go('room', { id: r.group.id });
                        }).catch(function (e) { toast(e.message, 'bad'); });
                    };
                });
            }).catch(function (e) { toast(e.message, 'bad'); });
        }
        pullToRefresh(scr, scr.querySelector('.scr-body'), load);
        load();
        return scr;
    };

    SCREENS.room = function (route) {
        var gid = route.id;
        var scr = el('<section class="scr room" aria-label="Chat">' + barHeader('Chat', '', '<button type="button" class="icon-btn plain press" data-members aria-label="Members">' + icon('users') + '</button>') +
            '<div class="scr-body msgs" aria-live="polite"><div class="older" hidden><button type="button" class="link-btn" data-older>Load earlier messages</button></div><div class="thread"></div></div>' +
            '<form class="composer" hidden><input type="file" id="tc-file" hidden>' +
            '<label class="icon-btn plain press" for="tc-file" aria-label="Attach a file">' + icon('plus') + '</label>' +
            '<div class="att-chip" hidden></div>' +
            '<textarea rows="1" placeholder="Message" aria-label="Message" maxlength="4000"></textarea>' +
            '<button type="submit" class="send press" aria-label="Send">' + icon('chev') + '</button></form>' +
            '<div class="not-member" hidden></div></section>');
        var body = scr.querySelector('.msgs'), thread = scr.querySelector('.thread'), form = scr.querySelector('.composer');
        var ta = form.querySelector('textarea'), fileIn = form.querySelector('#tc-file'), chip = form.querySelector('.att-chip');
        var me = null, lastId = 0, firstId = 0, avatars = {}, pending = null, polling = null, lastDay = '', settings = {};

        function dayLabel(ts) {
            var d = new Date(ts * 1000), today = new Date();
            var y = new Date(); y.setDate(today.getDate() - 1);
            if (d.toDateString() === today.toDateString()) return 'Today';
            if (d.toDateString() === y.toDateString()) return 'Yesterday';
            return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
        }
        function timeLabel(ts) { return new Date(ts * 1000).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' }); }
        function bubble(m, opts) {
            var mine = me && m.sender_id === me.id;
            var av = avatars[String(m.sender_id)];
            var atts = (m.attachments || []).map(function (a) {
                var url = 'ajax/team_chat.php?action=download&id=' + a.id;
                return a.is_image
                    ? '<a class="att-img" href="' + url + '"><img src="' + url + '&inline=1" alt="' + esc(a.name) + '" loading="lazy"></a>'
                    : '<a class="att-file" href="' + url + '"><span class="ft other">' + esc(a.type_label.slice(0, 4).toUpperCase()) + '</span><span class="t"><b>' + esc(a.name) + '</b><small>' + mb(a.size) + '</small></span>' + icon('download') + '</a>';
            }).join('');
            return '<div class="msg' + (mine ? ' mine' : '') + (opts && opts.sending ? ' sending' : '') + '" data-mid="' + (m.id || '') + '">' +
                (mine ? '' : '<span class="mav">' + (av ? '<img src="' + esc(av) + '" alt="">' : esc(initials(m.sender_name))) + '</span>') +
                '<div class="bub">' + (mine ? '' : '<b class="who">' + esc(m.sender_name) + '</b>') + atts +
                (m.text ? '<div class="txt">' + esc(m.text).replace(/\n/g, '<br>') + '</div>' : '') +
                '<span class="tm">' + (opts && opts.sending ? 'Sending…' : timeLabel(m.ts)) + '</span></div></div>';
        }
        function render(msgs, where) {
            var html = '', day = where === 'top' ? '' : lastDay;
            msgs.forEach(function (m) {
                var dl = dayLabel(m.ts);
                if (dl !== day) { html += '<div class="day"><span>' + dl + '</span></div>'; day = dl; }
                html += bubble(m);
            });
            if (where === 'top') {
                var h = body.scrollHeight;
                // drop a repeated day divider at the join
                var firstDay = thread.querySelector('.day');
                if (firstDay && msgs.length && dayLabel(msgs[msgs.length - 1].ts) === firstDay.textContent) firstDay.remove();
                thread.insertAdjacentHTML('afterbegin', html);
                body.scrollTop += body.scrollHeight - h;
            } else {
                thread.insertAdjacentHTML('beforeend', html);
                lastDay = day;
            }
        }
        function atBottom() { return body.scrollHeight - body.scrollTop - body.clientHeight < 120; }
        function toBottom(smooth) { body.scrollTo({ top: body.scrollHeight, behavior: smooth && !REDUCE ? 'smooth' : 'auto' }); }
        function markRead() { if (lastId) tcPost('mark_read', { group_id: gid, message_id: lastId }).catch(function () {}); }

        tc('bootstrap', { group_id: gid }).then(function (d) {
            me = d.me; settings = d.settings || {};
            gid = d.group.id;
            scr.querySelector('.hdr-bar .t').innerHTML = '<b>' + esc(d.group.name) + '</b><small>' + d.members.length + ' members</small>';
            if (!me.is_member) {
                var nm = scr.querySelector('.not-member');
                nm.hidden = false;
                nm.innerHTML = '<div class="empty"><span class="ic purple">' + icon('users') + '</span><b>You are not a member of this group.</b>' + (me.can_manage ? '<span>Add yourself from Members to read and write here.</span>' : '') + '</div>';
                if (!me.can_manage) scr.querySelector('[data-members]').hidden = true;
                return;
            }
            form.hidden = false;
            return tc('messages', { group_id: gid }).then(function (r) {
                Object.assign(avatars, r.avatars || {});
                if (r.messages.length) { firstId = r.messages[0].id; lastId = r.messages[r.messages.length - 1].id; }
                render(r.messages);
                if (!r.messages.length) thread.innerHTML = '<div class="empty" data-empty><span class="ic purple">' + icon('chat') + '</span>No messages yet — say hello.</div>';
                scr.querySelector('.older').hidden = !r.has_more;
                toBottom(false);
                markRead();
                poll();
            });
        }).catch(function (e) { thread.innerHTML = '<div class="empty"><span class="ic red">' + icon('alert') + '</span><b>' + esc(e.message) + '</b></div>'; });

        function poll() {
            clearTimeout(polling);
            polling = setTimeout(function () {
                if (!scr.isConnected) return;
                if (document.hidden) { poll(); return; }
                tc('poll', { group_id: gid, after_id: lastId }).then(function (r) {
                    Object.assign(avatars, r.avatars || {});
                    var fresh = r.messages.filter(function (m) { return !thread.querySelector('[data-mid="' + m.id + '"]'); });
                    if (fresh.length) {
                        var stick = atBottom();
                        var empty = thread.querySelector('[data-empty]'); if (empty) empty.remove();
                        render(fresh);
                        lastId = r.messages[r.messages.length - 1].id;
                        if (stick) toBottom(true);
                        if (fresh.some(function (m) { return m.sender_id !== me.id; })) haptic();
                        markRead();
                    } else if (r.messages.length) lastId = r.messages[r.messages.length - 1].id;
                    scr.querySelector('.hdr-bar .t small').textContent = r.member_count + ' members';
                }).catch(function () {}).then(poll);
            }, 3000);
        }

        scr.querySelector('[data-older]').addEventListener('click', function () {
            var b = this;
            b.disabled = true;
            tc('messages', { group_id: gid, before_id: firstId }).then(function (r) {
                Object.assign(avatars, r.avatars || {});
                if (r.messages.length) { firstId = r.messages[0].id; render(r.messages, 'top'); }
                scr.querySelector('.older').hidden = !r.has_more;
            }).catch(function (e) { toast(e.message, 'bad'); }).then(function () { b.disabled = false; });
        });

        ta.addEventListener('input', function () { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 120) + 'px'; });
        fileIn.addEventListener('change', function () {
            var f = fileIn.files[0];
            if (!f) return;
            var ext = (f.name.split('.').pop() || '').toLowerCase();
            if (settings.allowed_ext && settings.allowed_ext.indexOf(ext) === -1) { toast('This file type cannot be shared in chat.', 'bad'); fileIn.value = ''; return; }
            if (settings.max_upload_bytes && f.size > settings.max_upload_bytes) { toast('File is too large. Maximum is ' + Math.round(settings.max_upload_bytes / 1048576) + ' MB.', 'bad'); fileIn.value = ''; return; }
            pending = f;
            chip.hidden = false;
            chip.innerHTML = '<span>' + esc(f.name) + '</span><button type="button" class="icon-btn plain" aria-label="Remove file">' + icon('x') + '</button>';
            chip.querySelector('button').onclick = function () { pending = null; fileIn.value = ''; chip.hidden = true; };
        });
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var text = ta.value.trim();
            if (!text && !pending) return;
            var token = 'app-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
            var fd = new FormData();
            fd.append('group_id', gid); fd.append('text', text); fd.append('client_token', token);
            if (pending) fd.append('file', pending);
            var empty = thread.querySelector('[data-empty]'); if (empty) empty.remove();
            var nowTs = Date.now() / 1000;
            if (dayLabel(nowTs) !== lastDay) { lastDay = dayLabel(nowTs); thread.insertAdjacentHTML('beforeend', '<div class="day"><span>' + lastDay + '</span></div>'); }
            var temp = el(bubble({ sender_id: me.id, sender_name: me.name, text: text + (pending ? (text ? '\n' : '') + 'Attachment: ' + pending.name : ''), ts: nowTs }, { sending: true }));
            thread.appendChild(temp);
            toBottom(true);
            ta.value = ''; ta.style.height = 'auto'; pending = null; fileIn.value = ''; chip.hidden = true;
            haptic();
            tcPost('send', fd).then(function (r) {
                Object.assign(avatars, r.avatars || {});
                if (r.message && !thread.querySelector('[data-mid="' + r.message.id + '"]')) {
                    temp.replaceWith(el(bubble(r.message)));
                    lastId = Math.max(lastId, r.message.id);
                } else temp.remove();
            }).catch(function (x) {
                temp.classList.add('failed');
                temp.querySelector('.tm').textContent = 'Not sent — ' + x.message;
            });
        });
        ta.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey && !NATIVE) { e.preventDefault(); form.requestSubmit(); } });

        scr.querySelector('[data-members]').addEventListener('click', function () {
            Promise.all([tc('members', { group_id: gid }), me && me.can_manage ? tc('candidates', { group_id: gid }) : Promise.resolve({ candidates: [] })]).then(function (r) {
                var manage = me && me.can_manage;
                openSheet('<h2>Members</h2><div class="pick-list">' + r[0].members.map(function (m) {
                    return '<div class="pick-row"><span class="gav sm">' + esc(initials(m.name)) + '</span><span class="t"><b>' + esc(m.name) + '</b><small>' + esc(m.role) + '</small></span>' +
                        (manage ? '<button type="button" class="link-btn" style="color:#b91c1c" data-rm-member="' + m.user_id + '">Remove</button>' : '') + '</div>';
                }).join('') + '</div>' +
                    (manage && r[1].candidates.length ? '<div class="field-h">Add people</div><div class="pick-list">' + r[1].candidates.map(function (c) {
                        return '<div class="pick-row"><span class="gav sm">' + esc(initials(c.name)) + '</span><span class="t"><b>' + esc(c.name) + '</b><small>' + esc(c.role) + '</small></span><button type="button" class="link-btn" data-add-member="' + c.user_id + '">Add</button></div>';
                    }).join('') + '</div>' : ''),
                function (sh) {
                    sh.addEventListener('click', function (ev) {
                        var b = ev.target.closest('[data-rm-member],[data-add-member]');
                        if (!b) return;
                        var add = b.hasAttribute('data-add-member');
                        tcPost(add ? 'add_member' : 'remove_member', { group_id: gid, user_id: b.getAttribute(add ? 'data-add-member' : 'data-rm-member') }).then(function () {
                            toast(add ? 'Member added' : 'Member removed', 'ok');
                            closeSheet(true);
                            if (+b.getAttribute(add ? 'data-add-member' : 'data-rm-member') === me.id) { show({ name: 'room', id: gid }, 'fade'); }
                        }).catch(function (x) { toast(x.message, 'bad'); });
                    });
                });
            }).catch(function (x) { toast(x.message, 'bad'); });
        });
        return scr;
    };

    /* ---------------- Picker sheet (searchable, single or multi) ---------------- */
    // items: [{ value, label, sub }]; resolves with the chosen value(s), or null when closed.
    function pickSheet(title, items, opt) {
        opt = opt || {};
        var chosen = (opt.selected || []).map(String);
        return new Promise(function (resolve) {
            var done = false;
            var sh = openSheet('<h2>' + esc(title) + '</h2>' +
                (items.length > 7 ? '<label class="search flat" style="margin-bottom:8px">' + icon('search') + '<span class="sr">Search</span><input type="search" placeholder="Search" data-q></label>' : '') +
                '<div class="pick-list" data-items></div>' +
                (opt.multi ? '<div class="sheet-actions"><button type="button" class="btn primary press" data-ok>Done</button></div>' : ''));
            var box = sh.querySelector('[data-items]'), q = sh.querySelector('[data-q]');
            function draw() {
                var term = q ? q.value.trim().toLowerCase() : '';
                var html = '', group = null;
                items.forEach(function (it) {
                    if (term && (it.label + ' ' + (it.sub || '')).toLowerCase().indexOf(term) === -1) return;
                    if (opt.groupBy && it.sub !== group) { group = it.sub; html += '<div class="pick-group">' + esc(group) + '</div>'; }
                    var on = chosen.indexOf(String(it.value)) !== -1;
                    html += '<button type="button" class="pick-row opt press' + (on ? ' on' : '') + (it.warn ? ' warn' : '') + '" data-v="' + esc(it.value) + '" aria-pressed="' + on + '">' +
                        '<span class="t"><b>' + esc(it.label) + '</b>' + (!opt.groupBy && it.sub ? '<small>' + esc(it.sub) + '</small>' : '') + '</span>' +
                        '<span class="tick">' + icon('check') + '</span></button>';
                });
                box.innerHTML = html || '<div class="empty" style="padding:24px">Nothing matches</div>';
            }
            draw();
            if (q) q.addEventListener('input', draw);
            box.addEventListener('click', function (e) {
                var b = e.target.closest('[data-v]');
                if (!b) return;
                var v = b.getAttribute('data-v');
                haptic();
                if (!opt.multi) { done = true; resolve(v); closeSheet(); return; }
                var i = chosen.indexOf(v);
                if (opt.exclusive && v === opt.exclusive) chosen = i === -1 ? [v] : [];
                else {
                    chosen = chosen.filter(function (x) { return x !== opt.exclusive; });
                    if (i === -1) chosen.push(v); else chosen.splice(i, 1);
                }
                draw();
            });
            var ok = sh.querySelector('[data-ok]');
            if (ok) ok.onclick = function () { done = true; resolve(chosen.slice()); closeSheet(); };
            var watch = setInterval(function () { if (!sh.isConnected) { clearInterval(watch); if (!done) resolve(null); } }, 300);
        });
    }

    /* ---------------- Assign vessel / Request survey / Edit assignment (assign_vessel.php) ---------------- */
    SCREENS.assign = function (route) {
        var editId = route.id || 0;
        var scr = el('<section class="scr" aria-label="Assign vessel">' + barHeader(editId ? 'Edit vessel' : 'Assign vessel', '') +
            '<div class="scr-body"><div class="scr-pad" data-form><div class="skel-card" style="height:420px"></div></div></div></section>');
        var pad = scr.querySelector('[data-form]');
        api('assign_form', editId ? { edit_id: editId } : {}).then(build).catch(function (e) {
            if (e.message !== 'login') pad.innerHTML = '<div class="empty"><span class="ic red">' + icon('alert') + '</span><b>Not available</b></div>';
        });

        function build(d) {
            var isClient = d.role === 'Client', e = d.edit || {};
            var f = {
                client: e.client_id || (isClient && d.clients[0] ? d.clients[0].id : 0),
                port: e.port_id || 0,
                types: (e.type_ids || []).map(String),
                surveyors: (e.surveyor_ids || []).map(String),
                files: []
            };
            scr.querySelector('.hdr-bar .t').innerHTML = '<b>' + (editId ? 'Edit vessel' : isClient ? 'Request survey' : 'Assign vessel') + '</b>' + (editId ? '<small>' + esc(e.report_no) + '</small>' : '');
            function name(list, id) { var x = list.filter(function (i) { return String(i.id) === String(id); })[0]; return x ? x.name : ''; }
            function selectRow(key, label, value, placeholder, locked) {
                return '<div class="field"><label>' + label + ' <i class="req">*</i></label><button type="button" class="sel press' + (locked ? ' locked' : '') + '" data-sel="' + key + '"' + (locked ? ' disabled' : '') + '>' +
                    '<span class="' + (value ? '' : 'ph') + '">' + esc(value || placeholder) + '</span>' + (locked ? icon('lock') : icon('chev', 'chev')) + '</button></div>';
            }
            function chipsOf(list, ids, out) {
                return ids.length ? '<div class="sel-chips">' + ids.map(function (id) { return '<span class="chip on">' + esc(id === 'outsourcing' ? 'Outsourcing' : name(list, id)) + '</span>'; }).join('') + '</div>' : '<div class="hint">' + out + '</div>';
            }
            function draw() {
                pad.innerHTML =
                    '<section class="card card-pad" data-rise><div class="card-h">Vessel</div>' +
                    '<div class="field"><label for="av-vessel">Vessel name <i class="req">*</i></label><div class="inp"><input id="av-vessel" autocapitalize="characters" placeholder="e.g. MV. OCEAN STAR" value="' + esc(f.vessel != null ? f.vessel : (e.vessel || '')) + '"></div></div>' +
                    selectRow('client', 'Client', name(d.clients, f.client), 'Choose client', isClient || !!editId && false) +
                    (!editId && !isClient ? '<div class="rn" data-rn' + (f.client ? '' : ' hidden') + '><small>Report no.</small><b data-rnv>…</b></div>' : '') +
                    '<div class="field"><label for="av-agent">Agent name <i class="req">*</i></label><div class="inp"><input id="av-agent" autocapitalize="characters" value="' + esc(f.agent != null ? f.agent : (e.agent || '')) + '"></div></div>' +
                    '</section>' +
                    '<section class="card card-pad" data-rise><div class="card-h">Survey</div>' +
                    selectRow('port', 'Port', name(d.ports, f.port) ? name(d.ports, f.port) + ' · ' + d.ports.filter(function (p) { return p.id === +f.port; })[0].country : '', 'Choose port') +
                    '<div class="field"><label>Survey type <i class="req">*</i></label><button type="button" class="sel press" data-sel="types"><span class="' + (f.types.length ? '' : 'ph') + '">' + (f.types.length ? f.types.length + ' selected' : 'Choose one or more') + '</span>' + icon('chev', 'chev') + '</button>' + chipsOf(d.types, f.types, '') + '</div>' +
                    (isClient ? '' : '<div class="field"><label>Surveyor(s) <i class="req">*</i></label><button type="button" class="sel press" data-sel="surveyors"><span class="' + (f.surveyors.length ? '' : 'ph') + '">' + (f.surveyors.length ? f.surveyors.length + ' selected' : 'Choose one or more') + '</span>' + icon('chev', 'chev') + '</button>' + chipsOf(d.surveyors, f.surveyors, '') + '</div>') +
                    '<div class="field"><label for="av-date">Appointment date <i class="req">*</i></label><div class="inp"><input type="date" id="av-date" value="' + esc(f.date != null ? f.date : (e.appointment || '')) + '"></div></div>' +
                    '<div class="field"><label for="av-remarks">Remarks</label><textarea id="av-remarks" class="ta" rows="3" placeholder="Any specific instructions or notes">' + esc(f.remarks != null ? f.remarks : (e.remarks || '')) + '</textarea></div>' +
                    '</section>' +
                    picker('att', 'Attachments', editId ? 'Optional · added to the files already there' : 'Optional · PDF, Word, Excel, images, ZIP', '.pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.zip', true, false) +
                    '<div class="err" hidden role="alert">' + icon('alert') + '<span></span></div><div style="height:84px"></div>';
                renderFiles();
                if (!editId && !isClient && f.client) preview();
                stagger(pad);
            }
            function keep() {
                var v = pad.querySelector('#av-vessel'); if (!v) return;
                f.vessel = v.value; f.agent = pad.querySelector('#av-agent').value; f.date = pad.querySelector('#av-date').value; f.remarks = pad.querySelector('#av-remarks').value;
            }
            function renderFiles() {
                var box = pad.querySelector('[data-pick="att"] .pick-files');
                box.innerHTML = f.files.map(function (x, i) {
                    var t = docType({ name: x.name });
                    return '<div class="pf"><span class="ft ' + t[0] + '">' + t[1] + '</span><span class="t"><b>' + esc(x.name) + '</b><small>' + mb(x.size) + '</small></span><button type="button" class="icon-btn plain" data-rmf="' + i + '" aria-label="Remove ' + esc(x.name) + '">' + icon('x') + '</button></div>';
                }).join('');
                pad.querySelector('[data-pick="att"]').classList.toggle('has', f.files.length > 0);
            }
            function preview() {
                fetch('ajax/preview_report_number.php?client_id=' + encodeURIComponent(f.client), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
                    var v = pad.querySelector('[data-rnv]'); if (v) v.textContent = r.success ? r.report_number : '—';
                }).catch(function () {});
            }
            function err(m) { var x = pad.querySelector('.err'); x.hidden = !m; x.querySelector('span').textContent = m || ''; if (m) { haptic('HEAVY'); x.scrollIntoView({ behavior: 'smooth', block: 'center' }); } }
            draw();
            scr.appendChild(el('<div class="actions one"><button type="button" class="btn primary press" data-save>' + icon(editId ? 'check' : 'plus') + (editId ? 'Save changes' : isClient ? 'Send request' : 'Assign &amp; save vessel') + '</button></div>'));

            pad.addEventListener('click', function (ev) {
                var s = ev.target.closest('[data-sel]'), rm = ev.target.closest('[data-rmf]');
                if (rm) { f.files.splice(+rm.getAttribute('data-rmf'), 1); renderFiles(); return; }
                if (!s || s.disabled) return;
                keep();
                var k = s.getAttribute('data-sel');
                var p = k === 'client' ? pickSheet('Client', d.clients.map(function (c) { return { value: c.id, label: c.name }; }), { selected: [f.client] })
                    : k === 'port' ? pickSheet('Port', d.ports.map(function (x) { return { value: x.id, label: x.name, sub: x.country }; }), { selected: [f.port], groupBy: true })
                    : k === 'types' ? pickSheet('Survey type', d.types.map(function (x) { return { value: x.id, label: x.name }; }), { multi: true, selected: f.types })
                    : pickSheet('Surveyor(s)', d.surveyors.map(function (x) { return { value: x.id, label: x.name }; }).concat([{ value: 'outsourcing', label: 'Outsourcing', warn: true }]), { multi: true, selected: f.surveyors, exclusive: 'outsourcing' });
                p.then(function (v) {
                    if (v === null) return;
                    if (k === 'client') f.client = v; else if (k === 'port') f.port = v; else if (k === 'types') f.types = v; else f.surveyors = v;
                    draw();
                });
            });
            pad.addEventListener('change', function (ev) {
                if (ev.target.id !== 'pk-att') return;
                f.files = f.files.concat(Array.prototype.slice.call(ev.target.files));
                ev.target.value = '';
                renderFiles();
            });

            scr.querySelector('[data-save]').addEventListener('click', function () {
                keep(); err('');
                var vessel = (f.vessel || '').trim(), agent = (f.agent || '').trim();
                if (!vessel) return err('Vessel name is required.');
                if (!f.client) return err(isClient ? 'Your login is not linked to a client company. Please contact the admin.' : 'Please choose the client.');
                if (!agent) return err('Agent name is required.');
                if (!f.port) return err('Please choose the port.');
                if (!f.types.length) return err('Please choose at least one survey type.');
                if (!isClient && !f.surveyors.length) return err('Please choose a surveyor (or Outsourcing).');
                if (!f.date) return err('Appointment date is required.');
                var total = 0; f.files.forEach(function (x) { total += x.size; });
                if (total > MAX_TOTAL) return err('The attachments add up to ' + mb(total) + ' (limit 31 MB).');
                var fd = new FormData();
                if (editId) fd.append('edit_id', String(editId));
                fd.append('vessel_name', vessel); fd.append('client_id', String(f.client)); fd.append('agent_name', agent);
                fd.append('port_id', String(f.port)); fd.append('survey_type_ids', f.types.join(','));
                fd.append('surveyor_ids', f.surveyors.join(',')); fd.append('appointment_date', f.date); fd.append('remarks', (f.remarks || '').trim());
                f.files.forEach(function (x) { fd.append('assignment_attachment[]', x); });
                var go2 = function () { save(fd); };
                if (editId) { go2(); return; }
                // Same "already in Pending Vessels?" question the website asks.
                fetch('ajax/check_pending_vessel_duplicate.php?vessel_name=' + encodeURIComponent(vessel), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
                    if (!r.exists) { go2(); return; }
                    var x = r.entry;
                    openSheet('<h2>Already in Pending Vessels</h2><p class="sheet-p">A vessel named <b>' + esc(x.vessel_name) + '</b> is already pending:</p>' +
                        '<div class="card rows" style="box-shadow:none;background:#f8fafc"><div class="kv"><span>Report no.</span><b>' + esc(x.report_number) + '</b></div><div class="kv"><span>Client</span><b>' + esc(x.client_name) + '</b></div><div class="kv"><span>Port</span><b>' + esc(x.port_name) + '</b></div><div class="kv"><span>Assigned</span><b>' + esc(x.assign_date) + '</b></div></div>' +
                        '<div class="sheet-actions"><button type="button" class="btn primary press" data-yes>Yes, add it anyway</button><button type="button" class="btn ghost" data-no>Cancel</button></div>',
                    function (sh) { sh.querySelector('[data-no]').onclick = function () { closeSheet(); }; sh.querySelector('[data-yes]').onclick = function () { closeSheet(true); go2(); }; });
                }).catch(go2);
            });
            function save(fd) {
                var b = scr.querySelector('[data-save]');
                b.disabled = true; b.innerHTML = '<span class="spin"></span> Saving…';
                postPage('assign_vessel.php' + (editId ? '?edit_id=' + editId : ''), fd).then(function (r) {
                    if (!r.ok) throw new Error(r.message || 'Could not save. Please check the details.');
                    haptic('MEDIUM');
                    toast(r.message, 'ok');
                    if (editId) history.back(); else go('vessels', { anim: 'fade' });
                }).catch(function (x) {
                    b.disabled = false; b.innerHTML = icon(editId ? 'check' : 'plus') + (editId ? 'Save changes' : isClient ? 'Send request' : 'Assign &amp; save vessel');
                    err(x.message);
                });
            }
        }
        return scr;
    };

    /* ---------------- Simple forms (fields → inputs, posted to the existing pages / JSON) ---------------- */
    // defs: [{ name, label, type: text|email|tel|date|password|select|checkbox|textarea, required, options, hint, readonly }]
    function fieldsHtml(defs, values) {
        values = values || {};
        return defs.map(function (f) {
            var id = 'ff-' + f.name, v = values[f.name] != null ? values[f.name] : '';
            var req = f.required ? ' <i class="req">*</i>' : '';
            var hint = f.hint ? '<small class="hint">' + esc(f.hint) + '</small>' : '';
            if (f.type === 'select') {
                return '<div class="field"><label for="' + id + '">' + esc(f.label) + req + '</label><div class="inp"><select id="' + id + '" name="' + f.name + '">' +
                    (f.options || []).map(function (o) { return '<option' + (String(o) === String(v) ? ' selected' : '') + '>' + esc(o) + '</option>'; }).join('') + '</select></div>' + hint + '</div>';
            }
            if (f.type === 'checkbox') {
                return '<label class="check"><input type="checkbox" name="' + f.name + '" value="1"' + (v ? ' checked' : '') + '> ' + esc(f.label) + '</label>' + hint;
            }
            if (f.type === 'textarea') {
                return '<div class="field"><label for="' + id + '">' + esc(f.label) + req + '</label><textarea id="' + id + '" name="' + f.name + '" class="ta" rows="3">' + esc(v) + '</textarea>' + hint + '</div>';
            }
            var type = { password: 'password', email: 'email', tel: 'tel', date: 'date' }[f.type] || 'text';
            return '<div class="field"><label for="' + id + '">' + esc(f.label) + req + '</label><div class="inp' + (f.readonly ? ' ro' : '') + '"><input id="' + id + '" name="' + f.name + '" type="' + type + '" value="' + esc(v) + '"' +
                (f.readonly ? ' readonly' : '') + (type === 'password' ? ' autocomplete="new-password"' : '') + (f.name === 'username' ? ' autocapitalize="none" spellcheck="false"' : '') + '></div>' + hint + '</div>';
        }).join('');
    }
    function collect(root) {
        var out = {};
        root.querySelectorAll('input[name], select[name], textarea[name]').forEach(function (i) {
            if (i.type === 'checkbox') { if (i.checked) out[i.name] = i.value; return; }
            if (i.type === 'file') return;
            out[i.name] = i.type === 'password' ? i.value : i.value.trim();
        });
        return out;
    }
    function missing(defs, data) {
        var f = defs.filter(function (d) { return d.required && !d.readonly && !data[d.name]; })[0];
        return f ? f.label + ' is required.' : '';
    }
    function busyBtn(b, on, label) {
        if (on) { b.dataset.label = b.innerHTML; b.disabled = true; b.innerHTML = '<span class="spin"></span> Saving…'; }
        else { b.disabled = false; b.innerHTML = label || b.dataset.label || 'Save'; }
    }
    // Post to a JSON endpoint ({success, message}).
    function postJson(url, data) {
        var body = new URLSearchParams(data);
        body.set('csrf_token', state.me.csrf);
        return fetch(url, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d.success) throw new Error(d.message || 'Could not save.');
            return d;
        });
    }

    /* ---------------- Admin hub ---------------- */
    SCREENS.admin = function () {
        var me = state.me, admin = me.role === 'Admin';
        var scr = el('<section class="scr" aria-label="Admin">' + barHeader(admin ? 'Admin controls' : 'Manage', '') +
            '<div class="scr-body"><div class="scr-pad"><div data-mods><div class="skel-card" style="height:160px"></div></div>' +
            '<div class="section-title" data-rise>People</div><div class="menu-card" data-rise>' +
            (admin ? adminItem('Add surveyor', 'user', 'orange', 'surveyor') : '') +
            adminItem('Add client', 'users', 'green', 'addclient') +
            (!admin ? adminItem('Add admin', 'shield', 'purple', 'addadmin') : '') +
            '</div><div data-more-tools></div></div></div></section>');
        function adminItem(label, ic, cls, go2) {
            return '<button type="button" class="menu-item press" data-go="' + go2 + '"><span class="ic ' + cls + '">' + icon(ic) + '</span><span class="grow">' + label + '</span>' + icon('chev', 'chev') + '</button>';
        }
        var ICON_OF = { ports: ['pin', 'blue'], agents: ['users', 'orange'], clients: ['folder', 'green'], survey_types: ['file', 'purple'], surveyors: ['user', 'sky'] };
        api('admin_modules').then(function (d) {
            scr.querySelector('[data-mods]').innerHTML = '<div class="section-title" data-rise>Master data</div><div class="tiles" data-rise>' + d.modules.map(function (m) {
                var ic = ICON_OF[m.key] || ['folder', 'blue'];
                return '<button type="button" class="tile press" data-module="' + m.key + '"><span class="ic ' + ic[1] + '">' + icon(ic[0]) + '</span><b>' + esc(m.label) + '</b></button>';
            }).join('') + '</div>';
            stagger(scr);
        }).catch(function () {});
        scr.addEventListener('click', function (e) {
            var t = e.target.closest('[data-module]');
            if (t) { haptic(); go('module', { id: t.getAttribute('data-module') }); }
        });
        return scr;
    };

    /* ---------------- One master-data module (ajax/admin_master.php) ---------------- */
    SCREENS.module = function (route) {
        var key = route.id, mod = null, rows = [];
        var scr = el('<section class="scr" aria-label="Master data">' + barHeader('…', '') +
            '<div class="hdr-white" style="padding-top:8px"><label class="search flat">' + icon('search') + '<span class="sr">Search</span><input type="search" placeholder="Search" enterkeyhint="search"></label></div>' +
            '<div class="scr-body"><div class="scr-pad"><div class="list">' + skeletons(4) + '</div></div></div>' +
            '<button type="button" class="fab press" style="bottom:24px" data-add aria-label="Add">' + icon('plus') + '</button></section>');
        var list = scr.querySelector('.list'), input = scr.querySelector('input[type=search]');
        var logins = {}; // Super Admin: login of each client company
        api('admin_modules').then(function (d) {
            mod = d.modules.filter(function (m) { return m.key === key; })[0];
            if (!mod) { list.innerHTML = '<div class="empty"><b>Not available</b></div>'; scr.querySelector('[data-add]').hidden = true; return; }
            scr.querySelector('.hdr-bar .t').innerHTML = '<b>' + esc(mod.label) + '</b><small data-count></small>';
            load();
        }).catch(function () {});
        function sub(r) {
            return mod.fields.filter(function (f) { return f.name !== mod.name_field && f.type !== 'password' && r[f.name]; }).map(function (f) { return r[f.name]; }).join(' · ');
        }
        function load() {
            var q = input.value.trim();
            var p1 = fetch('ajax/admin_master.php?' + new URLSearchParams({ module: key, action: 'list', q: q }), { credentials: 'same-origin' }).then(function (r) { return r.json(); });
            var p2 = key === 'clients' && state.me.role === 'Super Admin'
                ? fetch('ajax/super_admin_client_login.php?action=list_logins', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (x) { logins = x.by_client || {}; }).catch(function () {})
                : Promise.resolve();
            return Promise.all([p1, p2]).then(function (r) {
                var d = r[0];
                if (!d.success) throw new Error(d.message);
                rows = d.rows;
                scr.querySelector('[data-count]').textContent = rows.length + (rows.length === 1 ? ' entry' : ' entries');
                list.innerHTML = rows.length ? rows.map(function (row, i) {
                    var lg = logins[row.id];
                    return '<button type="button" class="mrow press" data-rise data-i="' + i + '"><span class="t"><b>' + esc(row[mod.name_field]) + '</b>' +
                        (sub(row) ? '<small>' + esc(sub(row)) + '</small>' : '') +
                        (key === 'clients' && state.me.role === 'Super Admin' ? '<small class="' + (lg ? 'ok' : 'no') + '">' + (lg ? 'Login: ' + esc(lg.username) : 'No login yet') + '</small>' : '') +
                        '</span>' + (row.status ? '<span class="tag ' + (row.status === 'Active' ? 'green' : 'red') + '">' + esc(row.status) + '</span>' : '') + icon('chev', 'chev') + '</button>';
                }).join('') : '<div class="empty"><span class="ic blue">' + icon('folder') + '</span><b>' + (q ? 'Nothing matches' : 'Nothing here yet') + '</b></div>';
                stagger(list);
            }).catch(function (e) { list.innerHTML = '<div class="empty"><span class="ic red">' + icon('alert') + '</span><b>' + esc(e.message || 'Could not load') + '</b></div>'; });
        }
        var t = null;
        input.addEventListener('input', function () { clearTimeout(t); t = setTimeout(load, 300); });
        list.addEventListener('click', function (e) {
            var b = e.target.closest('[data-i]');
            if (!b) return;
            var row = rows[+b.getAttribute('data-i')];
            haptic();
            if (key === 'surveyors') { go('surveyor', { id: +row.id }); return; }
            edit(row);
        });
        scr.querySelector('[data-add]').addEventListener('click', function () {
            haptic();
            if (key === 'surveyors') { go('surveyor'); return; }
            edit(null);
        });
        function edit(row) {
            var defs = mod.fields.filter(function (f) { return row || !f.readonly; });
            var sa = key === 'clients' && state.me.role === 'Super Admin' && row;
            openSheet('<h2>' + (row ? 'Edit ' : 'Add ') + esc(mod.singular.toLowerCase()) + '</h2><div data-f>' + fieldsHtml(defs, row || {}) + '</div>' +
                '<div class="err" hidden role="alert">' + icon('alert') + '<span></span></div>' +
                '<div class="sheet-actions"><button type="button" class="btn primary press" data-save>' + (row ? 'Save changes' : 'Add ' + esc(mod.singular.toLowerCase())) + '</button>' +
                (sa ? '<button type="button" class="btn outline press" data-login>' + (logins[row.id] ? 'Client login' : 'Create client login') + '</button>' : '') +
                (row ? '<button type="button" class="btn danger press" data-del>Delete</button>' : '') + '<button type="button" class="btn ghost" data-no>Cancel</button></div>',
            function (sh) {
                var err = function (m) { var x = sh.querySelector('.err'); x.hidden = !m; x.querySelector('span').textContent = m || ''; };
                sh.querySelector('[data-no]').onclick = function () { closeSheet(); };
                sh.querySelector('[data-save]').onclick = function () {
                    var data = collect(sh.querySelector('[data-f]')), m = missing(defs, data), b = this;
                    if (m) return err(m);
                    data.module = key; data.action = row ? 'edit' : 'add';
                    if (row) data.id = row.id;
                    busyBtn(b, true);
                    postJson('ajax/admin_master.php', data).then(function (r) { closeSheet(); toast(r.message, 'ok'); load(); })
                        .catch(function (x) { busyBtn(b, false); err(x.message); });
                };
                var del = sh.querySelector('[data-del]');
                if (del) del.onclick = function () {
                    if (del.dataset.sure !== '1') { del.dataset.sure = '1'; del.textContent = 'Tap again to delete'; haptic('HEAVY'); return; }
                    postJson('ajax/admin_master.php', { module: key, action: 'delete', id: row.id }).then(function (r) { closeSheet(); toast(r.message, 'ok'); load(); })
                        .catch(function (x) { err(x.message); del.dataset.sure = ''; del.textContent = 'Delete'; });
                };
                var lgBtn = sh.querySelector('[data-login]');
                if (lgBtn) lgBtn.onclick = function () { closeSheet(true); clientLogin(row); };
            });
        }
        // Super Admin: create / edit the login of a client company (ajax/super_admin_client_login.php).
        function clientLogin(row) {
            var lg = logins[row.id] || {};
            var defs = [
                { name: 'username', label: 'Username', required: true },
                { name: 'full_name', label: 'Contact person', required: true },
                { name: 'email', label: 'Email', type: 'email' },
                { name: 'phone', label: 'Mobile', type: 'tel' },
                { name: 'password', label: lg.user_id ? 'New password' : 'Password', type: 'password', required: !lg.user_id, hint: lg.user_id ? 'Leave blank to keep the current password.' : '' }
            ];
            openSheet('<h2>Client login · ' + esc(row.company_name) + '</h2><div data-f>' + fieldsHtml(defs, lg) + '</div>' +
                '<div class="err" hidden role="alert">' + icon('alert') + '<span></span></div>' +
                '<div class="sheet-actions"><button type="button" class="btn primary press" data-save>' + (lg.user_id ? 'Save login' : 'Create login') + '</button><button type="button" class="btn ghost" data-no>Cancel</button></div>',
            function (sh) {
                sh.querySelector('[data-no]').onclick = function () { closeSheet(); };
                sh.querySelector('[data-save]').onclick = function () {
                    var data = collect(sh.querySelector('[data-f]')), m = missing(defs, data), b = this;
                    var x = sh.querySelector('.err');
                    if (m) { x.hidden = false; x.querySelector('span').textContent = m; return; }
                    data.action = 'save'; data.client_id = row.id;
                    busyBtn(b, true);
                    postJson('ajax/super_admin_client_login.php', data).then(function (r) { closeSheet(); toast(r.message, 'ok'); load(); })
                        .catch(function (e2) { busyBtn(b, false); x.hidden = false; x.querySelector('span').textContent = e2.message; });
                };
            });
        }
        pullToRefresh(scr, scr.querySelector('.scr-body'), load);
        return scr;
    };

    /* ---------------- Add / edit surveyor (add_surveyor.php) ---------------- */
    SCREENS.surveyor = function (route) {
        var id = route.id || 0;
        var scr = el('<section class="scr" aria-label="Surveyor">' + barHeader(id ? 'Edit surveyor' : 'Add surveyor', '') +
            '<div class="scr-body"><div class="scr-pad" data-form><div class="skel-card" style="height:420px"></div></div></div></section>');
        var pad = scr.querySelector('[data-form]'), photo = null;
        var defs = [
            { name: 'first_name', label: 'First name', required: true }, { name: 'last_name', label: 'Last name', required: true },
            { name: 'username', label: 'Username', required: true }, { name: 'phone', label: 'Mobile', type: 'tel' },
            { name: 'email', label: 'Email', type: 'email' }, { name: 'dob', label: 'Date of birth', type: 'date' },
            { name: 'date_of_joining', label: 'Date of joining', type: 'date' }, { name: 'status', label: 'Status', type: 'select', options: ['Active', 'Inactive'], required: true }
        ];
        var pw = [{ name: 'password', label: 'Password', type: 'password', required: !id }, { name: 'confirm_password', label: 'Confirm password', type: 'password', required: !id }];
        (id ? api('surveyor', { id: id }) : Promise.resolve({ status: 'Active' })).then(function (u) {
            pad.innerHTML = '<div class="me-photo" data-rise><span class="avatar big">' + (u.avatar ? '<img src="' + esc(u.avatar) + '" alt="">' : esc(initials((u.first_name || '') + ' ' + (u.last_name || '')) || '+')) + '</span>' +
                '<label class="cam press" for="sv-photo" aria-label="Choose photo">' + icon('camera') + '</label><input type="file" id="sv-photo" accept="image/jpeg,image/png,image/webp" hidden></div>' +
                '<section class="card card-pad" data-rise data-f>' + fieldsHtml(defs, u) + '</section>' +
                (id ? '' : '<section class="card card-pad" data-rise data-p><div class="card-h">Login password</div>' + fieldsHtml(pw, {}) + '</section>') +
                '<div class="err" hidden role="alert">' + icon('alert') + '<span></span></div><div style="height:84px"></div>';
            scr.appendChild(el('<div class="actions one"><button type="button" class="btn primary press" data-save>' + (id ? 'Save changes' : 'Create surveyor') + '</button></div>'));
            stagger(pad);
            pad.querySelector('#sv-photo').addEventListener('change', function () {
                photo = this.files[0] || null;
                if (photo) pad.querySelector('.avatar').innerHTML = '<img src="' + URL.createObjectURL(photo) + '" alt="">';
            });
            scr.querySelector('[data-save]').addEventListener('click', function () {
                var b = this, x = pad.querySelector('.err');
                var data = collect(pad);
                var m = missing(defs.concat(id ? [] : pw), data);
                if (!m && !id && data.password !== data.confirm_password) m = 'Passwords do not match.';
                if (m) { x.hidden = false; x.querySelector('span').textContent = m; haptic('HEAVY'); return; }
                var fd = new FormData();
                Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
                fd.append('save_surveyor', '1');
                if (photo) fd.append('profile_photo', photo);
                busyBtn(b, true);
                postPage('add_surveyor.php' + (id ? '?id=' + id : ''), fd).then(function (r) {
                    if (!r.ok) throw new Error(r.message || 'Could not save.');
                    toast(r.message, 'ok'); haptic('MEDIUM'); history.back();
                }).catch(function (e) { busyBtn(b, false); x.hidden = false; x.querySelector('span').textContent = e.message; });
            });
        }).catch(function () { pad.innerHTML = '<div class="empty"><b>Not available</b></div>'; });
        return scr;
    };

    /* ---------------- Add client (add_client.php) / Add admin (add_admin.php) ---------------- */
    function simpleCreate(title, url, defs, extra, button) {
        var scr = el('<section class="scr" aria-label="' + esc(title) + '">' + barHeader(title, '') +
            '<div class="scr-body"><div class="scr-pad"><section class="card card-pad" data-rise data-f>' + fieldsHtml(defs, {}) + '</section>' +
            '<div class="err" hidden role="alert">' + icon('alert') + '<span></span></div><div style="height:84px"></div></div></div></section>');
        scr.appendChild(el('<div class="actions one"><button type="button" class="btn primary press" data-save>' + button + '</button></div>'));
        scr.querySelector('[data-save]').addEventListener('click', function () {
            var b = this, x = scr.querySelector('.err'), data = collect(scr.querySelector('[data-f]'));
            var m = missing(defs, data);
            if (!m && data.password !== data.confirm_password) m = 'Passwords do not match.';
            if (m) { x.hidden = false; x.querySelector('span').textContent = m; haptic('HEAVY'); return; }
            busyBtn(b, true);
            postPage(url, Object.assign(data, extra || {})).then(function (r) {
                if (!r.ok) throw new Error(r.message || 'Could not save.');
                toast(r.message, 'ok'); haptic('MEDIUM'); history.back();
            }).catch(function (e) { busyBtn(b, false); x.hidden = false; x.querySelector('span').textContent = e.message; });
        });
        stagger(scr);
        return scr;
    }
    SCREENS.addclient = function () {
        return simpleCreate('Add client', 'add_client.php', [
            { name: 'company_name', label: 'Company name', required: true }, { name: 'contact_person', label: 'Contact person', required: true },
            { name: 'username', label: 'Username', required: true }, { name: 'mobile', label: 'Mobile', type: 'tel', required: true },
            { name: 'email', label: 'Email', type: 'email', required: true }, { name: 'password', label: 'Password', type: 'password', required: true },
            { name: 'confirm_password', label: 'Confirm password', type: 'password', required: true }
        ], null, 'Create client');
    };
    SCREENS.addadmin = function () {
        return simpleCreate('Add admin', 'add_admin.php', [
            { name: 'full_name', label: 'Full name', required: true }, { name: 'username', label: 'Username', required: true },
            { name: 'mobile', label: 'Mobile', type: 'tel' }, { name: 'email', label: 'Email', type: 'email' },
            { name: 'password', label: 'Password', type: 'password', required: true }, { name: 'confirm_password', label: 'Confirm password', type: 'password', required: true },
            { name: 'is_super_admin', label: 'Make this a Super Admin login', type: 'checkbox' }
        ], null, 'Create login');
    };

    /* ---------------- global taps ---------------- */
    document.addEventListener('click', function (e) {
        var t = e.target.closest('[data-web],[data-go],[data-vessel],[data-upload],[data-history],[data-edit],[data-back],[data-refresh]');
        if (!t) return;
        if (t.hasAttribute('data-web')) {
            e.preventDefault();
            var url = t.getAttribute('data-web') || t.getAttribute('href');
            closeSheet(true); openWeb(url); return;
        }
        if (t.hasAttribute('data-vessel')) { haptic(); go('vessel', { id: +t.getAttribute('data-vessel') }); return; }
        if (t.hasAttribute('data-upload')) { haptic(); go('upload', { id: +t.getAttribute('data-upload') }); return; }
        if (t.hasAttribute('data-history')) { haptic(); go('history', { id: +t.getAttribute('data-history') }); return; }
        if (t.hasAttribute('data-edit')) { closeSheet(true); haptic(); go('assign', { id: +t.getAttribute('data-edit') }); return; }
        if (t.hasAttribute('data-go')) { haptic(); go(t.getAttribute('data-go')); return; }
        if (t.hasAttribute('data-back')) { back(); return; }
        if (t.hasAttribute('data-refresh')) { closeSheet(); location.reload(); }
    });

    function back() {
        if (closeSheet()) return;
        var r = current && current.route;
        if (current && current.depth > 0) { history.back(); return; }
        if (r && r.name !== 'home' && r.name !== 'login' && TABS.indexOf(r.name) !== -1) { go('home'); return; }
        if (r && TABS.indexOf(r.name) === -1 && r.name !== 'login') { go('home'); return; }
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
            if (!history.state) history.replaceState({ d: 0 }, '', hashFor(route.name, route.id));
            show(route, 'fade', d);
        }).catch(function () {});
    }
    splash(ready);
})();

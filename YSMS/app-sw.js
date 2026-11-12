/* YSMS app (app.php) offline support. Keeps the app's own page, scripts, styles and images so the
   app can open without a connection; the screens then show the data last saved on the phone
   (assets/app/app.js). Every other page of the website is left alone: no respondWith for them. */
var CACHE = 'ysms-app-v1';

self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) {
    e.waitUntil(caches.keys().then(function (keys) {
        return Promise.all(keys.filter(function (k) { return k.indexOf('ysms-app-') === 0 && k !== CACHE; }).map(function (k) { return caches.delete(k); }));
    }).then(function () { return self.clients.claim(); }));
});
self.addEventListener('message', function (e) {
    // Sign-out: drop everything kept for this user.
    if (e.data === 'clear') e.waitUntil(caches.delete(CACHE));
});

function scope() { return new URL(self.registration.scope).pathname; }
function isShell(url) {
    if (url.origin === self.location.origin) {
        var p = url.pathname, base = scope();
        return p === base + 'app.php' || p.indexOf(base + 'assets/app/') === 0 || p.indexOf(base + 'assets/images/') === 0;
    }
    return url.hostname === 'fonts.googleapis.com' || url.hostname === 'fonts.gstatic.com';
}

self.addEventListener('fetch', function (e) {
    var req = e.request;
    if (req.method !== 'GET') return;
    var url = new URL(req.url);
    if (!isShell(url)) return;
    var page = url.pathname === scope() + 'app.php';
    if (page) {
        // Network first, so sign-in state and new versions arrive whenever there is a connection.
        e.respondWith(fetch(req).then(function (res) {
            if (res.ok) { var copy = res.clone(); caches.open(CACHE).then(function (c) { c.put(scope() + 'app.php', copy); }); }
            return res;
        }).catch(function () {
            return caches.match(scope() + 'app.php').then(function (r) { return r || Response.error(); });
        }));
        return;
    }
    // Scripts, styles, images, fonts: saved copy at once, refreshed in the background.
    e.respondWith(caches.open(CACHE).then(function (c) {
        return c.match(req).then(function (hit) {
            var net = fetch(req).then(function (res) { if (res.ok || res.type === 'opaque') c.put(req, res.clone()); return res; });
            return hit || net;
        });
    }));
});

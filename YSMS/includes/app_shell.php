<?php
/**
 * Android app shell (mobile/ — Capacitor). The app opens these same pages and adds
 * "YSMSApp/<version>" to its user agent; only then do we load the app look and behaviour
 * (assets/css/app-shell.css, assets/js/app-shell.js). The website is unchanged.
 *
 * For a preview in a normal browser: ?ysms_app=1 turns the app look on for this browser
 * session, ?ysms_app=0 turns it off.
 *
 * Include inside <head>: <?php include __DIR__ . '/app_shell.php'; ?>
 */
if (!function_exists('ysmsIsApp')) {
    function ysmsIsApp(): bool
    {
        static $isApp = null;
        if ($isApp !== null) return $isApp;
        if (isset($_GET['ysms_app'])) {
            $on = $_GET['ysms_app'] === '1';
            if (!headers_sent()) setcookie('ysms_app', $on ? '1' : '', [
                'expires' => $on ? 0 : time() - 3600,
                'path' => '/',
                'samesite' => 'Lax',
                'httponly' => true,
                'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
            ]);
            return $isApp = $on;
        }
        return $isApp = (strpos((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 'YSMSApp/') !== false)
            || (($_COOKIE['ysms_app'] ?? '') === '1');
    }
}

if (ysmsIsApp()):
    $appShellDir = __DIR__ . '/../assets';
?>
    <meta name="theme-color" content="#0b1e46">
    <script>
    // Inside the app, Home and sign-in are the app's own screens (app.php).
    (function () {
        var pg = (location.pathname.split('/').pop() || 'index.php').toLowerCase();
        if (pg === 'index.php') location.replace('app.php#/home');
        else if (pg === 'login.php') location.replace('app.php');
    })();
    document.documentElement.classList.add('ysms-app');
    // Page transitions (assets/css/app-shell.css): slide back on Back, fade between bottom tabs.
    (function () {
        function navType() { try { return sessionStorage.getItem('ysmsNavType'); } catch (e) { return null; } }
        addEventListener('pageswap', function (e) {
            if (!e.viewTransition) return;
            if (e.activation && e.activation.navigationType === 'traverse') e.viewTransition.types.add('back');
            else if (navType() === 'tab') e.viewTransition.types.add('tab');
        });
        addEventListener('pagereveal', function (e) {
            var t = navType();
            try { sessionStorage.removeItem('ysmsNavType'); } catch (x) {}
            if (!e.viewTransition) return;
            var act = window.navigation && navigation.activation;
            if (act && act.navigationType === 'traverse') e.viewTransition.types.add('back');
            else if (t === 'tab') e.viewTransition.types.add('tab');
        });
    })();
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app-shell.css?v=<?= @filemtime($appShellDir . '/css/app-shell.css') ?: time() ?>">
    <script src="assets/js/app-shell.js?v=<?= @filemtime($appShellDir . '/js/app-shell.js') ?: time() ?>" defer></script>
<?php endif; ?>

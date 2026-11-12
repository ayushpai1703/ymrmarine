<?php
/**
 * YSMS Android app — the app's own screens (login, dashboard, lists, vessel details, profile).
 * The Android app (mobile/) opens this page. Data comes from app_api.php; actions that need
 * the full tools (uploads, survey form, report generator, admin pages) open the existing
 * pages inside the app. Works in a normal browser too, for a preview.
 */
require_once 'config/config.php';
header('Cache-Control: no-cache');
$v = function (string $f): string { return (string)(@filemtime(__DIR__ . '/' . $f) ?: time()); };
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <meta name="theme-color" content="#0b1e46">
    <script>
    // Light / dark before the first paint (Profile > Appearance; "system" follows the phone).
    (function () {
        var p = 'system';
        try { p = localStorage.getItem('ysmsTheme') || 'system'; } catch (e) {}
        var dark = p === 'dark' || (p === 'system' && window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
    })();
    </script>
    <title>YMR YSMS</title>
    <link rel="icon" href="assets/images/logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app/app.css?v=<?= $v('assets/app/app.css') ?>">
</head>
<body>
    <div id="app" aria-live="polite"></div>
    <nav id="tabbar" class="tabbar" aria-label="Main" hidden></nav>
    <script>window.YSMS_APP = { loggedIn: <?= !empty($_SESSION['user_id']) ? 'true' : 'false' ?>, uid: <?= (int)($_SESSION['user_id'] ?? 0) ?> };</script>
    <script src="assets/app/app.js?v=<?= $v('assets/app/app.js') ?>"></script>
</body>
</html>

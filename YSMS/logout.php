<?php
require_once 'config/config.php';
require_once 'includes/login_activity_helpers.php';

// 🌟 Login Activity audit log — close out this session's logout_time /
// session_duration before the session is wiped. Never blocks logout.
if (isset($_SESSION['user_id'])) {
    try {
        recordLogout(getDB(), (int)$_SESSION['user_id'], session_id());
    } catch (Throwable $e) {
        error_log('logout.php recordLogout: ' . $e->getMessage());
    }
}

// 🌟 Android app: this phone's "stay signed in" token goes too (fingerprint login stays, see
// includes/app_login_tokens.php).
if (!empty($_COOKIE['ysms_rm'])) {
    require_once 'includes/app_login_tokens.php';
    appRememberForget(getDB());
}

$_SESSION = [];
session_destroy();
header("Location: login.php");
exit;
?>
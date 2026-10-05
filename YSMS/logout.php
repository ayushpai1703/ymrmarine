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

$_SESSION = [];
session_destroy();
header("Location: login.php");
exit;
?>
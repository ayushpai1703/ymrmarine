<?php
require_once __DIR__.'/session.php';
if(!isset($_SESSION['user_id'])){
    // back to this page after signing in (login.php checks it with ysmsSafeLoginNext: a YSMS page only)
    $next = '';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && basename(dirname((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))) === 'report-generator') {
        $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
        $next = 'report-generator/' . basename((string)$_SERVER['SCRIPT_FILENAME']) . ($qs !== '' ? '?' . $qs : '');
    }
    header('Location: ../login.php' . ($next !== '' ? '?next=' . rawurlencode($next) : ''));
    exit;
}
csrf_require();
?>
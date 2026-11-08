<?php
/**
 * Vessel pictures (includes/vessel_image.php).
 *   GET  a=img&k=<key>      the vessel's picture (fetched by the server, kept for a week in the temp
 *                           folder); the YMR ship when there is none. Same origin, so a card can be shared as a picture.
 *   POST a=lookup names[]   the state of these vessels' pictures; up to 2 not looked up yet are asked of the AI now.
 * Signed-in users only.
 */
ob_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/vessel_image.php';

function viOut(int $code, array $d): void {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($d);
    exit;
}
function viDefault(): void {
    while (ob_get_level() > 0) ob_end_clean();
    header('Location: ../' . VI_DEFAULT, true, 302);
    header('Cache-Control: private, max-age=3600');
    exit;
}

if (empty($_SESSION['user_id'])) viOut(401, ['ok' => false, 'error' => 'Sign in again.']);
csrf_require();
$a = (string)($_REQUEST['a'] ?? '');

try {
    $db = getDB();
    viEnsure($db);

    if ($a === 'img') {
        $k = viKey((string)($_GET['k'] ?? ''));
        if ($k === '') viDefault();
        $q = $db->prepare("SELECT url FROM vessel_images WHERE name_key = ? AND status = 'found'");
        $q->execute([$k]);
        $url = (string)($q->fetchColumn() ?: '');
        if ($url === '') viDefault();
        $dir = rtrim(sys_get_temp_dir(), '/') . '/ysms_vessel_img';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $f = $dir . '/' . sha1($k . '|' . $url);
        $meta = @json_decode((string)@file_get_contents($f . '.json'), true);
        if (!is_file($f) || !$meta || filemtime($f) < time() - 7 * 86400) {
            $img = viFetch($url);
            if (!$img) viDefault();
            @file_put_contents($f . '.tmp', $img['body']); @rename($f . '.tmp', $f);
            @file_put_contents($f . '.json', json_encode(['type' => $img['type']]));
            $meta = ['type' => $img['type']];
        }
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: ' . (in_array($meta['type'] ?? '', ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true) ? $meta['type'] : 'image/jpeg'));
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: default-src \'none\'');
        header('Cache-Control: private, max-age=86400');
        header('Content-Length: ' . filesize($f));
        readfile($f);
        exit;
    }

    if ($a === 'lookup') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') viOut(405, ['ok' => false, 'error' => 'POST only.']);
        $names = array_slice(array_filter(array_map('strval', (array)($_POST['names'] ?? []))), 0, 40);
        $states = viStates($db, $names);
        $asked = 0;
        @set_time_limit(150);
        foreach ($states as $k => $st) {
            if ($asked >= 2) break;
            if ($st['status'] !== 'pending' && $st['status'] !== 'unknown') continue;
            $asked++;
            $new = viLookup($db, $k);
            $states[$k] = ['status' => $new, 'img' => $new === 'found' ? viImgUrl($k) : VI_DEFAULT];
        }
        viOut(200, ['ok' => true, 'states' => $states, 'more' => (bool)array_filter($states, fn($s) => in_array($s['status'], ['pending', 'unknown'], true))]);
    }
    viOut(400, ['ok' => false, 'error' => 'Unknown action.']);
} catch (Throwable $e) {
    error_log('vessel_image: ' . $e->getMessage());
    if ($a === 'img') viDefault();
    viOut(500, ['ok' => false, 'error' => 'Server error.']);
}

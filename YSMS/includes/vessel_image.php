<?php
/**
 * Vessel pictures for the vessel cards (Pending Vessels, mobile and desktop).
 *
 *  - A vessel assigned (assign_vessel.php) is queued: viQueue() adds a "pending" row for its name.
 *  - When the list is shown, the page asks ajax/vessel_image.php to look the pending names up:
 *    viLookup() asks Gemini (Google Search grounding) for a direct photo URL of that ship, then the
 *    server checks the picture itself (viFetch: https only, public address, an image, ≤ 3 MB).
 *    Found → "found" + the URL; not found / not usable → "none" (asked again after 30 days).
 *  - The picture is always served through ajax/vessel_image.php?a=img (same origin, so the card
 *    can be shared as a picture); the YMR ship (assets/images/ymr-ship.png) is shown otherwise.
 *
 * Table vessel_images (made on first use): one row per vessel name key.
 */

const VI_DEFAULT = 'assets/images/ymr-ship.png';
const VI_MAX_BYTES = 3145728;
const VI_RETRY_DAYS = 30;

function viEnsure(PDO $db): void {
    static $done = false;
    if ($done) return;
    $db->exec("CREATE TABLE IF NOT EXISTS `vessel_images` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `name_key` varchar(160) NOT NULL,
        `vessel_name` varchar(200) NOT NULL,
        `status` varchar(10) NOT NULL DEFAULT 'pending',
        `url` varchar(1000) DEFAULT NULL,
        `source` varchar(1000) DEFAULT NULL,
        `tried_at` datetime DEFAULT NULL,
        `created_at` datetime NOT NULL,
        PRIMARY KEY (`id`), UNIQUE KEY `uq_vi_name` (`name_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** "MV. SANTORINI " / "m/v santorini" → "SANTORINI" (the key one picture is kept under). */
function viKey(string $name): string {
    $k = strtoupper(trim(preg_replace('/\s+/', ' ', $name)));
    $k = preg_replace('/^(M\s*[\/.]?\s*V|M\s*[\/.]?\s*T|MT|MV|SS)\b\.?\s*/', '', $k);
    return mb_substr(trim($k, " .-"), 0, 160);
}

/** Queue a vessel name for a picture (nothing happens when it is known already). */
function viQueue(PDO $db, string $name): void {
    $k = viKey($name);
    if ($k === '') return;
    viEnsure($db);
    $db->prepare('INSERT IGNORE INTO vessel_images (name_key, vessel_name, status, created_at) VALUES (?, ?, \'pending\', NOW())')->execute([$k, mb_substr(trim($name), 0, 200)]);
}

/** Rows by key for these names: [key => ['status' => …, 'img' => url to show]]. */
function viStates(PDO $db, array $names): array {
    $keys = array_values(array_unique(array_filter(array_map('viKey', $names))));
    $out = [];
    foreach ($keys as $k) $out[$k] = ['status' => 'unknown', 'img' => VI_DEFAULT];
    if (!$keys) return $out;
    viEnsure($db);
    foreach (array_chunk($keys, 200) as $part) {
        $q = $db->prepare('SELECT name_key, status, tried_at FROM vessel_images WHERE name_key IN (' . implode(',', array_fill(0, count($part), '?')) . ')');
        $q->execute($part);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $st = $r['status'];
            if ($st === 'none' && $r['tried_at'] && strtotime($r['tried_at']) < time() - VI_RETRY_DAYS * 86400) $st = 'pending';
            $out[$r['name_key']] = ['status' => $st, 'img' => $st === 'found' ? viImgUrl($r['name_key']) : VI_DEFAULT];
        }
    }
    return $out;
}

function viImgUrl(string $key): string { return 'ajax/vessel_image.php?a=img&k=' . rawurlencode($key); }

/** The public internet address of this host (not this server, not a private network), or null. */
function viPublicIp(string $host): ?string {
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) return null;
    if (getenv('YSMS_VI_ALLOW_PRIVATE') === '1') return $ips[0]; // test servers only (never set in production)
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return null;
    }
    return $ips[0];
}

/**
 * Fetch a picture safely: http(s) only (https unless test mode), public address, at most 3 MB,
 * an image type, no redirects followed blindly (each hop checked, at most 3).
 * Returns ['type' => mime, 'body' => bytes] or null.
 */
function viFetch(string $url): ?array {
    for ($hop = 0; $hop < 4; $hop++) {
        $p = parse_url($url);
        $scheme = strtolower((string)($p['scheme'] ?? ''));
        $okScheme = $scheme === 'https' || ($scheme === 'http' && getenv('YSMS_VI_ALLOW_PRIVATE') === '1');
        $ip = ($okScheme && !empty($p['host'])) ? viPublicIp((string)$p['host']) : null;
        if ($ip === null) return null;
        $port = (int)($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        $body = ''; $tooBig = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; YSMS vessel picture)', CURLOPT_HTTPHEADER => ['Accept: image/*'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$p['host'] . ':' . $port . ':' . $ip], // the checked address is the one connected to
            CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$body, &$tooBig) {
                $body .= $chunk;
                if (strlen($body) > VI_MAX_BYTES) { $tooBig = true; return 0; }
                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = strtolower(trim(explode(';', (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE))[0]));
        $loc = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);
        if ($code >= 300 && $code < 400 && $loc !== '') { $url = $loc; continue; }
        if ($tooBig || $code !== 200 || $body === '') return null;
        $info = @getimagesizefromstring($body);
        if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) return null;
        if ($info[0] < 120 || $info[1] < 80) return null; // icons / tracking pixels
        return ['type' => $info['mime'], 'body' => $body];
    }
    return null;
}

/** Ask the AI for a photo of this vessel and keep the result. Returns the new status. */
function viLookup(PDO $db, string $key): string {
    viEnsure($db);
    $q = $db->prepare('SELECT * FROM vessel_images WHERE name_key = ?');
    $q->execute([$key]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) { viQueue($db, $key); $row = ['vessel_name' => $key]; }
    // claim it (one lookup at a time per vessel)
    $claim = $db->prepare("UPDATE vessel_images SET tried_at = NOW() WHERE name_key = ? AND (tried_at IS NULL OR tried_at < NOW() - INTERVAL 2 MINUTE)");
    $claim->execute([$key]);
    if ($claim->rowCount() === 0) return 'pending';
    require_once __DIR__ . '/gemini_json.php';
    $name = trim((string)$row['vessel_name']) ?: $key;
    $prompt = 'Find a real photograph of the merchant ship named "' . $name . '" (search ship photo sites such as ShipSpotting, MarineTraffic, VesselFinder, BalticShipping, MyShipTracking, FleetMon, or the owner\'s website). '
        . 'Answer only with JSON: {"image_url": "<direct https link to the photo file itself, .jpg/.jpeg/.png/.webp — not a web page>", "page_url": "<page where you saw it>", "same_ship": true|false}. '
        . 'If you cannot find a photo of this exact ship, answer {"image_url": "", "page_url": "", "same_ship": false}.';
    $res = geminiJson([['text' => $prompt]], 60, 'vessel picture', ['search' => true]);
    $status = 'none'; $url = null; $src = null;
    if ($res['ok']) {
        $cand = trim((string)($res['data']['image_url'] ?? ''));
        if ($cand !== '' && !empty($res['data']['same_ship']) && viFetch($cand)) { $status = 'found'; $url = mb_substr($cand, 0, 1000); $src = mb_substr((string)($res['data']['page_url'] ?? ''), 0, 1000); }
    } elseif (!empty($res['retry'])) {
        $status = 'pending'; // the AI did not answer: try again later
    }
    $db->prepare('UPDATE vessel_images SET status = ?, url = ?, source = ?, tried_at = NOW() WHERE name_key = ?')->execute([$status, $url, $src, $key]);
    return $status;
}

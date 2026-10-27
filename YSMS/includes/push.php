<?php
/**
 * Push notifications to the Android app (Firebase Cloud Messaging, HTTP v1).
 *
 * Every in-app notification (createNotification) is also sent to the phones where that user is
 * signed in to the app. Off until the server has the Firebase service account in the environment
 * variable YSMS_FCM_SERVICE_ACCOUNT (the JSON key file's contents); without it nothing is sent and
 * nothing changes. The key never leaves the server and is never logged.
 *
 * Phones register their token through ajax/app_push_token.php (table app_push_tokens).
 */

function ensurePushTokensTable(PDO $db): void {
    static $done = false;
    if ($done) return;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `app_push_tokens` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `user_id` INT(11) NOT NULL,
          `token` VARCHAR(512) NOT NULL,
          `platform` VARCHAR(20) NOT NULL DEFAULT 'android',
          `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_push_token` (`token`(255)),
          KEY `idx_push_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('ensurePushTokensTable: ' . $e->getMessage());
    }
    $done = true;
}

/** The service account (array) or null when push is not set up on this server. */
function pushServiceAccount(): ?array {
    static $sa = false;
    if ($sa !== false) return $sa;
    $raw = getenv('YSMS_FCM_SERVICE_ACCOUNT') ?: '';
    $j = $raw !== '' ? json_decode($raw, true) : null;
    $sa = (is_array($j) && !empty($j['client_email']) && !empty($j['private_key']) && !empty($j['project_id'])) ? $j : null;
    return $sa;
}

function pushEnabled(): bool {
    return pushServiceAccount() !== null;
}

/** OAuth access token for FCM, kept for 50 minutes in the system temp folder. */
function pushAccessToken(array $sa): ?string {
    $cache = sys_get_temp_dir() . '/ysms_fcm_' . md5($sa['client_email']) . '.json';
    $c = @json_decode((string)@file_get_contents($cache), true);
    if (is_array($c) && ($c['exp'] ?? 0) > time() + 60) return $c['token'];
    $b64 = function (string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); };
    $now = time();
    $head = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claims = $b64(json_encode(['iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));
    $sig = '';
    if (!openssl_sign($head . '.' . $claims, $sig, $sa['private_key'], 'sha256WithRSAEncryption')) {
        error_log('push: could not sign the access token request');
        return null;
    }
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5,
        CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $head . '.' . $claims . '.' . $b64($sig)])]);
    $res = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    if (empty($res['access_token'])) {
        error_log('push: no access token from Google');
        return null;
    }
    @file_put_contents($cache, json_encode(['token' => $res['access_token'], 'exp' => $now + 3000]), LOCK_EX);
    @chmod($cache, 0600);
    return $res['access_token'];
}

/** Pushes queued during this request, sent together when it ends (see pushFlush). */
function pushQueue(int $userId, string $title, string $body, ?string $link): void {
    static $registered = false;
    if (!pushEnabled() || $userId <= 0) return;
    $GLOBALS['__ysms_push_queue'][] = [$userId, $title, $body, $link];
    if (!$registered) {
        $registered = true;
        register_shutdown_function('pushFlush');
    }
}

function pushFlush(): void {
    $queue = $GLOBALS['__ysms_push_queue'] ?? [];
    $GLOBALS['__ysms_push_queue'] = [];
    $sa = pushServiceAccount();
    if (!$queue || !$sa) return;
    try {
        $db = getDB();
        ensurePushTokensTable($db);
        $access = pushAccessToken($sa);
        if (!$access) return;
        $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($sa['project_id']) . '/messages:send';
        $mh = curl_multi_init();
        $handles = [];
        $st = $db->prepare('SELECT token FROM app_push_tokens WHERE user_id = ?');
        foreach ($queue as [$uid, $title, $body, $link]) {
            $st->execute([$uid]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $token) {
                $msg = ['message' => [
                    'token' => $token,
                    'notification' => ['title' => mb_substr($title, 0, 120), 'body' => mb_substr(trim(strip_tags($body)), 0, 240)],
                    'data' => ['url' => (string)($link ?? '')],
                    'android' => ['priority' => 'HIGH', 'notification' => ['channel_id' => 'ysms', 'color' => '#1e3a8a']],
                ]];
                $ch = curl_init($url);
                curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4,
                    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $access, 'Content-Type: application/json'],
                    CURLOPT_POSTFIELDS => json_encode($msg)]);
                curl_multi_add_handle($mh, $ch);
                $handles[] = [$ch, $token];
            }
        }
        do { $s = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running && $s === CURLM_OK);
        $del = $db->prepare('DELETE FROM app_push_tokens WHERE token = ?');
        foreach ($handles as [$ch, $token]) {
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            // The app was removed or signed out on that phone: forget the token.
            if ($code === 404 || ($code === 400 && strpos((string)curl_multi_getcontent($ch), 'UNREGISTERED') !== false)) $del->execute([$token]);
            elseif ($code !== 200) error_log('push: FCM answered HTTP ' . $code);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    } catch (Throwable $e) {
        error_log('push: ' . $e->getMessage());
    }
}

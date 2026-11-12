<?php
/**
 * Android app sign-in that lasts until Log out, and fingerprint login.
 *
 *  - remember: after a password login in the app (login.php, YSMSApp user agent) the phone gets the
 *    cookie ysms_rm = <selector>:<validator> for 180 days. When the PHP session is gone (expired,
 *    new server instance) config/config.php signs the user back in from it and gives a new
 *    validator. Log out deletes it.
 *  - biometric: "Fingerprint login" in the app's Profile stores a device key on the phone, encrypted
 *    by the phone's Keystore so only the fingerprint can read it (YsmsNativePlugin). It stays after
 *    Log out, so the login screen can sign in with the fingerprint (ajax/app_bio_login.php); turning
 *    the switch off deletes it.
 *
 * Only a SHA-256 of each validator is stored; selectors are random and the tokens are per phone.
 */

const APP_REMEMBER_COOKIE = 'ysms_rm';
const APP_REMEMBER_DAYS = 180;
const APP_BIOMETRIC_DAYS = 365;

function ensureAppLoginTokensTable(PDO $db): void {
    static $done = false;
    if ($done) return;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `app_login_tokens` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `user_id` INT(11) NOT NULL,
          `kind` VARCHAR(16) NOT NULL,
          `selector` CHAR(24) NOT NULL,
          `validator_hash` CHAR(64) NOT NULL,
          `device` VARCHAR(64) DEFAULT NULL,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `last_used_at` DATETIME DEFAULT NULL,
          `expires_at` DATETIME NOT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_login_selector` (`selector`),
          KEY `idx_login_user` (`user_id`, `kind`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('ensureAppLoginTokensTable: ' . $e->getMessage());
    }
    $done = true;
}

function appIsAppRequest(): bool {
    return strpos((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 'YSMSApp/') !== false;
}

/** New token row; returns "<selector>:<validator>" (the only time the validator exists in clear). */
function appTokenCreate(PDO $db, int $userId, string $kind, int $days, ?string $device = null): string {
    ensureAppLoginTokensTable($db);
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO app_login_tokens (user_id, kind, selector, validator_hash, device, expires_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$userId, $kind, $selector, hash('sha256', $validator), $device, date('Y-m-d H:i:s', time() + $days * 86400)]);
    return $selector . ':' . $validator;
}

/**
 * The active user for a "<selector>:<validator>" token of this kind, or null. A right selector with
 * a wrong validator means the token was copied: all of that user's tokens of this kind are removed.
 */
function appTokenCheck(PDO $db, string $token, string $kind): ?array {
    if (!preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $token, $m)) return null;
    ensureAppLoginTokensTable($db);
    $st = $db->prepare('SELECT * FROM app_login_tokens WHERE selector = ? AND kind = ?');
    $st->execute([$m[1], $kind]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    if (!hash_equals($row['validator_hash'], hash('sha256', $m[2]))) {
        $db->prepare('DELETE FROM app_login_tokens WHERE user_id = ? AND kind = ?')->execute([(int)$row['user_id'], $kind]);
        error_log('app_login_tokens: wrong validator, ' . $kind . ' tokens of user ' . (int)$row['user_id'] . ' removed');
        return null;
    }
    if (strtotime($row['expires_at']) < time()) {
        $db->prepare('DELETE FROM app_login_tokens WHERE id = ?')->execute([(int)$row['id']]);
        return null;
    }
    $u = $db->prepare("SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ? AND u.status = 'Active'");
    $u->execute([(int)$row['user_id']]);
    $user = $u->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        $db->prepare('DELETE FROM app_login_tokens WHERE user_id = ?')->execute([(int)$row['user_id']]);
        return null;
    }
    $db->prepare('UPDATE app_login_tokens SET last_used_at = NOW() WHERE id = ?')->execute([(int)$row['id']]);
    return ['user' => $user, 'row' => $row];
}

/** The same session values login.php sets for a password login. */
function appStartSession(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role_name'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['avatar'] = !empty($user['avatar']) ? $user['avatar'] : (!empty($user['profile_pic']) ? $user['profile_pic'] : null);
}

function appRememberSetCookie(string $value, int $expires): void {
    if (headers_sent()) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie(APP_REMEMBER_COOKIE, $value, ['expires' => $expires, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
}

/** After a successful login in the app: stay signed in on this phone until Log out. */
function appRememberIssue(PDO $db, int $userId): void {
    try {
        appRememberForget($db);
        appRememberSetCookie(appTokenCreate($db, $userId, 'remember', APP_REMEMBER_DAYS), time() + APP_REMEMBER_DAYS * 86400);
    } catch (Throwable $e) {
        error_log('appRememberIssue: ' . $e->getMessage());
    }
}

/** No session but a remember cookie: sign back in (called from config/config.php). */
function appRememberRestore(PDO $db): bool {
    $token = (string)($_COOKIE[APP_REMEMBER_COOKIE] ?? '');
    try {
        $hit = $token !== '' ? appTokenCheck($db, $token, 'remember') : null;
        if (!$hit) {
            appRememberSetCookie('', time() - 3600);
            return false;
        }
        appStartSession($hit['user']);
        // New validator each time, so an old copy of the cookie stops working.
        $validator = bin2hex(random_bytes(32));
        $db->prepare('UPDATE app_login_tokens SET validator_hash = ?, expires_at = ? WHERE id = ?')
            ->execute([hash('sha256', $validator), date('Y-m-d H:i:s', time() + APP_REMEMBER_DAYS * 86400), (int)$hit['row']['id']]);
        appRememberSetCookie($hit['row']['selector'] . ':' . $validator, time() + APP_REMEMBER_DAYS * 86400);
        $_COOKIE[APP_REMEMBER_COOKIE] = $hit['row']['selector'] . ':' . $validator;
        return true;
    } catch (Throwable $e) {
        error_log('appRememberRestore: ' . $e->getMessage());
        return false;
    }
}

/** Log out: this phone's remember token goes. */
function appRememberForget(PDO $db): void {
    $token = (string)($_COOKIE[APP_REMEMBER_COOKIE] ?? '');
    if ($token === '') return;
    try {
        if (preg_match('/^([a-f0-9]{24}):/', $token, $m)) {
            ensureAppLoginTokensTable($db);
            $db->prepare("DELETE FROM app_login_tokens WHERE selector = ? AND kind = 'remember'")->execute([$m[1]]);
        }
    } catch (Throwable $e) {
        error_log('appRememberForget: ' . $e->getMessage());
    }
    appRememberSetCookie('', time() - 3600);
    unset($_COOKIE[APP_REMEMBER_COOKIE]);
}

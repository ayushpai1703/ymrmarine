<?php
/**
 * Login Activity / Access Monitoring — shared helpers.
 *
 * SAFETY NET: login_activity table — one row per login attempt (success
 * or failure) and, where known, its matching logout. Mirrors the
 * established ensureXTable(PDO $db) lazy-create pattern already used
 * throughout this app (see config/config.php's ensureSurveyAttachmentsTable
 * / ensureFloatingNotesTable) — also mirrored in
 * database/migration_login_activity.sql for manual/DBA reference.
 *
 * user_id/user_name/username/role/user_email are captured redundantly at
 * login time (not just via a users.id FK) so the audit trail still reads
 * correctly even if a user is later renamed, has their role changed, or
 * is deleted — an audit log must describe what happened at the time, not
 * be silently rewritten by later changes to the live users table.
 *
 * Nothing in this file may ever throw out of a login/logout request —
 * every public function here swallows its own errors (error_log only),
 * because tracking a login must never be able to block a legitimate one.
 */

function ensureLoginActivityTable(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `login_activity` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `user_id` int DEFAULT NULL,
            `user_name` varchar(150) DEFAULT NULL,
            `username` varchar(100) DEFAULT NULL,
            `user_email` varchar(150) DEFAULT NULL,
            `role` varchar(50) DEFAULT NULL,
            `ip_address` varchar(45) DEFAULT NULL,
            `country` varchar(100) DEFAULT NULL,
            `country_code` varchar(10) DEFAULT NULL,
            `device_type` varchar(50) DEFAULT NULL,
            `operating_system` varchar(100) DEFAULT NULL,
            `browser` varchar(100) DEFAULT NULL,
            `user_agent` text,
            `login_time` datetime NOT NULL,
            `logout_time` datetime DEFAULT NULL,
            `session_duration` int DEFAULT NULL,
            `login_status` varchar(30) NOT NULL DEFAULT 'SUCCESS',
            `failure_reason` varchar(255) DEFAULT NULL,
            `session_id` varchar(255) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_id` (`user_id`),
            KEY `idx_role` (`role`),
            KEY `idx_login_time` (`login_time`),
            KEY `idx_login_status` (`login_status`),
            KEY `idx_ip_address` (`ip_address`),
            KEY `idx_session_id` (`session_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('ensureLoginActivityTable: ' . $e->getMessage());
    }
}

/* ==========================================================================
   Client IP — Cloud Run aware
   ========================================================================== */

/**
 * Real client IP behind Cloud Run / Google Cloud Load Balancer.
 *
 * Cloud Run containers are only reachable through Google's own front end —
 * there is no way for an external client to connect to this container
 * directly and forge REMOTE_ADDR or inject an arbitrary X-Forwarded-For
 * that Apache would see as the first hop. Google's edge sets/prepends the
 * true client IP as the FIRST entry of X-Forwarded-For before the request
 * ever reaches this container (see Dockerfile: php:8.3-apache on Cloud
 * Run, no other reverse proxy in front of it), so taking that first entry
 * is safe in this specific deployment. Falls back to REMOTE_ADDR for any
 * environment where that header isn't present (e.g. local CLI testing).
 */
function getClientIp(): string
{
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') {
        $first = trim(explode(',', $xff)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '';
}

/* ==========================================================================
   User-Agent parsing — server-side only, no JS dependency
   ========================================================================== */

/**
 * Classifies device type / OS / browser from a User-Agent string using
 * ordered regex checks (order matters: e.g. iPad must be checked before
 * generic Mac, Edge before Chrome since Edge's UA also contains "Chrome").
 */
function parseUserAgent(?string $ua): array
{
    $ua = (string)($ua ?? '');
    if ($ua === '') {
        return ['device_type' => 'Unknown', 'operating_system' => 'Unknown', 'browser' => 'Unknown'];
    }

    // A User-Agent cannot reliably distinguish a laptop from a desktop PC
    // (both report the same "Windows NT"/"Macintosh"/"X11; Linux" tokens),
    // so non-mobile/tablet traffic is reported as "Desktop" — the neutral,
    // defensible default for an audit log — rather than guessing "Laptop".
    $deviceType = 'Desktop';
    if (preg_match('/iPad|Android(?!.*Mobile)|Tablet|Kindle|Silk/i', $ua)) {
        $deviceType = 'Tablet';
    } elseif (preg_match('/Mobi|iPhone|iPod|Android.*Mobile|BlackBerry|IEMobile|Opera Mini/i', $ua)) {
        $deviceType = 'Mobile';
    }

    // Windows 10 and 11 both report "Windows NT 10.0" in the UA string —
    // not reliably distinguishable without client-side hints, so both
    // report as plain "Windows".
    $os = 'Unknown';
    if (preg_match('/Windows NT/i', $ua)) {
        $os = 'Windows';
    } elseif (preg_match('/iPhone|iPad|iPod/i', $ua)) {
        $os = 'iOS';
    } elseif (preg_match('/Mac OS X/i', $ua)) {
        $os = 'macOS';
    } elseif (preg_match('/Android/i', $ua)) {
        $os = 'Android';
    } elseif (preg_match('/CrOS/i', $ua)) {
        $os = 'Chrome OS';
    } elseif (preg_match('/Linux/i', $ua)) {
        $os = 'Linux';
    }

    $browser = 'Other';
    if (preg_match('/Edg\//i', $ua)) {
        $browser = 'Edge';
    } elseif (preg_match('/OPR\/|Opera/i', $ua)) {
        $browser = 'Opera';
    } elseif (preg_match('/SamsungBrowser/i', $ua)) {
        $browser = 'Samsung Internet';
    } elseif (preg_match('/FxiOS/i', $ua)) {
        $browser = 'Firefox';
    } elseif (preg_match('/CriOS/i', $ua)) {
        $browser = 'Chrome';
    } elseif (preg_match('/Firefox\//i', $ua)) {
        $browser = 'Firefox';
    } elseif (preg_match('/Chrome\//i', $ua) && !preg_match('/Chromium/i', $ua)) {
        $browser = 'Chrome';
    } elseif (preg_match('/Safari\//i', $ua) && preg_match('/Version\//i', $ua)) {
        $browser = 'Safari';
    } elseif (preg_match('/MSIE|Trident/i', $ua)) {
        $browser = 'Internet Explorer';
    }

    return ['device_type' => $deviceType, 'operating_system' => $os, 'browser' => $browser];
}

/* ==========================================================================
   Country lookup — offline, no external API, no API key
   Reuses the bundled DB-IP "Country Lite" dataset already shipped in this
   repo at data/geoip/country-ipv4.bin (see includes/analytics.php at the
   repo root for the original implementation this mirrors). Kept as its own
   copy here so this YSMS module doesn't reach into the sibling root-level
   app's includes/ — same bundled binary file, read independently.
   ========================================================================== */

/** Binary search against the bundled IPv4 country-range file (10-byte
 *  records: 4-byte start, 4-byte end, 2-byte country code, sorted by
 *  start) — ~19 seeks for ~357k ranges, never a full file read. Returns a
 *  2-letter ISO country code, or '' for IPv6 / unparsable / uncovered IPs. */
function ysmsLookupCountryCode(string $ip): string
{
    static $fh = null;
    static $recordCount = null;

    if ($ip === '' || strpos($ip, ':') !== false) {
        return ''; // IPv6 — not covered by this IPv4-only dataset
    }
    $long = ip2long($ip);
    if ($long === false) {
        return '';
    }
    $target = (float)sprintf('%u', $long);

    if ($fh === null) {
        $file = __DIR__ . '/../../data/geoip/country-ipv4.bin';
        if (!is_file($file)) {
            $fh = false;
        } else {
            $fh = fopen($file, 'rb');
            $recordCount = $fh ? intdiv(filesize($file), 10) : 0;
        }
    }
    if ($fh === false || !$recordCount) {
        return '';
    }

    $lo = 0;
    $hi = $recordCount - 1;
    while ($lo <= $hi) {
        $mid = intdiv($lo + $hi, 2);
        fseek($fh, $mid * 10);
        $data = fread($fh, 10);
        if ($data === false || strlen($data) < 10) {
            break;
        }
        $rec = unpack('Nstart/Nend/a2cc', $data);
        $start = (float)sprintf('%u', $rec['start']);
        $end = (float)sprintf('%u', $rec['end']);
        if ($target < $start) {
            $hi = $mid - 1;
        } elseif ($target > $end) {
            $lo = $mid + 1;
        } else {
            return strtoupper($rec['cc']);
        }
    }
    return '';
}

/** 2-letter ISO code -> flag emoji, via Unicode regional indicator symbols
 *  (no lookup table needed — each letter A-Z maps to U+1F1E6..U+1F1FF). */
function ysmsCountryFlagEmoji(string $code): string
{
    $code = strtoupper(trim($code));
    if (!preg_match('/^[A-Z]{2}$/', $code)) {
        return '';
    }
    $flag = '';
    foreach (str_split($code) as $letter) {
        $flag .= mb_chr(0x1F1E6 + (ord($letter) - 65), 'UTF-8');
    }
    return $flag;
}

/** Full country name for display, e.g. 'IN' -> 'India'. Falls back to the
 *  bare code for anything not in this map rather than failing. */
function ysmsCountryName(string $code): string
{
    static $names = [
        'AF'=>'Afghanistan','AL'=>'Albania','DZ'=>'Algeria','AD'=>'Andorra','AO'=>'Angola',
        'AG'=>'Antigua and Barbuda','AR'=>'Argentina','AM'=>'Armenia','AU'=>'Australia','AT'=>'Austria',
        'AZ'=>'Azerbaijan','BS'=>'Bahamas','BH'=>'Bahrain','BD'=>'Bangladesh','BB'=>'Barbados',
        'BY'=>'Belarus','BE'=>'Belgium','BZ'=>'Belize','BJ'=>'Benin','BT'=>'Bhutan',
        'BO'=>'Bolivia','BA'=>'Bosnia and Herzegovina','BW'=>'Botswana','BR'=>'Brazil','BN'=>'Brunei',
        'BG'=>'Bulgaria','BF'=>'Burkina Faso','BI'=>'Burundi','KH'=>'Cambodia','CM'=>'Cameroon',
        'CA'=>'Canada','CV'=>'Cabo Verde','CF'=>'Central African Republic','TD'=>'Chad','CL'=>'Chile',
        'CN'=>'China','CO'=>'Colombia','KM'=>'Comoros','CG'=>'Congo','CD'=>'DR Congo',
        'CR'=>'Costa Rica','CI'=>"Cote d'Ivoire",'HR'=>'Croatia','CU'=>'Cuba','CY'=>'Cyprus',
        'CZ'=>'Czechia','DK'=>'Denmark','DJ'=>'Djibouti','DM'=>'Dominica','DO'=>'Dominican Republic',
        'EC'=>'Ecuador','EG'=>'Egypt','SV'=>'El Salvador','GQ'=>'Equatorial Guinea','ER'=>'Eritrea',
        'EE'=>'Estonia','SZ'=>'Eswatini','ET'=>'Ethiopia','FJ'=>'Fiji','FI'=>'Finland',
        'FR'=>'France','GA'=>'Gabon','GM'=>'Gambia','GE'=>'Georgia','DE'=>'Germany',
        'GH'=>'Ghana','GR'=>'Greece','GD'=>'Grenada','GT'=>'Guatemala','GN'=>'Guinea',
        'GW'=>'Guinea-Bissau','GY'=>'Guyana','HT'=>'Haiti','HN'=>'Honduras','HK'=>'Hong Kong',
        'HU'=>'Hungary','IS'=>'Iceland','IN'=>'India','ID'=>'Indonesia','IR'=>'Iran',
        'IQ'=>'Iraq','IE'=>'Ireland','IL'=>'Israel','IT'=>'Italy','JM'=>'Jamaica',
        'JP'=>'Japan','JO'=>'Jordan','KZ'=>'Kazakhstan','KE'=>'Kenya','KI'=>'Kiribati',
        'KP'=>'North Korea','KR'=>'South Korea','KW'=>'Kuwait','KG'=>'Kyrgyzstan','LA'=>'Laos',
        'LV'=>'Latvia','LB'=>'Lebanon','LS'=>'Lesotho','LR'=>'Liberia','LY'=>'Libya',
        'LI'=>'Liechtenstein','LT'=>'Lithuania','LU'=>'Luxembourg','MO'=>'Macao','MG'=>'Madagascar',
        'MW'=>'Malawi','MY'=>'Malaysia','MV'=>'Maldives','ML'=>'Mali','MT'=>'Malta',
        'MH'=>'Marshall Islands','MR'=>'Mauritania','MU'=>'Mauritius','MX'=>'Mexico','FM'=>'Micronesia',
        'MD'=>'Moldova','MC'=>'Monaco','MN'=>'Mongolia','ME'=>'Montenegro','MA'=>'Morocco',
        'MZ'=>'Mozambique','MM'=>'Myanmar','NA'=>'Namibia','NR'=>'Nauru','NP'=>'Nepal',
        'NL'=>'Netherlands','NZ'=>'New Zealand','NI'=>'Nicaragua','NE'=>'Niger','NG'=>'Nigeria',
        'MK'=>'North Macedonia','NO'=>'Norway','OM'=>'Oman','PK'=>'Pakistan','PW'=>'Palau',
        'PS'=>'Palestine','PA'=>'Panama','PG'=>'Papua New Guinea','PY'=>'Paraguay','PE'=>'Peru',
        'PH'=>'Philippines','PL'=>'Poland','PT'=>'Portugal','QA'=>'Qatar','RO'=>'Romania',
        'RU'=>'Russia','RW'=>'Rwanda','KN'=>'Saint Kitts and Nevis','LC'=>'Saint Lucia','VC'=>'Saint Vincent and the Grenadines',
        'WS'=>'Samoa','SM'=>'San Marino','ST'=>'Sao Tome and Principe','SA'=>'Saudi Arabia','SN'=>'Senegal',
        'RS'=>'Serbia','SC'=>'Seychelles','SL'=>'Sierra Leone','SG'=>'Singapore','SK'=>'Slovakia',
        'SI'=>'Slovenia','SB'=>'Solomon Islands','SO'=>'Somalia','ZA'=>'South Africa','SS'=>'South Sudan',
        'ES'=>'Spain','LK'=>'Sri Lanka','SD'=>'Sudan','SR'=>'Suriname','SE'=>'Sweden',
        'CH'=>'Switzerland','SY'=>'Syria','TW'=>'Taiwan','TJ'=>'Tajikistan','TZ'=>'Tanzania',
        'TH'=>'Thailand','TL'=>'Timor-Leste','TG'=>'Togo','TO'=>'Tonga','TT'=>'Trinidad and Tobago',
        'TN'=>'Tunisia','TR'=>'Turkiye','TM'=>'Turkmenistan','TV'=>'Tuvalu','UG'=>'Uganda',
        'UA'=>'Ukraine','AE'=>'United Arab Emirates','GB'=>'United Kingdom','US'=>'United States','UY'=>'Uruguay',
        'UZ'=>'Uzbekistan','VU'=>'Vanuatu','VA'=>'Vatican City','VE'=>'Venezuela','VN'=>'Vietnam',
        'YE'=>'Yemen','ZM'=>'Zambia','ZW'=>'Zimbabwe',
    ];
    $code = strtoupper(trim($code));
    return $names[$code] ?? $code;
}

/** Combined helper used by the login tracker: resolves an IP straight to
 *  a display-ready ['country'=>'India','country_code'=>'IN'] pair, or
 *  ['country'=>'Unknown','country_code'=>''] if it can't be determined.
 *  Never throws — geolocation failure must never block a login. */
function ysmsResolveCountry(string $ip): array
{
    try {
        $code = ysmsLookupCountryCode($ip);
        if ($code === '') {
            return ['country' => 'Unknown', 'country_code' => ''];
        }
        return ['country' => ysmsCountryName($code), 'country_code' => $code];
    } catch (Throwable $e) {
        error_log('ysmsResolveCountry: ' . $e->getMessage());
        return ['country' => 'Unknown', 'country_code' => ''];
    }
}

/* ==========================================================================
   Recording — called from login.php / logout.php
   ========================================================================== */

/**
 * Records one login attempt (success or failure). Never throws — a
 * tracking failure must never block or break a legitimate login. $userRow
 * is the users-joined-roles row (with role_name) when a matching account
 * was found — even on a failed attempt (wrong password), so the audit log
 * still shows which real account was targeted — or null if the username
 * itself didn't match any account.
 */
function recordLoginAttempt(?PDO $db, ?array $userRow, string $attemptedUsername, bool $success, string $failureReason = ''): void
{
    if (!$db) return;
    try {
        ensureLoginActivityTable($db);
        $ip = getClientIp();
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $parsed = parseUserAgent($ua);
        $geo = ysmsResolveCountry($ip);

        $stmt = $db->prepare("INSERT INTO login_activity
            (user_id, user_name, username, user_email, role, ip_address, country, country_code,
             device_type, operating_system, browser, user_agent, login_time,
             login_status, failure_reason, session_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $userRow['id'] ?? null,
            $userRow['full_name'] ?? null,
            $userRow['username'] ?? $attemptedUsername,
            $userRow['email'] ?? null,
            $userRow['role_name'] ?? null,
            $ip !== '' ? $ip : null,
            $geo['country'],
            $geo['country_code'] !== '' ? $geo['country_code'] : null,
            $parsed['device_type'],
            $parsed['operating_system'],
            $parsed['browser'],
            $ua !== '' ? $ua : null,
            function_exists('getIST') ? getIST() : date('Y-m-d H:i:s'),
            $success ? 'SUCCESS' : 'FAILED',
            $success ? null : $failureReason,
            $success ? session_id() : null,
        ]);
    } catch (Throwable $e) {
        error_log('recordLoginAttempt: ' . $e->getMessage());
    }
}

/**
 * Closes out the current session's most recent SUCCESS login_activity row
 * with a logout_time + computed session_duration (seconds). Called from
 * logout.php before the session is destroyed. Never throws.
 */
function recordLogout(?PDO $db, int $userId, string $sessionId): void
{
    if (!$db || $userId <= 0 || $sessionId === '') return;
    try {
        ensureLoginActivityTable($db);
        $stmt = $db->prepare("SELECT id, login_time FROM login_activity
            WHERE user_id = ? AND session_id = ? AND login_status = 'SUCCESS' AND logout_time IS NULL
            ORDER BY id DESC LIMIT 1");
        $stmt->execute([$userId, $sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;

        $now = function_exists('getIST') ? getIST() : date('Y-m-d H:i:s');
        $duration = max(0, strtotime($now) - strtotime($row['login_time']));
        $upd = $db->prepare("UPDATE login_activity SET logout_time = ?, session_duration = ? WHERE id = ?");
        $upd->execute([$now, $duration, $row['id']]);
    } catch (Throwable $e) {
        error_log('recordLogout: ' . $e->getMessage());
    }
}

/** '8534' seconds -> '2h 22m'; under an hour -> '18m 32s'; under a minute -> '45s'. */
function formatSessionDuration(?int $seconds): string
{
    if ($seconds === null) return 'Not recorded';
    if ($seconds < 60) return $seconds . 's';
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    if ($h > 0) return $h . 'h ' . $m . 'm';
    $s = $seconds % 60;
    return $m . 'm ' . $s . 's';
}

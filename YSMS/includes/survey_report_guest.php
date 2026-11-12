<?php
/**
 * Survey report without signing in (survey_report_public.php, ajax/survey_report_public.php).
 *
 * Anyone with the page link can start a new report. The report is stored like any other report made
 * without a vessel (survey_reports, survey_id NULL, created_by NULL), so the Admin sees it in Survey
 * Reports. The person who made it reaches it again only with its secret link (?t=<token>): only the
 * SHA-256 of the token is stored. A guest never sees any other report, vessel, user or template file.
 * Each IP address is limited per hour (survey_report_guest_hits), so the page cannot be used to fill
 * the database or run up the IMO lookup / PDF services.
 */
require_once __DIR__ . '/survey_reports.php';
require_once __DIR__ . '/login_activity_helpers.php'; // getClientIp()

/** Per IP address per hour. */
const SRG_LIMITS = ['new' => 10, 'lookup' => 20, 'generate' => 30, 'preview' => 60, 'import' => 20, 'save' => 900];
const SRG_MAX_DATA = 1024 * 1024; // the saved data of one report (JSON), bytes

function srgEnsure(PDO $db): void {
    static $done = false;
    if ($done) return;
    rtEnsureTables($db);
    $db->exec("CREATE TABLE IF NOT EXISTS `survey_report_guests` (
        `report_id` int(11) NOT NULL, `token_hash` char(64) NOT NULL, `ip` varchar(45) NOT NULL DEFAULT '', `created_at` datetime NOT NULL,
        PRIMARY KEY (`report_id`), UNIQUE KEY `uq_srg_token` (`token_hash`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS `survey_report_guest_hits` (
        `id` int(11) NOT NULL AUTO_INCREMENT, `ip` varchar(45) NOT NULL, `action` varchar(20) NOT NULL, `created_at` datetime NOT NULL,
        PRIMARY KEY (`id`), KEY `idx_srgh` (`ip`, `action`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // who started it when signed in; when the Admin locks it (read-only on the link from lock_at on)
    $have = array_column($db->query("SHOW COLUMNS FROM survey_report_guests")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    foreach (['user_id' => 'int(11) DEFAULT NULL', 'lock_at' => 'datetime DEFAULT NULL', 'lock_by' => 'int(11) DEFAULT NULL'] as $c => $def) {
        if (!in_array($c, $have, true)) $db->exec("ALTER TABLE survey_report_guests ADD COLUMN `$c` $def");
    }
    // every download of a made report from the link (shown to the Admin)
    $db->exec("CREATE TABLE IF NOT EXISTS `survey_report_downloads` (
        `id` int(11) NOT NULL AUTO_INCREMENT, `report_id` int(11) NOT NULL, `file_id` int(11) NOT NULL, `user_id` int(11) DEFAULT NULL,
        `ip` varchar(45) NOT NULL DEFAULT '', `downloaded_at` datetime NOT NULL,
        PRIMARY KEY (`id`), KEY `idx_srd_report` (`report_id`, `file_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** The link's lock of a report made on the link: ['lock_at' => ?string, 'locked' => bool]; not a link report → null. */
function srgLock(PDO $db, int $reportId): ?array {
    srgEnsure($db);
    $q = $db->prepare('SELECT lock_at FROM survey_report_guests WHERE report_id = ?');
    $q->execute([$reportId]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $at = $row['lock_at'] ?: null;
    return ['lock_at' => $at, 'locked' => $at !== null && strtotime($at) <= time()];
}

/** Record a download of a made report (from the link or by a signed-in non-Admin). */
function srgLogDownload(PDO $db, int $reportId, int $fileId): void {
    try {
        srgEnsure($db);
        $db->prepare('INSERT INTO survey_report_downloads (report_id, file_id, user_id, ip, downloaded_at) VALUES (?, ?, ?, ?, ?)')
           ->execute([$reportId, $fileId, (int)($_SESSION['user_id'] ?? 0) ?: null, getClientIp(), date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
        error_log('srgLogDownload: ' . $e->getMessage());
    }
}

/** A well-formed token (64 hex characters) or ''. */
function srgToken($t): string {
    $t = strtolower(trim((string)$t));
    return preg_match('/^[0-9a-f]{64}$/', $t) ? $t : '';
}

/** Counts one use of $action by this IP; false when the hourly limit is reached (the use is not counted). */
function srgHit(PDO $db, string $action): bool {
    srgEnsure($db);
    $ip = getClientIp() ?: 'unknown';
    if (mt_rand(1, 50) === 1) $db->exec("DELETE FROM survey_report_guest_hits WHERE created_at < NOW() - INTERVAL 1 DAY");
    $q = $db->prepare('SELECT COUNT(*) FROM survey_report_guest_hits WHERE ip = ? AND action = ? AND created_at > ?');
    $q->execute([$ip, $action, date('Y-m-d H:i:s', time() - 3600)]);
    if ((int)$q->fetchColumn() >= (SRG_LIMITS[$action] ?? 30)) return false;
    $db->prepare('INSERT INTO survey_report_guest_hits (ip, action, created_at) VALUES (?, ?, ?)')->execute([$ip, $action, date('Y-m-d H:i:s')]);
    return true;
}

/** The report of a guest token, shaped like srReport() (survey null), or null. */
function srgReport(PDO $db, string $token): ?array {
    if ($token === '') return null;
    srgEnsure($db);
    $q = $db->prepare('SELECT r.*, NULL AS updated_by_name, g.lock_at FROM survey_report_guests g JOIN survey_reports r ON r.id = g.report_id WHERE g.token_hash = ? AND r.survey_id IS NULL');
    $q->execute([hash('sha256', $token)]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $r['survey'] = null;
    $r['locked'] = !empty($r['lock_at']) && strtotime($r['lock_at']) <= time();
    $r['data'] = json_decode((string)$r['data'], true) ?: ['fields' => [], 'tanks' => []];
    $r['id'] = (int)$r['id']; $r['version'] = (int)$r['version']; $r['template_id'] = (int)$r['template_id'];
    return $r;
}

/** A new report of the active template of $kind, not tied to a vessel → its token. */
function srgNew(PDO $db, string $kind, int $uid = 0): array {
    srgEnsure($db);
    if (!isset(RT_KINDS[$kind])) return ['ok' => false, 'error' => 'Choose the report type.'];
    $t = rtActive($db, $kind);
    if (!$t) return ['ok' => false, 'error' => 'This report type is not available yet.'];
    $token = bin2hex(random_bytes(32));
    $now = date('Y-m-d H:i:s');
    $db->beginTransaction();
    try {
        $data = srDefaults(rtEffective($t), null);
        if ($uid && isset($data['fields']['surveyor_name']) && $data['fields']['surveyor_name'] === '') { // signed in: their name to start with
            $data['fields']['surveyor_name'] = strtoupper(trim((string)($_SESSION['full_name'] ?? '')));
        }
        $db->prepare('INSERT INTO survey_reports (survey_id, template_id, kind, data, version, created_by, created_at, updated_by, updated_at) VALUES (NULL, ?, ?, ?, 1, ?, ?, ?, ?)')
           ->execute([$t['id'], $kind, json_encode($data), $uid ?: null, $now, $uid ?: null, $now]);
        $id = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO survey_report_guests (report_id, token_hash, ip, created_at, user_id) VALUES (?, ?, ?, ?, ?)')
           ->execute([$id, hash('sha256', $token), getClientIp(), $now, $uid ?: null]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return ['ok' => true, 'token' => $token];
}

/** Report types a guest can start (the active templates). */
function srgKinds(PDO $db): array {
    rtEnsureTables($db);
    $out = [];
    foreach (RT_KINDS as $k => $l) if (rtActive($db, $k)) $out[$k] = $l;
    return $out;
}

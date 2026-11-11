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
    $done = true;
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
    $q = $db->prepare('SELECT r.*, NULL AS updated_by_name FROM survey_report_guests g JOIN survey_reports r ON r.id = g.report_id WHERE g.token_hash = ? AND r.survey_id IS NULL');
    $q->execute([hash('sha256', $token)]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $r['survey'] = null;
    $r['data'] = json_decode((string)$r['data'], true) ?: ['fields' => [], 'tanks' => []];
    $r['id'] = (int)$r['id']; $r['version'] = (int)$r['version']; $r['template_id'] = (int)$r['template_id'];
    return $r;
}

/** A new report of the active template of $kind, not tied to a vessel → its token. */
function srgNew(PDO $db, string $kind): array {
    srgEnsure($db);
    if (!isset(RT_KINDS[$kind])) return ['ok' => false, 'error' => 'Choose the report type.'];
    $t = rtActive($db, $kind);
    if (!$t) return ['ok' => false, 'error' => 'This report type is not available yet.'];
    $token = bin2hex(random_bytes(32));
    $now = date('Y-m-d H:i:s');
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO survey_reports (survey_id, template_id, kind, data, version, created_by, created_at, updated_by, updated_at) VALUES (NULL, ?, ?, ?, 1, NULL, ?, NULL, ?)')
           ->execute([$t['id'], $kind, json_encode(srDefaults(rtEffective($t), null)), $now, $now]);
        $id = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO survey_report_guests (report_id, token_hash, ip, created_at) VALUES (?, ?, ?, ?)')
           ->execute([$id, hash('sha256', $token), getClientIp(), $now]);
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

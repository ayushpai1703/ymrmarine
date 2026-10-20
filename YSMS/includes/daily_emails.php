<?php
/**
 * Daily automatic emails — 17:00 IST by default (Admin Controls → Daily Emails, admin_daily_emails.php):
 *
 *  surveyor_pending — "Daily Assigned Vessels Report": every Active surveyor with a profile email
 *                     gets only the vessels currently assigned to them (status Pending Vessel, as
 *                     primary or co-surveyor), as a table in the mail + Excel attached.
 *  appointments     — "Daily Vessel Appointments Report": one consolidated mail (default
 *                     ops@ymrmarine.com → survey@ymrmarine.com) with every vessel whose Appointment
 *                     Date is today or that was received (assigned) today; a "no appointments"
 *                     notice when there are none.
 *
 * Trigger: Cloud Scheduler → ajax/daily_emails_run.php?job=<job> with the X-Cron-Token header
 * (YSMS_CRON_TOKEN from Secret Manager), "0 17 * * *" Asia/Kolkata; a throttled check from
 * logged-in pages is only a safety net. All dates/times are Asia/Kolkata.
 *
 * Duplicates: one scheduled run per job per day (daily_email_runs.auto_key = job|date, UNIQUE),
 * claimed atomically. Every recipient is tracked in daily_email_deliveries; a failed run can be
 * retried (scheduler retry, next check, or "Retry failed" in Admin) and the retry only sends to
 * recipients whose delivery is not already 'sent'. A delivery is 'sent' only when PHPMailer /
 * the Gmail API accepted the message.
 */
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/excel_export_helpers.php';

const DAILY_EMAIL_JOBS = [
    'surveyor_pending' => 'Daily Assigned Vessels Report (each surveyor)',
    'appointments'     => 'Daily Vessel Appointments Report',
];

/** Fixed routing — not editable: the appointments report goes only from ops@ to survey@ (no CC);
 *  the assigned-vessels report goes to each surveyor's own profile email with survey@ in CC. */
const DAILY_EMAIL_APPT_FROM = 'ops@ymrmarine.com';
const DAILY_EMAIL_APPT_TO = 'survey@ymrmarine.com';
const DAILY_EMAIL_SURVEYOR_CC = 'survey@ymrmarine.com';

const DAILY_EMAIL_DEFAULTS = [
    'surveyor_pending_enabled'    => '1',
    'surveyor_pending_time'       => '17:00',
    'surveyor_pending_from'       => 'ops@ymrmarine.com',
    'surveyor_pending_cc'         => '',
    'surveyor_pending_send_empty' => '0',
    'appointments_enabled'        => '1',
    'appointments_time'           => '17:00',
    'appointments_from'           => 'ops@ymrmarine.com',
    'appointments_to'             => 'survey@ymrmarine.com',
    'appointments_cc'             => '',
    'appointments_send_empty'     => '1',
];

/** A scheduled run stays 'running' at most this long before a retry may take it over. */
const DAILY_EMAIL_STALE_MINUTES = 15;
/** Scheduled attempts per job per day (first run + retries). */
const DAILY_EMAIL_MAX_ATTEMPTS = 5;

function dailyEmailEnsureTables(PDO $db): void {
    static $done = false;
    if ($done) return;
    $db->exec("CREATE TABLE IF NOT EXISTS `daily_email_settings` (
        `name` VARCHAR(64) NOT NULL PRIMARY KEY,
        `value` TEXT NULL,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS `daily_email_runs` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `job` VARCHAR(40) NOT NULL,
        `run_date` DATE NOT NULL,
        `kind` VARCHAR(10) NOT NULL DEFAULT 'auto',
        `auto_key` VARCHAR(60) NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'running',
        `detail` TEXT NULL,
        `started_by` INT NULL,
        `started_at` DATETIME NOT NULL,
        `finished_at` DATETIME NULL,
        UNIQUE KEY `uq_auto_key` (`auto_key`),
        KEY `idx_job_date` (`job`, `run_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Added after the first release of this table
    $cols = array_column($db->query('SHOW COLUMNS FROM daily_email_runs')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('attempts', $cols, true)) $db->exec("ALTER TABLE daily_email_runs ADD COLUMN `attempts` INT NOT NULL DEFAULT 1 AFTER `status`");
    if (!in_array('via', $cols, true)) $db->exec("ALTER TABLE daily_email_runs ADD COLUMN `via` VARCHAR(20) NULL AFTER `kind`");
    $db->exec("CREATE TABLE IF NOT EXISTS `daily_email_deliveries` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `run_id` INT NOT NULL,
        `recipient` VARCHAR(190) NOT NULL,
        `recipient_name` VARCHAR(190) NULL,
        `vessels` INT NOT NULL DEFAULT 0,
        `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
        `error` TEXT NULL,
        `attempts` INT NOT NULL DEFAULT 0,
        `sent_at` DATETIME NULL,
        `updated_at` DATETIME NULL,
        UNIQUE KEY `uq_run_recipient` (`run_id`, `recipient`),
        KEY `idx_run` (`run_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $dcols = array_column($db->query('SHOW COLUMNS FROM daily_email_deliveries')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('cc_sent', $dcols, true)) $db->exec("ALTER TABLE daily_email_deliveries ADD COLUMN `cc_sent` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`");
    $done = true;
    dailyEmailApply1700Schedule($db);
}

/**
 * One-time: both reports are mandatory at 17:00 IST, so switch them on at 17:00 even where an
 * earlier version saved other values (Admin can change them afterwards). When this runs after
 * 17:00, today is marked skipped so the first email is the next 17:00, not "now".
 */
function dailyEmailApply1700Schedule(PDO $db): void {
    $ins = $db->prepare("INSERT IGNORE INTO daily_email_settings (name, value) VALUES ('schedule_1700_applied', ?)");
    $ins->execute([date('Y-m-d H:i:s')]);
    if ($ins->rowCount() !== 1) return;
    foreach (array_keys(DAILY_EMAIL_JOBS) as $job) {
        dailyEmailSetMeta($db, $job . '_enabled', '1');
        dailyEmailSetMeta($db, $job . '_time', '17:00');
        if (date('H:i') >= '17:00') {
            $db->prepare("INSERT IGNORE INTO daily_email_runs (job, run_date, kind, via, auto_key, status, detail, started_at, finished_at)
                          VALUES (?, ?, 'auto', 'setup', ?, 'skipped', ?, ?, ?)")
               ->execute([$job, date('Y-m-d'), $job . '|' . date('Y-m-d'), '17:00 schedule switched on after 17:00 today — first email at the next 17:00.', date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        }
    }
}

function dailyEmailSettings(PDO $db): array {
    dailyEmailEnsureTables($db);
    $s = DAILY_EMAIL_DEFAULTS;
    foreach ($db->query('SELECT name, value FROM daily_email_settings') as $r) {
        if (array_key_exists($r['name'], $s)) $s[$r['name']] = (string)$r['value'];
    }
    $s['appointments_from'] = DAILY_EMAIL_APPT_FROM;
    $s['appointments_to'] = DAILY_EMAIL_APPT_TO;
    $s['surveyor_pending_cc'] = $s['appointments_cc'] = '';
    return $s;
}

function dailyEmailSetMeta(PDO $db, string $name, string $value): void {
    $db->prepare('INSERT INTO daily_email_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')->execute([$name, $value]);
}

function dailyEmailGetMeta(PDO $db, string $name): string {
    $st = $db->prepare('SELECT value FROM daily_email_settings WHERE name = ?');
    $st->execute([$name]);
    return (string)($st->fetchColumn() ?: '');
}

/** Comma / semicolon / space separated list → valid addresses only. */
function dailyEmailAddressList(string $raw): array {
    $out = [];
    foreach (preg_split('/[\s,;]+/', $raw) as $e) {
        $e = trim($e);
        if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $out[strtolower($e)] = $e;
    }
    return array_values($out);
}

/** Validates and saves the Admin form. Returns a list of error messages (empty = saved). */
function dailyEmailSaveSettings(PDO $db, array $in): array {
    $errors = [];
    $new = [];
    foreach (array_keys(DAILY_EMAIL_JOBS) as $job) {
        $label = DAILY_EMAIL_JOBS[$job];
        $new[$job . '_enabled'] = !empty($in[$job . '_enabled']) ? '1' : '0';
        $new[$job . '_send_empty'] = !empty($in[$job . '_send_empty']) ? '1' : '0';
        $time = trim((string)($in[$job . '_time'] ?? ''));
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) $errors[] = $label . ': enter a valid time (HH:MM).';
        $new[$job . '_time'] = $time;
    }
    $from = trim((string)($in['surveyor_pending_from'] ?? ''));
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) $errors[] = DAILY_EMAIL_JOBS['surveyor_pending'] . ': "From" must be one valid email address.';
    $new['surveyor_pending_from'] = $from;
    if ($errors) return $errors;

    $old = dailyEmailSettings($db);
    foreach ($new as $k => $v) dailyEmailSetMeta($db, $k, $v);

    // Switched on (or time moved) after today's time has already passed: start from the next
    // day instead of sending straight away — "Send now" is there for today.
    foreach (array_keys(DAILY_EMAIL_JOBS) as $job) {
        $changed = $new[$job . '_enabled'] === '1' && ($old[$job . '_enabled'] !== '1' || $old[$job . '_time'] !== $new[$job . '_time']);
        if ($changed && date('H:i') >= $new[$job . '_time']) {
            $db->prepare("INSERT IGNORE INTO daily_email_runs (job, run_date, kind, via, auto_key, status, detail, started_at, finished_at)
                          VALUES (?, ?, 'auto', 'admin', ?, 'skipped', ?, ?, ?)")
               ->execute([$job, date('Y-m-d'), $job . '|' . date('Y-m-d'), 'Time had already passed when the schedule was saved — first email goes tomorrow.', date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        }
    }
    return [];
}

/* ---------- data ---------- */

const DAILY_EMAIL_SELECT = "
    SELECT s.id, s.vessel_name, s.report_number, s.agent_name, s.status, c.company_name, st.type_name, s.survey_type_ids,
           u.full_name AS surveyor_name, s.custom_live_status, s.appointment_date, s.assign_date, p.port_name, p.country
    FROM surveys s
    LEFT JOIN clients c ON s.client_id = c.id
    LEFT JOIN survey_types st ON s.survey_type_id = st.id
    LEFT JOIN users u ON s.surveyor_id = u.id
    LEFT JOIN ports p ON s.port_id = p.id";

const DAILY_EMAIL_SURVEYOR_HEADERS = ['Vessel Name', 'Report No', 'Client', 'Agent', 'Port', 'Country', 'Survey Type', 'Surveyor(s)', 'Assigned On', 'Appointment Date', 'Status', 'Latest Update'];
const DAILY_EMAIL_APPT_HEADERS = ['Vessel Name', 'Report No', 'Client', 'Agent', 'Port', 'Country', 'Survey Type', 'Surveyor(s)', 'Appointment Date', 'Received On', 'Status', 'Latest Update'];

function dailyEmailStatusLabel(string $status): string {
    return ['Pending Vessel' => 'Assigned (Pending Vessel)', 'Pending Report' => 'Survey done (Pending Report)',
            'Completed' => 'Completed', 'Cancelled' => 'Cancelled'][$status] ?? $status;
}

function dailyEmailDate(?string $v, string $fmt): string {
    return (!empty($v) && strpos((string)$v, '0000') !== 0) ? date($fmt, strtotime((string)$v)) : '';
}

/** Vessels currently assigned to one surveyor (Pending Vessel, as primary or co-surveyor). */
function dailyEmailAssignedRows(PDO $db, int $surveyorId): array {
    $st = $db->prepare(DAILY_EMAIL_SELECT . "
        WHERE s.status = 'Pending Vessel'
          AND (s.surveyor_id = ? OR s.id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = ?))
        ORDER BY s.appointment_date IS NULL, s.appointment_date, s.assign_date, s.id");
    $st->execute([$surveyorId, $surveyorId]);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $country = $r['country'] ?: 'India';
        $rows[] = ['country' => $country, 'cells' => [
            (string)$r['vessel_name'], (string)($r['report_number'] ?? ''), (string)($r['company_name'] ?? ''),
            (string)($r['agent_name'] ?: 'N/A'), (string)($r['port_name'] ?: 'N/A'), $country,
            getCombinedSurveyTypeNames($db, $r['survey_type_ids'] ?? '', $r['type_name'] ?? 'N/A'),
            getCombinedSurveyorNames($db, (int)$r['id'], $r['surveyor_name'] ?? 'N/A'),
            dailyEmailDate($r['assign_date'], 'd M Y'), dailyEmailDate($r['appointment_date'], 'd M Y'),
            dailyEmailStatusLabel((string)$r['status']), trim(strip_tags((string)($r['custom_live_status'] ?? ''))),
        ]];
    }
    return $rows;
}

/** Every vessel with today's Appointment Date or received (assigned) today, any status. */
function dailyEmailAppointmentRows(PDO $db, string $date): array {
    $next = date('Y-m-d', strtotime($date . ' +1 day'));
    $st = $db->prepare(DAILY_EMAIL_SELECT . "
        WHERE s.appointment_date = ? OR (s.assign_date >= ? AND s.assign_date < ?)
        ORDER BY s.appointment_date IS NULL, s.appointment_date, s.assign_date, s.id");
    $st->execute([$date, $date . ' 00:00:00', $next . ' 00:00:00']);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $country = $r['country'] ?: 'India';
        $rows[] = ['country' => $country, 'cells' => [
            (string)$r['vessel_name'], (string)($r['report_number'] ?? ''), (string)($r['company_name'] ?? ''),
            (string)($r['agent_name'] ?: 'N/A'), (string)($r['port_name'] ?: 'N/A'), $country,
            getCombinedSurveyTypeNames($db, $r['survey_type_ids'] ?? '', $r['type_name'] ?? 'N/A'),
            getCombinedSurveyorNames($db, (int)$r['id'], $r['surveyor_name'] ?? 'N/A'),
            dailyEmailDate($r['appointment_date'], 'd M Y'), dailyEmailDate($r['assign_date'], 'd M Y H:i'),
            dailyEmailStatusLabel((string)$r['status']), trim(strip_tags((string)($r['custom_live_status'] ?? ''))),
        ]];
    }
    return $rows;
}

/** Active surveyors with a valid profile email. */
function dailyEmailSurveyors(PDO $db): array {
    $out = [];
    foreach ($db->query("SELECT id, full_name, email FROM users WHERE role_id = 2 AND (status = 'Active' OR status IS NULL) ORDER BY full_name") as $u) {
        if (filter_var(trim((string)$u['email']), FILTER_VALIDATE_EMAIL)) $out[] = ['id' => (int)$u['id'], 'name' => trim((string)$u['full_name']), 'email' => trim((string)$u['email'])];
    }
    return $out;
}

function dailyEmailActiveSurveyorCount(PDO $db): int {
    return (int)$db->query("SELECT COUNT(*) FROM users WHERE role_id = 2 AND (status = 'Active' OR status IS NULL)")->fetchColumn();
}

/* ---------- mail ---------- */

/** YMR Marine Solutions branded HTML (inline styles — mail clients drop <style>). */
function dailyEmailHtml(string $title, string $dateText, string $intro, array $headers, array $rows): string {
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $th = 'style="background:#0b1e46;color:#ffffff;font-size:12px;font-weight:bold;text-align:left;padding:8px 9px;border:1px solid #0b1e46;white-space:nowrap;"';
    $td = 'style="font-size:12.5px;color:#0f172a;padding:7px 9px;border:1px solid #d7dee8;vertical-align:top;"';
    $html = '<div style="background:#eef2f7;padding:18px 10px;font-family:Arial,Helvetica,sans-serif;">'
          . '<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;max-width:1100px;margin:0 auto;background:#ffffff;border-collapse:collapse;border:1px solid #d7dee8;">'
          . '<tr><td style="background:#0b1e46;padding:16px 20px;">'
          . '<div style="color:#ffffff;font-size:19px;font-weight:bold;letter-spacing:.3px;">YMR Marine Solutions</div>'
          . '<div style="color:#9fb3d1;font-size:12px;margin-top:2px;">YMR Survey Management System</div></td></tr>'
          . '<tr><td style="padding:18px 20px 6px;">'
          . '<div style="font-size:17px;font-weight:bold;color:#0b1e46;">' . $h($title) . '</div>'
          . '<div style="font-size:12.5px;color:#64748b;margin-top:3px;">' . $h($dateText) . '</div>'
          . '<p style="font-size:13.5px;color:#334155;margin:14px 0 4px;line-height:1.5;">' . $intro . '</p></td></tr>';
    if ($rows) {
        $html .= '<tr><td style="padding:10px 20px 4px;"><div style="overflow-x:auto;"><table cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%;"><tr><th ' . $th . '>#</th>';
        foreach ($headers as $col) $html .= '<th ' . $th . '>' . $h($col) . '</th>';
        $html .= '</tr>';
        foreach ($rows as $i => $r) {
            $html .= '<tr' . ($i % 2 ? ' style="background:#f8fafc;"' : '') . '><td ' . $td . '>' . ($i + 1) . '</td>';
            foreach ($r['cells'] as $c => $v) {
                $html .= '<td ' . $td . '>' . ($c === 0 ? '<b>' . $h($v) . '</b>' : nl2br($h($v))) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table></div></td></tr>';
    }
    return $html . '<tr><td style="padding:16px 20px 18px;font-size:11.5px;color:#64748b;border-top:1px solid #eef2f7;">'
        . 'This is an automatic daily email from the YMR Survey Management System. Times are India Standard Time (IST).<br>'
        . 'YMR Marine Solutions LLP</td></tr></table></div>';
}

/** Excel attachment (same country-grouped layout as Export Excel), or null without PhpSpreadsheet. */
function dailyEmailXlsx(array $headers, array $rows, string $sheetTitle): ?string {
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!$rows || !is_file($autoload)) return null;
    try {
        require_once $autoload;
        if (!class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) return null;
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(mb_substr($sheetTitle, 0, 31));
        writeCountryGroupedSheet($sheet, $headers, $rows);
        $path = tempnam(sys_get_temp_dir(), 'ysms_daily_') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);
        return $path;
    } catch (Throwable $e) {
        error_log('daily email xlsx: ' . $e->getMessage());
        return null;
    }
}

/** Sends one message; ok only when the transport accepted it. Returns [ok, error]. */
function dailyEmailSend(array $to, array $cc, string $from, string $subject, string $html, array $headers, array $rows, string $fileBase): array {
    $xlsx = dailyEmailXlsx($headers, $rows, 'Vessels');
    $att = $xlsx ? [['path' => $xlsx, 'name' => exportFileName($fileBase) . '.xlsx']] : [];
    $recipients = array_map(fn($e) => is_array($e) ? $e : ['email' => $e, 'name' => ''], $to);
    $ok = sendHtmlEmailMulti($recipients, $subject, $html, '', $cc, $att,
        ['from_email' => $from, 'from_name' => 'YMR Marine Solutions', 'reply_to_email' => $from]);
    if ($xlsx) @unlink($xlsx);
    return [$ok, $ok ? '' : (string)($GLOBALS['ysms_last_mail_error'] ?? 'Send failed')];
}

/* ---------- delivery log ---------- */

function dailyEmailDeliveryStatus(PDO $db, int $runId, string $recipient): string {
    return (string)(dailyEmailDeliveryRow($db, $runId, $recipient)['status'] ?? '');
}

function dailyEmailDeliveryRow(PDO $db, int $runId, string $recipient): ?array {
    $st = $db->prepare('SELECT status, cc_sent FROM daily_email_deliveries WHERE run_id = ? AND recipient = ?');
    $st->execute([$runId, strtolower($recipient)]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** A failed send where SMTP refused only some addresses still reached the others: did $cc get it? */
function dailyEmailCcDelivered(bool $ok, string $error, string $cc): bool {
    return $ok || (stripos($error, 'recipients failed') !== false && stripos($error, $cc) === false);
}

function dailyEmailRecordDelivery(PDO $db, int $runId, string $recipient, string $name, int $vessels, bool $ok, string $error, bool $ccSent = false): void {
    $now = date('Y-m-d H:i:s');
    $db->prepare("INSERT INTO daily_email_deliveries (run_id, recipient, recipient_name, vessels, status, cc_sent, error, attempts, sent_at, updated_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
                  ON DUPLICATE KEY UPDATE vessels = VALUES(vessels), status = VALUES(status), cc_sent = VALUES(cc_sent), error = VALUES(error),
                      attempts = attempts + 1, sent_at = VALUES(sent_at), updated_at = VALUES(updated_at)")
       ->execute([$runId, strtolower($recipient), $name, $vessels, $ok ? 'sent' : 'failed', $ccSent ? 1 : 0, $ok ? null : mb_substr($error, 0, 1000), $ok ? $now : null, $now]);
    if (!$ok) error_log(json_encode(['evt' => 'daily_email_delivery_failed', 'run_id' => $runId, 'recipient' => $recipient, 'error' => $error]));
}

/* ---------- jobs ---------- */

/** Test sample: the first surveyor (in $list) who has assigned vessels, or the first one. */
function dailyEmailSampleSurveyor(PDO $db, array $list): ?array {
    foreach ($list as $u) { if (dailyEmailAssignedRows($db, $u['id'])) return $u; }
    return $list[0] ?? null;
}

/** @return array{ok:bool, summary:string, nothing_sent?:bool} */
function dailyEmailJobSurveyorPending(PDO $db, array $s, int $runId, string $onlyTo = ''): array {
    $dateText = date('d-m-Y');
    $sent = 0; $already = 0; $skipped = 0; $failed = [];
    $list = dailyEmailSurveyors($db);
    $noEmail = max(0, dailyEmailActiveSurveyorCount($db) - count($list));
    if ($onlyTo !== '') {
        $sample = dailyEmailSampleSurveyor($db, $list);
        $list = $sample ? [$sample] : [];
    }
    foreach ($list as $u) {
        $rows = dailyEmailAssignedRows($db, $u['id']);
        if (!$rows && $s['surveyor_pending_send_empty'] !== '1' && $onlyTo === '') { $skipped++; continue; }
        $to = $onlyTo !== '' ? $onlyTo : $u['email'];
        $prev = dailyEmailDeliveryRow($db, $runId, $to);
        if (($prev['status'] ?? '') === 'sent') { $already++; continue; } // retry: never twice
        // survey@ in CC — but not again on a retry when the earlier attempt already reached it
        $cc = ($onlyTo !== '' || !empty($prev['cc_sent'])) ? [] : [DAILY_EMAIL_SURVEYOR_CC];
        $n = count($rows);
        $html = dailyEmailHtml('Daily Assigned Vessels Report', $u['name'] . ' · ' . date('l, d F Y') . ' · 17:00 IST report',
            'Dear ' . htmlspecialchars($u['name'], ENT_QUOTES, 'UTF-8') . ',<br>' . ($n
                ? '<b>' . $n . '</b> vessel' . ($n === 1 ? ' is' : 's are') . ' currently assigned to you (Pending Vessels) as on ' . $dateText . '. The details are below and attached as Excel.'
                : 'No vessels are currently assigned to you as on ' . $dateText . '.'),
            DAILY_EMAIL_SURVEYOR_HEADERS, $rows);
        [$ok, $err] = dailyEmailSend([['email' => $to, 'name' => $u['name']]], $cc, $s['surveyor_pending_from'],
            'Daily Assigned Vessels Report – ' . $dateText, $html, DAILY_EMAIL_SURVEYOR_HEADERS, $rows,
            'Assigned Vessels ' . $u['name'] . ' as on ' . $dateText);
        dailyEmailRecordDelivery($db, $runId, $to, $u['name'], $n, $ok, $err,
            !empty($prev['cc_sent']) || ($cc && dailyEmailCcDelivered($ok, $err, $cc[0])));
        if ($ok) $sent++; else $failed[] = $u['name'] . ' (' . $err . ')';
    }
    if ($onlyTo !== '') {
        $summary = $list ? ($sent ? 'sample (' . $list[0]['name'] . "'s list) sent" : 'sample failed') : 'no surveyor has a profile email';
    } else {
        $summary = $sent . ' sent';
        if ($already) $summary .= ', ' . $already . ' already sent earlier';
        if ($skipped) $summary .= ', ' . $skipped . ' skipped (no assigned vessels)';
        if ($noEmail) $summary .= ', ' . $noEmail . ' active surveyor(s) without a profile email';
    }
    if ($failed) $summary .= '; failed: ' . implode('; ', $failed);
    return ['ok' => !$failed, 'summary' => $summary, 'nothing_sent' => !$sent && !$already && !$failed];
}

function dailyEmailJobAppointments(PDO $db, array $s, int $runId, string $onlyTo = ''): array {
    $rows = dailyEmailAppointmentRows($db, date('Y-m-d'));
    // A test always sends, so the layout can be checked on a day without appointments too.
    if (!$rows && $s['appointments_send_empty'] !== '1' && $onlyTo === '') return ['ok' => true, 'summary' => 'No appointments today — not sent (option off)', 'nothing_sent' => true];
    $to = $onlyTo !== '' ? [$onlyTo] : [DAILY_EMAIL_APPT_TO];
    $key = implode(',', array_map('strtolower', $to));
    if (dailyEmailDeliveryStatus($db, $runId, $key) === 'sent') return ['ok' => true, 'summary' => 'Already sent earlier to ' . $key];
    $dateText = date('d-m-Y');
    $n = count($rows);
    $html = dailyEmailHtml('Daily Vessel Appointments Report', date('l, d F Y') . ' · 17:00 IST report',
        $n ? '<b>' . $n . '</b> vessel appointment' . ($n === 1 ? ' was' : 's were') . ' received or scheduled for today (' . $dateText . ') — every vessel whose Appointment Date is today or that was received/assigned today.'
             . ' The details are below and attached as Excel.'
           : '<b>No vessel appointments were received or scheduled for today (' . $dateText . ').</b>',
        DAILY_EMAIL_APPT_HEADERS, $rows);
    [$ok, $err] = dailyEmailSend($to, [], DAILY_EMAIL_APPT_FROM,
        'Daily Vessel Appointments Report – ' . $dateText, $html, DAILY_EMAIL_APPT_HEADERS, $rows,
        'Vessel Appointments ' . $dateText);
    dailyEmailRecordDelivery($db, $runId, $key, 'Appointments report', $n, $ok, $err);
    return ['ok' => $ok, 'summary' => $ok ? ($n . ' appointment(s), sent to ' . implode(', ', $to)) : 'Failed: ' . $err];
}

/**
 * Claims today's scheduled run of $job. New → inserted 'running'. Existing 'failed' (or a
 * 'running' one older than DAILY_EMAIL_STALE_MINUTES) → taken over for a retry with a
 * compare-and-set, so only one request ever works on it. Returns the run id or 0.
 */
function dailyEmailClaimAuto(PDO $db, string $job, string $via): int {
    $key = $job . '|' . date('Y-m-d');
    $now = date('Y-m-d H:i:s');
    $ins = $db->prepare("INSERT IGNORE INTO daily_email_runs (job, run_date, kind, via, auto_key, status, attempts, started_at) VALUES (?, ?, 'auto', ?, ?, 'running', 1, ?)");
    $ins->execute([$job, date('Y-m-d'), $via, $key, $now]);
    if ($ins->rowCount() === 1) return (int)$db->lastInsertId();
    $st = $db->prepare('SELECT id, status, attempts, started_at FROM daily_email_runs WHERE auto_key = ?');
    $st->execute([$key]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || (int)$r['attempts'] >= DAILY_EMAIL_MAX_ATTEMPTS) return 0;
    $stale = $r['status'] === 'running' && strtotime($r['started_at']) < time() - DAILY_EMAIL_STALE_MINUTES * 60;
    if ($r['status'] !== 'failed' && !$stale) return 0;
    $up = $db->prepare("UPDATE daily_email_runs SET status = 'running', attempts = attempts + 1, via = ?, started_at = ?, finished_at = NULL
                        WHERE id = ? AND status = ? AND attempts = ? AND started_at = ?");
    $up->execute([$via, $now, $r['id'], $r['status'], $r['attempts'], $r['started_at']]);
    return $up->rowCount() === 1 ? (int)$r['id'] : 0;
}

/**
 * Runs one job and logs it.
 *  kind 'auto'   — today's scheduled run (claimed; retries only re-send failed recipients)
 *  kind 'manual' — Admin "Send now": real recipients, its own run
 *  kind 'test'   — one sample to $onlyTo only; never touches the scheduled run
 */
function dailyEmailRunJob(PDO $db, string $job, string $kind, ?int $userId = null, string $onlyTo = '', string $via = 'admin'): array {
    dailyEmailEnsureTables($db);
    $s = dailyEmailSettings($db);
    if ($kind === 'auto') {
        $runId = dailyEmailClaimAuto($db, $job, $via);
        if (!$runId) return ['ok' => true, 'summary' => 'Not due / already sent today', 'claimed' => false];
    } else {
        $db->prepare("INSERT INTO daily_email_runs (job, run_date, kind, via, status, started_by, started_at) VALUES (?, ?, ?, ?, 'running', ?, ?)")
           ->execute([$job, date('Y-m-d'), $kind, $via, $userId, date('Y-m-d H:i:s')]);
        $runId = (int)$db->lastInsertId();
    }
    @set_time_limit(280);
    try {
        $res = $job === 'surveyor_pending' ? dailyEmailJobSurveyorPending($db, $s, $runId, $onlyTo) : dailyEmailJobAppointments($db, $s, $runId, $onlyTo);
    } catch (Throwable $e) {
        error_log('daily email ' . $job . ': ' . $e->getMessage());
        $res = ['ok' => false, 'summary' => 'Error: ' . $e->getMessage()];
    }
    if ($onlyTo !== '') $res['summary'] = 'Test to ' . $onlyTo . ': ' . $res['summary'];
    $status = !$res['ok'] ? 'failed' : (!empty($res['nothing_sent']) ? 'skipped' : 'sent');
    $db->prepare('UPDATE daily_email_runs SET status = ?, detail = ?, finished_at = ? WHERE id = ?')
       ->execute([$status, mb_substr($res['summary'], 0, 4000), date('Y-m-d H:i:s'), $runId]);
    error_log(json_encode(['evt' => 'daily_email_run', 'job' => $job, 'kind' => $kind, 'via' => $via, 'run_id' => $runId, 'status' => $status]));
    return $res + ['claimed' => true, 'status' => $status, 'run_id' => $runId];
}

/**
 * Scheduled check: every enabled job (or only $onlyJob) whose time has passed today and that
 * has not been sent today — or whose run today failed (retry). Jobs run independently: one
 * failing never stops the other. Returns job => result.
 */
function dailyEmailRunDue(PDO $db, string $via, string $onlyJob = ''): array {
    $s = dailyEmailSettings($db);
    $out = [];
    $now = date('H:i');
    @ignore_user_abort(true);
    foreach (array_keys(DAILY_EMAIL_JOBS) as $job) {
        if ($onlyJob !== '' && $job !== $onlyJob) continue;
        if ($s[$job . '_enabled'] !== '1' || $now < $s[$job . '_time']) continue;
        try {
            $res = dailyEmailRunJob($db, $job, 'auto', null, '', $via);
        } catch (Throwable $e) {
            error_log('daily email due ' . $job . ': ' . $e->getMessage());
            $res = ['ok' => false, 'summary' => 'Error: ' . $e->getMessage(), 'claimed' => true, 'status' => 'failed'];
        }
        if ($res['claimed']) $out[$job] = ['status' => $res['status'], 'summary' => $res['summary']];
    }
    return $out;
}

/** Admin "Retry failed": re-runs today's failed scheduled run now (same idempotency as a scheduler retry). */
function dailyEmailRetryToday(PDO $db, string $job): array {
    $res = dailyEmailRunJob($db, $job, 'auto', null, '', 'admin-retry');
    return $res['claimed'] ? $res : ['ok' => false, 'summary' => "Nothing to retry — today's scheduled email is not in a failed state (or all retries are used)."];
}

function dailyEmailTodayRun(PDO $db, string $job): ?array {
    $st = $db->prepare('SELECT * FROM daily_email_runs WHERE auto_key = ?');
    $st->execute([$job . '|' . date('Y-m-d')]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function dailyEmailDeliveries(PDO $db, int $runId): array {
    $st = $db->prepare('SELECT * FROM daily_email_deliveries WHERE run_id = ? ORDER BY status <> \'failed\', recipient_name');
    $st->execute([$runId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function dailyEmailRecentRuns(PDO $db, int $limit = 20): array {
    dailyEmailEnsureTables($db);
    $st = $db->prepare('SELECT r.*, u.full_name FROM daily_email_runs r LEFT JOIN users u ON u.id = r.started_by ORDER BY r.id DESC LIMIT ' . (int)$limit);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

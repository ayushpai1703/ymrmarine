<?php
/**
 * Daily automatic emails (Admin Controls → Daily Emails, admin_daily_emails.php):
 *
 *  surveyor_pending — every Active surveyor with a profile email gets their own Pending
 *                     Vessels list (same rows / columns as the Pending Vessels export
 *                     filtered by that surveyor), as a table in the mail + Excel attached.
 *  appointments     — Pending Vessels whose Appointment Date is today, to a fixed address
 *                     (default ops@ymrmarine.com → survey@ymrmarine.com).
 *
 * Times are India time (Asia/Kolkata). A job is "due" once its time has passed today and
 * it has not run today; daily_email_runs.auto_key (job|date, UNIQUE) makes sure only one
 * request ever sends it, however many trigger at once. Triggers: ajax/daily_emails_run.php
 * — Cloud Scheduler with YSMS_CRON_TOKEN, and a light check from logged-in pages.
 */
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/excel_export_helpers.php';

const DAILY_EMAIL_JOBS = [
    'surveyor_pending' => 'Pending vessels to each surveyor',
    'appointments'     => "Today's appointments",
];

const DAILY_EMAIL_DEFAULTS = [
    'surveyor_pending_enabled'    => '0',
    'surveyor_pending_time'       => '08:00',
    'surveyor_pending_from'       => 'ops@ymrmarine.com',
    'surveyor_pending_cc'         => '',
    'surveyor_pending_send_empty' => '0',
    'appointments_enabled'        => '0',
    'appointments_time'           => '08:00',
    'appointments_from'           => 'ops@ymrmarine.com',
    'appointments_to'             => 'survey@ymrmarine.com',
    'appointments_cc'             => '',
    'appointments_send_empty'     => '1',
];

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
    $done = true;
}

function dailyEmailSettings(PDO $db): array {
    dailyEmailEnsureTables($db);
    $s = DAILY_EMAIL_DEFAULTS;
    foreach ($db->query('SELECT name, value FROM daily_email_settings') as $r) {
        if (array_key_exists($r['name'], $s)) $s[$r['name']] = (string)$r['value'];
    }
    return $s;
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
        $from = trim((string)($in[$job . '_from'] ?? ''));
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) $errors[] = $label . ': "From" must be one valid email address.';
        $new[$job . '_from'] = $from;
        $ccRaw = trim((string)($in[$job . '_cc'] ?? ''));
        $cc = dailyEmailAddressList($ccRaw);
        if ($ccRaw !== '' && count($cc) !== count(preg_split('/[\s,;]+/', $ccRaw, -1, PREG_SPLIT_NO_EMPTY))) $errors[] = $label . ': CC has an invalid email address.';
        $new[$job . '_cc'] = implode(', ', $cc);
    }
    $toRaw = trim((string)($in['appointments_to'] ?? ''));
    $to = dailyEmailAddressList($toRaw);
    if (!$to || count($to) !== count(preg_split('/[\s,;]+/', $toRaw, -1, PREG_SPLIT_NO_EMPTY))) $errors[] = DAILY_EMAIL_JOBS['appointments'] . ': "To" must be valid email address(es).';
    $new['appointments_to'] = implode(', ', $to);
    if ($errors) return $errors;

    $old = dailyEmailSettings($db);
    $st = $db->prepare('INSERT INTO daily_email_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
    foreach ($new as $k => $v) $st->execute([$k, $v]);

    // Switched on (or time moved) after today's time has already passed: start from the next
    // day instead of sending straight away — "Send now" is there for today.
    foreach (array_keys(DAILY_EMAIL_JOBS) as $job) {
        $changed = $new[$job . '_enabled'] === '1' && ($old[$job . '_enabled'] !== '1' || $old[$job . '_time'] !== $new[$job . '_time']);
        if ($changed && date('H:i') >= $new[$job . '_time']) {
            $db->prepare("INSERT IGNORE INTO daily_email_runs (job, run_date, kind, auto_key, status, detail, started_at, finished_at)
                          VALUES (?, ?, 'auto', ?, 'skipped', ?, ?, ?)")
               ->execute([$job, date('Y-m-d'), $job . '|' . date('Y-m-d'), 'Time had already passed when the schedule was saved — first email goes tomorrow.', date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        }
    }
    return [];
}

/** Pending Vessels rows (same columns as export_vessels.php). */
function dailyEmailPendingRows(PDO $db, int $surveyorId = 0, string $appointmentDate = ''): array {
    $where = ["s.status = 'Pending Vessel'"];
    $params = [];
    if ($surveyorId > 0) {
        $where[] = '(s.surveyor_id = ? OR s.id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = ?))';
        array_push($params, $surveyorId, $surveyorId);
    }
    if ($appointmentDate !== '') {
        $where[] = 's.appointment_date = ?';
        $params[] = $appointmentDate;
    }
    $st = $db->prepare("
        SELECT s.id, s.vessel_name, s.report_number, s.agent_name, c.company_name, st.type_name, s.survey_type_ids,
               u.full_name AS surveyor_name, s.custom_live_status, s.appointment_date, p.port_name, p.country
        FROM surveys s
        LEFT JOIN clients c ON s.client_id = c.id
        LEFT JOIN survey_types st ON s.survey_type_id = st.id
        LEFT JOIN users u ON s.surveyor_id = u.id
        LEFT JOIN ports p ON s.port_id = p.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY s.appointment_date IS NULL, s.appointment_date, s.id DESC");
    $st->execute($params);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $country = $r['country'] ?: 'India';
        $rows[] = [
            'country' => $country,
            'cells' => [
                (string)$r['vessel_name'],
                (string)($r['report_number'] ?? ''),
                (string)($r['company_name'] ?? ''),
                (string)($r['agent_name'] ?: 'N/A'),
                (string)($r['port_name'] ?: 'N/A'),
                $country,
                getCombinedSurveyTypeNames($db, $r['survey_type_ids'] ?? '', $r['type_name'] ?? 'N/A'),
                getCombinedSurveyorNames($db, (int)$r['id'], $r['surveyor_name'] ?? 'N/A'),
                !empty($r['appointment_date']) ? date('d M Y', strtotime($r['appointment_date'])) : '',
                trim(strip_tags((string)($r['custom_live_status'] ?? ''))),
            ],
        ];
    }
    return $rows;
}

const DAILY_EMAIL_HEADERS = ['Vessel Name', 'Report No', 'Client', 'Agent', 'Port', 'Country', 'Survey Type', 'Surveyor', 'Appointment Date', 'Latest Update'];

function dailyEmailHtml(string $heading, string $intro, array $rows): string {
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $th = 'style="background:#0b1e46;color:#fff;font-size:12px;text-align:left;padding:7px 9px;border:1px solid #0b1e46;"';
    $td = 'style="font-size:12.5px;padding:7px 9px;border:1px solid #d7dee8;vertical-align:top;"';
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;color:#0f172a;">'
          . '<h2 style="font-size:17px;margin:0 0 6px;color:#0b1e46;">' . $h($heading) . '</h2>'
          . '<p style="font-size:13px;margin:0 0 14px;color:#334155;">' . $intro . '</p>';
    if ($rows) {
        $html .= '<table cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%;"><tr><th ' . $th . '>#</th>';
        foreach (DAILY_EMAIL_HEADERS as $col) $html .= '<th ' . $th . '>' . $h($col) . '</th>';
        $html .= '</tr>';
        foreach ($rows as $i => $r) {
            $html .= '<tr' . ($i % 2 ? ' style="background:#f8fafc;"' : '') . '><td ' . $td . '>' . ($i + 1) . '</td>';
            foreach ($r['cells'] as $c => $v) {
                $html .= '<td ' . $td . '>' . ($c === 0 ? '<b>' . $h($v) . '</b>' : nl2br($h($v))) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table>';
    }
    return $html . '<p style="font-size:11.5px;color:#64748b;margin-top:16px;">Automatic daily email from YMR Survey Management System.</p></div>';
}

/** Excel attachment (same country-grouped layout as Export Excel), or null without PhpSpreadsheet. */
function dailyEmailXlsx(array $rows, string $sheetTitle): ?string {
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!$rows || !is_file($autoload)) return null;
    try {
        require_once $autoload;
        if (!class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) return null;
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(mb_substr($sheetTitle, 0, 31));
        writeCountryGroupedSheet($sheet, DAILY_EMAIL_HEADERS, $rows);
        $path = tempnam(sys_get_temp_dir(), 'ysms_daily_') . '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);
        return $path;
    } catch (Throwable $e) {
        error_log('daily email xlsx: ' . $e->getMessage());
        return null;
    }
}

/** Sends one email (+ optional Excel) and returns [ok, error]. */
function dailyEmailSend(array $to, array $cc, string $from, string $subject, string $html, array $rows, string $fileBase): array {
    $xlsx = dailyEmailXlsx($rows, 'Pending Vessels');
    $att = $xlsx ? [['path' => $xlsx, 'name' => exportFileName($fileBase) . '.xlsx']] : [];
    $recipients = array_map(fn($e) => is_array($e) ? $e : ['email' => $e, 'name' => ''], $to);
    $ok = sendHtmlEmailMulti($recipients, $subject, $html, '', $cc, $att,
        ['from_email' => $from, 'from_name' => 'YMR Marine Solutions', 'reply_to_email' => $from]);
    if ($xlsx) @unlink($xlsx);
    return [$ok, $ok ? '' : (string)($GLOBALS['ysms_last_mail_error'] ?? 'Send failed')];
}

/** Active surveyors with a valid profile email. */
function dailyEmailSurveyors(PDO $db): array {
    $out = [];
    foreach ($db->query("SELECT id, full_name, email FROM users WHERE role_id = 2 AND (status = 'Active' OR status IS NULL) ORDER BY full_name") as $u) {
        if (filter_var(trim((string)$u['email']), FILTER_VALIDATE_EMAIL)) $out[] = ['id' => (int)$u['id'], 'name' => trim((string)$u['full_name']), 'email' => trim((string)$u['email'])];
    }
    return $out;
}

/** Test sample: id of the first surveyor (in $list) who has pending vessels, or the first one. */
function dailyEmailSurveyorWithPending(PDO $db, array $list): int {
    static $id = null;
    if ($id === null) {
        $id = $list ? $list[0]['id'] : 0;
        foreach ($list as $u) { if (dailyEmailPendingRows($db, $u['id'])) { $id = $u['id']; break; } }
    }
    return $id;
}

/** @return array{ok:bool, summary:string} */
function dailyEmailJobSurveyorPending(PDO $db, array $s, string $onlyTo = ''): array {
    $today = date('d-m-Y');
    $sent = 0; $skipped = 0; $failed = []; $noEmail = 0; $sample = '';
    $all = $db->query("SELECT COUNT(*) FROM users WHERE role_id = 2 AND (status = 'Active' OR status IS NULL)")->fetchColumn();
    $list = dailyEmailSurveyors($db);
    $noEmail = max(0, (int)$all - count($list));
    foreach ($list as $u) {
        $rows = dailyEmailPendingRows($db, $u['id']);
        if (!$rows && $s['surveyor_pending_send_empty'] !== '1' && $onlyTo === '') { $skipped++; continue; }
        if (!$rows && $onlyTo !== '' && $sample === '' && dailyEmailSurveyorWithPending($db, $list) !== $u['id']) continue; // test: prefer a surveyor who has vessels
        $n = count($rows);
        $html = dailyEmailHtml('Pending Vessels — ' . $u['name'],
            'Dear ' . htmlspecialchars($u['name'], ENT_QUOTES, 'UTF-8') . ',<br>' . ($n
                ? 'You have <b>' . $n . '</b> pending vessel' . ($n === 1 ? '' : 's') . ' as on ' . $today . '. The list is below and attached as Excel.'
                : 'You have no pending vessels as on ' . $today . '.'),
            $rows);
        $to = $onlyTo !== '' ? [$onlyTo] : [['email' => $u['email'], 'name' => $u['name']]];
        $cc = $onlyTo !== '' ? [] : dailyEmailAddressList($s['surveyor_pending_cc']);
        [$ok, $err] = dailyEmailSend($to, $cc, $s['surveyor_pending_from'],
            'PENDING VESSELS — ' . mb_strtoupper($u['name']) . ' — AS ON ' . $today, $html, $rows,
            'Pending Vessels by ' . $u['name'] . ' as on ' . $today);
        if ($ok) $sent++; else $failed[] = $u['name'] . ' (' . $err . ')';
        $sample = $u['name'] . ' — ' . $n . ' vessel' . ($n === 1 ? '' : 's');
        if ($onlyTo !== '') break; // test: one sample only
    }
    $summary = $onlyTo !== '' ? ($sent ? 'sample sent (' . $sample . ')' : 'no surveyor with a profile email') : $sent . ' sent';
    if ($skipped) $summary .= ', ' . $skipped . ' skipped (no pending vessels)';
    if ($noEmail) $summary .= ', ' . $noEmail . ' surveyor(s) without a profile email';
    if ($failed) $summary .= '; failed: ' . implode('; ', $failed);
    return ['ok' => !$failed, 'summary' => $summary, 'nothing_sent' => !$sent && !$failed];
}

function dailyEmailJobAppointments(PDO $db, array $s, string $onlyTo = ''): array {
    $rows = dailyEmailPendingRows($db, 0, date('Y-m-d'));
    // A test always sends, so the layout can be checked even on a day without appointments.
    if (!$rows && $s['appointments_send_empty'] !== '1' && $onlyTo === '') return ['ok' => true, 'summary' => 'No appointments today — not sent', 'nothing_sent' => true];
    $today = date('d-m-Y');
    $n = count($rows);
    $html = dailyEmailHtml("Today's Appointments — " . date('d M Y'),
        $n ? '<b>' . $n . '</b> pending vessel' . ($n === 1 ? ' has its' : 's have their') . ' appointment today (' . $today . '). The list is below and attached as Excel.'
           : 'There are no pending vessels with an appointment today (' . $today . ').',
        $rows);
    $to = $onlyTo !== '' ? [$onlyTo] : dailyEmailAddressList($s['appointments_to']);
    $cc = $onlyTo !== '' ? [] : dailyEmailAddressList($s['appointments_cc']);
    [$ok, $err] = dailyEmailSend($to, $cc, $s['appointments_from'],
        "TODAY'S APPOINTMENTS — " . $today . ' — ' . $n . ' VESSEL' . ($n === 1 ? '' : 'S'), $html, $rows,
        'Pending Vessels Appointment ' . $today . ' as on ' . $today);
    return ['ok' => $ok, 'summary' => $ok ? ($n . ' vessel(s), sent to ' . implode(', ', array_map(fn($e) => is_array($e) ? $e['email'] : $e, $to))) : 'Failed: ' . $err];
}

/** Runs one job and logs it. $autoKey set = scheduled run (claimed first, so it can only run once). */
function dailyEmailRunJob(PDO $db, string $job, string $kind, ?int $userId = null, string $onlyTo = ''): array {
    dailyEmailEnsureTables($db);
    $s = dailyEmailSettings($db);
    $autoKey = $kind === 'auto' ? $job . '|' . date('Y-m-d') : null;
    // India date/time from PHP (the DB server may run on UTC)
    $ins = $db->prepare("INSERT IGNORE INTO daily_email_runs (job, run_date, kind, auto_key, status, started_by, started_at) VALUES (?, ?, ?, ?, 'running', ?, ?)");
    $ins->execute([$job, date('Y-m-d'), $kind, $autoKey, $userId, date('Y-m-d H:i:s')]);
    if ($ins->rowCount() === 0) return ['ok' => true, 'summary' => 'Already sent today', 'claimed' => false];
    $runId = (int)$db->lastInsertId();
    @set_time_limit(300);
    try {
        $res = $job === 'surveyor_pending' ? dailyEmailJobSurveyorPending($db, $s, $onlyTo) : dailyEmailJobAppointments($db, $s, $onlyTo);
    } catch (Throwable $e) {
        error_log('daily email ' . $job . ': ' . $e->getMessage());
        $res = ['ok' => false, 'summary' => 'Error: ' . $e->getMessage()];
    }
    if ($onlyTo !== '') $res['summary'] = 'Test to ' . $onlyTo . ': ' . $res['summary'];
    $db->prepare('UPDATE daily_email_runs SET status = ?, detail = ?, finished_at = ? WHERE id = ?')
       ->execute([!$res['ok'] ? 'failed' : (!empty($res['nothing_sent']) ? 'skipped' : 'sent'), mb_substr($res['summary'], 0, 4000), date('Y-m-d H:i:s'), $runId]);
    return $res + ['claimed' => true];
}

/** Scheduled check: runs every enabled job whose time has passed today and hasn't run today. */
function dailyEmailRunDue(PDO $db): array {
    $s = dailyEmailSettings($db);
    $done = [];
    $now = date('H:i');
    foreach (array_keys(DAILY_EMAIL_JOBS) as $job) {
        if ($s[$job . '_enabled'] !== '1' || $now < $s[$job . '_time']) continue;
        $exists = $db->prepare('SELECT 1 FROM daily_email_runs WHERE auto_key = ?');
        $exists->execute([$job . '|' . date('Y-m-d')]);
        if ($exists->fetchColumn()) continue;
        @ignore_user_abort(true);
        $res = dailyEmailRunJob($db, $job, 'auto');
        if ($res['claimed']) $done[$job] = $res['summary'];
    }
    return $done;
}

function dailyEmailRecentRuns(PDO $db, int $limit = 20): array {
    dailyEmailEnsureTables($db);
    $st = $db->prepare('SELECT r.*, u.full_name FROM daily_email_runs r LEFT JOIN users u ON u.id = r.started_by ORDER BY r.id DESC LIMIT ' . (int)$limit);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

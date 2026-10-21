<?php
require_once 'config/config.php';
require_once 'includes/daily_emails.php';
checkAuth();
if (($_SESSION['role'] ?? '') !== 'Admin') {
    header('Location: index.php');
    exit;
}

$db = getDB();
dailyEmailEnsureTables($db);
$msg = ''; $errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $job = (string)($_POST['job'] ?? '');
    if ($action === 'save') {
        $errors = dailyEmailSaveSettings($db, $_POST);
        if (!$errors) $msg = 'Daily email settings saved.';
    } elseif (in_array($action, ['send_now', 'send_test', 'retry'], true) && isset(DAILY_EMAIL_JOBS[$job])) {
        $me = $db->prepare('SELECT email FROM users WHERE id = ?');
        $me->execute([(int)$_SESSION['user_id']]);
        $myEmail = trim((string)$me->fetchColumn());
        if ($action === 'send_test' && !filter_var($myEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Add an email address to your own profile to receive test emails.';
        } else {
            if ($action === 'retry') $res = dailyEmailRetryToday($db, $job);
            elseif ($action === 'send_test') $res = dailyEmailRunJob($db, $job, 'test', (int)$_SESSION['user_id'], $myEmail);
            else $res = dailyEmailRunJob($db, $job, 'manual', (int)$_SESSION['user_id']);
            if ($res['ok']) $msg = DAILY_EMAIL_JOBS[$job] . ': ' . $res['summary'];
            else $errors[] = DAILY_EMAIL_JOBS[$job] . ': ' . $res['summary'];
        }
    }
}

$s = dailyEmailSettings($db);
if ($errors && ($_POST['action'] ?? '') === 'save') {
    foreach (DAILY_EMAIL_DEFAULTS as $k => $v) {
        if (str_ends_with($k, '_enabled') || str_ends_with($k, '_send_empty')) $s[$k] = !empty($_POST[$k]) ? '1' : '0';
        else $s[$k] = (string)($_POST[$k] ?? $s[$k]);
    }
}
$runs = dailyEmailRecentRuns($db, 25);
$surveyors = dailyEmailSurveyors($db);
$allSurveyors = dailyEmailActiveSurveyorCount($db);
$apptToday = count(dailyEmailAppointmentRows($db, date('Y-m-d')));
$pendingNow = count(dailyEmailAllPendingRows($db));
$reportsNow = count(dailyEmailReportRows($db));
$today = [];
foreach (array_keys(DAILY_EMAIL_JOBS) as $j) {
    $run = dailyEmailTodayRun($db, $j);
    $today[$j] = ['run' => $run, 'deliveries' => $run ? dailyEmailDeliveries($db, (int)$run['id']) : []];
}
$cronReady = (string)(getenv('YSMS_CRON_TOKEN') ?: '') !== '';
$lastCall = [];
foreach (array_keys(DAILY_EMAIL_JOBS) as $j) $lastCall[$j] = dailyEmailGetMeta($db, 'last_scheduler_call_' . $j);
$mailOn = !defined('EMAIL_NOTIFICATIONS_ENABLED') || EMAIL_NOTIFICATIONS_ENABLED;
$fmt = fn($v) => $v ? date('d M Y H:i', strtotime($v)) : '—';

include 'includes/header.php';
?>
<style>
.de-wrap { padding: 0 16px 28px; max-width: 980px; margin: 0 auto; }
.de-note { font-size: 13px; color: #475569; background: #fff; border: 1px solid var(--border-color); border-radius: 14px; padding: 12px 14px; margin: 12px 0; line-height: 1.55; }
.de-note.warn { background: #FFF7ED; border-color: #FED7AA; color: #9A3412; }
.de-note.ok { background: #F0FDF4; border-color: #BBF7D0; color: #166534; }
.de-card { background: #fff; border: 1px solid var(--border-color); border-radius: 16px; padding: 16px; margin-bottom: 14px; }
.de-card-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 10px; }
.de-title { font-weight: 800; font-size: 15px; color: #0f172a; }
.de-sub { font-size: 12.5px; color: #64748b; margin-top: 2px; }
.de-grid { display: grid; grid-template-columns: 1fr; gap: 10px; }
.de-grid label { font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 3px; display: block; }
.de-grid input[type=text], .de-grid input[type=time], .de-grid input[type=email] { width: 100%; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 8px 10px; font-size: 14px; min-height: 42px; }
.de-check { display: flex; align-items: center; gap: 8px; font-size: 13.5px; font-weight: 600; color: #0f172a; }
.de-check input { width: 18px; height: 18px; }
.de-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
.de-btn { display: inline-flex; align-items: center; gap: 6px; min-height: 40px; padding: 0 14px; border-radius: 10px; font-size: 13px; font-weight: 700; border: 1.5px solid #cbd5e1; background: #fff; color: #334155; cursor: pointer; }
.de-btn.primary { background: #1e3a8a; border-color: #1e3a8a; color: #fff; }
.de-btn.warn { border-color: #FDBA74; color: #9A3412; background: #FFF7ED; }
.de-pill { display: inline-block; font-size: 11.5px; font-weight: 700; padding: 3px 10px; border-radius: 999px; }
.de-pill.on { background: #DCFCE7; color: #166534; } .de-pill.off { background: #F1F5F9; color: #475569; }
.de-pill.sent { background: #DCFCE7; color: #166534; } .de-pill.failed { background: #FEE2E2; color: #991B1B; }
.de-pill.running, .de-pill.pending { background: #DBEAFE; color: #1E40AF; } .de-pill.skipped { background: #F1F5F9; color: #475569; }
.de-log { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.de-log th { text-align: left; font-size: 11px; text-transform: uppercase; color: #475569; background: #F8FAFC; padding: 8px; }
.de-log td { padding: 8px; border-top: 1px solid #f1f5f9; vertical-align: top; }
@media (min-width: 768px) { .de-grid { grid-template-columns: 1fr 1fr; } .de-grid .full { grid-column: 1 / -1; } }
</style>
<div class="scroll-content" data-testid="daily-emails-page">
    <?php $page_title = 'Daily Emails'; $back_url = 'admin_controls.php'; $page_testid = 'daily-emails'; include 'includes/top_app_bar.php'; ?>
    <div class="de-wrap">
        <?php if ($msg): ?><div class="alert alert-success py-2 mt-3" data-testid="de-msg"><?= sanitize($msg) ?></div><?php endif; ?>
        <?php foreach ($errors as $e): ?><div class="alert alert-danger py-2 mt-3" data-testid="de-error"><?= sanitize($e) ?></div><?php endforeach; ?>
        <?php if (!$mailOn): ?><div class="de-note warn" data-testid="de-mail-off"><i class="fa-solid fa-triangle-exclamation me-1"></i> Outgoing email is switched off on this environment (YSMS_EMAIL_NOTIFICATIONS_ENABLED=false) — runs will be logged as failed and nothing is sent.</div><?php endif; ?>
        <?php if ($cronReady): ?>
            <div class="de-note ok" data-testid="de-scheduler">
                <i class="fa-solid fa-clock me-1"></i> Scheduler connected (YSMS_CRON_TOKEN is set). Last scheduler call —
                By Surveyor: <b><?= $fmt($lastCall['surveyor_pending']) ?></b> · Full list: <b><?= $fmt($lastCall['all_pending']) ?></b><?= $lastCall['all_pending'] ? '' : ' <span title="Create the third Cloud Scheduler job (docs/daily-emails.md)">(no scheduler job yet)</span>' ?> · Appointments: <b><?= $fmt($lastCall['appointments']) ?></b>
                · Reports by Surveyor (Fri): <b><?= $fmt($lastCall['surveyor_reports']) ?></b> · Reports full list (Fri): <b><?= $fmt($lastCall['all_reports']) ?></b>.
                Times are India time (IST).
            </div>
        <?php else: ?>
            <div class="de-note warn" data-testid="de-scheduler">
                <i class="fa-solid fa-triangle-exclamation me-1"></i> <b>Cloud Scheduler is not connected yet</b> (YSMS_CRON_TOKEN is not set on this server).
                Until it is, the emails only go out when someone has YSMS open after the set time. Times are India time (IST).
            </div>
        <?php endif; ?>

        <form method="post" data-testid="de-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">

            <div class="de-card" data-testid="de-card-surveyor">
                <div class="de-card-head">
                    <div>
                        <div class="de-title"><i class="fa-solid fa-user-gear me-1"></i> Pending Vessels by Surveyor — each surveyor</div>
                        <div class="de-sub">Each active surveyor receives only their own Pending Vessels (as surveyor or co-surveyor) — the Pending Vessels export filtered by that surveyor, attached as Excel, plus a table in the mail.
                            <?= count($surveyors) ?> of <?= $allSurveyors ?> active surveyors have a profile email.</div>
                    </div>
                    <span class="de-pill <?= $s['surveyor_pending_enabled'] === '1' ? 'on' : 'off' ?>"><?= $s['surveyor_pending_enabled'] === '1' ? 'ON' : 'OFF' ?></span>
                </div>
                <div class="de-grid">
                    <div class="full"><label class="de-check"><input type="checkbox" name="surveyor_pending_enabled" value="1" <?= $s['surveyor_pending_enabled'] === '1' ? 'checked' : '' ?> data-testid="de-surveyor-enabled"> Send every day</label></div>
                    <div><label for="spt">Time (IST)</label><input type="time" id="spt" name="surveyor_pending_time" value="<?= sanitize($s['surveyor_pending_time']) ?>" required data-testid="de-surveyor-time"></div>
                    <div><label for="spf">From</label><input type="email" id="spf" name="surveyor_pending_from" value="<?= sanitize($s['surveyor_pending_from']) ?>" required data-testid="de-surveyor-from"></div>
                    <div class="full de-sub" data-testid="de-surveyor-to">To: each surveyor's own profile email only — their own list, no CC (fixed).</div>
                    <div class="full"><label class="de-check"><input type="checkbox" name="surveyor_pending_send_empty" value="1" <?= $s['surveyor_pending_send_empty'] === '1' ? 'checked' : '' ?>> Also email surveyors who have no assigned vessels</label></div>
                </div>
            </div>

            <div class="de-card" data-testid="de-card-all-pending">
                <div class="de-card-head">
                    <div>
                        <div class="de-title"><i class="fa-solid fa-ship me-1"></i> Pending Vessels — full list</div>
                        <div class="de-sub">The complete Pending Vessels list (all surveyors) — same as Pending Vessels → Export Excel without filters, attached, plus a table in the mail. Now: <?= $pendingNow ?> vessel<?= $pendingNow === 1 ? '' : 's' ?>.</div>
                    </div>
                    <span class="de-pill <?= $s['all_pending_enabled'] === '1' ? 'on' : 'off' ?>"><?= $s['all_pending_enabled'] === '1' ? 'ON' : 'OFF' ?></span>
                </div>
                <div class="de-grid">
                    <div class="full"><label class="de-check"><input type="checkbox" name="all_pending_enabled" value="1" <?= $s['all_pending_enabled'] === '1' ? 'checked' : '' ?> data-testid="de-all-enabled"> Send every day</label></div>
                    <div><label for="alt">Time (IST)</label><input type="time" id="alt" name="all_pending_time" value="<?= sanitize($s['all_pending_time']) ?>" required data-testid="de-all-time"></div>
                    <div class="full de-sub" data-testid="de-all-route">From <b><?= sanitize(DAILY_EMAIL_APPT_FROM) ?></b> to <b><?= sanitize(DAILY_EMAIL_ALL_PENDING_TO) ?></b> only — fixed, no CC.</div>
                    <div class="full"><label class="de-check"><input type="checkbox" name="all_pending_send_empty" value="1" <?= $s['all_pending_send_empty'] === '1' ? 'checked' : '' ?>> Send a "no pending vessels" email when the list is empty</label></div>
                </div>
            </div>

            <div class="de-card" data-testid="de-card-appointments">
                <div class="de-card-head">
                    <div>
                        <div class="de-title"><i class="fa-solid fa-calendar-day me-1"></i> Daily Vessel Appointments Report</div>
                        <div class="de-sub">One email with every vessel whose Appointment Date is that day or that was received (assigned) that day, in the mail and as Excel. Today so far: <?= $apptToday ?> vessel<?= $apptToday === 1 ? '' : 's' ?>.</div>
                    </div>
                    <span class="de-pill <?= $s['appointments_enabled'] === '1' ? 'on' : 'off' ?>"><?= $s['appointments_enabled'] === '1' ? 'ON' : 'OFF' ?></span>
                </div>
                <div class="de-grid">
                    <div class="full"><label class="de-check"><input type="checkbox" name="appointments_enabled" value="1" <?= $s['appointments_enabled'] === '1' ? 'checked' : '' ?> data-testid="de-appt-enabled"> Send every day</label></div>
                    <div><label for="apt">Time (IST)</label><input type="time" id="apt" name="appointments_time" value="<?= sanitize($s['appointments_time']) ?>" required data-testid="de-appt-time"></div>
                    <div class="full de-sub" data-testid="de-appt-route">From <b data-testid="de-appt-from"><?= sanitize(DAILY_EMAIL_APPT_FROM) ?></b> to <b data-testid="de-appt-to"><?= sanitize(DAILY_EMAIL_APPT_TO) ?></b> only — fixed, no CC.</div>
                    <div class="full"><label class="de-check"><input type="checkbox" name="appointments_send_empty" value="1" <?= $s['appointments_send_empty'] === '1' ? 'checked' : '' ?>> Send a "no appointments" email when there are none that day</label></div>
                </div>
            </div>

            <div class="de-card" data-testid="de-card-surveyor-reports">
                <div class="de-card-head">
                    <div>
                        <div class="de-title"><i class="fa-solid fa-file-circle-exclamation me-1"></i> Pending Reports by Surveyor — every Friday</div>
                        <div class="de-sub">Each active surveyor receives only their own Pending Reports (as surveyor or co-surveyor) — the Pending Reports export filtered by that surveyor, attached as Excel, plus a table in the mail.</div>
                    </div>
                    <span class="de-pill <?= $s['surveyor_reports_enabled'] === '1' ? 'on' : 'off' ?>"><?= $s['surveyor_reports_enabled'] === '1' ? 'ON' : 'OFF' ?></span>
                </div>
                <div class="de-grid">
                    <div class="full"><label class="de-check"><input type="checkbox" name="surveyor_reports_enabled" value="1" <?= $s['surveyor_reports_enabled'] === '1' ? 'checked' : '' ?> data-testid="de-sr-enabled"> Send every Friday</label></div>
                    <div><label for="srt">Time (IST)</label><input type="time" id="srt" name="surveyor_reports_time" value="<?= sanitize($s['surveyor_reports_time']) ?>" required data-testid="de-sr-time"></div>
                    <div class="full de-sub" data-testid="de-sr-route">From <b><?= sanitize(DAILY_EMAIL_APPT_FROM) ?></b> to each surveyor's own profile email only — their own list, no CC (fixed).</div>
                    <div class="full"><label class="de-check"><input type="checkbox" name="surveyor_reports_send_empty" value="1" <?= $s['surveyor_reports_send_empty'] === '1' ? 'checked' : '' ?>> Also email surveyors who have no pending reports</label></div>
                </div>
            </div>

            <div class="de-card" data-testid="de-card-all-reports">
                <div class="de-card-head">
                    <div>
                        <div class="de-title"><i class="fa-solid fa-file-lines me-1"></i> Pending Reports — full list, every Friday</div>
                        <div class="de-sub">The complete Pending Reports list (all surveyors) — same as Pending Reports → Export Excel without filters, attached, plus a table in the mail. Now: <?= $reportsNow ?> report<?= $reportsNow === 1 ? '' : 's' ?>.</div>
                    </div>
                    <span class="de-pill <?= $s['all_reports_enabled'] === '1' ? 'on' : 'off' ?>"><?= $s['all_reports_enabled'] === '1' ? 'ON' : 'OFF' ?></span>
                </div>
                <div class="de-grid">
                    <div class="full"><label class="de-check"><input type="checkbox" name="all_reports_enabled" value="1" <?= $s['all_reports_enabled'] === '1' ? 'checked' : '' ?> data-testid="de-ar-enabled"> Send every Friday</label></div>
                    <div><label for="art">Time (IST)</label><input type="time" id="art" name="all_reports_time" value="<?= sanitize($s['all_reports_time']) ?>" required data-testid="de-ar-time"></div>
                    <div class="full de-sub" data-testid="de-ar-route">From <b><?= sanitize(DAILY_EMAIL_APPT_FROM) ?></b> to <b><?= sanitize(implode(' and ', DAILY_EMAIL_ALL_REPORTS_TO)) ?></b> only — fixed, no CC.</div>
                    <div class="full"><label class="de-check"><input type="checkbox" name="all_reports_send_empty" value="1" <?= $s['all_reports_send_empty'] === '1' ? 'checked' : '' ?>> Send a "no pending reports" email when the list is empty</label></div>
                </div>
            </div>

            <div class="de-actions">
                <button type="submit" class="de-btn primary" data-testid="de-save"><i class="fa-solid fa-floppy-disk"></i> Save settings</button>
            </div>
        </form>

        <div class="de-card mt-3" data-testid="de-today">
            <div class="de-title mb-1">Today's scheduled emails (<?= date('d M Y') ?>)</div>
            <?php foreach (DAILY_EMAIL_JOBS as $job => $label): $t = $today[$job]; $run = $t['run']; ?>
                <div class="mt-2" data-testid="de-today-<?= $job ?>">
                    <b style="font-size:13.5px;"><?= sanitize($label) ?></b> —
                    <?php if (!$run): ?>
                        <span class="de-sub"><?= dailyEmailRunsToday($job) ? 'not sent yet (due at ' . sanitize($s[$job . '_time']) . ' IST)' : 'not due today — Fridays only' ?></span>
                    <?php else: ?>
                        <span class="de-pill <?= sanitize($run['status']) ?>"><?= sanitize($run['status']) ?></span>
                        <span class="de-sub"><?= sanitize((string)$run['detail']) ?> · attempt <?= (int)$run['attempts'] ?> · <?= sanitize((string)$run['via']) ?></span>
                        <?php if ($run['status'] === 'failed'): ?>
                        <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="job" value="<?= $job ?>">
                            <button type="submit" name="action" value="retry" class="de-btn warn ms-2" data-testid="de-retry-<?= $job ?>"><i class="fa-solid fa-rotate-right"></i> Retry failed</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($t['deliveries']): ?>
                        <div style="overflow-x:auto;margin-top:6px;">
                            <table class="de-log" data-testid="de-deliveries-<?= $job ?>">
                                <thead><tr><th>Recipient</th><th>Vessels</th><th>Status</th><th>Sent at</th><th>Error</th></tr></thead>
                                <tbody>
                                <?php foreach ($t['deliveries'] as $d): ?>
                                    <tr><td><?= sanitize((string)$d['recipient_name']) ?><br><span class="de-sub"><?= sanitize($d['recipient']) ?></span></td>
                                        <td><?= (int)$d['vessels'] ?></td>
                                        <td><span class="de-pill <?= sanitize($d['status']) ?>"><?= sanitize($d['status']) ?></span><?= (int)$d['attempts'] > 1 ? ' <span class="de-sub">×' . (int)$d['attempts'] . '</span>' : '' ?></td>
                                        <td><?= $d['sent_at'] ? date('H:i', strtotime($d['sent_at'])) : '—' ?></td>
                                        <td><?= sanitize((string)$d['error']) ?></td></tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="de-card">
            <div class="de-title mb-1">Test / send now</div>
            <div class="de-sub mb-2">"Test to me" sends one sample only to your own profile email — it never touches the scheduled 17:00 email.
                "Send now" sends to the real recipients straight away as an extra email (the scheduled one still goes at its time).</div>
            <?php foreach (DAILY_EMAIL_JOBS as $job => $label): ?>
            <form method="post" class="de-actions" style="align-items:center;">
                <?= csrf_field() ?>
                <input type="hidden" name="job" value="<?= $job ?>">
                <span style="min-width:260px;font-weight:700;font-size:13.5px;"><?= sanitize($label) ?></span>
                <button type="submit" name="action" value="send_test" class="de-btn" data-testid="de-test-<?= $job ?>"><i class="fa-solid fa-flask"></i> Test to me</button>
                <button type="submit" name="action" value="send_now" class="de-btn" data-testid="de-now-<?= $job ?>" onclick="return confirm('Send <?= sanitize($label) ?> to the real recipients now? This is in addition to the scheduled email.');"><i class="fa-solid fa-paper-plane"></i> Send now</button>
            </form>
            <?php endforeach; ?>
        </div>

        <div class="de-card">
            <div class="de-title mb-2">Recent runs</div>
            <?php if (!$runs): ?><div class="de-sub">Nothing sent yet.</div><?php else: ?>
            <div style="overflow-x:auto;">
            <table class="de-log" data-testid="de-log">
                <thead><tr><th>When</th><th>Email</th><th>How</th><th>Status</th><th>Details</th></tr></thead>
                <tbody>
                <?php foreach ($runs as $r): ?>
                    <tr>
                        <td style="white-space:nowrap;"><?= date('d M Y H:i', strtotime($r['started_at'])) ?></td>
                        <td><?= sanitize(DAILY_EMAIL_JOBS[$r['job']] ?? $r['job']) ?></td>
                        <td><?= $r['kind'] === 'auto' ? 'Scheduled' . ($r['via'] ? ' (' . sanitize($r['via']) . ')' : '') : ($r['kind'] === 'test' ? 'Test' : 'Send now') ?><?= $r['full_name'] ? ' · ' . sanitize($r['full_name']) : '' ?></td>
                        <td><span class="de-pill <?= sanitize($r['status']) ?>"><?= sanitize($r['status']) ?></span><?= (int)($r['attempts'] ?? 1) > 1 ? ' <span class="de-sub">attempt ' . (int)$r['attempts'] . '</span>' : '' ?></td>
                        <td><?= sanitize((string)$r['detail']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>

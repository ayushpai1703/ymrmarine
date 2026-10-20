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
    } elseif (in_array($action, ['send_now', 'send_test'], true) && isset(DAILY_EMAIL_JOBS[$job])) {
        $me = $db->prepare('SELECT email FROM users WHERE id = ?');
        $me->execute([(int)$_SESSION['user_id']]);
        $myEmail = trim((string)$me->fetchColumn());
        if ($action === 'send_test' && !filter_var($myEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Add an email address to your own profile to receive test emails.';
        } else {
            $res = dailyEmailRunJob($db, $job, $action === 'send_test' ? 'test' : 'manual', (int)$_SESSION['user_id'], $action === 'send_test' ? $myEmail : '');
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
$allSurveyors = (int)$db->query("SELECT COUNT(*) FROM users WHERE role_id = 2 AND (status = 'Active' OR status IS NULL)")->fetchColumn();
$apptToday = count(dailyEmailPendingRows($db, 0, date('Y-m-d')));
$lastAuto = [];
foreach (array_keys(DAILY_EMAIL_JOBS) as $j) {
    $st = $db->prepare("SELECT status, detail, finished_at FROM daily_email_runs WHERE auto_key = ?");
    $st->execute([$j . '|' . date('Y-m-d')]);
    $lastAuto[$j] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
$cronReady = (string)(getenv('YSMS_CRON_TOKEN') ?: '') !== '';
$mailOn = !defined('EMAIL_NOTIFICATIONS_ENABLED') || EMAIL_NOTIFICATIONS_ENABLED;

include 'includes/header.php';
?>
<style>
.de-wrap { padding: 0 16px 28px; max-width: 980px; margin: 0 auto; }
.de-note { font-size: 13px; color: #475569; background: #fff; border: 1px solid var(--border-color); border-radius: 14px; padding: 12px 14px; margin: 12px 0; line-height: 1.55; }
.de-note.warn { background: #FFF7ED; border-color: #FED7AA; color: #9A3412; }
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
.de-pill { display: inline-block; font-size: 11.5px; font-weight: 700; padding: 3px 10px; border-radius: 999px; }
.de-pill.on { background: #DCFCE7; color: #166534; } .de-pill.off { background: #F1F5F9; color: #475569; }
.de-pill.sent { background: #DCFCE7; color: #166534; } .de-pill.failed { background: #FEE2E2; color: #991B1B; }
.de-pill.running { background: #DBEAFE; color: #1E40AF; } .de-pill.skipped { background: #F1F5F9; color: #475569; }
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
        <div class="de-note">
            Times are <b>India time</b>. Each email goes out once a day, as soon as its time has passed.
            <?php if ($cronReady): ?>
                The server's scheduler checks every few minutes.
            <?php else: ?>
                It is sent by the next check after that time — a check runs whenever someone has YSMS open (at most every 10 minutes).
                For an exact time even when nobody is logged in, set up a Cloud Scheduler job calling <code>ajax/daily_emails_run.php</code> with the <code>X-Cron-Token</code> header (YSMS_CRON_TOKEN).
            <?php endif; ?>
        </div>

        <form method="post" data-testid="de-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">

            <div class="de-card" data-testid="de-card-surveyor">
                <div class="de-card-head">
                    <div>
                        <div class="de-title"><i class="fa-solid fa-user-gear me-1"></i> Pending vessels to each surveyor</div>
                        <div class="de-sub">Every active surveyor gets only their own Pending Vessels (as the Pending Vessels page filtered by that surveyor), in the mail and as Excel, at their profile email.
                            <?= count($surveyors) ?> of <?= $allSurveyors ?> active surveyors have a profile email.</div>
                    </div>
                    <span class="de-pill <?= $s['surveyor_pending_enabled'] === '1' ? 'on' : 'off' ?>"><?= $s['surveyor_pending_enabled'] === '1' ? 'ON' : 'OFF' ?></span>
                </div>
                <div class="de-grid">
                    <div class="full"><label class="de-check"><input type="checkbox" name="surveyor_pending_enabled" value="1" <?= $s['surveyor_pending_enabled'] === '1' ? 'checked' : '' ?> data-testid="de-surveyor-enabled"> Send every day</label></div>
                    <div><label for="spt">Time (India)</label><input type="time" id="spt" name="surveyor_pending_time" value="<?= sanitize($s['surveyor_pending_time']) ?>" required data-testid="de-surveyor-time"></div>
                    <div><label for="spf">From</label><input type="email" id="spf" name="surveyor_pending_from" value="<?= sanitize($s['surveyor_pending_from']) ?>" required data-testid="de-surveyor-from"></div>
                    <div class="full"><label for="spc">CC (optional, comma separated)</label><input type="text" id="spc" name="surveyor_pending_cc" value="<?= sanitize($s['surveyor_pending_cc']) ?>" placeholder="e.g. ops@ymrmarine.com" data-testid="de-surveyor-cc"></div>
                    <div class="full"><label class="de-check"><input type="checkbox" name="surveyor_pending_send_empty" value="1" <?= $s['surveyor_pending_send_empty'] === '1' ? 'checked' : '' ?>> Also email surveyors who have no pending vessels</label></div>
                </div>
                <?php if ($lastAuto['surveyor_pending']): ?><div class="de-sub mt-2">Today: <span class="de-pill <?= sanitize($lastAuto['surveyor_pending']['status']) ?>"><?= sanitize($lastAuto['surveyor_pending']['status']) ?></span> <?= sanitize((string)$lastAuto['surveyor_pending']['detail']) ?></div><?php endif; ?>
            </div>

            <div class="de-card" data-testid="de-card-appointments">
                <div class="de-card-head">
                    <div>
                        <div class="de-title"><i class="fa-solid fa-calendar-day me-1"></i> Today's appointments</div>
                        <div class="de-sub">Pending Vessels whose Appointment Date is that day (same as the Pending Vessels Appointment filter), in the mail and as Excel. Today: <?= $apptToday ?> vessel<?= $apptToday === 1 ? '' : 's' ?>.</div>
                    </div>
                    <span class="de-pill <?= $s['appointments_enabled'] === '1' ? 'on' : 'off' ?>"><?= $s['appointments_enabled'] === '1' ? 'ON' : 'OFF' ?></span>
                </div>
                <div class="de-grid">
                    <div class="full"><label class="de-check"><input type="checkbox" name="appointments_enabled" value="1" <?= $s['appointments_enabled'] === '1' ? 'checked' : '' ?> data-testid="de-appt-enabled"> Send every day</label></div>
                    <div><label for="apt">Time (India)</label><input type="time" id="apt" name="appointments_time" value="<?= sanitize($s['appointments_time']) ?>" required data-testid="de-appt-time"></div>
                    <div><label for="apf">From</label><input type="email" id="apf" name="appointments_from" value="<?= sanitize($s['appointments_from']) ?>" required data-testid="de-appt-from"></div>
                    <div><label for="apto">To (comma separated)</label><input type="text" id="apto" name="appointments_to" value="<?= sanitize($s['appointments_to']) ?>" required data-testid="de-appt-to"></div>
                    <div><label for="apc">CC (optional)</label><input type="text" id="apc" name="appointments_cc" value="<?= sanitize($s['appointments_cc']) ?>" data-testid="de-appt-cc"></div>
                    <div class="full"><label class="de-check"><input type="checkbox" name="appointments_send_empty" value="1" <?= $s['appointments_send_empty'] === '1' ? 'checked' : '' ?>> Send even when there are no appointments that day</label></div>
                </div>
                <?php if ($lastAuto['appointments']): ?><div class="de-sub mt-2">Today: <span class="de-pill <?= sanitize($lastAuto['appointments']['status']) ?>"><?= sanitize($lastAuto['appointments']['status']) ?></span> <?= sanitize((string)$lastAuto['appointments']['detail']) ?></div><?php endif; ?>
            </div>

            <div class="de-actions">
                <button type="submit" class="de-btn primary" data-testid="de-save"><i class="fa-solid fa-floppy-disk"></i> Save settings</button>
            </div>
        </form>

        <div class="de-card mt-3">
            <div class="de-title mb-1">Send now</div>
            <div class="de-sub mb-2">"Test to me" sends one sample to your own profile email. "Send now" sends to the real recipients straight away (it doesn't stop the scheduled email).</div>
            <?php foreach (DAILY_EMAIL_JOBS as $job => $label): ?>
            <form method="post" class="de-actions" style="align-items:center;">
                <?= csrf_field() ?>
                <input type="hidden" name="job" value="<?= $job ?>">
                <span style="min-width:230px;font-weight:700;font-size:13.5px;"><?= sanitize($label) ?></span>
                <button type="submit" name="action" value="send_test" class="de-btn" data-testid="de-test-<?= $job ?>"><i class="fa-solid fa-flask"></i> Test to me</button>
                <button type="submit" name="action" value="send_now" class="de-btn" data-testid="de-now-<?= $job ?>" onclick="return confirm('Send <?= sanitize($label) ?> to the real recipients now?');"><i class="fa-solid fa-paper-plane"></i> Send now</button>
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
                        <td><?= $r['kind'] === 'auto' ? 'Scheduled' : ($r['kind'] === 'test' ? 'Test' : 'Send now') ?><?= $r['full_name'] ? ' · ' . sanitize($r['full_name']) : '' ?></td>
                        <td><span class="de-pill <?= sanitize($r['status']) ?>"><?= sanitize($r['status']) ?></span></td>
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

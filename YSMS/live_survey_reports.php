<?php
/**
 * Live Survey Reports (Admin, Super Admin): who is filling a report on the page without sign-in right now.
 * Rules and status times: includes/live_survey_reports.php. Read-only; a report opens in survey_report.php
 * (its tank history — version 1 and every later change of sounding / volume / density / temperature — is there).
 */
require_once 'config/config.php';
require_once 'includes/live_survey_reports.php';
checkAuth();
if (!in_array($_SESSION['role'] ?? '', ['Admin', 'Super Admin'], true)) {
    header('Location: index.php');
    exit;
}
$range = (string)($_GET['range'] ?? '7');
$ranges = ['1' => 'Last 24 hours', '7' => 'Last 7 days', '30' => 'Last 30 days', 'all' => 'All'];
if (!isset($ranges[$range])) $range = '7';
$rows = [];
$error = '';
$hidden = 0;
try {
    $rows = lsrList(getDB(), $range === 'all' ? null : (int)$range * 24);
    $hidden = 0;
    if (empty($_GET['empty'])) { // reports where none of vessel / IMO / surveyor is typed yet (the link only opened)
        $all = count($rows);
        $rows = array_values(array_filter($rows, fn($r) => $r['vessel'] !== '' || $r['imo'] !== '' || $r['surveyor'] !== ''));
        $hidden = $all - count($rows);
    }
} catch (Throwable $e) {
    error_log('live_survey_reports: ' . $e->getMessage());
    $error = 'The list could not be loaded. Please try again.';
}
$count = ['progress' => 0, 'check' => 0, 'done' => 0];
foreach ($rows as $r) $count[$r['status']['key']]++;
$page_title = 'Live Survey Reports';
include 'includes/header.php';
echo lsrCss();
?>
<div class="scroll-content" data-testid="live-survey-reports-page">
<?php $back_url = 'index.php'; $page_testid = 'live-survey-reports'; include 'includes/top_app_bar.php'; ?>
<div class="container-fluid py-3" style="max-width:1200px">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
            <h1 class="h4 fw-bold mb-1"><i class="fa-solid fa-tower-broadcast text-success me-2"></i>Live Survey Reports</h1>
            <div class="text-muted" style="font-size:13px">Reports filled on the survey report link (no sign-in). Status from the time the report was started: under 4 h in progress, 4–24 h check now, after 24 h done. The list refreshes every minute.</div>
        </div>
        <form method="get" class="d-flex gap-2" data-testid="lsr-range">
            <?php if (!empty($_GET['empty'])): ?><input type="hidden" name="empty" value="1"><?php endif; ?>
            <select name="range" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach ($ranges as $k => $l): ?><option value="<?= $k ?>"<?= (string)$k === $range ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
            </select>
        </form>
    </div>
    <div class="d-flex flex-wrap gap-2 mb-3" data-testid="lsr-counts">
        <span class="lsr-pill lsr-p">In progress: <?= $count['progress'] ?></span>
        <span class="lsr-pill lsr-c">Check now: <?= $count['check'] ?></span>
        <span class="lsr-pill lsr-d">Done: <?= $count['done'] ?></span>
    </div>
    <?php if (!empty($hidden)): ?><div class="text-muted mb-2" style="font-size:12.5px" data-testid="lsr-hidden"><?= (int)$hidden ?> report<?= $hidden === 1 ? '' : 's' ?> with nothing typed yet (link only opened) not shown — <a href="?range=<?= sanitize($range) ?>&amp;empty=1">show them</a></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= sanitize($error) ?></div><?php endif; ?>
    <div class="bg-white rounded-4 shadow-sm" style="border:1px solid var(--border-color, #e2e8f0)">
        <div class="lsr-row" style="border-top:0;font-size:12px;color:#64748b;font-weight:700">
            <span>VESSEL · IMO</span><span>SURVEYOR</span><span class="lsr-hide-sm">STARTED</span><span>STATUS</span>
        </div>
        <?php if (!$rows): ?>
            <div class="p-4 text-center text-muted" data-testid="lsr-none">No reports in this period.</div>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <a class="lsr-row" href="survey_report.php?id=<?= (int)$r['id'] ?>" data-testid="lsr-row-<?= (int)$r['id'] ?>">
                <span>
                    <b><?= $r['vessel'] !== '' ? sanitize($r['vessel']) : '<span class="lsr-empty">Vessel not entered yet</span>' ?></b>
                    <small>IMO <?= $r['imo'] !== '' ? sanitize($r['imo']) : '—' ?> · <?= sanitize($r['kind']) ?><?= $r['tank_changes'] ? ' · <span class="text-warning-emphasis fw-bold">' . (int)$r['tank_changes'] . ' tank change' . ($r['tank_changes'] === 1 ? '' : 's') . '</span>' : '' ?><?= $r['pdfs'] ? ' · PDF made' : '' ?></small>
                </span>
                <span><?= $r['surveyor'] !== '' ? sanitize($r['surveyor']) : '<span class="lsr-empty">Not entered yet</span>' ?></span>
                <span class="lsr-hide-sm"><?= sanitize(date('d M Y, H:i', strtotime($r['started_at']))) ?><small><?= sanitize(lsrAgo($r['status']['hours'])) ?> · last saved <?= sanitize(date('H:i', strtotime($r['updated_at']))) ?></small></span>
                <span><?= lsrPill($r['status']) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</div>
</div>
<script>setTimeout(function () { if (!document.hidden) location.reload(); else document.addEventListener('visibilitychange', function () { location.reload(); }, { once: true }); }, 60000);</script>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>

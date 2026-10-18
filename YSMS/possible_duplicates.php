<?php
require_once 'config/config.php';
require_once 'includes/duplicate_finder.php';
checkAuth();

// Admin only — deleting goes through Admin Controls' delete (ajax/admin_surveys.php), also Admin only.
if (($_SESSION['role'] ?? '') !== 'Admin') {
    header('Location: index.php');
    exit;
}

$db = getDB();
$clusters = findPossibleDuplicates($db);

$kindFilter = in_array($_GET['kind'] ?? '', ['duplicate', 'check'], true) ? $_GET['kind'] : '';
$monthFilter = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)($_GET['month'] ?? '')) ? (string)$_GET['month'] : '';
$months = array_unique(array_column($clusters, 'month'));
rsort($months);

$count = ['duplicate' => 0, 'check' => 0]; $extraShips = 0; $extraMt = 0.0;
foreach ($clusters as $c) {
    $count[$c['kind']]++;
    if ($c['kind'] === 'duplicate') { $extraShips += $c['extra_ships']; $extraMt += $c['extra_mt']; }
}
$shown = array_values(array_filter($clusters, fn($c) => ($kindFilter === '' || $c['kind'] === $kindFilter) && ($monthFilter === '' || $c['month'] === $monthFilter)));

$fmtDate = fn($v) => (!empty($v) && strpos((string)$v, '0000') !== 0) ? date('d-m-Y', strtotime((string)$v)) : '—';
$fmtMt = fn($v) => $v === null ? '—' : number_format((float)$v, 3);

include 'includes/header.php';
?>
<style>
.pd-wrap { padding: 0 16px 24px; max-width: 1300px; margin: 0 auto; }
.pd-intro { font-size: 13px; color: #475569; background: #fff; border: 1px solid var(--border-color); border-radius: 14px; padding: 12px 14px; margin: 12px 0; line-height: 1.55; }
.pd-cards { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 12px; }
.pd-card { background: #fff; border: 1px solid var(--border-color); border-radius: 14px; padding: 12px 14px; }
.pd-card-l { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .3px; }
.pd-card-v { font-size: 18px; font-weight: 800; color: #0f172a; margin-top: 2px; }
.pd-card-v.red { color: #B91C1C; } .pd-card-v.amber { color: #B45309; }
.pd-filters { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
.pd-filters select { border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 7px 10px; font-size: 13px; background: #fff; }
.pd-group { background: #fff; border: 1px solid var(--border-color); border-radius: 14px; margin-bottom: 12px; overflow: hidden; }
.pd-group-head { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 10px 14px; border-bottom: 1px solid #f1f5f9; }
.pd-group-title { font-weight: 800; font-size: 14px; color: #0f172a; }
.pd-group-sub { font-size: 12px; color: #64748b; }
.pd-tag { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 800; }
.pd-tag.duplicate { background: #FEE2E2; color: #991B1B; } .pd-tag.check { background: #FFEDD5; color: #9A3412; }
.pd-scroll { overflow-x: auto; }
.pd-table { width: 100%; border-collapse: collapse; font-size: 12.5px; white-space: nowrap; }
.pd-table th { background: #F8FAFC; color: #475569; font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 8px 10px; text-align: left; }
.pd-table td { padding: 8px 10px; border-top: 1px solid #f1f5f9; color: #0f172a; }
.pd-table .num { text-align: right; }
.pd-src { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 999px; }
.pd-src.imp { background: #EEF2FF; color: #3730A3; } .pd-src.live { background: #ECFDF5; color: #047857; }
.pd-btn { display: inline-flex; align-items: center; gap: 5px; padding: 5px 11px; border-radius: 9px; font-size: 12px; font-weight: 700; text-decoration: none; border: 1.5px solid #e2e8f0; background: #fff; color: #334155; cursor: pointer; }
.pd-btn.del { border-color: #FECACA; color: #B91C1C; background: #FEF2F2; }
.pd-btn.del:hover { background: #B91C1C; color: #fff; }
.pd-empty { text-align: center; padding: 40px 16px; color: #94a3b8; background: #fff; border: 1px solid var(--border-color); border-radius: 14px; }
@media (min-width: 768px) { .pd-cards { grid-template-columns: repeat(4, 1fr); } }
</style>
<div class="scroll-content" data-testid="possible-duplicates-page">
    <?php $page_title = 'Possible Duplicates'; $back_url = 'admin_controls.php'; $page_testid = 'possible-duplicates'; include 'includes/top_app_bar.php'; ?>
    <div class="pd-wrap">
        <div class="pd-intro">
            The same vessel for the same client listed more than once in Pending Reports / Completed — usually entered live in YSMS and then imported again from Excel. Each copy is counted in the dashboard.
            <b>Duplicate</b> = same recovery, Survey Completed within <?= DUP_SAME_RECOVERY_DAYS ?> days.
            <b>Check</b> = within <?= DUP_CHECK_DAYS ?> days but a different / zero recovery: a repeat survey or a duplicate — you decide.
            Delete the copy you don't need; usually keep the one that has the report / files. Deleting is permanent.
        </div>
        <div class="pd-cards">
            <div class="pd-card"><div class="pd-card-l">Duplicate groups</div><div class="pd-card-v red" data-testid="pd-count-duplicate"><?= $count['duplicate'] ?></div></div>
            <div class="pd-card"><div class="pd-card-l">To check</div><div class="pd-card-v amber" data-testid="pd-count-check"><?= $count['check'] ?></div></div>
            <div class="pd-card"><div class="pd-card-l">Possible extra ships</div><div class="pd-card-v" data-testid="pd-extra-ships"><?= $extraShips ?></div></div>
            <div class="pd-card"><div class="pd-card-l">Possible extra recovery</div><div class="pd-card-v" data-testid="pd-extra-mt"><?= number_format($extraMt, 3) ?> MT</div></div>
        </div>
        <form method="get" class="pd-filters" id="pdFilters">
            <select name="kind" data-testid="pd-kind" aria-label="Type">
                <option value="">All (<?= count($clusters) ?>)</option>
                <option value="duplicate"<?= $kindFilter === 'duplicate' ? ' selected' : '' ?>>Duplicates (<?= $count['duplicate'] ?>)</option>
                <option value="check"<?= $kindFilter === 'check' ? ' selected' : '' ?>>To check (<?= $count['check'] ?>)</option>
            </select>
            <select name="month" data-testid="pd-month" aria-label="Month (Survey Completed)">
                <option value="">All months</option>
                <?php foreach ($months as $m): ?><option value="<?= sanitize($m) ?>"<?= $m === $monthFilter ? ' selected' : '' ?>><?= date('F Y', strtotime($m . '-01')) ?></option><?php endforeach; ?>
            </select>
        </form>

        <?php if (!$shown): ?>
            <div class="pd-empty" data-testid="pd-empty"><i class="fa-solid fa-circle-check"></i> No possible duplicates<?= $kindFilter || $monthFilter ? ' for this filter' : '' ?>.</div>
        <?php endif; ?>
        <?php foreach ($shown as $c): $first = $c['records'][0]; ?>
        <div class="pd-group" data-testid="pd-group" data-kind="<?= $c['kind'] ?>">
            <div class="pd-group-head">
                <span class="pd-tag <?= $c['kind'] ?>"><?= $c['kind'] === 'duplicate' ? 'DUPLICATE' : 'CHECK' ?></span>
                <span class="pd-group-title"><?= sanitize($first['vessel_name']) ?></span>
                <span class="pd-group-sub"><?= sanitize((string)$first['company_name']) ?> · <?= count($c['records']) ?> records<?= $c['extra_mt'] > 0 ? ' · ' . number_format($c['extra_mt'], 3) . ' MT counted twice' : '' ?></span>
            </div>
            <div class="pd-scroll">
                <table class="pd-table">
                    <thead><tr><th>Report No</th><th>Status</th><th>Source</th><th>Survey Type</th><th>Surveyor</th><th>Survey Completed</th><th>Report Completed</th><th class="num">Recovery (MT)</th><th class="num">Files</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($c['records'] as $r): $src = duplicateRecordSource($r); $detail = ($r['status'] === 'Completed' ? 'completed_detail.php' : 'report_detail.php') . '?id=' . (int)$r['id']; ?>
                        <tr data-testid="pd-record" data-id="<?= (int)$r['id'] ?>">
                            <td><b><?= sanitize((string)($r['report_number'] ?: '—')) ?></b></td>
                            <td><?= $r['status'] === 'Completed' ? 'Completed' : 'Report Pending' ?></td>
                            <td><span class="pd-src <?= $src === 'Imported from Excel' ? 'imp' : 'live' ?>"><?= $src ?></span></td>
                            <td><?= sanitize(getCombinedSurveyTypeNames($db, $r['survey_type_ids'] ?? '', $r['type_name'] ?? '')) ?></td>
                            <td><?= sanitize(getCombinedSurveyorNames($db, (int)$r['id'], $r['surveyor_name'] ?? '')) ?></td>
                            <td><?= $fmtDate($r['survey_completed_date']) ?></td>
                            <td><?= $fmtDate($r['report_uploaded_date']) ?></td>
                            <td class="num"><?= $fmtMt($r['recovery_amount']) ?></td>
                            <td class="num"><?= (int)$r['files'] ?></td>
                            <td>
                                <a class="pd-btn" href="<?= $detail ?>" target="_blank" rel="noopener"><i class="fa-solid fa-up-right-from-square"></i> Open</a>
                                <button type="button" class="pd-btn del" data-pd-delete="<?= (int)$r['id'] ?>" data-type="<?= $r['status'] === 'Completed' ? 'completed_surveys' : 'pending_reports' ?>"
                                    data-label="<?= sanitize($r['vessel_name'] . ' — ' . ($r['report_number'] ?: '#' . $r['id'])) ?>" data-testid="pd-delete-<?= (int)$r['id'] ?>"><i class="fa-solid fa-trash"></i> Delete</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<script>
document.querySelectorAll('#pdFilters select').forEach(function (s) { s.addEventListener('change', function () { s.form.submit(); }); });
document.querySelectorAll('[data-pd-delete]').forEach(function (b) {
    b.addEventListener('click', function () {
        if (!confirm('Delete ' + b.dataset.label + ' permanently?\n\nThe record and its file list are removed. This cannot be undone.')) return;
        b.disabled = true;
        var fd = new FormData(); fd.append('type', b.dataset.type); fd.append('action', 'delete'); fd.append('id', b.dataset.pdDelete);
        fetch('ajax/admin_surveys.php', { method: 'POST', body: fd, headers: { 'X-CSRF-Token': <?= json_encode(csrf_token()) ?> } })
            .then(function (r) { return r.json(); })
            .then(function (res) { if (res.success) location.reload(); else { alert(res.message || 'Could not delete.'); b.disabled = false; } })
            .catch(function () { alert('Could not delete. Please try again.'); b.disabled = false; });
    });
});
</script>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>

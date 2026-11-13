<?php
/**
 * Missed Vessels — vessels our team could not attend, moved here by hand from Pending Vessels
 * with a reason (includes/missed_vessels.php has the rules and permissions).
 * Computer: table; phone (<992px): cards. Search (vessel / client), filters (client, surveyor,
 * port, date), sort (latest missed first), 25 per page. Actions: View Details, Edit Remarks,
 * Restore to Pending Vessels (modals: assets/js/missed-vessels.js → ajax/missed_vessels.php).
 */
require_once 'config/config.php';
require_once 'includes/list_filters.php';
require_once 'includes/missed_vessels.php';
checkAuth();

if (!missedCanViewList()) {
    header('Location: index.php');
    exit;
}

$db = getDB();
$setupError = '';
try { missedEnsureSchema($db); } catch (Throwable $e) {
    error_log('missed.php schema: ' . $e->getMessage());
    $setupError = 'Missed Vessels is not set up on this database yet. Please run database/migration_missed_vessels.sql.';
}
$showSurveyor = missedShowsSurveyor();

$rows = []; $total = 0; $pages = 1; $page = 1; $per = 25;
$clients = $ports = $surveyors = [];
[$f, $where, $params] = missedFilters($_GET);
if ($setupError === '') {
    try {
        $cnt = $db->prepare('SELECT COUNT(*) ' . MISSED_FROM . " WHERE $where");
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();
        $pages = max(1, (int)ceil($total / $per));
        $page = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
        $q = $db->prepare(MISSED_SELECT . MISSED_FROM . " WHERE $where ORDER BY " . missedOrderSql($f['sort']) . " LIMIT $per OFFSET " . (($page - 1) * $per));
        $q->execute($params);
        $rows = array_map(fn($r) => missedRowView($db, $r), $q->fetchAll(PDO::FETCH_ASSOC));

        // filter choices: from every missed vessel this user can see
        [$scope, $sp] = missedScope();
        $o = $db->prepare('SELECT s.id, c.company_name, p.port_name, u.full_name AS surveyor_name ' . MISSED_FROM . " WHERE s.status = 'Missed' AND $scope");
        $o->execute($sp);
        foreach ($o->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!empty($r['company_name'])) $clients[$r['company_name']] = true;
            if (!empty($r['port_name'])) $ports[$r['port_name']] = true;
            if ($showSurveyor) foreach (listFilterSplitNames(getCombinedSurveyorNames($db, $r['id'], $r['surveyor_name'] ?? '')) as $n) $surveyors[$n] = true;
        }
        foreach ([&$clients, &$ports, &$surveyors] as &$list) { $list = array_keys($list); natcasesort($list); $list = array_values($list); }
        unset($list);
    } catch (Throwable $e) {
        error_log('missed.php list: ' . $e->getMessage());
        $setupError = 'Could not load the missed vessels. Please refresh the page.';
    }
}
$allMissed = $setupError === '' ? missedCount($db) : 0;
$filtered = $f['q'] !== '' || $f['client'] !== '' || $f['surveyor'] !== '' || $f['port'] !== '' || $f['from'] !== '' || $f['to'] !== '';

function mvQs(array $f, array $extra = []): string {
    $q = array_filter(array_merge(['q' => $f['q'], 'client' => $f['client'], 'surveyor' => $f['surveyor'], 'port' => $f['port'],
        'from' => $f['from'], 'to' => $f['to'], 'sort' => $f['sort'] === 'latest' ? '' : $f['sort']], $extra), fn($v) => $v !== '' && $v !== null);
    return $q ? '?' . http_build_query($q) : '';
}
$fmtDate = fn(string $d) => $d !== '' && strpos($d, '0000') !== 0 && strtotime($d) ? date('d M Y', strtotime($d)) : '—';
$fmtTime = fn(string $d) => $d !== '' && strpos($d, '0000') !== 0 && strtotime($d) ? date('h:i A', strtotime($d)) : '';

$page_title = 'Missed Vessels';
include 'includes/header.php';
echo missedAssets();
?>
<div class="scroll-content mvp" data-testid="missed-vessels-page">
<?php $back_url = ''; $page_testid = 'missed-vessels'; include 'includes/top_app_bar.php'; ?>
<div class="mvp-wrap">
    <div class="mvp-head">
        <span class="mvp-logo"><i class="fa-solid fa-triangle-exclamation"></i></span>
        <div><h1>Missed Vessels</h1><div class="mvp-sub">Surveys our team could not attend, moved here from Pending Vessels with a reason.</div></div>
        <div class="mvp-total" data-testid="mv-total"><b data-mv-count="missed"><?= (int)$allMissed ?></b> missed vessel<?= $allMissed === 1 ? '' : 's' ?></div>
    </div>

    <?php if ($setupError !== ''): ?>
        <div class="alert alert-warning" data-testid="mv-setup-error"><?= sanitize($setupError) ?></div>
    <?php endif; ?>

    <form class="mvp-filters<?= ($f['client'] !== '' || $f['surveyor'] !== '' || $f['port'] !== '' || $f['from'] !== '' || $f['to'] !== '' || $f['sort'] !== 'latest') ? ' show' : '' ?>" method="get" action="missed.php" data-testid="mv-filters">
        <button type="button" class="mvp-ftog" onclick="this.form.classList.toggle('show')" aria-label="Show filters" data-testid="mv-filter-toggle"><i class="fa-solid fa-sliders"></i> Filters</button>
        <label class="q">Search<input type="search" name="q" value="<?= sanitize($f['q']) ?>" placeholder="Vessel name or client name…" data-testid="mv-search"></label>
        <label>Client<select name="client" data-testid="mv-f-client"><option value="">All clients</option><?php foreach ($clients as $v): ?><option<?= $v === $f['client'] ? ' selected' : '' ?>><?= sanitize($v) ?></option><?php endforeach; ?></select></label>
        <?php if ($showSurveyor): ?>
        <label>Surveyor<select name="surveyor" data-testid="mv-f-surveyor"><option value="">All surveyors</option><?php foreach ($surveyors as $v): ?><option<?= $v === $f['surveyor'] ? ' selected' : '' ?>><?= sanitize($v) ?></option><?php endforeach; ?></select></label>
        <?php endif; ?>
        <label>Port<select name="port" data-testid="mv-f-port"><option value="">All ports</option><?php foreach ($ports as $v): ?><option<?= $v === $f['port'] ? ' selected' : '' ?>><?= sanitize($v) ?></option><?php endforeach; ?></select></label>
        <label>Missed from<input type="date" name="from" value="<?= sanitize($f['from']) ?>" data-testid="mv-f-from"></label>
        <label>to<input type="date" name="to" value="<?= sanitize($f['to']) ?>" data-testid="mv-f-to"></label>
        <label>Sort<select name="sort" data-testid="mv-sort">
            <option value="latest"<?= $f['sort'] === 'latest' ? ' selected' : '' ?>>Latest missed first</option>
            <option value="oldest"<?= $f['sort'] === 'oldest' ? ' selected' : '' ?>>Oldest missed first</option>
            <option value="vessel"<?= $f['sort'] === 'vessel' ? ' selected' : '' ?>>Vessel name A–Z</option>
        </select></label>
        <div class="mvp-fbtn"><button type="submit" data-testid="mv-apply"><i class="fa-solid fa-filter"></i> Apply</button><?php if ($filtered || $f['sort'] !== 'latest'): ?><a href="missed.php" data-testid="mv-clear">Clear</a><?php endif; ?></div>
    </form>

    <div class="mvp-card">
        <?php if (!$rows): ?>
            <div class="mvp-empty" data-testid="mv-empty"><i class="fa-solid fa-triangle-exclamation"></i><?= $filtered ? 'No missed vessels match these filters.' : 'No missed vessels.' ?></div>
        <?php else: ?>
        <div class="mvp-t-wrap table-responsive">
            <table class="mvp-t" data-testid="mv-table">
                <thead><tr><th>#</th><th>Vessel</th><th>Client</th><th>Port</th><th>Survey Type</th><?php if ($showSurveyor): ?><th>Surveyor</th><?php endif; ?><th>Missed On / By</th><th>Reason / Remarks</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $i => $r): ?>
                    <tr data-mv-row="<?= $r['id'] ?>" data-testid="mv-row-<?= $r['id'] ?>">
                        <td class="mvp-mut"><?= ($page - 1) * $per + $i + 1 ?></td>
                        <td><a class="vn" href="vessel_detail.php?id=<?= $r['id'] ?>"><?= sanitize($r['vessel']) ?></a><div><span class="mvp-pill">Missed</span></div></td>
                        <td><?= sanitize($r['client']) ?: '—' ?><?php if ($r['agent'] !== ''): ?><div class="mvp-mut">Agent: <?= sanitize($r['agent']) ?></div><?php endif; ?></td>
                        <td><?= sanitize($r['port']) ?: '—' ?></td>
                        <td><?= sanitize($r['type']) ?: '—' ?></td>
                        <?php if ($showSurveyor): ?><td><?= sanitize($r['surveyor']) ?: '—' ?></td><?php endif; ?>
                        <td style="white-space:nowrap"><?= $fmtDate($r['missed_at']) ?><div class="mvp-mut"><?= $fmtTime($r['missed_at']) ?></div><div class="mvp-by" data-testid="mv-marked-by-<?= $r['id'] ?>">by <?= sanitize($r['marked_by']) ?: '—' ?></div></td>
                        <td><div class="mvp-rem" data-mv-remarks-of="<?= $r['id'] ?>" data-testid="mv-remarks-<?= $r['id'] ?>"><?= sanitize($r['remarks']) ?: '—' ?></div>
                            <?php if ($r['edited_at'] !== ''): ?><div class="mvp-mut">Edited <?= missedWhen($r['edited_at']) ?><?= $r['edited_by'] !== '' ? ' by ' . sanitize($r['edited_by']) : '' ?></div><?php endif; ?></td>
                        <td><div class="mvp-act">
                            <a href="vessel_detail.php?id=<?= $r['id'] ?>" data-testid="mv-view-<?= $r['id'] ?>"><i class="fa-solid fa-eye"></i> View</a>
                            <?php if ($r['can_edit']): ?><button type="button" class="ed" <?= missedBtnData('edit', $r) ?> data-testid="mv-edit-<?= $r['id'] ?>"><i class="fa-solid fa-pen"></i> Edit Remarks</button><?php endif; ?>
                            <?php if ($r['can_restore']): ?><button type="button" class="rs" <?= missedBtnData('restore', $r) ?> data-testid="mv-restore-<?= $r['id'] ?>"><i class="fa-solid fa-rotate-left"></i> Restore</button><?php endif; ?>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="mvp-cards" data-testid="mv-cards">
            <?php foreach ($rows as $r): ?>
            <div class="mvc" data-mv-row="<?= $r['id'] ?>" data-testid="mv-card-<?= $r['id'] ?>">
                <div class="mvc-h"><a href="vessel_detail.php?id=<?= $r['id'] ?>"><?= sanitize($r['vessel']) ?></a><span class="mvp-pill">Missed</span></div>
                <div class="mvc-g">
                    <div><span>Client</span><b><?= sanitize($r['client']) ?: '—' ?></b></div>
                    <div><span>Port</span><b><?= sanitize($r['port']) ?: '—' ?></b></div>
                    <div><span>Survey Type</span><b><?= sanitize($r['type']) ?: '—' ?></b></div>
                    <?php if ($showSurveyor): ?><div><span>Surveyor</span><b><?= sanitize($r['surveyor']) ?: '—' ?></b></div><?php endif; ?>
                    <div><span>Missed On</span><b><?= $fmtDate($r['missed_at']) ?> <?= $fmtTime($r['missed_at']) ?></b></div>
                    <div><span>Marked By</span><b><?= sanitize($r['marked_by']) ?: '—' ?></b></div>
                </div>
                <div class="mvc-r"><span>Reason / Remarks</span><p data-mv-remarks-of="<?= $r['id'] ?>"><?= sanitize($r['remarks']) ?: '—' ?></p></div>
                <div class="mvp-act">
                    <a href="vessel_detail.php?id=<?= $r['id'] ?>"><i class="fa-solid fa-eye"></i> View Details</a>
                    <?php if ($r['can_edit']): ?><button type="button" class="ed" <?= missedBtnData('edit', $r) ?> data-testid="mvc-edit-<?= $r['id'] ?>"><i class="fa-solid fa-pen"></i> Edit Remarks</button><?php endif; ?>
                    <?php if ($r['can_restore']): ?><button type="button" class="rs" <?= missedBtnData('restore', $r) ?> data-testid="mvc-restore-<?= $r['id'] ?>"><i class="fa-solid fa-rotate-left"></i> Restore</button><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="mvp-pager" data-testid="mv-pager">
            <div data-testid="mv-meta"><?= $total ? 'Showing ' . (($page - 1) * $per + 1) . '–' . min($total, $page * $per) . ' of ' . $total . ($filtered ? ' matching' : '') : '0 vessels' ?></div>
            <?php if ($pages > 1): ?>
            <div class="mvp-pg">
                <?= $page > 1 ? '<a href="missed.php' . sanitize(mvQs($f, ['page' => $page - 1])) . '" aria-label="Previous page" data-testid="mv-prev"><i class="fa-solid fa-chevron-left"></i></a>' : '<span class="dis"><i class="fa-solid fa-chevron-left"></i></span>' ?>
                <?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?>
                    <?= $p === $page ? '<span class="on">' . $p . '</span>' : '<a href="missed.php' . sanitize(mvQs($f, ['page' => $p])) . '">' . $p . '</a>' ?>
                <?php endfor; ?>
                <?= $page < $pages ? '<a href="missed.php' . sanitize(mvQs($f, ['page' => $page + 1])) . '" aria-label="Next page" data-testid="mv-next"><i class="fa-solid fa-chevron-right"></i></a>' : '<span class="dis"><i class="fa-solid fa-chevron-right"></i></span>' ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</div>
<script>
// a restored vessel leaves this list: reload once the last one on this page is gone (keeps paging right)
document.addEventListener('mv:removed', function () { if (!document.querySelector('.mvp-t [data-mv-row], .mvp-cards [data-mv-row]')) location.reload(); });
</script>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>

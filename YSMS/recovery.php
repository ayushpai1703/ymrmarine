<?php
require_once 'config/config.php';
require_once 'includes/recovery_list.php';
checkAuth();

$db = getDB();
$role = (string)($_SESSION['role'] ?? '');
$user_id = (int)($_SESSION['user_id'] ?? 0);
$is_full_access = in_array($role, ['Admin', 'Super Admin'], true);
$client_row_id = ($role === 'Client') ? getClientIdForUser($db, $user_id) : 0;

$f = recoveryListFilters($_GET, $is_full_access);
[$whereSql, $params, $orderSql] = recoveryListWhere($role, $user_id, $client_row_id, $f);

// Filter options — only values that occur in the vessels this user can see.
[$scopeWhere, $scopeParams] = recoveryListScope($role, $user_id, $client_row_id);
$scopeSql = implode(' AND ', $scopeWhere);
$opt = function (string $col) use ($db, $scopeSql, $scopeParams): array {
    $st = $db->prepare("SELECT DISTINCT $col AS v " . RECOVERY_LIST_FROM . " WHERE $scopeSql AND $col IS NOT NULL AND $col <> '' ORDER BY v ASC");
    $st->execute($scopeParams);
    return $st->fetchAll(PDO::FETCH_COLUMN);
};
$types_opt = $opt('st.type_name');
$places_opt = $opt('p.port_name');
$clients_opt = $role === 'Client' ? [] : $opt('c.company_name');
$st = $db->prepare("SELECT DATE_FORMAT(s.survey_completed_date, '%Y-%m') AS m, COUNT(*) AS n " . RECOVERY_LIST_FROM . " WHERE $scopeSql AND s.survey_completed_date IS NOT NULL GROUP BY m ORDER BY m DESC");
$st->execute($scopeParams);
$months_opt = array_filter($st->fetchAll(PDO::FETCH_KEY_PAIR), fn($k) => preg_match('/^\d{4}-\d{2}$/', (string)$k) && strpos((string)$k, '0000') !== 0, ARRAY_FILTER_USE_KEY);
$surveyors_opt = $is_full_access ? $db->query("SELECT id, full_name, status FROM users WHERE role_id = 2 ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC) : [];

// Totals over every matching vessel (all pages).
$st = $db->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(s.vlsfo_recovery),0) AS vlsfo, COALESCE(SUM(s.lsmgo_recovery),0) AS lsmgo,
    COALESCE(SUM(s.hsfo_recovery),0) AS hsfo, COALESCE(SUM(s.recovery_amount),0) AS total " . RECOVERY_LIST_FROM . " WHERE $whereSql");
$st->execute($params);
$tot = $st->fetch(PDO::FETCH_ASSOC);

$perPage = 50;
$totalRows = (int)$tot['n'];
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min(max(1, (int)($_GET['page'] ?? 1)), $totalPages);
$offset = ($page - 1) * $perPage;
$st = $db->prepare("SELECT " . RECOVERY_LIST_COLUMNS . " " . RECOVERY_LIST_FROM . " WHERE $whereSql ORDER BY $orderSql LIMIT $perPage OFFSET $offset");
$st->execute($params);
$rows = array_map(fn($r) => recoveryListRow($db, $r), $st->fetchAll(PDO::FETCH_ASSOC));

$has_filters = recoveryListQs($f) !== '';
$mt = fn($v) => number_format((float)$v, 3);
$cell = fn($v) => $v !== null ? number_format($v, 3) : '—';

include 'includes/header.php';
?>
<style>
.rc-wrap { padding: 0 16px 24px; max-width: 1400px; margin: 0 auto; }
.rc-cards { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin: 12px 0; }
.rc-card { background: #fff; border: 1px solid var(--border-color); border-radius: 14px; padding: 12px 14px; }
.rc-card-l { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .3px; }
.rc-card-v { font-size: 18px; font-weight: 800; color: #0f172a; margin-top: 2px; }
.rc-card-v.rc-main { color: #4B2FD6; }
.rc-filters { background: #fff; border: 1px solid var(--border-color); border-radius: 14px; padding: 12px; display: grid; grid-template-columns: 1fr; gap: 8px; }
.rc-filters input, .rc-filters select { width: 100%; min-width: 0; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 7px 10px; font-size: 13px; background: #F8FAFC; color: #0f172a; }
.rc-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.rc-btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 10px; font-size: 13px; font-weight: 700; text-decoration: none; border: 1.5px solid transparent; cursor: pointer; }
.rc-btn-apply { background: #4B2FD6; color: #fff; }
.rc-btn-clear { background: #fff; color: #475569; border-color: #e2e8f0; }
.rc-btn-dl { background: #ECFDF5; color: #047857; border-color: #A7F3D0; margin-left: auto; }
.rc-btn-dl:hover { background: #047857; color: #fff; }
.rc-table-wrap { background: #fff; border: 1px solid var(--border-color); border-radius: 14px; margin-top: 12px; overflow-x: auto; }
.rc-table { width: 100%; border-collapse: collapse; font-size: 12.5px; white-space: nowrap; }
.rc-table th { background: #F8FAFC; color: #475569; font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 9px 10px; border-bottom: 1px solid #e2e8f0; text-align: left; }
.rc-table td { padding: 8px 10px; border-bottom: 1px solid #f1f5f9; color: #0f172a; }
.rc-table .num { text-align: right; }
.rc-table tbody tr:hover { background: #F8FAFC; }
.rc-table a { color: #0f172a; font-weight: 700; text-decoration: none; }
.rc-table a:hover { color: #4B2FD6; }
.rc-table tfoot td { font-weight: 800; background: #F8FAFC; border-top: 2px solid #cbd5e1; }
.rc-na { font-weight: 700; color: #64748b; text-align: right; }
.rc-st { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; }
.rc-st-c { background: #DCFCE7; color: #166534; }
.rc-st-p { background: #FFEDD5; color: #9A3412; }
.rc-pages { display: flex; gap: 6px; justify-content: center; flex-wrap: wrap; margin-top: 12px; }
.rc-pages a, .rc-pages span { padding: 5px 11px; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 12.5px; font-weight: 700; color: #475569; text-decoration: none; background: #fff; }
.rc-pages .active { background: #4B2FD6; color: #fff; border-color: #4B2FD6; }
.rc-empty { text-align: center; padding: 40px 16px; color: #94a3b8; }
.rc-note { font-size: 11.5px; color: #94a3b8; margin-top: 8px; }
@media (min-width: 768px) {
    .rc-cards { grid-template-columns: repeat(5, 1fr); }
    .rc-filters { grid-template-columns: 2fr repeat(3, 1fr); }
    .rc-actions { grid-column: 1 / -1; }
}
@media (min-width: 1200px) {
    .rc-filters { grid-template-columns: 2fr repeat(4, 1fr); }
}
</style>
<div class="scroll-content" data-testid="recovery-page">
    <?php $page_title = 'Recovery'; $back_url = 'index.php'; $page_testid = 'recovery'; include 'includes/top_app_bar.php'; ?>

    <div class="rc-wrap">
        <div class="rc-cards">
            <div class="rc-card"><div class="rc-card-l">Vessels</div><div class="rc-card-v" data-testid="rc-count"><?= $totalRows ?></div></div>
            <div class="rc-card"><div class="rc-card-l">Total Recovery</div><div class="rc-card-v rc-main" data-testid="rc-total"><?= $mt($tot['total']) ?> MT</div></div>
            <div class="rc-card"><div class="rc-card-l">VLSFO</div><div class="rc-card-v" data-testid="rc-vlsfo"><?= $mt($tot['vlsfo']) ?> MT</div></div>
            <div class="rc-card"><div class="rc-card-l">LSMGO</div><div class="rc-card-v" data-testid="rc-lsmgo"><?= $mt($tot['lsmgo']) ?> MT</div></div>
            <div class="rc-card"><div class="rc-card-l">HSFO</div><div class="rc-card-v" data-testid="rc-hsfo"><?= $mt($tot['hsfo']) ?> MT</div></div>
        </div>

        <form method="get" class="rc-filters" data-testid="rc-filters" id="rcFilters">
            <input type="search" name="q" value="<?= sanitize($f['q']) ?>" placeholder="Search vessel, report no, client, port, surveyor…" data-testid="rc-q">
            <select name="month" data-testid="rc-month" aria-label="Month (Survey Completed Date)">
                <option value="">All months</option>
                <?php foreach ($months_opt as $m => $n): ?>
                <option value="<?= sanitize($m) ?>"<?= $m === $f['month'] ? ' selected' : '' ?>><?= date('F Y', strtotime($m . '-01')) ?> (<?= (int)$n ?>)</option>
                <?php endforeach; ?>
            </select>
            <select name="status" data-testid="rc-status" aria-label="Status">
                <option value="">All statuses</option>
                <option value="Pending Report"<?= $f['status'] === 'Pending Report' ? ' selected' : '' ?>>Report Pending</option>
                <option value="Completed"<?= $f['status'] === 'Completed' ? ' selected' : '' ?>>Completed</option>
            </select>
            <select name="type" data-testid="rc-type" aria-label="Survey type">
                <option value="">All survey types</option>
                <?php foreach ($types_opt as $v): ?><option value="<?= sanitize($v) ?>"<?= $v === $f['type'] ? ' selected' : '' ?>><?= sanitize($v) ?></option><?php endforeach; ?>
            </select>
            <select name="place" data-testid="rc-place" aria-label="Survey place">
                <option value="">All survey places</option>
                <?php foreach ($places_opt as $v): ?><option value="<?= sanitize($v) ?>"<?= $v === $f['place'] ? ' selected' : '' ?>><?= sanitize($v) ?></option><?php endforeach; ?>
            </select>
            <?php if ($role !== 'Client'): ?>
            <select name="client" data-testid="rc-client" aria-label="Client">
                <option value="">All clients</option>
                <?php foreach ($clients_opt as $v): ?><option value="<?= sanitize($v) ?>"<?= $v === $f['client'] ? ' selected' : '' ?>><?= sanitize($v) ?></option><?php endforeach; ?>
            </select>
            <?php endif; ?>
            <?php if ($is_full_access): ?>
            <select name="surveyor" data-testid="rc-surveyor" aria-label="Surveyor">
                <option value="">All surveyors</option>
                <?php foreach ($surveyors_opt as $sv): ?><option value="<?= (int)$sv['id'] ?>"<?= (int)$sv['id'] === $f['surveyor'] ? ' selected' : '' ?>><?= sanitize($sv['full_name']) ?><?= $sv['status'] === 'Inactive' ? ' (Inactive)' : '' ?></option><?php endforeach; ?>
            </select>
            <?php endif; ?>
            <select name="sort" data-testid="rc-sort" aria-label="Sort">
                <option value="newest"<?= $f['sort'] === 'newest' ? ' selected' : '' ?>>Survey completed — newest</option>
                <option value="oldest"<?= $f['sort'] === 'oldest' ? ' selected' : '' ?>>Survey completed — oldest</option>
                <option value="recovery-desc"<?= $f['sort'] === 'recovery-desc' ? ' selected' : '' ?>>Recovery — highest</option>
                <option value="recovery-asc"<?= $f['sort'] === 'recovery-asc' ? ' selected' : '' ?>>Recovery — lowest</option>
                <option value="name-asc"<?= $f['sort'] === 'name-asc' ? ' selected' : '' ?>>Vessel name A–Z</option>
            </select>
            <div class="rc-actions">
                <button type="submit" class="rc-btn rc-btn-apply" data-testid="rc-apply"><i class="fa-solid fa-filter"></i> Apply</button>
                <?php if ($has_filters): ?><a href="recovery.php" class="rc-btn rc-btn-clear" data-testid="rc-clear"><i class="fa-solid fa-rotate-left"></i> Clear Filters</a><?php endif; ?>
                <a href="export_recovery.php?<?= sanitize(recoveryListQs($f, ['full' => 1])) ?>" class="rc-btn rc-btn-dl" data-testid="rc-download"><i class="fa-solid fa-file-excel"></i> Download Excel</a>
            </div>
        </form>

        <div class="rc-table-wrap">
            <?php if (!$rows): ?>
                <div class="rc-empty" data-testid="rc-empty">No vessels match these filters.</div>
            <?php else: ?>
            <table class="rc-table" data-testid="rc-table">
                <thead>
                    <tr>
                        <th>#</th><th>Vessel Name</th><th>Report No</th><th>Client</th><th>Port</th><th>Survey Type</th><th>Surveyor</th><th>Status</th>
                        <th>Survey Completed</th><th>Report Completed</th>
                        <th class="num">VLSFO (MT)</th><th class="num">LSMGO (MT)</th><th class="num">HSFO (MT)</th><th class="num">Total (MT)</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $i => $r): $href = ($r['status'] === 'Completed' ? 'completed_detail.php' : 'report_detail.php') . '?id=' . $r['id']; ?>
                    <tr data-testid="rc-row">
                        <td><?= $offset + $i + 1 ?></td>
                        <td><a href="<?= $href ?>"><?= sanitize($r['vessel']) ?></a></td>
                        <td><?= sanitize($r['report_no']) ?></td>
                        <td><?= sanitize($r['client']) ?></td>
                        <td><?= sanitize($r['port']) ?></td>
                        <td><?= sanitize($r['type']) ?></td>
                        <td><?= sanitize($r['surveyor']) ?></td>
                        <td><?= $r['status'] === 'Completed' ? '<span class="rc-st rc-st-c">Completed</span>' : '<span class="rc-st rc-st-p">Report Pending</span>' ?></td>
                        <td><?= sanitize($r['completed']) ?></td>
                        <td><?= sanitize($r['report']) ?></td>
                        <?php if ($r['na']): ?>
                        <td colspan="4" class="rc-na"><?= RECOVERY_NA_LABEL ?></td>
                        <?php else: ?>
                        <td class="num"><?= $cell($r['vlsfo']) ?></td>
                        <td class="num"><?= $cell($r['lsmgo']) ?></td>
                        <td class="num"><?= $cell($r['hsfo']) ?></td>
                        <td class="num fw-bold"><?= $cell($r['total']) ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="10">Total — <?= $totalRows ?> vessel<?= $totalRows === 1 ? '' : 's' ?><?= $totalPages > 1 ? ' (all pages)' : '' ?></td>
                        <td class="num"><?= $mt($tot['vlsfo']) ?></td>
                        <td class="num"><?= $mt($tot['lsmgo']) ?></td>
                        <td class="num"><?= $mt($tot['hsfo']) ?></td>
                        <td class="num" data-testid="rc-foot-total"><?= $mt($tot['total']) ?></td>
                    </tr>
                </tfoot>
            </table>
            <?php endif; ?>
        </div>

        <?php if ($totalPages > 1): ?>
        <nav class="rc-pages" data-testid="rc-pages">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <?php if ($p === $page): ?><span class="active"><?= $p ?></span>
                <?php elseif ($p === 1 || $p === $totalPages || abs($p - $page) <= 2): ?><a href="recovery.php?<?= sanitize(recoveryListQs($f, ['page' => $p])) ?>"><?= $p ?></a>
                <?php elseif (abs($p - $page) === 3): ?><span>…</span><?php endif; ?>
            <?php endfor; ?>
        </nav>
        <?php endif; ?>
        <div class="rc-note">Vessels in Pending Reports and Completed. Month = Survey Completed Date (same as the dashboard Recovery cards).</div>
    </div>
</div>
<script>
// Dropdowns apply straight away; the search box applies on Enter / Apply.
document.querySelectorAll('#rcFilters select').forEach(function (s) {
    s.addEventListener('change', function () { s.form.submit(); });
});
</script>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>

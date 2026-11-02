<?php
/**
 * Invoices (Admin). Lists the `invoices` table (includes/invoice_helpers.php) — its own records,
 * not a view of Pending Reports / Completed: a vessel registered here stays here whatever its
 * survey status becomes, and invoice status is stored only in `invoices`.
 * Actions run through ajax/invoices.php; Generate Invoice opens invoice_pdf.php.
 */
require_once 'config/config.php';
require_once 'includes/list_filters.php';
require_once 'includes/invoice_helpers.php';
checkAuth();
if (!invoiceCanManage()) { header('Location: index.php'); exit; }

$db = getDB();
invoiceEnsureTable($db);
try {
    invoiceBackfill($db); // registers any Pending Report vessel not yet here
} catch (Throwable $e) {
    error_log('invoices backfill: ' . $e->getMessage());
}

// ---------- filters (GET) ----------
// Opens on the pending invoices (every status except Completed); ?view=completed shows the rest.
[$f, $whereSql, $params] = invoiceListFilters($_GET);
$view = $f['view'];
$q = $f['q']; $fStatus = $f['status']; $fType = $f['type']; $fMonth = $f['month'];
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$from = "FROM invoices i LEFT JOIN surveys s ON s.id = i.survey_id LEFT JOIN clients c ON c.id = s.client_id
         LEFT JOIN survey_types st ON st.id = s.survey_type_id LEFT JOIN ports p ON p.id = s.port_id";
$cnt = $db->prepare("SELECT COUNT(*) $from WHERE $whereSql");
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;
$list = $db->prepare(invoiceRowSql() . " WHERE $whereSql ORDER BY " . invoiceListOrderSql($view) . " LIMIT $perPage OFFSET $offset");
$list->execute($params);
$rows = $list->fetchAll(PDO::FETCH_ASSOC);

// summary + filter choices
$byStatus = array_fill_keys(INVOICE_STATUSES, 0);
foreach ($db->query('SELECT invoice_status, COUNT(*) n FROM invoices GROUP BY invoice_status')->fetchAll(PDO::FETCH_ASSOC) as $r) $byStatus[$r['invoice_status']] = (int)$r['n'];
$allCount = array_sum($byStatus);
$pendingCount = $allCount - $byStatus['Completed'];
$types = $db->query("SELECT type_name FROM survey_types WHERE type_name <> '' ORDER BY type_name")->fetchAll(PDO::FETCH_COLUMN);
$months = $db->query("SELECT DISTINCT DATE_FORMAT(s.survey_completed_date, '%Y-%m') m FROM invoices i JOIN surveys s ON s.id = i.survey_id
    WHERE YEAR(s.survey_completed_date) > 0 ORDER BY m DESC")->fetchAll(PDO::FETCH_COLUMN);
$filtered = $q !== '' || $fStatus !== '' || $fType !== '' || $fMonth !== '';

function invQs(array $set = []): string {
    global $f;
    $base = ['view' => $f['view'] === 'completed' ? 'completed' : '', 'q' => $f['q'], 'status' => $f['status'], 'type' => $f['type'], 'month' => $f['month'], 'page' => $_GET['page'] ?? ''];
    return http_build_query(array_filter(array_merge($base, $set), fn($v) => $v !== '' && $v !== null));
}
function invPageUrl(int $p): string {
    $qs = invQs(['page' => $p > 1 ? (string)$p : '']);
    return 'invoices.php' . ($qs !== '' ? '?' . $qs : '');
}

$page_title = 'Invoices';
include 'includes/header.php';
?>
<style>
.inv-wrap { padding: 8px 20px 40px; max-width: 1400px; margin: 0 auto; }
.inv-hero { position: relative; overflow: hidden; border-radius: 20px; background: linear-gradient(120deg, #0f172a 0%, #1E1B6B 48%, #4B2FD6 100%);
    padding: 15px 26px; margin-bottom: 10px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px; box-shadow: 0 10px 28px rgba(30,27,107,.22); }
.inv-hero-bg { position: absolute; right: -22px; top: -38px; font-size: 150px; color: rgba(255,255,255,.07); pointer-events: none; line-height: 1; }
.inv-hero-title { font-size: 19px; font-weight: 800; color: #fff; position: relative; }
.inv-hero-sub { font-size: 11.5px; color: rgba(255,255,255,.62); margin-top: 2px; font-weight: 500; position: relative; }
.inv-stats { display: flex; gap: 8px; flex-wrap: wrap; position: relative; }
.inv-stat { background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.16); border-radius: 14px; padding: 7px 14px; text-align: center; min-width: 68px; text-decoration: none; }
.inv-stat .n { font-size: 17px; font-weight: 800; color: #fff; line-height: 1.1; }
.inv-stat .l { font-size: 9px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: rgba(255,255,255,.6); margin-top: 2px; white-space: nowrap; }
.inv-stat.on { background: rgba(255,255,255,.24); border-color: rgba(255,255,255,.55); box-shadow: 0 0 0 2px rgba(255,255,255,.18); }
.inv-stat:hover { background: rgba(255,255,255,.18); }
.inv-dl { display: inline-flex; align-items: center; gap: 7px; margin-left: auto; flex: none; background: linear-gradient(120deg, #14B8A6, #0D9488); color: #fff; padding: 0 16px; border-radius: 999px; font-weight: 700; font-size: 12.5px; height: 34px; text-decoration: none; box-shadow: 0 6px 16px rgba(13,148,136,.3); white-space: nowrap; }
.inv-dl:hover { color: #fff; box-shadow: 0 10px 22px rgba(13,148,136,.4); }
.inv-dl span { width: 18px; height: 18px; border-radius: 50%; background: rgba(255,255,255,.22); display: inline-flex; align-items: center; justify-content: center; font-size: 9px; }
.inv-row-out { transition: opacity .35s ease, transform .35s ease; opacity: 0; transform: translateX(16px); }
.inv-stat.cp .n { color: #FDBA74; } .inv-stat.cost .n { color: #93C5FD; } .inv-stat.other .n { color: #CBD5E1; } .inv-stat.done .n { color: #86EFAC; }
.inv-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 10px; background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 9px 14px; box-shadow: 0 2px 10px rgba(15,23,42,.05); }
.inv-search { display: flex; align-items: center; gap: 7px; background: #F8FAFC; border: 1.5px solid #e2e8f0; border-radius: 999px; padding: 0 12px; height: 34px; flex: 0 1 260px; min-width: 160px; }
.inv-search:focus-within { border-color: #A78BFA; box-shadow: 0 0 0 4px rgba(91,45,238,.12); }
.inv-search i { color: #94A3B8; font-size: 12.5px; }
.inv-search input { border: 0; background: transparent; font-size: 12.5px; flex: 1; min-width: 0; outline: none; height: 100%; }
.inv-sel { height: 34px; border: 1px solid #e2e8f0; border-radius: 999px; background: #F8FAFC; font-size: 12px; font-weight: 600; color: #334155; padding: 0 28px 0 12px; max-width: 200px; }
.inv-sel.on { border-color: #A78BFA; background-color: #F3EEFE; color: #5B2DEE; }
.inv-reset { display: inline-flex; align-items: center; gap: 5px; height: 34px; padding: 0 10px; font-size: 12px; font-weight: 600; color: #94A3B8; text-decoration: none; }
.inv-reset:hover { color: #5B2DEE; }
.inv-meta { font-size: 12.5px; color: #64748b; margin: 0 2px 6px; font-weight: 500; }
.inv-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 20px; overflow: hidden; box-shadow: 0 4px 20px rgba(15,23,42,.05); }
.inv-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.inv-table thead th { background: #F8FAFC; color: #64748b; font-weight: 700; font-size: 10.5px; letter-spacing: .06em; text-transform: uppercase; padding: 13px 10px; text-align: left; white-space: nowrap; border-bottom: 1px solid #e2e8f0; }
.inv-table tbody td { padding: 12px 10px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; color: #334155; }
.inv-table tbody tr:hover { background: #FAF8FF; }
.inv-table tbody tr:last-child td { border-bottom: none; }
.inv-vessel { display: flex; align-items: center; gap: 10px; min-width: 0; }
.inv-vessel-ic { width: 34px; height: 34px; border-radius: 11px; flex: none; background: linear-gradient(135deg, #EEF2FF, #DCE3FF); color: #4F46E5; display: flex; align-items: center; justify-content: center; font-size: 14px; }
.inv-vessel b { display: block; font-size: 13.5px; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 170px; }
.inv-sub { font-size: 11px; color: #94a3b8; font-weight: 500; }
.inv-type { display: inline-block; background: #EFF6FF; color: #1D4ED8; font-size: 10.5px; font-weight: 800; padding: 4px 10px; border-radius: 999px; text-transform: uppercase; letter-spacing: .03em; }
.inv-port { display: flex; align-items: center; gap: 7px; } .inv-port i { color: #0891B2; font-size: 12px; }
.inv-actions { display: flex; gap: 5px; flex-wrap: wrap; min-width: 150px; max-width: 260px; }
.inv-btn { display: inline-flex; align-items: center; gap: 5px; height: 28px; padding: 0 9px; border-radius: 8px; font-family: inherit; line-height: 1; font-size: 11.5px; font-weight: 700; text-decoration: none; border: 1.5px solid #e2e8f0; color: #475569; background: #fff; cursor: pointer; white-space: nowrap; }
button.inv-btn { height: 28px !important; min-height: 0 !important; padding: 0 9px !important; box-sizing: border-box; margin: 0; box-shadow: none; }
.inv-table thead th:last-child { white-space: normal; min-width: 92px; }
.inv-btn:hover { border-color: #6C4CF1; color: #6C4CF1; }
.inv-btn.gen { color: #6C4CF1; border-color: #DDD6FE; }
.inv-btn.up { color: #0086b8; border-color: #BAE6FD; }
.inv-btn.del { color: #DC2626; border-color: #FECACA; } .inv-btn.del:hover { background: #FEF2F2; border-color: #DC2626; }
.inv-btn.save { background: #6C4CF1; border-color: #6C4CF1; color: #fff; } .inv-btn.save:hover { background: #5a3ce0; color: #fff; }
.inv-btn[disabled] { opacity: .6; pointer-events: none; }
.inv-btn[hidden] { display: none; }
.inv-file { font-size: 10.5px; color: #64748b; margin-top: 4px; max-width: 240px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.inv-status { appearance: none; -webkit-appearance: none; border-radius: 999px; font-family: inherit; font-size: 11px; font-weight: 700; padding: 5px 24px 5px 10px; max-width: 190px; border: 1.5px solid; cursor: pointer;
    background-repeat: no-repeat; background-position: right 9px center; background-size: 9px;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%2364748b' stroke-width='1.6' fill='none'/%3E%3C/svg%3E"); }
.inv-status.cp { background-color: #FFF7ED; color: #C2410C; border-color: #FDBA74; }
.inv-status.cost { background-color: #EFF6FF; color: #1D4ED8; border-color: #93C5FD; }
.inv-status.other { background-color: #F1F5F9; color: #475569; border-color: #CBD5E1; }
.inv-status.done { background-color: #F0FDF4; color: #15803D; border-color: #86EFAC; }
.inv-status.static { cursor: default; padding-right: 10px; background-image: none; display: inline-flex; align-items: center; gap: 5px; }
.inv-sub.inv-no { color: #6C4CF1; font-weight: 700; }
.inv-pend { display: inline-flex; align-items: center; gap: 4px; font-size: 10.5px; font-weight: 700; color: #B45309; background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 999px; padding: 2px 8px; margin-top: 4px; }
.inv-btn.rec { color: #0E7490; border-color: #A5F3FC; }
.inv-status.changed { box-shadow: 0 0 0 3px rgba(108,76,241,.2); }
.inv-date { white-space: nowrap; color: #64748b; font-size: 12.5px; }
.inv-empty { text-align: center; padding: 50px 20px; color: #94a3b8; }
.inv-empty i { font-size: 30px; display: block; margin-bottom: 10px; opacity: .5; }
.inv-foot { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; padding: 14px 18px; background: #F8FAFC; border-top: 1px solid #e2e8f0; font-size: 12.5px; color: #64748b; }
.inv-pages { display: flex; gap: 5px; flex-wrap: wrap; }
.inv-pages a, .inv-pages span { min-width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border-radius: 10px; font-size: 12.5px; font-weight: 700; text-decoration: none; border: 1px solid #e2e8f0; background: #fff; color: #475569; padding: 0 8px; }
.inv-pages .on { background: linear-gradient(135deg, #8B7CF6, #4B2FD6); color: #fff; border-color: transparent; }
.inv-pages .off { opacity: .4; }
.inv-m { display: none; }
.inv-mcard { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 12px 14px; margin-bottom: 10px; box-shadow: 0 1px 3px rgba(15,23,42,.05); }
.inv-mcard .top { display: flex; justify-content: space-between; gap: 8px; align-items: flex-start; }
.inv-mcard .num { width: 24px; height: 24px; border-radius: 50%; background: #f1f5f9; color: #64748b; font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; flex: none; }
.inv-mcard .rows { font-size: 12.5px; color: #475569; margin: 8px 0; display: grid; gap: 3px; }
.inv-mcard .rows b { color: #0f172a; font-weight: 600; }
.inv-mcard .inv-actions { min-width: 0; margin-top: 8px; }
.swal2-popup .inv-form label { display: block; text-align: left; font-size: 12px; font-weight: 700; color: #475569; margin: 10px 0 4px; }
.swal2-popup .inv-form input, .swal2-popup .inv-form textarea { width: 100%; font-size: 13.5px; border: 1px solid #cbd5e1; border-radius: 8px; padding: 7px 10px; }
.swal2-popup .inv-form .two { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.swal2-popup .inv-form small { display: block; text-align: left; color: #64748b; font-size: 11px; margin-top: 4px; }
@media (min-width: 992px) { .top-app-bar { display: none !important; } }
@media (min-width: 992px) and (max-width: 1499.98px) {
    .inv-table thead th, .inv-table tbody td { padding-left: 8px; padding-right: 8px; }
    .inv-table thead th { white-space: normal; }
    .inv-actions { flex-direction: column; align-items: stretch; min-width: 0; max-width: 150px; }
    .inv-btn { justify-content: center; }
    .inv-vessel b { white-space: normal; max-width: 160px; }
    .inv-vessel-ic { display: none; }
    .inv-status { max-width: 160px; }
}
@media (max-width: 991.98px) {
    .inv-wrap { padding: 4px 12px 110px; }
    .inv-stats { display: grid; grid-template-columns: repeat(5, 1fr); width: 100%; gap: 6px; }
    .inv-dl { margin-left: 0; flex: 1 1 100%; justify-content: center; }
    .inv-stat { min-width: 0; padding: 6px 4px; }
    .inv-stat .l { white-space: normal; font-size: 8px; }
    .inv-d { display: none; }
    .inv-m { display: block; }
    .inv-hero { border-radius: 16px; padding: 12px 16px; }
    .inv-hero-title { font-size: 16px; }
    .inv-search { flex: 1 1 100%; }
    .inv-sel { flex: 1 1 calc(50% - 8px); max-width: none; }
}
</style>
<?php if ($view === 'completed'): ?><link rel="stylesheet" href="assets/css/invoice-airtable.css?v=<?= @filemtime(__DIR__ . '/assets/css/invoice-airtable.css') ?>"><?php endif; ?>
<style>
.inv-at .at-t tbody tr { cursor: default; }
.inv-at .at-grid { min-height: 0; }
.inv-at .at-t th.c-pk, .inv-at .at-t td.c-pk { left: 52px; }
.inv-at .at-t td[data-inv-actions] { overflow: visible; }
.inv-at .at-t td.c-pk { font-weight: 700; color: #1f2328; }
.inv-at .at-tag { display: inline-block; background: #EFF6FF; color: #1D4ED8; font-size: 10.5px; font-weight: 700; padding: 1px 8px; border-radius: 4px; text-transform: uppercase; letter-spacing: .03em; max-width: 100%; overflow: hidden; text-overflow: ellipsis; vertical-align: middle; }
.inv-at .inv-actions { display: inline-flex; gap: 6px; flex-wrap: nowrap; min-width: 0; max-width: none; flex-direction: row; align-items: center; }
.inv-at .at-ig { display: inline-flex; align-items: center; gap: 2px; border: 1px solid #e1e4e8; border-radius: 6px; padding: 0 2px 0 7px; height: 24px; background: #fff; }
.inv-at .at-ig b { font-size: 10.5px; font-weight: 700; color: #b42318; margin-right: 2px; }
.inv-at .at-ig.rec b { color: #0E7490; }
.inv-at .at-ib { width: 22px; height: 20px; border: 0; background: transparent; border-radius: 4px; color: #4b5563; display: inline-flex; align-items: center; justify-content: center; font-size: 11.5px; text-decoration: none; padding: 0; }
.inv-at .at-ib:hover { background: #f1edfe; color: #6C4CF1; }
.inv-at .at-ib.mod, .inv-at .at-ib.del { width: 26px; height: 24px; border: 1px solid #e1e4e8; background: #fff; border-radius: 6px; }
.inv-at button.at-ib { height: 24px !important; min-height: 0 !important; padding: 0 !important; margin: 0; box-shadow: none; line-height: 1; }
.inv-at .at-ib.del { color: #b42318; } .inv-at .at-ib.del:hover { background: #fef2f2; border-color: #b42318; color: #b42318; }
.inv-at .inv-btn, .inv-at button.inv-btn { height: 24px !important; padding: 0 8px !important; border-radius: 6px; font-size: 11.5px; border-width: 1px; justify-content: flex-start; }
.inv-at .at-pg a, .inv-at .at-pg span.off { height: 26px; min-width: 26px; border: 1px solid #e1e4e8; background: #fff; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; color: #374151; text-decoration: none; font-size: 12px; }
.inv-at .at-pg span.off { opacity: .4; }
.inv-at .at-t tfoot td { color: #6b7280; }
</style>
<div class="scroll-content" data-testid="invoices-page">
<?php $back_url = ''; $page_testid = 'invoices'; include 'includes/top_app_bar.php'; ?>
<div class="inv-wrap">
    <div class="inv-hero" data-testid="invoices-hero">
        <i class="fa-solid fa-file-invoice-dollar inv-hero-bg" aria-hidden="true"></i>
        <div>
            <div class="inv-hero-title">Invoices</div>
            <div class="inv-hero-sub"><?= $view === 'completed' ? $byStatus['Completed'] . ' completed invoice' . ($byStatus['Completed'] === 1 ? '' : 's') : $pendingCount . ' pending invoice' . ($pendingCount === 1 ? '' : 's') ?></div>
        </div>
        <div class="inv-stats">
            <a class="inv-stat pend<?= $view === 'pending' && $fStatus === '' ? ' on' : '' ?>" href="invoices.php" title="All pending invoices" data-testid="inv-stat-pending"><div class="n" data-count="pending"><?= $pendingCount ?></div><div class="l">Pending</div></a>
            <?php foreach (INVOICE_STATUSES as $s): if ($s === 'Completed') continue; ?>
            <a class="inv-stat <?= invoiceStatusClass($s) ?><?= $view === 'pending' && $fStatus === $s ? ' on' : '' ?>" href="invoices.php?status=<?= urlencode($s) ?>" title="Show <?= sanitize($s) ?>" data-testid="inv-stat-<?= invoiceStatusClass($s) ?>"><div class="n" data-count="<?= sanitize($s) ?>"><?= $byStatus[$s] ?></div><div class="l"><?= sanitize(str_replace('Pending for ', '', $s)) ?></div></a>
            <?php endforeach; ?>
            <a class="inv-stat done<?= $view === 'completed' ? ' on' : '' ?>" href="invoices.php?view=completed" title="Completed invoices" data-testid="inv-stat-done"><div class="n" data-count="Completed"><?= $byStatus['Completed'] ?></div><div class="l">Completed</div></a>
        </div>
    </div>

    <form class="inv-toolbar" method="get" action="invoices.php" id="invFilters" data-testid="inv-filters">
        <?php if ($view === 'completed'): ?><input type="hidden" name="view" value="completed"><?php endif; ?>
        <div class="inv-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" value="<?= sanitize($q) ?>" placeholder="Search vessel, client, port, report no…" aria-label="Search" data-testid="inv-search"></div>
        <?php if ($view === 'pending'): ?>
        <select name="status" class="inv-sel<?= $fStatus ? ' on' : '' ?>" aria-label="Invoice status" data-testid="inv-f-status">
            <option value="">All pending statuses</option>
            <?php foreach (INVOICE_STATUSES as $s): if ($s === 'Completed') continue; ?><option value="<?= sanitize($s) ?>"<?= $fStatus === $s ? ' selected' : '' ?>><?= sanitize($s) ?></option><?php endforeach; ?>
        </select>
        <?php endif; ?>
        <select name="type" class="inv-sel<?= $fType ? ' on' : '' ?>" aria-label="Survey type" data-testid="inv-f-type">
            <option value="">All survey types</option>
            <?php foreach ($types as $t): ?><option value="<?= sanitize($t) ?>"<?= $fType === $t ? ' selected' : '' ?>><?= sanitize($t) ?></option><?php endforeach; ?>
        </select>
        <select name="month" class="inv-sel<?= $fMonth ? ' on' : '' ?>" aria-label="Survey completed month" data-testid="inv-f-month">
            <option value="">Survey completed — any month</option>
            <?php foreach ($months as $m): ?><option value="<?= sanitize($m) ?>"<?= $fMonth === $m ? ' selected' : '' ?>><?= date('F Y', strtotime($m . '-01')) ?></option><?php endforeach; ?>
        </select>
        <?php if ($filtered): ?><a class="inv-reset" href="invoices.php<?= $view === 'completed' ? '?view=completed' : '' ?>" data-testid="inv-reset"><i class="fa-solid fa-rotate-left"></i> Reset filters</a><?php endif; ?>
        <?php $expQs = invQs(['page' => '']); ?>
        <a class="inv-dl" href="export_invoices.php<?= $expQs !== '' ? '?' . sanitize($expQs) : '' ?>" data-testid="inv-download"><span><i class="fa-solid fa-arrow-down"></i></span> Download</a>
    </form>
    <div class="inv-meta" data-testid="inv-meta">Showing <b><?= $total ? ($offset + 1) . '–' . ($offset + count($rows)) : 0 ?></b> of <b><?= $total ?></b> <?= $view === 'completed' ? 'completed' : 'pending' ?> invoice<?= $total === 1 ? '' : 's' ?><?= $filtered ? ' (filtered)' : '' ?><?= $view === 'completed' ? ' · in invoice number order' : ' · newest Survey Completed Date first' ?></div>

    <?php if (!$rows): ?>
    <div class="inv-card"><div class="inv-empty" data-testid="inv-empty"><i class="fa-solid fa-file-invoice"></i><?= $filtered ? 'No invoices match these filters.' : ($view === 'completed' ? 'No completed invoices yet.' : 'No pending invoices. Vessels appear here as soon as their survey is completed (documents submitted).') ?></div></div>
    <?php else: ?>
    <?php if ($view === 'completed'): ?>
    <!-- Completed: Airtable-style grid (same look as Invoice Airtable) -->
    <div class="inv-d inv-at at-page" data-testid="inv-at">
        <div class="at-shell">
        <div class="at-grid" style="max-height:none">
        <table class="at-t" data-testid="inv-table">
            <colgroup><col style="width:52px"><col style="width:180px"><col style="width:112px"><col style="width:300px"><col style="width:160px"><col style="width:170px"><col style="width:120px"><col style="width:140px"><col style="width:120px"></colgroup>
            <thead><tr>
                <th class="c-no">#</th>
                <?php foreach ([['c-pk', 'fa-hashtag', 'Invoice Number'], ['', 'fa-circle-half-stroke', 'Status'], ['', 'fa-bolt', 'Actions'], ['', 'fa-ship', 'Vessel'], ['', 'fa-building', 'Client Name'], ['', 'fa-tags', 'Survey Type'], ['', 'fa-location-dot', 'Port Name'],
                                ['', 'fa-regular fa-calendar-check', 'Survey Completed Date']] as [$cls, $ic, $lbl]): ?>
                <th class="<?= $cls ?>"><div class="th"><i class="ic <?= strpos($ic, ' ') ? $ic : 'fa-solid ' . $ic ?>"></i><span class="lbl"><?= $lbl ?></span><?= $lbl === 'Invoice Number' ? '<span class="sorti" title="Sorted by invoice number"><i class="fa-solid fa-arrow-up"></i></span>' : '' ?></div></th>
                <?php endforeach; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $i => $r):
                $json = invoiceRowJson($db, $r);
                $vessel = (string)($r['s_vessel_name'] ?? $r['vessel_name'] ?? '');
                $type = !empty($r['sid']) ? getCombinedSurveyTypeNames($db, $r['survey_type_ids'] ?? '', $r['type_name'] ?? '') : '';
                $rep = (string)($r['s_report_number'] ?? $r['report_number'] ?? '');
            ?>
            <tr data-inv='<?= sanitize(json_encode($json)) ?>' data-testid="inv-row-<?= (int)$r['id'] ?>">
                <td class="c-no"><?= $offset + $i + 1 ?></td>
                <td class="c-pk"><?php if (!empty($r['invoice_no'])): ?><span class="inv-no" data-testid="inv-no-<?= (int)$r['id'] ?>"><?= sanitize($r['invoice_no']) ?></span><?= $json['has_rec'] ? '<span class="at-kind" title="Recovery invoice ' . sanitize($r['invoice_no']) . 'B">+ Recovery</span>' : '' ?><?php else: ?><span class="muted">No number</span><?php endif; ?></td>
                <td data-inv-status></td>
                <td data-inv-actions></td>
                <td title="<?= sanitize($vessel) ?>"><?= sanitize($vessel ?: '—') ?><?php if ($rep !== ''): ?> <span class="muted" style="font-size:11px">· <?= sanitize($rep) ?></span><?php endif; ?><?php if (empty($r['sid'])): ?> <span style="color:#DC2626;font-size:11px">· survey deleted</span><?php endif; ?></td>
                <td title="<?= sanitize($r['company_name'] ?? '') ?>"><?= !empty($r['company_name']) ? sanitize($r['company_name']) : '<span class="muted">—</span>' ?></td>
                <td title="<?= sanitize($type) ?>"><?= $type !== '' ? '<span class="at-tag">' . sanitize($type) . '</span>' : '<span class="muted">—</span>' ?></td>
                <td title="<?= sanitize($r['port_name'] ?? '') ?>"><?= !empty($r['port_name']) ? sanitize($r['port_name']) : '<span class="muted">—</span>' ?></td>
                <td><?= $json['survey_completed'] !== '' ? sanitize($json['survey_completed']) : '<span class="muted">—</span>' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td class="c-no"></td><td class="c-pk"><b><?= $total ?></b> record<?= $total === 1 ? '' : 's' ?></td><td colspan="7"></td></tr></tfoot>
        </table>
        </div>
        <?php if ($pages > 1): ?>
        <div class="at-pager">
            <div class="at-count">25 per page</div>
            <div class="at-pg">
                <?= $page > 1 ? '<a href="' . sanitize(invPageUrl($page - 1)) . '" aria-label="Previous page"><i class="fa-solid fa-chevron-left"></i></a>' : '<span class="off"><i class="fa-solid fa-chevron-left"></i></span>' ?>
                <span><?= $page ?> / <?= $pages ?></span>
                <?= $page < $pages ? '<a href="' . sanitize(invPageUrl($page + 1)) . '" aria-label="Next page"><i class="fa-solid fa-chevron-right"></i></a>' : '<span class="off"><i class="fa-solid fa-chevron-right"></i></span>' ?>
            </div>
        </div>
        <?php endif; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="inv-card inv-d">
        <div style="overflow-x:auto">
        <table class="inv-table" data-testid="inv-table">
            <thead><tr><th>#</th><th>Vessel</th><th>Client Name</th><th>Survey Type</th><th>Port Name</th><th>Actions</th><th>Status</th><th>Survey Completed Date</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $i => $r):
                $json = invoiceRowJson($db, $r);
                $vessel = (string)($r['s_vessel_name'] ?? $r['vessel_name'] ?? '');
                $type = !empty($r['sid']) ? getCombinedSurveyTypeNames($db, $r['survey_type_ids'] ?? '', $r['type_name'] ?? '') : '';
                $rep = (string)($r['s_report_number'] ?? $r['report_number'] ?? '');
            ?>
            <tr data-inv='<?= sanitize(json_encode($json)) ?>' data-testid="inv-row-<?= (int)$r['id'] ?>">
                <td style="color:#94a3b8;font-weight:600"><?= $offset + $i + 1 ?></td>
                <td><div class="inv-vessel"><div class="inv-vessel-ic"><i class="fa-solid fa-ship"></i></div><div style="min-width:0"><b><?= sanitize($vessel ?: '—') ?></b><?php if ($view === 'completed' && !empty($r['invoice_no'])): ?><div class="inv-sub inv-no" data-testid="inv-no-<?= (int)$r['id'] ?>">Invoice: <?= sanitize($r['invoice_no']) ?></div><?php endif; ?><?php if ($rep !== ''): ?><div class="inv-sub">Report: <?= sanitize($rep) ?></div><?php endif; ?><?php if (empty($r['sid'])): ?><div class="inv-sub" style="color:#DC2626">Survey record deleted</div><?php endif; ?></div></div></td>
                <td style="font-weight:600"><?= sanitize($r['company_name'] ?? '—') ?></td>
                <td><?= $type !== '' ? '<span class="inv-type">' . sanitize($type) . '</span>' : '—' ?></td>
                <td><div class="inv-port"><i class="fa-solid fa-location-dot"></i><?= sanitize($r['port_name'] ?? '—') ?></div></td>
                <td data-inv-actions></td>
                <td data-inv-status></td>
                <td class="inv-date"><?= $json['survey_completed'] !== '' ? sanitize($json['survey_completed']) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if ($pages > 1): ?>
        <div class="inv-foot"><div>25 per page</div><div class="inv-pages">
            <?= $page > 1 ? '<a href="' . sanitize(invPageUrl($page - 1)) . '">‹ Prev</a>' : '<span class="off">‹ Prev</span>' ?>
            <?php for ($p = max(1, $page - 2); $p <= min($pages, max(1, $page - 2) + 4); $p++): ?>
                <?= $p === $page ? '<span class="on">' . $p . '</span>' : '<a href="' . sanitize(invPageUrl($p)) . '">' . $p . '</a>' ?>
            <?php endfor; ?>
            <?= $page < $pages ? '<a href="' . sanitize(invPageUrl($page + 1)) . '">Next ›</a>' : '<span class="off">Next ›</span>' ?>
        </div></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="inv-m">
        <?php foreach ($rows as $i => $r):
            $json = invoiceRowJson($db, $r);
            $vessel = (string)($r['s_vessel_name'] ?? $r['vessel_name'] ?? '');
            $type = !empty($r['sid']) ? getCombinedSurveyTypeNames($db, $r['survey_type_ids'] ?? '', $r['type_name'] ?? '') : '';
        ?>
        <div class="inv-mcard" data-inv='<?= sanitize(json_encode($json)) ?>' data-testid="inv-card-<?= (int)$r['id'] ?>">
            <div class="top">
                <div class="inv-vessel"><span class="num"><?= $offset + $i + 1 ?></span><div style="min-width:0"><b><?= sanitize($vessel ?: '—') ?></b><?php if ($view === 'completed' && !empty($r['invoice_no'])): ?><div class="inv-sub inv-no">Invoice: <?= sanitize($r['invoice_no']) ?></div><?php endif; ?></div></div>
                <div data-inv-status></div>
            </div>
            <div class="rows">
                <div>Client: <b><?= sanitize($r['company_name'] ?? '—') ?></b></div>
                <div>Survey Type: <b><?= sanitize($type ?: '—') ?></b></div>
                <div>Port: <b><?= sanitize($r['port_name'] ?? '—') ?></b></div>
                <div>Survey Completed: <b><?= $json['survey_completed'] !== '' ? sanitize($json['survey_completed']) : '—' ?></b></div>
            </div>
            <div data-inv-actions></div>
        </div>
        <?php endforeach; ?>
        <?php if ($pages > 1): ?>
        <div class="inv-pages" style="justify-content:center;margin:6px 0 10px">
            <?= $page > 1 ? '<a href="' . sanitize(invPageUrl($page - 1)) . '">‹ Prev</a>' : '<span class="off">‹ Prev</span>' ?>
            <span class="on"><?= $page ?> / <?= $pages ?></span>
            <?= $page < $pages ? '<a href="' . sanitize(invPageUrl($page + 1)) . '">Next ›</a>' : '<span class="off">Next ›</span>' ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
(function () {
  'use strict';
  var STATUSES = <?= json_encode(INVOICE_STATUSES) ?>;
  var CLS = { 'Pending for CP Prices': 'cp', 'Pending for Cost': 'cost', 'Pending for Other Reason': 'other', 'Completed': 'done' };
  var CSRF = <?= json_encode(csrf_token()) ?>;
  var MAX_MB = <?= (int)(invoiceMaxBytes() / 1048576) ?>;
  var API = 'ajax/invoices.php';
  var VIEW = <?= json_encode($view) ?>;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(icon, title) { Swal.fire({ toast: true, position: 'top-end', icon: icon, title: title, showConfirmButton: false, timer: 2600, timerProgressBar: true }); }
  function post(data) {
    return fetch(API, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF } })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unexpected server response (' + r.status + ').' }; }); });
  }
  function fd(obj) { var f = new FormData(); Object.keys(obj).forEach(function (k) { f.append(k, obj[k]); }); f.append('csrf_token', CSRF); return f; }

  // Every row (table row + phone card) carries its invoice JSON; one invoice can have both.
  function holders(id) { return Array.prototype.filter.call(document.querySelectorAll('[data-inv]'), function (el) { return el._inv && el._inv.id === id; }); }

  function render(el) {
    var d = el._inv, fileUrl = API + '?a=file&id=' + d.id;
    var st = el.querySelector('[data-inv-status]');
    if (VIEW === 'completed') {
      // Completed: status is fixed; View / Download (vessel + recovery), Modify, Delete only.
      st.innerHTML = el.closest('.inv-at')
        ? '<span class="at-pill paid" data-testid="inv-status-' + d.id + '">Completed</span>'
        : '<span class="inv-status done static" data-testid="inv-status-' + d.id + '"><i class="fa-solid fa-circle-check"></i> Completed</span>';
      var c = '';
      if (el.closest('.inv-at')) {
        // Airtable grid: compact icon actions (labels in tooltips)
        var ib = function (cls, href, icon, label, tid, extra) {
          return '<a class="at-ib ' + cls + '" href="' + href + '"' + (extra || '') + ' title="' + label + '" aria-label="' + label + '" data-testid="' + tid + '"><i class="fa-solid ' + icon + '"></i></a>';
        };
        if (d.has_file) c += '<span class="at-ig"><b>Invoice</b>' + ib('', fileUrl + '&mode=view', 'fa-eye', 'View invoice', 'inv-view-' + d.id, ' target="_blank" rel="noopener"') + ib('', fileUrl + '&mode=download', 'fa-download', 'Download invoice', 'inv-download-' + d.id) + '</span>';
        if (d.has_rec) c += '<span class="at-ig rec"><b>Recovery</b>' + ib('', fileUrl + '&which=recovery&mode=view', 'fa-eye', 'View recovery invoice', 'inv-rec-view-' + d.id, ' target="_blank" rel="noopener"') + ib('', fileUrl + '&which=recovery&mode=download', 'fa-download', 'Download recovery invoice', 'inv-rec-download-' + d.id) + '</span>';
        c += ib('mod', 'invoice_pdf.php?id=' + d.id, 'fa-pen-to-square', 'Modify', 'inv-modify-' + d.id) +
          '<button type="button" class="at-ib del" data-act="delete" title="Delete" aria-label="Delete" data-testid="inv-delete-' + d.id + '"><i class="fa-solid fa-trash"></i></button>';
        el.querySelector('[data-inv-actions]').innerHTML = '<div class="inv-actions at-acts">' + c + '</div>';
        return;
      }
      if (d.has_file) {
        c += '<a class="inv-btn" href="' + fileUrl + '&mode=view" target="_blank" rel="noopener" data-testid="inv-view-' + d.id + '"><i class="fa-solid fa-eye"></i> View</a>' +
          '<a class="inv-btn" href="' + fileUrl + '&mode=download" data-testid="inv-download-' + d.id + '"><i class="fa-solid fa-download"></i> Download</a>';
      }
      if (d.has_rec) {
        c += '<a class="inv-btn rec" href="' + fileUrl + '&which=recovery&mode=view" target="_blank" rel="noopener" data-testid="inv-rec-view-' + d.id + '"><i class="fa-solid fa-eye"></i> View Recovery</a>' +
          '<a class="inv-btn rec" href="' + fileUrl + '&which=recovery&mode=download" data-testid="inv-rec-download-' + d.id + '"><i class="fa-solid fa-download"></i> Download Recovery</a>';
      }
      c += '<a class="inv-btn gen" href="invoice_pdf.php?id=' + d.id + '" data-testid="inv-modify-' + d.id + '"><i class="fa-solid fa-pen-to-square"></i> Modify</a>' +
        '<button type="button" class="inv-btn del" data-act="delete" data-testid="inv-delete-' + d.id + '"><i class="fa-solid fa-trash"></i> Delete</button>';
      el.querySelector('[data-inv-actions]').innerHTML = '<div class="inv-actions">' + c + '</div>';
      return;
    }
    st.innerHTML = '<select class="inv-status ' + CLS[d.status] + '" aria-label="Invoice status" data-testid="inv-status-' + d.id + '">' +
      STATUSES.map(function (s) { return '<option' + (s === d.status ? ' selected' : '') + '>' + esc(s) + '</option>'; }).join('') + '</select>';
    var a = '<button type="button" class="inv-btn save" data-act="update" hidden data-testid="inv-update-' + d.id + '"><i class="fa-solid fa-floppy-disk"></i> Update</button>' +
      '<a class="inv-btn gen" href="invoice_pdf.php?id=' + d.id + (d.recovery_pending ? '&type=recovery' : '') + '" data-testid="inv-generate-' + d.id + '"><i class="fa-solid fa-file-invoice-dollar"></i> Generate Invoice</a>';
    if (d.has_file) {
      a += '<a class="inv-btn" href="' + fileUrl + '&mode=view" target="_blank" rel="noopener" data-testid="inv-view-' + d.id + '"><i class="fa-solid fa-eye"></i> View</a>' +
        '<a class="inv-btn" href="' + fileUrl + '&mode=download" data-testid="inv-download-' + d.id + '"><i class="fa-solid fa-download"></i> Download</a>' +
        '<button type="button" class="inv-btn up" data-act="edit" data-testid="inv-edit-' + d.id + '"><i class="fa-solid fa-pen"></i> Edit</button>' +
        '<button type="button" class="inv-btn del" data-act="delete" data-testid="inv-delete-' + d.id + '"><i class="fa-solid fa-trash"></i> Delete</button>';
    } else {
      a += '<button type="button" class="inv-btn up" data-act="upload" data-testid="inv-upload-' + d.id + '"><i class="fa-solid fa-upload"></i> Upload Invoice</button>';
    }
    var info = d.has_file ? '<div class="inv-file" title="' + esc(d.file_name) + '"><i class="fa-solid fa-file-pdf" style="color:#DC2626"></i> ' + esc(d.file_name) +
      (d.invoice_no ? ' · No. ' + esc(d.invoice_no) : '') + (d.uploaded_at ? ' · ' + esc(d.uploaded_at) : '') + '</div>' : '';
    if (d.recovery_pending) info += '<div class="inv-pend" data-testid="inv-rec-pending-' + d.id + '"><i class="fa-solid fa-droplet"></i> Recovery invoice pending</div>';
    el.querySelector('[data-inv-actions]').innerHTML = '<div class="inv-actions">' + a + '</div>' + info;
  }
  // Header counters (data-count="<status>" and "pending").
  function bump(status, by) {
    [status, status === 'Completed' ? null : 'pending'].forEach(function (k) {
      if (!k) return;
      var n = document.querySelector('[data-count="' + k + '"]');
      if (n) n.textContent = Math.max(0, (parseInt(n.textContent, 10) || 0) + by);
    });
  }
  function update(id, row) { holders(id).forEach(function (el) { el._inv = row; el.setAttribute('data-inv', JSON.stringify(row)); render(el); }); }

  document.querySelectorAll('[data-inv]').forEach(function (el) { el._inv = JSON.parse(el.getAttribute('data-inv')); render(el); });

  // Status: the Update button shows only while the choice differs from the saved status.
  document.addEventListener('change', function (ev) {
    var sel = ev.target.closest('.inv-status'); if (!sel) return;
    var el = sel.closest('[data-inv]'), changed = sel.value !== el._inv.status;
    sel.className = 'inv-status ' + CLS[sel.value] + (changed ? ' changed' : '');
    el.querySelector('[data-act="update"]').hidden = !changed;
  });

  function pdfForm(d, title) {
    return Swal.fire({
      title: title, width: 520, showCancelButton: true, confirmButtonText: d.has_file ? 'Save' : 'Upload', confirmButtonColor: '#6C4CF1', showLoaderOnConfirm: true,
      html: '<form class="inv-form" onsubmit="return false">' +
        '<label>Invoice PDF' + (d.has_file ? ' (choose a new file to replace ' + esc(d.file_name) + ')' : '') + '</label><input type="file" id="invFile" accept="application/pdf,.pdf" data-testid="inv-file-input"><small>PDF only, up to ' + MAX_MB + ' MB.</small>' +
        '<div class="two"><div><label>Invoice No.</label><input id="invNo" value="' + esc(d.invoice_no) + '" data-testid="inv-no-input"></div><div><label>Invoice Date</label><input type="date" id="invDate" value="' + esc(d.invoice_date) + '"></div></div>' +
        '<label>Amount (USD)</label><input id="invAmt" inputmode="decimal" value="' + esc(d.invoice_amount) + '" data-testid="inv-amount-input">' +
        '<label>Notes</label><textarea id="invNotes" rows="2">' + esc(d.invoice_notes) + '</textarea></form>',
      preConfirm: function () {
        var f = document.getElementById('invFile').files[0];
        if (!d.has_file && !f) { Swal.showValidationMessage('Choose the invoice PDF.'); return false; }
        if (f && !/\.pdf$/i.test(f.name)) { Swal.showValidationMessage('Only PDF files can be uploaded.'); return false; }
        if (f && f.size > MAX_MB * 1048576) { Swal.showValidationMessage('The PDF must be smaller than ' + MAX_MB + ' MB.'); return false; }
        var data = fd({ a: f ? 'upload' : 'meta', id: d.id, invoice_no: document.getElementById('invNo').value, invoice_date: document.getElementById('invDate').value,
          invoice_amount: document.getElementById('invAmt').value, invoice_notes: document.getElementById('invNotes').value });
        if (f) data.append('invoice_pdf', f);
        return post(data).then(function (res) { if (!res.ok) { Swal.showValidationMessage(res.error || 'Could not save.'); return false; } return res; })
          .catch(function () { Swal.showValidationMessage('Network error — please try again.'); return false; });
      },
      allowOutsideClick: function () { return !Swal.isLoading(); }
    });
  }

  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-act]'); if (!b) return;
    var el = b.closest('[data-inv]'), d = el._inv, act = b.getAttribute('data-act');
    if (act === 'update') {
      var sel = el.querySelector('.inv-status'), want = sel.value;
      if (b.disabled || want === d.status) return;
      b.disabled = true; b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving';
      post(fd({ a: 'status', id: d.id, status: want })).then(function (res) {
        if (!res.ok) throw new Error(res.error || 'Could not update the status.');
        var moved = (VIEW === 'pending') === (res.row.status === 'Completed');
        bump(d.status, -1); bump(res.row.status, +1);
        if (moved) {
          // The invoice now belongs to the other list (pending ↔ completed): take it off this one.
          holders(d.id).forEach(function (el) { el.classList.add('inv-row-out'); setTimeout(function () { el.remove(); }, 380); });
          toast('success', res.row.status === 'Completed' ? 'Marked Completed — moved to Completed invoices' : 'Moved back to pending invoices (' + res.row.status + ')');
        } else {
          update(d.id, res.row); toast('success', 'Status updated: ' + res.row.status);
        }
      }).catch(function (e) {
        update(d.id, d); // back to the saved status
        Swal.fire({ icon: 'error', title: 'Status not saved', text: e.message || 'Please try again.' });
      });
    } else if (act === 'upload' || act === 'edit') {
      pdfForm(d, act === 'upload' ? 'Upload Invoice' : 'Edit Invoice').then(function (r) {
        if (r.isConfirmed && r.value && r.value.ok) { update(d.id, r.value.row); toast('success', act === 'upload' ? 'Invoice uploaded' : (r.value.replaced ? 'Invoice replaced' : 'Invoice details saved')); }
      });
    } else if (act === 'delete') {
      var delHtml = VIEW === 'completed'
        ? 'The invoice PDF' + (d.has_rec ? 's (vessel + recovery invoice)' : '') + ' and saved details will be removed and the vessel goes back to <b>pending invoices</b> to be made again (same invoice number).<br><small>Survey records are not touched.</small>'
        : 'The uploaded PDF <b>' + esc(d.file_name) + '</b> and its invoice details will be removed.<br><small>The vessel stays in Invoices with its status and invoice number; survey records are not touched.</small>';
      Swal.fire({ icon: 'warning', title: 'Delete this invoice?', html: delHtml,
        showCancelButton: true, confirmButtonText: 'Delete', confirmButtonColor: '#DC2626', showLoaderOnConfirm: true,
        preConfirm: function () { return post(fd({ a: 'delete', id: d.id })).then(function (res) { if (!res.ok) { Swal.showValidationMessage(res.error || 'Could not delete.'); return false; } return res; }); },
        allowOutsideClick: function () { return !Swal.isLoading(); }
      }).then(function (r) {
        if (!(r.isConfirmed && r.value && r.value.ok)) return;
        if (VIEW === 'completed' && r.value.moved) {
          bump('Completed', -1); bump(r.value.row.status, +1);
          holders(d.id).forEach(function (el) { el.classList.add('inv-row-out'); setTimeout(function () { el.remove(); }, 380); });
          toast('success', 'Invoice deleted — back in pending invoices');
        } else { update(d.id, r.value.row); toast('success', 'Invoice deleted'); }
      });
    }
  });

  // Filters apply at once
  var form = document.getElementById('invFilters');
  form.querySelectorAll('select').forEach(function (s) { s.addEventListener('change', function () { form.submit(); }); });
})();
</script>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>

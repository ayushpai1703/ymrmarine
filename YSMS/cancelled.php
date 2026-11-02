<?php
/**
 * Cancelled Vessels — the Invoice Airtable grid (assets/css/invoice-airtable.css): summary
 * cards, survey-type quick filters, Hide fields, Filter, Sort, Export, search, resizable /
 * movable columns saved per user, paging, row selection and a record panel. Rows load from
 * ajax/cancelled_list.php (assets/js/cancelled-grid.js); visibility per role is in
 * includes/cancelled_list.php. Read-only: View / Edit open vessel_detail.php as before.
 */
require_once 'config/config.php';
require_once 'includes/cancelled_list.php';
checkAuth();

$db = getDB();
$role = (string)($_SESSION['role'] ?? '');
$showSurveyor = cancelledShowsSurveyor();

// filter choices from the cancelled vessels this user can see
[$scope, $sp] = cancelledScope($db);
$opt = $db->prepare('SELECT DISTINCT s.id, c.company_name, p.port_name, u.full_name AS surveyor_name ' . CANCELLED_FROM . " WHERE s.status = 'Cancelled' AND $scope");
$opt->execute($sp);
$clients = $ports = $surveyors = [];
foreach ($opt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (!empty($r['company_name'])) $clients[$r['company_name']] = true;
    if (!empty($r['port_name'])) $ports[$r['port_name']] = true;
    if ($showSurveyor) foreach (listFilterSplitNames(getCombinedSurveyorNames($db, $r['id'], $r['surveyor_name'] ?? '')) as $n) $surveyors[$n] = true;
}
foreach ([&$clients, &$ports, &$surveyors] as &$list) { $list = array_keys($list); natcasesort($list); $list = array_values($list); }
unset($list);
$types = $db->query("SELECT type_name FROM survey_types WHERE type_name <> '' ORDER BY type_name")->fetchAll(PDO::FETCH_COLUMN);
$init = ['q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100)];
foreach (['type', 'place', 'client', 'surveyor'] as $k) $init[$k] = listFilterFirst($_GET, $k);

$page_title = 'Cancelled Vessels';
include 'includes/header.php';
?>
<link rel="stylesheet" href="assets/css/invoice-airtable.css?v=<?= @filemtime(__DIR__ . '/assets/css/invoice-airtable.css') ?>">
<style>
/* Cancelled grid extras (everything else is the Invoice Airtable stylesheet) */
.at-sum.cn { grid-template-columns: repeat(<?= $showSurveyor ? 6 : 5 ?>, minmax(0, 1fr)); }
.at-sum-i.grey::before { background: #64748B; } .at-sum-i.grey .v { color: #475569; }
.at-chip .cnt { font-size: 10.5px; font-weight: 700; opacity: .75; }
.at-dot.cn { background: #DC2626; } .at-dot.ty { background: #2563EB; }
.at-pill.cancelled-st { background: #fee2e2; color: #b91c1c; }
.at-tag { display: inline-block; background: #EFF6FF; color: #1D4ED8; font-size: 10.5px; font-weight: 700; padding: 1px 8px; border-radius: 4px; text-transform: uppercase; letter-spacing: .03em; max-width: 100%; overflow: hidden; text-overflow: ellipsis; vertical-align: middle; }
.at-act a { border: 1px solid var(--at-line); background: #fff; border-radius: 6px; height: 24px; padding: 0 7px; font-size: 11.5px; font-weight: 600; color: #4b5563; display: inline-flex; align-items: center; gap: 4px; text-decoration: none; }
.at-act a:hover { border-color: var(--at-acc); color: var(--at-acc); }
.at-act a.edit { color: #C2410C; } .at-act a.edit:hover { border-color: #C2410C; color: #C2410C; }
.at-t td.c-pk a.vn { color: #1f2328; text-decoration: none; } .at-t td.c-pk a.vn:hover { color: var(--at-acc); text-decoration: underline; }
.at-sv { display: inline-flex; align-items: center; gap: 6px; } .at-sv i { width: 20px; height: 20px; border-radius: 50%; background: #eef2ff; color: #4f46e5; font-style: normal; font-size: 9.5px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; flex: none; }
@media (max-width: 1399.98px) { .at-sum.cn { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 991.98px) { .at-sum.cn { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
<div class="scroll-content at-page" data-testid="cancelled-vessels-page">
<?php $back_url = ''; $page_testid = 'cancelled-vessels'; include 'includes/top_app_bar.php'; ?>
<div class="at-wrap">
    <div class="at-head" data-testid="cancelled-hero">
        <div class="at-title"><span class="at-logo" style="background:linear-gradient(135deg,#F87171,#B91C1C);box-shadow:0 4px 12px rgba(185,28,28,.25)"><i class="fa-solid fa-ban"></i></span>
            <div><h1>Cancelled Vessels</h1><div class="at-sub">Cancelled surveys · <span class="at-view"><i class="fa-solid fa-table-list"></i> Grid view</span></div></div>
        </div>
    </div>

    <div class="at-sum cn" data-testid="cn-summary">
        <div class="at-sum-i red"><div class="l">Cancelled Vessels</div><div class="v" data-sum="vessels">—</div><div class="s">matching the filters</div></div>
        <div class="at-sum-i blue"><div class="l">Clients</div><div class="v" data-sum="clients">—</div><div class="s">with cancelled vessels</div></div>
        <div class="at-sum-i purple"><div class="l">Ports</div><div class="v" data-sum="ports">—</div><div class="s">survey places</div></div>
        <div class="at-sum-i amber"><div class="l">Survey Types</div><div class="v" data-sum="types">—</div><div class="s">kinds of survey</div></div>
        <div class="at-sum-i grey"><div class="l">Agents</div><div class="v" data-sum="agents">—</div><div class="s">named agents</div></div>
        <?php if ($showSurveyor): ?><div class="at-sum-i green"><div class="l">Surveyors</div><div class="v" data-sum="surveyors">—</div><div class="s">assigned</div></div><?php endif; ?>
    </div>

    <div class="at-shell">
        <div class="at-bar" data-testid="cn-toolbar">
            <div class="at-bar-l">
                <button type="button" class="at-tb" data-pop="fields" data-testid="at-btn-fields"><i class="fa-regular fa-eye-slash"></i> <span>Hide fields</span><b class="at-n" data-n="fields"></b></button>
                <button type="button" class="at-tb" data-pop="filter" data-testid="at-btn-filter"><i class="fa-solid fa-filter"></i> <span>Filter</span><b class="at-n" data-n="filter"></b></button>
                <button type="button" class="at-tb" data-pop="sort" data-testid="at-btn-sort"><i class="fa-solid fa-arrow-down-wide-short"></i> <span>Sort</span><b class="at-n" data-n="sort"></b></button>
                <button type="button" class="at-tb" data-pop="export" data-testid="at-btn-export"><i class="fa-solid fa-file-export"></i> <span>Export</span></button>
                <button type="button" class="at-tb at-clear" data-act="clear" hidden data-testid="cn-clear"><i class="fa-solid fa-xmark"></i> Clear filters</button>
            </div>
            <label class="at-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" placeholder="Search vessel, client, agent, port, report no…" aria-label="Search" data-testid="cn-search"></label>
        </div>
        <div class="at-quick" data-testid="cn-quick"><span data-chips></span><span class="at-sel-info" data-sel-info hidden></span></div>

        <div class="at-pop" data-popbox="fields" hidden data-testid="at-pop-fields"><div class="at-pop-h">Fields <small>drag to reorder</small></div><div class="at-fields" data-fields></div>
            <div class="at-pop-f"><button type="button" class="at-lnk" data-fields-all="1">Show all</button><button type="button" class="at-lnk" data-fields-all="0">Hide all</button></div></div>
        <div class="at-pop wide" data-popbox="filter" hidden data-testid="at-pop-filter">
            <div class="at-pop-h">Filter cancelled vessels</div>
            <div class="at-fgrid">
                <label>Survey type<select data-f="type" data-testid="cn-f-type"><option value="">All survey types</option><?php foreach ($types as $t): ?><option><?= sanitize($t) ?></option><?php endforeach; ?></select></label>
                <label>Port<select data-f="place" data-testid="cn-f-place"><option value="">All ports</option><?php foreach ($ports as $v): ?><option><?= sanitize($v) ?></option><?php endforeach; ?></select></label>
                <label>Client<select data-f="client" data-testid="cn-f-client"><option value="">All clients</option><?php foreach ($clients as $v): ?><option><?= sanitize($v) ?></option><?php endforeach; ?></select></label>
                <?php if ($showSurveyor): ?><label>Surveyor<select data-f="surveyor" data-testid="cn-f-surveyor"><option value="">All surveyors</option><?php foreach ($surveyors as $v): ?><option><?= sanitize($v) ?></option><?php endforeach; ?></select></label><?php endif; ?>
                <label>Agent<input data-f="agent" placeholder="Agent name contains…" data-testid="cn-f-agent"></label>
                <label>Date from<input type="date" data-f="from" data-testid="cn-f-from"></label>
                <label>to<input type="date" data-f="to" data-testid="cn-f-to"></label>
            </div>
            <div class="at-pop-f"><button type="button" class="at-lnk" data-act="clear">Clear filters</button></div>
        </div>
        <div class="at-pop wide" data-popbox="sort" hidden data-testid="at-pop-sort"><div class="at-pop-h">Sort by <small>Shift + click a column header adds a level</small></div><div data-sorts></div>
            <div class="at-pop-f"><button type="button" class="at-lnk" data-sort-add data-testid="at-sort-add"><i class="fa-solid fa-plus"></i> Add another sort</button></div></div>
        <div class="at-pop" data-popbox="export" hidden data-testid="at-pop-export">
            <div class="at-pop-h">Export to Excel</div>
            <button type="button" class="at-menu" data-export="filtered" data-testid="cn-export"><i class="fa-solid fa-filter"></i> This list <small>(search + filters)</small></button>
            <button type="button" class="at-menu" data-export="selected" data-testid="cn-export-selected"><i class="fa-regular fa-square-check"></i> Selected vessels <small data-sel-count>(0)</small></button>
            <button type="button" class="at-menu" data-export="all" data-testid="cn-export-all"><i class="fa-solid fa-table"></i> All cancelled vessels</button>
        </div>

        <div class="at-grid" data-grid data-testid="cn-grid">
            <table class="at-t" data-testid="cn-table"><colgroup data-cols></colgroup><thead data-head></thead><tbody data-body></tbody><tfoot data-foot></tfoot></table>
            <div class="at-empty" data-empty hidden data-testid="cn-empty"><i class="fa-solid fa-ban"></i><div>No cancelled vessels match.</div></div>
        </div>
        <div class="at-pager" data-testid="cn-pager">
            <div data-count-text class="at-count" data-testid="cn-meta">Loading…</div>
            <div class="at-pg">
                <select data-per aria-label="Rows per page"><option>25</option><option selected>50</option><option>100</option><option>200</option></select>
                <button type="button" data-pg="-1" aria-label="Previous page" data-testid="cn-prev"><i class="fa-solid fa-chevron-left"></i></button>
                <span data-pginfo>1 / 1</span>
                <button type="button" data-pg="1" aria-label="Next page" data-testid="cn-next"><i class="fa-solid fa-chevron-right"></i></button>
            </div>
        </div>
    </div>
</div>

<div class="at-scrim" data-scrim hidden></div>
<aside class="at-panel" data-panel aria-hidden="true" data-testid="cn-panel">
    <div class="at-p-head"><div><div class="at-p-kind">Cancelled vessel</div><h2 data-p-title>Vessel</h2></div>
        <div class="at-p-hr"><span class="at-pill cancelled-st">Cancelled</span><button type="button" class="at-x" data-close aria-label="Close" data-testid="cn-panel-close"><i class="fa-solid fa-xmark"></i></button></div></div>
    <div class="at-p-body" data-p-body></div>
</aside>
</div>

<script>
window.CN_CONFIG = <?= json_encode([
    'api' => 'ajax/cancelled_list.php',
    'export' => 'export_cancelled.php',
    'csrf' => csrf_token(),
    'canEdit' => $role === 'Admin',
    'showSurveyor' => $showSurveyor,
    'prefs' => listGridPrefsLoad($db, 'cancelled') ?: new stdClass(),
    'init' => $init,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="assets/js/cancelled-grid.js?v=<?= @filemtime(__DIR__ . '/assets/js/cancelled-grid.js') ?>"></script>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>

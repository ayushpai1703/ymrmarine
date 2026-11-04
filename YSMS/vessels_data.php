<?php
/**
 * Vessels Data — every vessel record (Pending Vessels, Pending Reports, Completed, Cancelled) in
 * one editable grid. An ADDITIONAL module: the existing pages stay as they are and read the same
 * surveys rows. Grid: assets/js/vessels-data.js + assets/css/invoice-airtable.css (the Invoice
 * Airtable look) + assets/css/vessels-data.css. Data and saves: ajax/vessels_data.php. Rules and
 * visibility: includes/vessels_data.php.
 */
require_once 'config/config.php';
require_once 'includes/vessels_data.php';
checkAuth();
if (!vdCanView()) { header('Location: index.php'); exit; }

$db = getDB();
$canEdit = vdCanEdit();
$full = vdFullAccess();
$opts = vdOptions($db);

// filter choices from the vessels this user can see
[$scope, $sp] = vdScope();
$distinct = function (string $expr) use ($db, $scope, $sp): array {
    $q = $db->prepare("SELECT DISTINCT $expr v " . VD_FROM . " WHERE $scope AND $expr IS NOT NULL AND $expr <> '' ORDER BY v");
    $q->execute($sp);
    return $q->fetchAll(PDO::FETCH_COLUMN);
};
$clients = $distinct('c.company_name');
$ports = $distinct('p.port_name');
$types = $db->query("SELECT type_name FROM survey_types WHERE type_name <> '' ORDER BY type_name")->fetchAll(PDO::FETCH_COLUMN);
$surveyors = $full ? array_column($opts['surveyors'], 'name') : [];
if ($full) $surveyors[] = VD_OUTSOURCING_LABEL;

$init = [];
foreach (['q', 'vessel', 'report_no', 'agent', 'imo', 'a_from', 'a_to', 'c_from', 'c_to', 'r_min', 'r_max'] as $k) if (isset($_GET[$k]) && is_scalar($_GET[$k])) $init[$k] = mb_substr(trim((string)$_GET[$k]), 0, 100);
foreach (['status', 'type', 'port', 'client', 'surveyor'] as $k) if ($v = listFilterFirst($_GET, $k)) $init[$k] = $v;

$page_title = 'Vessels Data';
include 'includes/header.php';
?>
<link rel="stylesheet" href="assets/css/invoice-airtable.css?v=<?= @filemtime(__DIR__ . '/assets/css/invoice-airtable.css') ?>">
<link rel="stylesheet" href="assets/css/vessels-data.css?v=<?= @filemtime(__DIR__ . '/assets/css/vessels-data.css') ?>">
<div class="scroll-content at-page vd-page" data-testid="vessels-data-page">
<?php $back_url = ''; $page_testid = 'vessels-data'; include 'includes/top_app_bar.php'; ?>
<div class="at-wrap">
    <div class="at-head" data-testid="vd-hero">
        <div class="at-title"><span class="at-logo vd-logo"><i class="fa-solid fa-table-list"></i></span>
            <div><h1>Vessels Data</h1><div class="at-sub"><b data-total>—</b> records · all stages in one grid<?= $canEdit ? ' · click a cell to edit' : ' · view only' ?> · <span class="vd-upd" data-updated data-testid="vd-updated">loading…</span></div></div>
        </div>
        <div class="vd-head-r">
            <button type="button" class="at-link" data-act="refresh" data-testid="vd-refresh"><i class="fa-solid fa-rotate"></i> Refresh</button>
        </div>
    </div>

    <div class="at-sum vd-sum" data-testid="vd-summary">
        <button type="button" class="at-sum-i navy" data-card="" data-testid="vd-card-total"><div class="l">Total Vessels</div><div class="v" data-sum="total">—</div><div class="s">all stages</div></button>
        <button type="button" class="at-sum-i amber" data-card="Pending Vessel" data-testid="vd-card-pv"><div class="l">Pending Vessels</div><div class="v" data-sum="Pending Vessel">—</div><div class="s">survey to do</div></button>
        <button type="button" class="at-sum-i orange" data-card="Pending Report" data-testid="vd-card-pr"><div class="l">Pending Reports</div><div class="v" data-sum="Pending Report">—</div><div class="s">report to upload</div></button>
        <button type="button" class="at-sum-i green" data-card="Completed" data-testid="vd-card-co"><div class="l">Completed</div><div class="v" data-sum="Completed">—</div><div class="s">reports uploaded</div></button>
        <button type="button" class="at-sum-i red" data-card="Cancelled" data-testid="vd-card-ca"><div class="l">Cancelled</div><div class="v" data-sum="Cancelled">—</div><div class="s">cancelled surveys</div></button>
        <div class="at-sum-i blue" data-testid="vd-card-rec"><div class="l">Total Recovery</div><div class="v" data-sum="recovery">—</div><div class="s" data-sum-sub="recovery">MT · Pending Reports + Completed</div></div>
    </div>

    <div class="at-shell">
        <div class="at-bar" data-testid="vd-toolbar">
            <div class="at-bar-l">
                <button type="button" class="at-tb" data-pop="fields" data-testid="at-btn-fields"><i class="fa-regular fa-eye-slash"></i> <span>Columns</span><b class="at-n" data-n="fields"></b></button>
                <button type="button" class="at-tb" data-pop="filter" data-testid="at-btn-filter"><i class="fa-solid fa-filter"></i> <span>Filter</span><b class="at-n" data-n="filter"></b></button>
                <button type="button" class="at-tb" data-pop="sort" data-testid="at-btn-sort"><i class="fa-solid fa-arrow-down-wide-short"></i> <span>Sort</span><b class="at-n" data-n="sort"></b></button>
                <button type="button" class="at-tb" data-pop="export" data-testid="at-btn-export"><i class="fa-solid fa-file-excel"></i> <span>Export</span></button>
                <?php if ($canEdit): ?><button type="button" class="at-tb" data-act="import" data-testid="vd-import"><i class="fa-solid fa-file-import"></i> <span>Import</span></button><?php endif; ?>
                <button type="button" class="at-tb at-clear" data-act="clear" hidden data-testid="vd-clear"><i class="fa-solid fa-xmark"></i> Clear filters</button>
            </div>
            <label class="at-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" placeholder="Search vessel, IMO, report no, client, agent, port, surveyor, remarks…" aria-label="Search" data-testid="vd-search"></label>
        </div>
        <div class="at-quick vd-quick" data-testid="vd-quick">
            <select data-f="status" aria-label="Status" data-testid="vd-q-status"><option value="">All statuses</option><?php foreach (VD_STATUSES as $s): ?><option><?= sanitize($s) ?></option><?php endforeach; ?></select>
            <select data-f="client" aria-label="Client" data-testid="vd-q-client"><option value="">All clients</option><?php foreach ($clients as $v): ?><option><?= sanitize($v) ?></option><?php endforeach; ?></select>
            <?php if ($full): ?><select data-f="surveyor" aria-label="Surveyor" data-testid="vd-q-surveyor"><option value="">All surveyors</option><?php foreach ($surveyors as $v): ?><option><?= sanitize($v) ?></option><?php endforeach; ?></select><?php endif; ?>
            <label class="vd-dr" title="Assigned date range"><span>Assigned</span><input type="date" data-f="a_from" aria-label="Assigned from" data-testid="vd-q-afrom"><i>–</i><input type="date" data-f="a_to" aria-label="Assigned to" data-testid="vd-q-ato"></label>
            <span class="at-sel-info" data-sel-info hidden></span>
        </div>
        <?php if ($canEdit): ?>
        <div class="vd-bulk" data-bulk hidden data-testid="vd-bulk">
            <b data-bulk-n>0</b> selected
            <select data-bulk-status aria-label="New status" data-testid="vd-bulk-status"><option value="">Change status to…</option><?php foreach (VD_STATUSES as $s): ?><option><?= sanitize($s) ?></option><?php endforeach; ?></select>
            <button type="button" class="at-btn pri" data-act="bulk-apply" data-testid="vd-bulk-apply"><i class="fa-solid fa-check"></i> Apply</button>
            <button type="button" class="at-btn" data-act="bulk-export" data-testid="vd-bulk-export"><i class="fa-solid fa-file-excel"></i> Export selected</button>
            <button type="button" class="at-lnk" data-selclear>Clear selection</button>
        </div>
        <?php endif; ?>

        <div class="at-pop" data-popbox="fields" hidden data-testid="at-pop-fields"><div class="at-pop-h">Columns <small>drag to reorder</small></div><div class="at-fields" data-fields></div>
            <div class="at-pop-f"><button type="button" class="at-lnk" data-fields-all="1">Show all</button><button type="button" class="at-lnk" data-fields-all="0">Hide all</button><button type="button" class="at-lnk" data-fields-reset>Default</button></div></div>
        <div class="at-pop wide" data-popbox="filter" hidden data-testid="at-pop-filter">
            <div class="at-pop-h">Filter vessels <small>all filters apply together</small></div>
            <div class="at-fgrid vd-fgrid">
                <label>Vessel name<input data-f="vessel" placeholder="contains…" data-testid="vd-f-vessel"></label>
                <label>Report number<input data-f="report_no" placeholder="contains…" data-testid="vd-f-report"></label>
                <label>IMO number<input data-f="imo" placeholder="contains…" data-testid="vd-f-imo"></label>
                <label>Agent<input data-f="agent" placeholder="contains…" data-testid="vd-f-agent"></label>
                <label>Survey type<select data-f="type" data-testid="vd-f-type"><option value="">All survey types</option><?php foreach ($types as $t): ?><option><?= sanitize($t) ?></option><?php endforeach; ?></select></label>
                <label>Port<select data-f="port" data-testid="vd-f-port"><option value="">All ports</option><?php foreach ($ports as $v): ?><option><?= sanitize($v) ?></option><?php endforeach; ?></select></label>
                <label>Client<select data-f="client" data-testid="vd-f-client"><option value="">All clients</option><?php foreach ($clients as $v): ?><option><?= sanitize($v) ?></option><?php endforeach; ?></select></label>
                <?php if ($full): ?><label>Surveyor<select data-f="surveyor" data-testid="vd-f-surveyor"><option value="">All surveyors</option><?php foreach ($surveyors as $v): ?><option><?= sanitize($v) ?></option><?php endforeach; ?></select></label><?php endif; ?>
                <label>Status<select data-f="status" data-testid="vd-f-status"><option value="">All statuses</option><?php foreach (VD_STATUSES as $s): ?><option><?= sanitize($s) ?></option><?php endforeach; ?></select></label>
                <label>Assigned from<input type="date" data-f="a_from" data-testid="vd-f-afrom"></label>
                <label>Assigned to<input type="date" data-f="a_to" data-testid="vd-f-ato"></label>
                <label>Survey completed from<input type="date" data-f="c_from" data-testid="vd-f-cfrom"></label>
                <label>Survey completed to<input type="date" data-f="c_to" data-testid="vd-f-cto"></label>
                <label>Total recovery from (MT)<input data-f="r_min" inputmode="decimal" placeholder="min" data-testid="vd-f-rmin"></label>
                <label>Total recovery to (MT)<input data-f="r_max" inputmode="decimal" placeholder="max" data-testid="vd-f-rmax"></label>
            </div>
            <div class="at-pop-f"><button type="button" class="at-lnk" data-act="clear">Clear filters</button></div>
        </div>
        <div class="at-pop wide" data-popbox="sort" hidden data-testid="at-pop-sort"><div class="at-pop-h">Sort by <small>Shift + click a column header adds a level</small></div><div data-sorts></div>
            <div class="at-pop-f"><button type="button" class="at-lnk" data-sort-add data-testid="at-sort-add"><i class="fa-solid fa-plus"></i> Add another sort</button></div></div>
        <div class="at-pop" data-popbox="export" hidden data-testid="at-pop-export">
            <div class="at-pop-h">Export to Excel</div>
            <button type="button" class="at-menu" data-export="filtered" data-testid="vd-export"><i class="fa-solid fa-filter"></i> This list <small>(search + filters)</small></button>
            <button type="button" class="at-menu" data-export="selected" data-testid="vd-export-selected"><i class="fa-regular fa-square-check"></i> Selected vessels <small data-sel-count>(0)</small></button>
            <button type="button" class="at-menu" data-export="all" data-testid="vd-export-all"><i class="fa-solid fa-table"></i> All vessels</button>
            <?php if ($canEdit): ?><button type="button" class="at-menu" data-export="template" data-testid="vd-export-template"><i class="fa-solid fa-file-arrow-down"></i> Import template</button><?php endif; ?>
        </div>

        <div class="at-grid" data-grid data-testid="vd-grid">
            <table class="at-t vd-t" data-testid="vd-table"><colgroup data-cols></colgroup><thead data-head></thead><tbody data-body></tbody><tfoot data-foot></tfoot></table>
            <div class="at-empty" data-empty hidden data-testid="vd-empty"><i class="fa-solid fa-ship"></i><div>No vessels match.</div><small>Change the search or clear the filters.</small></div>
        </div>
        <div class="at-pager" data-testid="vd-pager">
            <div data-count-text class="at-count" data-testid="vd-meta">Loading…</div>
            <div class="at-pg">
                <select data-per aria-label="Rows per page" data-testid="vd-per"><option value="25">25</option><option value="50">50</option><option value="100">100</option><option value="250">250</option><option value="0">All</option></select>
                <button type="button" data-pg="-1" aria-label="Previous page" data-testid="vd-prev"><i class="fa-solid fa-chevron-left"></i></button>
                <span data-pginfo>1 / 1</span>
                <button type="button" data-pg="1" aria-label="Next page" data-testid="vd-next"><i class="fa-solid fa-chevron-right"></i></button>
            </div>
        </div>
    </div>
</div>

<div class="at-scrim" data-scrim hidden></div>
<aside class="at-panel" data-panel aria-hidden="true" data-testid="vd-panel">
    <div class="at-p-head"><div><div class="at-p-kind">Vessel record <span data-p-id></span></div><h2 data-p-title>Vessel</h2></div>
        <div class="at-p-hr"><span data-p-status></span><button type="button" class="at-x" data-close aria-label="Close" data-testid="vd-panel-close"><i class="fa-solid fa-xmark"></i></button></div></div>
    <div class="at-p-body" data-p-body></div>
</aside>
</div>

<script>
window.VD_CONFIG = <?= json_encode([
    'api' => 'ajax/vessels_data.php',
    'export' => 'export_vessels_data.php',
    'csrf' => csrf_token(),
    'canEdit' => $canEdit,
    'full' => $full,
    'role' => vdRole(),
    'statuses' => VD_STATUSES,
    'outsourcing' => VD_OUTSOURCING_LABEL,
    'naLabel' => RECOVERY_NA_LABEL,
    'opts' => $canEdit ? $opts : new stdClass(),
    'prefs' => listGridPrefsLoad($db, 'vessels_data') ?: new stdClass(),
    'init' => $init,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js"></script>
<script src="assets/js/vessels-data.js?v=<?= @filemtime(__DIR__ . '/assets/js/vessels-data.js') ?>"></script>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>

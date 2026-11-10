<?php
/**
 * Invoice Airtable (Admin): an Airtable-style grid for invoice payment tracking.
 *
 * Rows are the invoices of the Invoices module (uploaded or generated PDFs, vessel and
 * recovery invoices — includes/invoice_ledger.php); nothing is entered twice. Payments, due
 * dates, remarks and the activity log are kept in their own tables. Everything loads and
 * saves through ajax/invoice_ledger.php; exports through export_invoice_ledger.php.
 * Survey / vessel workflow status is never read for, or changed by, payments.
 */
require_once 'config/config.php';
require_once 'includes/invoice_ledger.php';
checkAuth();
if (!ledgerCanManage()) { header('Location: index.php'); exit; }

$db = getDB();
ledgerEnsureTables($db);
$prefs = [];
try {
    $q = $db->prepare('SELECT prefs FROM invoice_grid_prefs WHERE user_id = ?');
    $q->execute([(int)($_SESSION['user_id'] ?? 0)]);
    $prefs = json_decode((string)$q->fetchColumn(), true) ?: [];
} catch (Throwable $e) { $prefs = []; }
// filter choices: clients and ports that have invoices
$clients = $db->query("SELECT DISTINCT c.id, c.company_name FROM invoices i LEFT JOIN surveys s ON s.id = i.survey_id JOIN clients c ON c.id = " . INV_E_CLIENT . "
    WHERE i.file_path IS NOT NULL OR i.rec_file_path IS NOT NULL ORDER BY c.company_name")->fetchAll(PDO::FETCH_ASSOC);
$ports = $db->query("SELECT DISTINCT p.id, p.port_name FROM invoices i LEFT JOIN surveys s ON s.id = i.survey_id JOIN ports p ON p.id = " . INV_E_PORT . "
    WHERE i.file_path IS NOT NULL OR i.rec_file_path IS NOT NULL ORDER BY p.port_name")->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Invoice Airtable';
include 'includes/header.php';
?>
<link rel="stylesheet" href="assets/css/invoice-airtable.css?v=<?= @filemtime(__DIR__ . '/assets/css/invoice-airtable.css') ?>">
<div class="scroll-content at-page" data-testid="invoice-airtable-page">
<?php $back_url = ''; $page_testid = 'invoice-airtable'; include 'includes/top_app_bar.php'; ?>
<div class="at-wrap">
    <div class="at-head">
        <div class="at-title"><span class="at-logo"><i class="fa-solid fa-table-cells"></i></span>
            <div><h1>Invoice Airtable</h1><div class="at-sub">Invoices &amp; payments · <span class="at-view"><i class="fa-solid fa-table-list"></i> Grid view</span></div></div>
        </div>
        <a class="at-link" href="invoices.php" data-testid="at-open-invoices"><i class="fa-solid fa-file-invoice-dollar"></i> Invoices module</a>
    </div>

    <div class="at-sum" data-testid="at-summary">
        <div class="at-sum-i blue"><div class="l">Total Invoices</div><div class="v" data-sum="invoices">—</div><div class="s" data-sum-sub="invoices"></div></div>
        <div class="at-sum-i purple"><div class="l">Total Invoice Amount</div><div class="v" data-sum="amount">—</div></div>
        <div class="at-sum-i green"><div class="l">Total Received</div><div class="v" data-sum="received">—</div></div>
        <div class="at-sum-i orange"><div class="l">Total Outstanding</div><div class="v" data-sum="outstanding">—</div></div>
        <div class="at-sum-i red"><div class="l">Overdue Amount</div><div class="v" data-sum="overdue">—</div><div class="s" data-sum-sub="overdue"></div></div>
        <div class="at-sum-i amber"><div class="l">Due Soon</div><div class="v" data-sum="due_soon">—</div><div class="s" data-sum-sub="due_soon"></div></div>
    </div>

    <div class="at-shell">
        <div class="at-bar" data-testid="at-toolbar">
            <div class="at-bar-l">
                <button type="button" class="at-tb" data-pop="fields" data-testid="at-btn-fields"><i class="fa-regular fa-eye-slash"></i> <span>Hide fields</span><b class="at-n" data-n="fields"></b></button>
                <button type="button" class="at-tb" data-pop="filter" data-testid="at-btn-filter"><i class="fa-solid fa-filter"></i> <span>Filter</span><b class="at-n" data-n="filter"></b></button>
                <button type="button" class="at-tb" data-pop="sort" data-testid="at-btn-sort"><i class="fa-solid fa-arrow-down-wide-short"></i> <span>Sort</span><b class="at-n" data-n="sort"></b></button>
                <button type="button" class="at-tb" data-pop="export" data-testid="at-btn-export"><i class="fa-solid fa-file-export"></i> <span>Export</span></button>
                <button type="button" class="at-tb at-clear" data-act="clear" hidden data-testid="at-clear"><i class="fa-solid fa-xmark"></i> Clear filters</button>
            </div>
            <label class="at-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" placeholder="Search invoice no, client, vessel, port, payment ref…" aria-label="Search" data-testid="at-search"></label>
        </div>
        <div class="at-quick" data-testid="at-quick">
            <?php foreach (['' => 'All Invoices', 'Paid' => 'Paid', 'Partially Paid' => 'Partially Paid', 'Unpaid' => 'Unpaid', 'Overdue' => 'Overdue', 'Due Soon' => 'Due Soon'] as $k => $l): ?>
            <button type="button" class="at-chip<?= $k === '' ? ' on' : '' ?>" data-quick="<?= sanitize($k) ?>" data-testid="at-quick-<?= $k === '' ? 'all' : strtolower(str_replace(' ', '-', $k)) ?>"><?= $k !== '' ? '<i class="at-dot ' . strtolower(str_replace(' ', '-', $k)) . '"></i>' : '' ?><?= sanitize($l) ?></button>
            <?php endforeach; ?>
            <span class="at-sel-info" data-sel-info hidden></span>
        </div>

        <!-- popovers -->
        <div class="at-pop" data-popbox="fields" hidden data-testid="at-pop-fields"><div class="at-pop-h">Fields <small>drag to reorder</small></div><div class="at-fields" data-fields></div>
            <div class="at-pop-f"><button type="button" class="at-lnk" data-fields-all="1">Show all</button><button type="button" class="at-lnk" data-fields-all="0">Hide all</button></div></div>
        <div class="at-pop wide" data-popbox="filter" hidden data-testid="at-pop-filter">
            <div class="at-pop-h">Filter invoices</div>
            <div class="at-fgrid">
                <label>Payment status<select data-f="status" data-testid="at-f-status"><option value="">Any</option><?php foreach (LEDGER_STATUSES as $s): ?><option><?= sanitize($s) ?></option><?php endforeach; ?></select></label>
                <label>Client<select data-f="client" data-testid="at-f-client"><option value="">Any</option><?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>"><?= sanitize($c['company_name']) ?></option><?php endforeach; ?></select></label>
                <label>Vessel<input data-f="vessel" placeholder="Vessel name contains…" data-testid="at-f-vessel"></label>
                <label>Port<select data-f="port" data-testid="at-f-port"><option value="">Any</option><?php foreach ($ports as $p): ?><option value="<?= (int)$p['id'] ?>"><?= sanitize($p['port_name']) ?></option><?php endforeach; ?></select></label>
                <label>Invoice date from<input type="date" data-f="inv_from" data-testid="at-f-inv-from"></label>
                <label>to<input type="date" data-f="inv_to" data-testid="at-f-inv-to"></label>
                <label>Due date from<input type="date" data-f="due_from" data-testid="at-f-due-from"></label>
                <label>to<input type="date" data-f="due_to" data-testid="at-f-due-to"></label>
                <label>Received ≥ ($)<input inputmode="decimal" data-f="rec_min" data-testid="at-f-rec-min"></label>
                <label>Received ≤ ($)<input inputmode="decimal" data-f="rec_max"></label>
                <label>Outstanding ≥ ($)<input inputmode="decimal" data-f="out_min" data-testid="at-f-out-min"></label>
                <label>Outstanding ≤ ($)<input inputmode="decimal" data-f="out_max"></label>
            </div>
            <div class="at-pop-f"><button type="button" class="at-lnk" data-act="clear">Clear filters</button></div>
        </div>
        <div class="at-pop wide" data-popbox="sort" hidden data-testid="at-pop-sort"><div class="at-pop-h">Sort by <small>Shift + click a column header adds a level</small></div><div data-sorts></div>
            <div class="at-pop-f"><button type="button" class="at-lnk" data-sort-add data-testid="at-sort-add"><i class="fa-solid fa-plus"></i> Add another sort</button></div></div>
        <div class="at-pop" data-popbox="export" hidden data-testid="at-pop-export">
            <div class="at-pop-h">Export to Excel</div>
            <button type="button" class="at-menu" data-export="all" data-testid="at-export-all"><i class="fa-solid fa-table"></i> All invoices</button>
            <button type="button" class="at-menu" data-export="filtered" data-testid="at-export-filtered"><i class="fa-solid fa-filter"></i> Filtered invoices</button>
            <button type="button" class="at-menu" data-export="selected" data-testid="at-export-selected"><i class="fa-regular fa-square-check"></i> Selected invoices <small data-sel-count>(0)</small></button>
            <button type="button" class="at-menu" data-export="payments" data-testid="at-export-payments"><i class="fa-solid fa-money-bill-transfer"></i> Payment history <small>(filtered / selected)</small></button>
        </div>

        <div class="at-grid" data-grid data-testid="at-grid">
            <table class="at-t" data-testid="at-table"><colgroup data-cols></colgroup><thead data-head></thead><tbody data-body></tbody><tfoot data-foot></tfoot></table>
            <div class="at-empty" data-empty hidden data-testid="at-empty"><i class="fa-solid fa-file-invoice"></i><div>No invoices match.</div><small>Invoices appear here as soon as they are uploaded or generated in the Invoices module.</small></div>
        </div>
        <div class="at-pager" data-testid="at-pager">
            <div data-count class="at-count">Loading…</div>
            <div class="at-pg">
                <select data-per aria-label="Rows per page"><option>25</option><option selected>50</option><option>100</option><option>200</option></select>
                <button type="button" data-pg="-1" aria-label="Previous page" data-testid="at-prev"><i class="fa-solid fa-chevron-left"></i></button>
                <span data-pginfo>1 / 1</span>
                <button type="button" data-pg="1" aria-label="Next page" data-testid="at-next"><i class="fa-solid fa-chevron-right"></i></button>
            </div>
        </div>
    </div>
</div>

<!-- record panel -->
<div class="at-scrim" data-scrim hidden></div>
<aside class="at-panel" data-panel aria-hidden="true" data-testid="at-panel">
    <div class="at-p-head"><div><div class="at-p-kind" data-p-kind></div><h2 data-p-title>Invoice</h2></div>
        <div class="at-p-hr"><span data-p-status></span><button type="button" class="at-x" data-close aria-label="Close" data-testid="at-panel-close"><i class="fa-solid fa-xmark"></i></button></div></div>
    <div class="at-p-body" data-p-body></div>
</aside>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
window.AT_CONFIG = <?= json_encode([
    'api' => 'ajax/invoice_ledger.php',
    'export' => 'export_invoice_ledger.php',
    'invoiceFile' => 'ajax/invoices.php',
    'csrf' => csrf_token(),
    'prefs' => $prefs ?: new stdClass(),
    'modes' => LEDGER_PAY_MODES,
    'today' => date('Y-m-d'),
    'dueSoonDays' => LEDGER_DUE_SOON_DAYS,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="assets/js/invoice-airtable.js?v=<?= @filemtime(__DIR__ . '/assets/js/invoice-airtable.js') ?>"></script>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>

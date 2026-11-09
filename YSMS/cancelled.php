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

// phone list (cards): the newest cancelled vessels this user can see; the grid is the computer view
require_once 'includes/vessel_image.php';
$cnRows = [];
try {
    $q = $db->prepare('SELECT s.*, c.company_name, u.full_name AS surveyor_name, st.type_name, p.port_name, ' . CANCELLED_DATE_SQL . ' AS shown_date '
        . CANCELLED_FROM . " WHERE s.status = 'Cancelled' AND $scope ORDER BY shown_date DESC, s.id DESC LIMIT 300");
    $q->execute($sp);
    $cnRows = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('cancelled cards: ' . $e->getMessage()); }
$cnVi = [];
try { $cnVi = viStates($db, array_map(fn($r) => (string)($r['vessel_name'] ?? ''), $cnRows)); } catch (Throwable $e) {}
$cnMonth = date('Y-m');
$cnTypes = [];
foreach ($cnRows as $r) { $t = getCombinedSurveyTypeNames($db, $r['survey_type_ids'] ?? '', $r['type_name'] ?? ''); foreach (array_filter(array_map('trim', explode('+', $t))) as $x) $cnTypes[$x] = ($cnTypes[$x] ?? 0) + 1; }
ksort($cnTypes);

$page_title = 'Cancelled Vessels';
include 'includes/header.php';
?>
<link rel="stylesheet" href="assets/css/mobile-card.css?v=<?= @filemtime(__DIR__ . '/assets/css/mobile-card.css') ?>">
<link rel="stylesheet" href="assets/css/app-pages.css?v=<?= @filemtime(__DIR__ . '/assets/css/app-pages.css') ?>">
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
<div class="scroll-content at-page cn-page" data-testid="cancelled-vessels-page">
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


<div class="cnm ap-page" data-testid="cn-mobile">
    <div class="ap-band ap-red">
        <div class="ap-top"><button type="button" class="hamburger-menu-btn ap-ib ap-hb" aria-label="Open navigation menu" aria-expanded="false" data-testid="cnm-menu"><span class="hamburger-lines" aria-hidden="true"><span class="hb-line hb-line-top"></span><span class="hb-line hb-line-mid"></span><span class="hb-line hb-line-bottom"></span></span></button><div class="ap-tt"><small>Survey list</small><b>Cancelled Vessels</b></div>
            <a class="ap-ib" href="export_cancelled.php" aria-label="Export to Excel" title="Export to Excel" data-testid="cnm-export"><i class="fa-solid fa-file-excel"></i></a></div>
        <label class="ap-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" placeholder="Search vessel, client, port…" data-cnm-q data-testid="cnm-search"></label>
    </div>
    <div class="ap-chips" data-testid="cnm-chips">
        <button type="button" class="ap-chip on" data-cnm-f="all">All · <?= count($cnRows) ?></button>
        <button type="button" class="ap-chip" data-cnm-f="month">This month · <?= count(array_filter($cnRows, fn($r) => substr((string)$r['shown_date'], 0, 7) === $cnMonth)) ?></button>
        <?php foreach ($cnTypes as $t => $n): ?><button type="button" class="ap-chip" data-cnm-f="type:<?= sanitize(strtolower($t)) ?>"><?= sanitize($t) ?> · <?= (int)$n ?></button><?php endforeach; ?>
    </div>
    <div class="vessel-list-container ap-list">
    <?php foreach ($cnRows as $r):
        $nm = (string)(($r['vessel_name'] ?? '') ?: 'Vessel');
        $tp = getCombinedSurveyTypeNames($db, $r['survey_type_ids'] ?? '', $r['type_name'] ?? 'N/A');
        $dt = !empty($r['shown_date']) ? date('d M Y', strtotime((string)$r['shown_date'])) : '—';
        $k = viKey($nm); $vs = $cnVi[$k] ?? ['status' => 'unknown', 'img' => VI_DEFAULT];
        $tc = fn($x) => ($x = trim((string)$x)) === '' ? '' : ucwords(strtolower($x), " -/(.&'");
        $share = implode("\n", array_filter([$nm, 'Client: ' . (($r['company_name'] ?? '') ?: '—'), 'Type of Survey: ' . $tp, 'Port: ' . (($r['port_name'] ?? '') ?: '—'), 'Date: ' . $dt, 'Status: Cancelled']));
    ?>
        <div class="vessel-card cc-card pvz pvz-red" data-pv-badge="Cancelled" data-pv-theme="red" onclick="location.href='vessel_detail.php?id=<?= (int)$r['id'] ?>'"
             data-cnm-card data-month="<?= substr((string)$r['shown_date'], 0, 7) === $cnMonth ? '1' : '0' ?>" data-types="<?= sanitize(strtolower($tp)) ?>"
             data-search="<?= sanitize(strtolower(implode(' ', [$nm, $r['company_name'] ?? '', $r['agent_name'] ?? '', $tp, $r['port_name'] ?? '', $r['report_number'] ?? '']))) ?>" data-testid="cnm-card-<?= (int)$r['id'] ?>">
            <div class="pvz-head">
                <span class="pvz-photo<?= $vs['status'] === 'found' ? ' real' : '' ?>"><img src="<?= sanitize($vs['img']) ?>" alt="" width="64" height="54" data-vimg="<?= sanitize($k) ?>" data-vimg-st="<?= sanitize($vs['status']) ?>" onerror="this.onerror=null;this.src='<?= VI_DEFAULT ?>';this.parentNode.classList.remove('real')"></span>
                <span class="pvz-bar" aria-hidden="true"></span>
                <span class="cc-name pvz-name"><?= sanitize($nm) ?></span>
                <button type="button" class="pvz-share" data-pv-share data-share-text="<?= sanitize($share) ?>" aria-label="Share vessel details" title="Share"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15V3.5M7.5 8 12 3.5 16.5 8"/><path d="M8 11H6.5A2.5 2.5 0 0 0 4 13.5v5A2.5 2.5 0 0 0 6.5 21h11a2.5 2.5 0 0 0 2.5-2.5v-5A2.5 2.5 0 0 0 17.5 11H16"/></svg></button>
            </div>
            <div class="pvz-grid">
                <div class="pvz-i"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 17l2 2a1 1 0 0 0 3-3"/><path d="M14 14l2.5 2.5a1 1 0 0 0 3-3l-3.88-3.88a3 3 0 0 0-4.24 0l-.88.88a1 1 0 1 1-3-3l2.81-2.81a5.79 5.79 0 0 1 7.06-.87l.47.28a2 2 0 0 0 1.42.25L21 4"/><path d="M21 3l1 11h-2"/><path d="M3 3L2 14l6.5 6.5a1 1 0 1 0 3-3"/><path d="M3 4h8"/></svg><div class="pvz-t"><span class="pvz-l">Client</span><b><?= sanitize($tc($r['company_name'] ?? '')) ?: '—' ?></b></div></div>
                <div class="pvz-i"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="4" width="14" height="17" rx="2.5"/><rect x="9" y="2.5" width="6" height="3.5" rx="1"/><path d="M11 10.5h5M11 14h5M11 17.5h5"/><circle cx="8.3" cy="10.5" r=".6"/><circle cx="8.3" cy="14" r=".6"/><circle cx="8.3" cy="17.5" r=".6"/></svg><div class="pvz-t"><span class="pvz-l">Type of Survey</span><b><?= sanitize($tp) ?></b></div></div>
                <div class="pvz-i"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg><div class="pvz-t"><span class="pvz-l">Port</span><b><?= sanitize($tc($r['port_name'] ?? '')) ?: '—' ?></b></div></div>
                <div class="pvz-i"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4M9.5 13.5l5 5M14.5 13.5l-5 5"/></svg><div class="pvz-t"><span class="pvz-l">Date</span><b><?= sanitize($dt) ?></b></div></div>
            </div>
            <?php if (trim((string)($r['remarks'] ?? '')) !== ''): ?>
            <div class="cnm-rem"><b>Remarks ·</b> <?= sanitize(mb_strimwidth(trim((string)$r['remarks']), 0, 220, '…')) ?></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
        <div class="ap-empty" data-cnm-empty<?= $cnRows ? ' hidden' : '' ?> data-testid="cnm-empty"><i class="fa-solid fa-ban"></i> No cancelled vessels.</div>
    </div>
</div>
<script>
(function () {
  var q = document.querySelector('[data-cnm-q]'), f = 'all';
  function apply() {
    var t = (q && q.value || '').trim().toLowerCase(), n = 0;
    document.querySelectorAll('[data-cnm-card]').forEach(function (c) {
      var ok = (!t || (c.getAttribute('data-search') || '').indexOf(t) >= 0) && (f === 'all' || (f === 'month' ? c.getAttribute('data-month') === '1' : (c.getAttribute('data-types') || '').indexOf(f.slice(5)) >= 0));
      c.classList.toggle('cnm-hide', !ok); if (ok) n++;
    });
    var e = document.querySelector('[data-cnm-empty]'); if (e) e.hidden = n > 0;
  }
  if (q) q.addEventListener('input', apply);
  document.querySelectorAll('[data-cnm-f]').forEach(function (b) { b.addEventListener('click', function () {
    f = b.getAttribute('data-cnm-f'); document.querySelectorAll('[data-cnm-f]').forEach(function (x) { x.classList.toggle('on', x === b); }); apply(); }); });
})();
</script>
<script>window.VC_CFG = window.VC_CFG || <?= json_encode(['csrf' => csrf_token(), 'def' => VI_DEFAULT]) ?>;</script>
<script src="assets/js/vessel-cards.js?v=<?= @filemtime(__DIR__ . '/assets/js/vessel-cards.js') ?>"></script>
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

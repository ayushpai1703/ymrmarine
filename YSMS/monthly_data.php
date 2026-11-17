<?php
// Monthly Data: Appointments / Survey Completed / Recovery of one month (Admin / Super Admin).
// Read only — rows and totals from includes/monthly_data.php; sort and filter in the page,
// Download (monthly_data_export.php) sends the rows on screen (filtered, in the shown order).
require_once 'config/config.php';
require_once 'includes/monthly_data.php';
checkAuth();
if (!mdCanView()) { header('Location: index.php'); exit; }

$db = getDB();
$tab = mdTab($_GET);
[$y, $m, $start] = mdMonth($_GET);
$monthVal = sprintf('%04d-%02d', $y, $m);
$monthLabel = date('F Y', strtotime($start));
[$rows, $tot] = mdData($db, $tab, $y, $m);
$cols = mdColumns($tab);
$mt = fn($v) => number_format((float)$v, 3);

include 'includes/header.php';
?>
<style>
.md-wrap { padding: 0 16px 24px; max-width: 1400px; margin: 0 auto; }
.md-tabs { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; background: #EEF2F7; border-radius: 14px; padding: 5px; margin: 12px 0; }
.md-tab { text-align: center; padding: 9px 6px; border-radius: 10px; font-size: 13.5px; font-weight: 700; color: #475569; text-decoration: none; line-height: 1.2; }
.md-tab.on { background: #fff; color: #4B2FD6; box-shadow: 0 2px 8px rgba(15,23,42,.08); }
.md-tab:hover { color: #4B2FD6; }
.md-bar { background: #fff; border: 1px solid var(--border-color); border-radius: 14px; padding: 12px; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
.md-month { display: flex; align-items: center; gap: 6px; }
.md-month label { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .3px; }
.md-month input { border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 6px 10px; font-size: 13.5px; font-weight: 700; color: #0f172a; background: #F8FAFC; }
.md-stats { display: flex; gap: 18px; flex-wrap: wrap; margin-left: auto; }
.md-stat { text-align: right; }
.md-stat-l { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .3px; }
.md-stat-v { font-size: 20px; font-weight: 800; color: #0f172a; line-height: 1.15; }
.md-stat-v.main { color: #4B2FD6; }
.md-tools { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 10px; }
.md-tools select, .md-tools input { border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 7px 10px; font-size: 13px; background: #fff; color: #0f172a; min-width: 0; }
.md-tools input { flex: 1 1 200px; }
.md-btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 10px; font-size: 13px; font-weight: 700; border: 1.5px solid #e2e8f0; background: #fff; color: #475569; cursor: pointer; }
.md-btn-dl { background: #ECFDF5; color: #047857; border-color: #A7F3D0; }
.md-btn-dl:hover { background: #047857; color: #fff; }
.md-shown { font-size: 12px; color: #64748b; margin-top: 8px; }
.md-table-wrap { background: #fff; border: 1px solid var(--border-color); border-radius: 14px; margin-top: 8px; overflow-x: auto; -webkit-overflow-scrolling: touch; }
.md-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.md-table th { background: #F8FAFC; color: #475569; font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 0; border-bottom: 1px solid #e2e8f0; text-align: left; white-space: nowrap; }
.md-table th button { all: unset; display: flex; align-items: center; gap: 5px; padding: 10px; cursor: pointer; width: 100%; box-sizing: border-box; }
.md-table th.num button { justify-content: flex-end; }
.md-table th button:hover { color: #4B2FD6; }
.md-table th .ar { font-size: 10px; opacity: .35; }
.md-table th[aria-sort="ascending"] .ar, .md-table th[aria-sort="descending"] .ar { opacity: 1; color: #4B2FD6; }
.md-table td { padding: 9px 10px; border-bottom: 1px solid #f1f5f9; color: #0f172a; white-space: nowrap; }
/* computer: text columns wrap so the whole table fits; numbers and dates stay on one line. Phone: scrolls sideways. */
@media (min-width: 992px) { .md-table td { white-space: normal; } .md-table td.num, .md-table td.dt, .md-table td:first-child { white-space: nowrap; } .md-table th, .md-table th button { white-space: normal; } .md-table th, .md-table td { padding-left: 7px; padding-right: 7px; } .md-table th button { padding-left: 7px; padding-right: 7px; } }
.md-table td.num, .md-table th.num { text-align: right; }
.md-table td.na { color: #64748b; font-weight: 700; }
.md-table tbody tr { cursor: pointer; }
.md-table tbody tr:hover { background: #F5F3FF; }
.md-table td a { color: #0f172a; font-weight: 700; text-decoration: none; }
.md-table tbody tr:hover td a { color: #4B2FD6; }
.md-table tfoot td { font-weight: 800; background: #F8FAFC; border-top: 2px solid #cbd5e1; }
.md-st { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; background: #EEF2F7; color: #334155; }
.md-st[data-st="Completed"] { background: #DCFCE7; color: #166534; }
.md-st[data-st="Pending Report"] { background: #FFEDD5; color: #9A3412; }
.md-st[data-st="Pending Vessel"] { background: #DBEAFE; color: #1E40AF; }
.md-st[data-st="Cancelled"], .md-st[data-st="Missed"] { background: #FEE2E2; color: #991B1B; }
.md-empty { text-align: center; padding: 40px 16px; color: #94a3b8; }
.md-note { font-size: 11.5px; color: #94a3b8; margin-top: 8px; }
@media (max-width: 575.98px) {
    .md-tab { font-size: 12.5px; padding: 9px 4px; }
    .md-stats { margin-left: 0; width: 100%; justify-content: space-between; }
    .md-stat { text-align: left; }
    .md-tools select { flex: 1 1 40%; }
    .md-tools input { flex: 1 1 100%; order: -1; }
    .md-btn-dl { flex: 1 1 100%; justify-content: center; }
}
</style>

<div class="scroll-content" data-testid="monthly-data-page">
    <?php $page_title = 'Monthly Data'; $back_url = 'index.php'; $page_testid = 'monthly-data'; include 'includes/top_app_bar.php'; ?>
    <div class="md-wrap">
        <nav class="md-tabs" role="tablist" data-testid="md-tabs">
            <?php foreach (MD_TABS as $k => $label): ?>
            <a class="md-tab<?= $k === $tab ? ' on' : '' ?>" role="tab" aria-selected="<?= $k === $tab ? 'true' : 'false' ?>" href="monthly_data.php?tab=<?= $k ?>&amp;month=<?= $monthVal ?>" data-testid="md-tab-<?= $k ?>"><?= sanitize($label) ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="md-bar">
            <form method="get" class="md-month" data-testid="md-month-form">
                <input type="hidden" name="tab" value="<?= $tab ?>">
                <label for="mdMonth">Month</label>
                <input type="month" id="mdMonth" name="month" value="<?= $monthVal ?>" required onchange="this.form.submit()" data-testid="md-month">
                <noscript><button type="submit" class="md-btn">Show</button></noscript>
            </form>
            <div class="md-stats">
                <div class="md-stat"><div class="md-stat-l"><?= $tab === 'appointments' ? 'Appointments' : 'Surveys Completed' ?> · <?= sanitize($monthLabel) ?></div>
                    <div class="md-stat-v<?= $tab !== 'recovery' ? ' main' : '' ?>" data-testid="md-count"><?= (int)$tot['count'] ?></div></div>
                <?php if ($tab === 'recovery'): ?>
                <div class="md-stat"><div class="md-stat-l">Total Recovery</div><div class="md-stat-v main" data-testid="md-total"><?= $mt($tot['total']) ?> MT</div></div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($rows): ?>
        <div class="md-tools">
            <input type="search" placeholder="Filter…" data-md-q aria-label="Filter text" data-testid="md-filter">
            <select data-md-col aria-label="Filter column" data-testid="md-filter-col">
                <option value="">All columns</option>
                <?php foreach ($cols as $i => [$key, $label]): ?><option value="<?= $i + 1 ?>"><?= sanitize($label) ?></option><?php endforeach; ?>
            </select>
            <form method="post" action="monthly_data_export.php" data-md-dl-form data-no-loading style="display:contents">
                <?= csrf_field() ?>
                <input type="hidden" name="tab" value="<?= $tab ?>"><input type="hidden" name="month" value="<?= $monthVal ?>">
                <input type="hidden" name="ids" value="all" data-md-ids>
                <button type="submit" class="md-btn md-btn-dl" data-testid="md-download"><i class="fa-solid fa-file-excel"></i> Download Excel</button>
            </form>
        </div>
        <div class="md-shown" data-md-shown data-testid="md-shown"></div>
        <?php endif; ?>

        <div class="md-table-wrap">
            <?php if (!$rows): ?>
                <div class="md-empty" data-testid="md-empty">No <?= $tab === 'appointments' ? 'appointments' : 'surveys completed' ?> in <?= sanitize($monthLabel) ?>.</div>
            <?php else: ?>
            <table class="md-table" data-md-table data-testid="md-table">
                <thead><tr>
                    <th aria-sort="none"><button type="button" data-md-sort="0" data-kind="num">#<span class="ar">▲▼</span></button></th>
                    <?php foreach ($cols as $i => [$key, $label, $kind]): ?>
                    <th class="<?= $kind === 'num' ? 'num' : '' ?>" aria-sort="none"><button type="button" data-md-sort="<?= $i + 1 ?>" data-kind="<?= $kind ?>" data-testid="md-sort-<?= $key ?>"><?= sanitize($label) ?><span class="ar">▲▼</span></button></th>
                    <?php endforeach; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $n => $r): ?>
                    <tr data-id="<?= (int)$r['id'] ?>" data-href="<?= sanitize($r['url']) ?>" data-testid="md-row-<?= (int)$r['id'] ?>">
                        <td data-v="<?= $n + 1 ?>"><?= $n + 1 ?></td>
                        <?php foreach ($cols as [$key, $label, $kind]): [$txt, $sv] = mdCell($r, $key, $kind); ?>
                        <?php if ($key === 'vessel'): ?>
                        <td data-v="<?= sanitize($sv) ?>"><a href="<?= sanitize($r['url']) ?>"><?= sanitize($txt) ?></a></td>
                        <?php elseif ($key === 'stage'): ?>
                        <td data-v="<?= sanitize($sv) ?>"><span class="md-st" data-st="<?= sanitize($r['stage']) ?>"><?= sanitize($txt) ?></span></td>
                        <?php else: ?>
                        <td class="<?= $kind === 'num' ? 'num' . ($r['na'] ? ' na' : '') : ($kind === 'date' ? 'dt' : '') ?>" data-v="<?= sanitize($sv) ?>"<?= $kind === 'num' ? ' data-n="' . ($r['na'] || $r[$key] === null ? '' : sanitize((string)$r[$key])) . '"' : '' ?>><?= sanitize($txt) ?></td>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <?php if ($tab === 'recovery'): ?>
                <tfoot><tr data-md-foot>
                    <td colspan="<?= count($cols) - 3 ?>">Total <span data-md-foot-n></span></td>
                    <?php foreach (['vlsfo', 'lsmgo', 'hsfo', 'total'] as $k): ?><td class="num" data-md-sum="<?= $k ?>" data-testid="md-foot-<?= $k ?>"><?= $mt($tot[$k]) ?></td><?php endforeach; ?>
                </tr></tfoot>
                <?php endif; ?>
            </table>
            <?php endif; ?>
        </div>
        <div class="md-note">
            <?php if ($tab === 'appointments'): ?>Vessels whose Appointment Date (given when the vessel is assigned) is in the month, in every stage. Click a vessel to open it at its current stage.
            <?php elseif ($tab === 'completed'): ?>Vessels in Pending Reports and Completed with their Survey Completed Date in the month (same as the dashboard Month Summary).
            <?php else: ?>The same vessels as Survey Completed, with their recovery (same rules as the Recovery page). N/A = survey type without recovery (<?= RECOVERY_NA_LABEL ?>), counted as 0; Onhire is shown with a minus and counted as positive in the totals.<?php endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
  var table = document.querySelector('[data-md-table]'); if (!table) return;
  var tbody = table.tBodies[0], rows = [].slice.call(tbody.rows);
  var q = document.querySelector('[data-md-q]'), col = document.querySelector('[data-md-col]');
  var shown = document.querySelector('[data-md-shown]'), total = rows.length;
  var foot = table.querySelector('[data-md-foot]'), fmt = new Intl.NumberFormat('en-US', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
  var sumCols = {}; // recovery: column index of each sum
  [].slice.call(table.tHead.rows[0].cells).forEach(function (th, i) { var b = th.querySelector('[data-md-sort]'); if (b && /^(VLSFO|LSMGO|HSFO|Total)/.test(b.textContent)) sumCols[b.textContent.split(' ')[0].toLowerCase()] = i; });
  function visible() { return rows.filter(function (r) { return !r.hidden; }); }
  function update() {
    var vis = visible();
    vis.forEach(function (r, i) { r.cells[0].textContent = i + 1; }); // # = position in the list shown
    shown.textContent = vis.length === total ? 'Showing all ' + total + ' rows' : 'Showing ' + vis.length + ' of ' + total + ' rows (filtered)';
    if (foot) {
      // totals of the rows shown (NOT APPLICABLE / empty cells count 0; always positive)
      Object.keys(sumCols).forEach(function (k) {
        var s = 0; vis.forEach(function (r) { var n = r.cells[sumCols[k]].getAttribute('data-n'); if (n) s += Math.abs(parseFloat(n)); });
        var c = foot.querySelector('[data-md-sum="' + k + '"]'); if (c) c.textContent = fmt.format(s);
      });
      foot.querySelector('[data-md-foot-n]').textContent = vis.length === total ? '' : '(' + vis.length + ' shown)';
    }
  }
  function filter() {
    var t = (q.value || '').trim().toLowerCase(), c = col.value;
    rows.forEach(function (r) {
      var hay = c ? r.cells[+c].textContent : [].slice.call(r.cells, 1).map(function (x) { return x.textContent; }).join(' ');
      r.hidden = t !== '' && hay.toLowerCase().indexOf(t) < 0;
    });
    update();
  }
  q.addEventListener('input', filter); col.addEventListener('change', filter);
  table.tHead.addEventListener('click', function (e) {
    var b = e.target.closest('[data-md-sort]'); if (!b) return;
    var th = b.parentNode, i = +b.getAttribute('data-md-sort'), kind = b.getAttribute('data-kind');
    var dir = th.getAttribute('aria-sort') === 'ascending' ? -1 : 1;
    [].slice.call(table.tHead.rows[0].cells).forEach(function (h) { h.setAttribute('aria-sort', 'none'); });
    th.setAttribute('aria-sort', dir === 1 ? 'ascending' : 'descending');
    var key = function (r) { var v = r.cells[i].getAttribute('data-v') || ''; return kind === 'num' ? (v === '' ? -Infinity : parseFloat(v)) : v; };
    rows.sort(function (a, b) {
      var x = key(a), y = key(b);
      if (kind !== 'num') { if (x === '' && y !== '') return 1; if (y === '' && x !== '') return -1; } // empty last
      return (x < y ? -1 : x > y ? 1 : 0) * dir || (+a.cells[0].getAttribute('data-v') - +b.cells[0].getAttribute('data-v'));
    });
    rows.forEach(function (r) { tbody.appendChild(r); });
    update();
  });
  tbody.addEventListener('click', function (e) {
    if (e.target.closest('a')) return;
    var tr = e.target.closest('tr[data-href]'); if (tr) location.href = tr.getAttribute('data-href');
  });
  // Download: the rows on screen (filtered), in the order shown
  var form = document.querySelector('[data-md-dl-form]');
  form.addEventListener('submit', function () {
    form.querySelector('[data-md-ids]').value = visible().map(function (r) { return r.getAttribute('data-id'); }).join(',');
  });
  update();
})();
</script>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>

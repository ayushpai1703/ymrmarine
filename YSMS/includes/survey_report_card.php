<?php
/**
 * "Survey Report" card on a vessel's page (vessel_detail.php, report_detail.php) — the only way
 * into the Survey Report generator. Expects $db, $survey (surveys row with id, status, type_name,
 * survey_type_ids) and the session.
 *
 *   No report yet  → Admin / assigned Surveyor (vessel pending): "Start Survey" + report type.
 *   Report exists  → "Resume Survey" (or "Open report" when it can no longer be changed).
 *   Admin only     → "Tank history": every saved version of every tank (loaded from
 *                    ajax/survey_reports.php?a=tank_history, which refuses everyone else).
 * Client, Sales and other roles see nothing.
 */
require_once __DIR__ . '/survey_reports.php';
if (!srCanUse() || empty($survey['id'])) return;
try {
    $srcSurvey = sfSurvey($db, (int)$survey['id']);
    if (!$srcSurvey || !sfCanAccess($db, $srcSurvey)) return;
    $srcReport = srReportForSurvey($db, (int)$srcSurvey['id']);
    $srcKinds = [];
    foreach (RT_KINDS as $k => $label) $srcKinds[$k] = ['label' => $label, 'active' => (bool)rtActive($db, $k)];
} catch (Throwable $e) {
    error_log('survey report card: ' . $e->getMessage());
    return;
}
$srcCanStart = !$srcReport && in_array(srRole(), ['Admin', 'Surveyor'], true) && sfCanEdit($srcSurvey) && srCanEdit($srcSurvey); // a survey starts only while the vessel is pending
if (!$srcReport && !$srcCanStart) return;
$srcIsAdmin = srRole() === 'Admin';
// the report type is preselected only when the vessel's survey type is exactly one of the report types
$srcPre = '';
$srcTypeNames = array_filter(array_map('trim', preg_split('/\s*\+\s*/', (string)($srcSurvey['types'] ?? $srcSurvey['type_name'] ?? ''))));
if (count($srcTypeNames) === 1) {
    foreach (RT_KINDS as $k => $label) if (strcasecmp(reset($srcTypeNames), $label) === 0 && $srcKinds[$k]['active']) $srcPre = $k;
}
$srcWhen = fn($v) => $v ? date('d M Y, H:i', strtotime((string)$v)) : '';
?>
<style>
.src-wrap { margin: 16px 12px 0; background: #fff; border: 1px solid var(--border-color, #e2e8f0); border-radius: 16px; overflow: hidden; font-family: 'Lexend', system-ui, sans-serif; }
.src-head { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 14px; padding: 14px 16px; }
.src-icon { width: 38px; height: 38px; border-radius: 10px; background: linear-gradient(135deg, #00A3DF, #6C4CF1); color: #fff; display: flex; align-items: center; justify-content: center; flex: none; }
.src-title { font-weight: 800; font-size: 15px; color: #0f172a; }
.src-sub { font-size: 12px; color: #64748b; margin-top: 1px; }
.src-grow { flex: 1 1 200px; min-width: 0; }
.src-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 42px; padding: 0 18px; border-radius: 12px; border: 0; font: 700 14px 'Lexend', system-ui, sans-serif; color: #fff; background: linear-gradient(135deg, #00A3DF, #6C4CF1); text-decoration: none; cursor: pointer; }
.src-btn:hover { filter: brightness(1.05); color: #fff; }
.src-btn[disabled] { opacity: .55; cursor: not-allowed; }
.src-btn.ghost { background: #fff; color: #4338ca; border: 1px solid #c7d2fe; }
.src-kinds { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 8px; padding: 0 16px 14px; }
.src-kind { display: flex; align-items: center; gap: 8px; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 10px 12px; font-size: 13.5px; font-weight: 600; color: #0f172a; cursor: pointer; }
.src-kind input { accent-color: #6C4CF1; width: 17px; height: 17px; }
.src-kind:has(input:checked) { border-color: #6C4CF1; background: #F5F3FF; }
.src-kind.off { opacity: .5; cursor: not-allowed; }
.src-kind small { display: block; font-weight: 500; font-size: 11px; color: #94a3b8; }
.src-err { color: #b91c1c; font-size: 12.5px; padding: 0 16px 12px; }
.src-hist { border-top: 1px solid #f1f5f9; padding: 12px 16px 16px; }
.src-hist-top { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; }
.src-hist-top select { min-height: 40px; border: 1px solid #cbd5e1; border-radius: 10px; padding: 0 10px; font: 500 13.5px 'Lexend', system-ui, sans-serif; max-width: 100%; }
.src-badge { font-size: 11px; font-weight: 700; padding: 2px 9px; border-radius: 999px; background: #EEF2FF; color: #3730A3; }
.src-badge.cur { background: #DCFCE7; color: #166534; }
.src-badge.gone { background: #FEE2E2; color: #991B1B; }
.src-tbl-wrap { overflow-x: auto; margin-top: 10px; border: 1px solid #e2e8f0; border-radius: 12px; }
.src-tbl { width: 100%; border-collapse: collapse; font-size: 12.5px; min-width: 620px; }
.src-tbl th { background: #f8fafc; text-align: left; padding: 8px 10px; font-weight: 700; color: #334155; white-space: nowrap; }
.src-tbl td { padding: 8px 10px; border-top: 1px solid #f1f5f9; white-space: nowrap; }
.src-tbl td.chg { background: #FEF9C3; font-weight: 700; }
.src-tbl tr.cur td:first-child { box-shadow: inset 3px 0 0 #16a34a; }
.src-cmp { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 12px; font-size: 13px; }
.src-cmp select { min-height: 36px; border: 1px solid #cbd5e1; border-radius: 10px; padding: 0 8px; font: 500 13px 'Lexend', system-ui, sans-serif; }
.src-cmp-out { margin-top: 8px; }
.src-muted { color: #94a3b8; font-size: 12.5px; }
@media (min-width: 992px) { .src-wrap { margin: 18px 24px 0; } }
</style>
<div class="src-wrap" id="srCard" data-testid="survey-report-card" data-survey-id="<?= (int)$srcSurvey['id'] ?>">
    <div class="src-head">
        <div class="src-icon"><i class="fa-solid fa-file-waveform"></i></div>
        <div class="src-grow">
            <div class="src-title">Survey Report</div>
            <?php if ($srcReport): ?>
            <div class="src-sub" data-testid="src-state"><?= sanitize(RT_KINDS[$srcReport['kind']] ?? $srcReport['kind']) ?> · last saved <?= sanitize($srcWhen($srcReport['updated_at'])) ?><?= $srcReport['updated_by_name'] ? ' by ' . sanitize($srcReport['updated_by_name']) : '' ?></div>
            <?php else: ?>
            <div class="src-sub">Choose the report type and start the survey for this vessel.</div>
            <?php endif; ?>
        </div>
        <?php if ($srcReport): ?>
            <a class="src-btn" href="survey_report.php?id=<?= (int)$srcReport['id'] ?>" data-testid="src-resume"><i class="fa-solid fa-<?= srCanEdit($srcSurvey, $srcReport) ? 'play' : 'eye' ?>"></i> <?= srCanEdit($srcSurvey, $srcReport) ? 'Resume Survey' : 'Open report' ?></a>
        <?php else: ?>
            <button type="button" class="src-btn" id="srStart" data-testid="src-start" <?= $srcPre ? '' : 'disabled' ?>><i class="fa-solid fa-play"></i> Start Survey</button>
        <?php endif; ?>
    </div>
    <?php if ($srcCanStart): ?>
    <div class="src-kinds" role="radiogroup" aria-label="Report type" data-testid="src-kinds">
        <?php foreach ($srcKinds as $k => $kd): ?>
        <label class="src-kind <?= $kd['active'] ? '' : 'off' ?>" data-testid="src-kind-<?= $k ?>">
            <input type="radio" name="src_kind" value="<?= $k ?>" <?= $kd['active'] ? '' : 'disabled' ?> <?= $srcPre === $k ? 'checked' : '' ?>>
            <span><?= sanitize($kd['label']) ?><?php if (!$kd['active']): ?><small>Template not uploaded yet</small><?php endif; ?></span>
        </label>
        <?php endforeach; ?>
    </div>
    <div class="src-err" id="srStartErr" hidden data-testid="src-error"></div>
    <?php endif; ?>
    <?php if ($srcReport && $srcIsAdmin): ?>
    <div class="src-hist" id="srHist" data-testid="src-history" data-report-id="<?= (int)$srcReport['id'] ?>">
        <div class="src-hist-top">
            <b style="font-size:13.5px"><i class="fa-solid fa-clock-rotate-left"></i> Tank history</b>
            <span class="src-muted">Admin only</span>
            <select id="srHistTank" aria-label="Tank" data-testid="src-history-tank" hidden></select>
        </div>
        <div id="srHistBody" class="src-muted" style="margin-top:8px">Loading…</div>
    </div>
    <?php endif; ?>
</div>
<script>
(function () {
  var card = document.getElementById('srCard'); if (!card) return;
  var API = 'ajax/survey_reports.php', CSRF = <?= json_encode(csrf_token()) ?>, SID = <?= (int)$srcSurvey['id'] ?>;
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  var start = document.getElementById('srStart');
  if (start) {
    card.addEventListener('change', function (e) { if (e.target.name === 'src_kind') start.disabled = false; });
    start.addEventListener('click', function () {
      var k = card.querySelector('input[name=src_kind]:checked'), err = document.getElementById('srStartErr');
      if (!k) return;
      start.disabled = true; err.hidden = true;
      var f = new FormData(); f.append('a', 'start'); f.append('survey_id', SID); f.append('kind', k.value); f.append('csrf_token', CSRF);
      fetch(API, { method: 'POST', body: f, credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF } }).then(function (r) { return r.json(); }).then(function (res) {
        if (res.ok) { location.href = 'survey_report.php?id=' + res.id; return; }
        err.textContent = res.error || 'Could not start the survey.'; err.hidden = false; start.disabled = false;
      }).catch(function () { err.textContent = 'Network error — please try again.'; err.hidden = false; start.disabled = false; });
    });
  }

  var hist = document.getElementById('srHist');
  if (!hist) return;
  var sel = document.getElementById('srHistTank'), body = document.getElementById('srHistBody'), TANKS = [];
  var COLS = [['sounding', 'Sounding'], ['volume', 'Volume (m³)'], ['density', 'Density'], ['temperature', 'Temp (°C)']];
  function when(v) { var d = new Date(String(v).replace(' ', 'T')); return isNaN(d) ? esc(v) : d.toLocaleString(undefined, { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }); }
  function show() {
    var t = TANKS[sel.value]; if (!t) return;
    var rows = t.versions.slice().reverse().map(function (v) {
      var cur = v.version === t.current_version;
      return '<tr class="' + (cur ? 'cur' : '') + '" data-testid="src-ver-' + v.version + '"><td><b>v' + v.version + '</b> ' + (cur ? '<span class="src-badge cur">Current</span>' : '') + '</td>' +
        COLS.map(function (c) { return '<td class="' + (v.changed.indexOf(c[0]) >= 0 ? 'chg' : '') + '">' + esc(v.values[c[0]] == null ? '—' : v.values[c[0]]) + '</td>'; }).join('') +
        '<td>' + esc(v.by || '—') + '</td><td>' + when(v.at) + '</td></tr>';
    }).join('');
    var opts = t.versions.map(function (v) { return '<option value="' + v.version + '">v' + v.version + '</option>'; }).join('');
    body.className = '';
    body.innerHTML = '<div class="src-sub">' + esc(t.grade_label) + ' · ' + t.versions.length + ' version' + (t.versions.length === 1 ? '' : 's') +
      (t.in_report ? '' : ' · <span class="src-badge gone">removed from the report</span>') + ' · highlighted = changed from the version before</div>' +
      '<div class="src-tbl-wrap"><table class="src-tbl" data-testid="src-history-table"><thead><tr><th>Version</th>' + COLS.map(function (c) { return '<th>' + c[1] + '</th>'; }).join('') + '<th>Changed by</th><th>When</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
      (t.versions.length > 1 ? '<div class="src-cmp">Compare <select id="srCmpA" data-testid="src-cmp-a">' + opts + '</select> with <select id="srCmpB" data-testid="src-cmp-b">' + opts + '</select></div><div class="src-cmp-out" id="srCmpOut" data-testid="src-cmp-out"></div>' : '');
    var a = document.getElementById('srCmpA'), b = document.getElementById('srCmpB');
    if (a) {
      a.value = t.versions[t.versions.length - 2].version; b.value = t.current_version;
      var cmp = function () {
        var va = t.versions.filter(function (v) { return v.version == a.value; })[0], vb = t.versions.filter(function (v) { return v.version == b.value; })[0];
        document.getElementById('srCmpOut').innerHTML = '<div class="src-tbl-wrap"><table class="src-tbl" style="min-width:0"><thead><tr><th></th><th>v' + va.version + '</th><th>v' + vb.version + '</th><th></th></tr></thead><tbody>' +
          COLS.map(function (c) { var x = va.values[c[0]], y = vb.values[c[0]], d = x !== y;
            return '<tr><td><b>' + c[1] + '</b></td><td>' + esc(x == null ? '—' : x) + '</td><td class="' + (d ? 'chg' : '') + '">' + esc(y == null ? '—' : y) + '</td><td>' + (d ? 'changed' : '<span class="src-muted">same</span>') + '</td></tr>'; }).join('') +
          '</tbody></table></div>';
      };
      a.onchange = b.onchange = cmp; cmp();
    }
  }
  fetch(API + '?a=tank_history&survey_id=' + SID, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
    if (!res.ok) { body.textContent = res.error || 'Could not load the history.'; return; }
    TANKS = res.tanks || [];
    if (!TANKS.length) { body.textContent = 'No tank has been saved yet.'; return; }
    sel.innerHTML = TANKS.map(function (t, i) { var n = t.versions.length - 1; return '<option value="' + i + '">' + esc(t.grade_label + ' · ' + t.name) + ' (v' + t.current_version + (n ? ', ' + n + ' change' + (n === 1 ? '' : 's') : '') + ')</option>'; }).join('');
    sel.hidden = false; sel.onchange = show; show();
  }).catch(function () { body.textContent = 'Could not load the history.'; });
})();
</script>

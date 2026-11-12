<?php
/**
 * Live Survey Reports — Admin only. The reports filled on the survey report link (survey_report_public.php):
 *  - three counts: Surveys in live (< 4 h since start), Might be completed (4–24 h), Completed (24 h +)
 *  - pick a vessel → its tank tables as they are now, every changed reading highlighted (all its versions on hover),
 *    then the totals: version 1 (the first readings of every tank) and after each later change — total MT,
 *    the log book ROB and the difference
 *  - every report made from the link and every time it was downloaded
 *  - lock: the link becomes read only now / after 1, 4 or 24 hours; edit access only when the Admin gives it back
 * Data: ajax/live_survey_reports.php. Rules: includes/live_survey_reports.php.
 */
require_once 'config/config.php';
require_once 'includes/live_survey_reports.php';
checkAuth();
if (($_SESSION['role'] ?? '') !== 'Admin') {
    header('Location: index.php');
    exit;
}
$page_title = 'Live Survey Reports';
include 'includes/header.php';
?>
<style>
.lv { padding: 14px 18px 120px; max-width: 1500px; font-family: 'Lexend', system-ui, sans-serif; }
.lv h1 { font-size: 22px; font-weight: 800; margin: 0 0 4px; color: #0f172a; }
.lv-sub { font-size: 13px; color: #64748b; margin-bottom: 14px; }
.lv-tiles { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-bottom: 14px; }
.lv-tile { border: 0; text-align: left; border-radius: 16px; padding: 14px 16px; color: #fff; cursor: pointer; box-shadow: 0 6px 18px rgba(15,23,42,.12); position: relative; overflow: hidden; }
.lv-tile b { display: block; font-size: 30px; line-height: 1.1; }
.lv-tile span { font-size: 13.5px; font-weight: 600; opacity: .95; }
.lv-tile i { position: absolute; right: 14px; top: 14px; font-size: 26px; opacity: .35; }
.lv-tile.p { background: linear-gradient(135deg, #0284c7, #0ea5e9); }
.lv-tile.c { background: linear-gradient(135deg, #d97706, #f59e0b); }
.lv-tile.d { background: linear-gradient(135deg, #15803d, #22c55e); }
.lv-tile.on { outline: 3px solid #0f172a; outline-offset: 2px; }
.lv-grid { display: grid; grid-template-columns: 340px minmax(0, 1fr); gap: 14px; align-items: start; }
.lv-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; }
.lv-list { max-height: calc(100vh - 220px); overflow: auto; }
.lv-item { display: block; width: 100%; border: 0; border-top: 1px solid #eef2f7; background: #fff; text-align: left; padding: 10px 12px; cursor: pointer; }
.lv-item:first-child { border-top: 0; }
.lv-item:hover { background: #f8fbff; } .lv-item.on { background: #eef5ff; box-shadow: inset 3px 0 0 #0b63e5; }
.lv-item b { font-size: 14px; color: #0f172a; } .lv-item small { display: block; color: #64748b; font-size: 12px; }
.lv-item .lsr-pill { margin-top: 6px; font-size: 11px; }
.lv-empty { padding: 30px 16px; text-align: center; color: #94a3b8; }
.lv-sec { padding: 14px 16px; border-top: 1px solid #eef2f7; }
.lv-sec:first-child { border-top: 0; }
.lv-sec h3 { font-size: 15px; font-weight: 800; margin: 0 0 10px; color: #0f2b5b; display: flex; align-items: center; gap: 8px; }
.lv-head { display: flex; flex-wrap: wrap; gap: 8px 18px; align-items: center; }
.lv-head .v { font-size: 20px; font-weight: 800; color: #0f172a; }
.lv-head .m { font-size: 13px; color: #475569; }
.lv-btn { height: 34px; border: 1px solid #cbd5e1; background: #fff; border-radius: 8px; padding: 0 12px; font-weight: 700; font-size: 13px; color: #0f172a; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; }
.lv-btn:hover { border-color: #0b63e5; color: #0b63e5; }
.lv-btn.danger { border-color: #fecaca; color: #b91c1c; } .lv-btn.ok { border-color: #bbf7d0; color: #166534; }
.lv-lock { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; font-size: 13.5px; }
.lv-lock .st { font-weight: 700; } .lv-lock .st.l { color: #b91c1c; } .lv-lock .st.s { color: #92400e; } .lv-lock .st.o { color: #166534; }
.lv-tw { overflow-x: auto; }
.lv-t { width: 100%; border-collapse: collapse; font-size: 13.5px; font-variant-numeric: tabular-nums; min-width: 720px; }
.lv-t th { background: #e8f1fc; color: #0f2b5b; font-weight: 700; font-size: 12.5px; padding: 7px 8px; text-align: center; border-bottom: 1px solid #d6e3f3; white-space: nowrap; }
.lv-t th.mt { background: #dcfce7; }
.lv-t td { padding: 6px 8px; border-bottom: 1px solid #eef2f7; text-align: center; white-space: nowrap; }
.lv-t td.n { text-align: left; font-weight: 700; }
.lv-t td.mt { font-weight: 800; text-align: right; }
.lv-t td.chg { background: #fef3c7; font-weight: 800; color: #92400e; cursor: help; position: relative; }
.lv-t td.chg::after { content: attr(data-v); position: absolute; top: 1px; right: 3px; font-size: 9.5px; color: #b45309; font-weight: 800; }
.lv-t tr.gone td { color: #94a3b8; text-decoration: line-through; }
.lv-t tr.open td { color: #94a3b8; }
.lv-ver { font-size: 11px; font-weight: 800; border-radius: 999px; padding: 2px 8px; background: #f1f5f9; color: #334155; }
.lv-ver.c { background: #fef3c7; color: #92400e; }
.lv-gt { font-size: 13px; color: #334155; margin: 6px 0 14px; }
.lv-d td.dif.neg { color: #b91c1c; font-weight: 800; } .lv-d td.dif.pos { color: #166534; font-weight: 800; }
.lv-d tr.v1 td { background: #f0f9ff; font-weight: 700; }
.lv-d td.what { text-align: left; white-space: normal; min-width: 220px; font-size: 12.5px; }
.lv-d td.up { background: #fef3c7; }
.lv-note { font-size: 12px; color: #64748b; margin-top: 6px; }
.lv-f td.dl { text-align: left; white-space: normal; font-size: 12px; color: #475569; }
@media (max-width: 991.98px) { .lv-grid { grid-template-columns: 1fr; } .lv-list { max-height: 360px; } .lv-tiles { gap: 8px; } .lv-tile b { font-size: 24px; } .lv-tile span { font-size: 12px; } }
</style>
<?= lsrCss() ?>
<div class="scroll-content" data-testid="live-survey-reports-page">
<?php $back_url = 'index.php'; $page_testid = 'live-survey-reports'; include 'includes/top_app_bar.php'; ?>
<div class="lv">
    <h1><i class="fa-solid fa-tower-broadcast text-success me-2"></i>Live Survey Reports</h1>
    <div class="lv-sub">Reports filled on the survey report link. From the time a report was started: under 4 h <b>in live</b>, 4–24 h <b>might be completed</b>, after 24 h <b>completed</b>. Refreshes every minute.</div>
    <div class="lv-tiles" data-testid="lv-tiles">
        <button type="button" class="lv-tile p" data-f="progress" data-testid="lv-tile-progress"><i class="fa-solid fa-tower-broadcast"></i><b data-c="progress">–</b><span>Surveys in Live</span></button>
        <button type="button" class="lv-tile c" data-f="check" data-testid="lv-tile-check"><i class="fa-solid fa-bell"></i><b data-c="check">–</b><span>Might be Completed</span></button>
        <button type="button" class="lv-tile d" data-f="done" data-testid="lv-tile-done"><i class="fa-solid fa-circle-check"></i><b data-c="done">–</b><span>Completed</span></button>
    </div>
    <div class="lv-grid">
        <div class="lv-card lv-list" data-list data-testid="lv-list"><div class="lv-empty">Loading…</div></div>
        <div class="lv-card" data-detail data-testid="lv-detail"><div class="lv-empty">Choose a vessel on the left.</div></div>
    </div>
</div>
</div>
<script src="assets/js/report-calc.js?v=<?= @filemtime(__DIR__ . '/assets/js/report-calc.js') ?>"></script>
<script>
(function () {
  'use strict';
  var API = 'ajax/live_survey_reports.php', CSRF = <?= json_encode(csrf_token()) ?>;
  var S = { rows: [], filter: '', sel: +(new URLSearchParams(location.search).get('id') || 0), det: null };
  var $ = function (s, r) { return (r || document).querySelector(s); };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function num(v) { var t = String(v == null ? '' : v).replace(/,/g, '').trim(); return t !== '' && !isNaN(t) ? parseFloat(t) : null; }
  function f3(v) { return v == null || isNaN(v) ? '—' : (+v).toLocaleString('en-US', { minimumFractionDigits: 3, maximumFractionDigits: 3 }); }
  function sg(v) { return v == null || isNaN(v) ? '—' : (v > 0 ? '+' : v < 0 ? '−' : '') + Math.abs(v).toLocaleString('en-US', { minimumFractionDigits: 3, maximumFractionDigits: 3 }); }
  function when(s) { var d = new Date(String(s).replace(' ', 'T')); return isNaN(d) ? s : d.toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }); }
  var PILL = { progress: ['lsr-p', 'fa-spinner fa-spin-pulse'], check: ['lsr-c', 'fa-bell'], done: ['lsr-d', 'fa-circle-check'] };
  function pill(st) { var p = PILL[st.key] || PILL.done; return '<span class="lsr-pill ' + p[0] + '"><i class="fa-solid ' + p[1] + '"></i> ' + esc(st.label) + '</span>'; }

  // ---------- list
  function load() {
    return fetch(API + '?a=list', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) throw new Error(j.error);
      S.rows = j.rows;
      Object.keys(j.count).forEach(function (k) { $('[data-c="' + k + '"]').textContent = j.count[k]; });
      list();
      if (!S.sel && S.rows.length) S.sel = S.rows[0].id;
      if (S.sel) detail(S.sel);
    }).catch(function (e) { $('[data-list]').innerHTML = '<div class="lv-empty">' + esc(e.message || 'Could not load.') + '</div>'; });
  }
  function list() {
    document.querySelectorAll('.lv-tile').forEach(function (t) { t.classList.toggle('on', t.getAttribute('data-f') === S.filter); });
    var rows = S.rows.filter(function (r) { return !S.filter || r.status.key === S.filter; });
    $('[data-list]').innerHTML = rows.length ? rows.map(function (r) {
      return '<button type="button" class="lv-item' + (r.id === S.sel ? ' on' : '') + '" data-id="' + r.id + '" data-testid="lv-item-' + r.id + '"><b>' + esc(r.vessel || 'Vessel not entered yet') + '</b>' +
        '<small>IMO ' + esc(r.imo || '—') + ' · ' + esc(r.surveyor || 'Surveyor not entered') + '</small><small>' + esc(r.kind) + ' · ' + esc(r.ago) + (r.tank_changes ? ' · <b style="color:#b45309">' + r.tank_changes + ' change' + (r.tank_changes === 1 ? '' : 's') + '</b>' : '') + '</small>' + pill(r.status) + '</button>';
    }).join('') : '<div class="lv-empty">No reports here.</div>';
  }

  // ---------- one report
  function detail(id) {
    return fetch(API + '?a=detail&id=' + id, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) { $('[data-detail]').innerHTML = '<div class="lv-empty">' + esc(j.error) + '</div>'; return; }
      S.det = j; render();
    });
  }
  function mtOf(v, calc) { // v: {volume, density, temp}
    if (!window.ReportCalc || !calc) return null;
    var t = ReportCalc.tank({ volume: v.volume, density: v.density, temp: v.temp }, calc);
    return t.mt;
  }
  var FIELDS = [['sounding', 'Sounding', 3], ['volume', 'Volume', 3], ['density', 'Density', 4], ['temperature', 'Temp', 1]];
  function render() {
    var d = S.det, r = d.report, calc = d.calc;
    var hist = {}; (d.history || []).forEach(function (t) { hist[t.tank_id] = t; });
    var h = '<div class="lv-sec"><div class="lv-head"><span class="v" data-testid="lv-vessel">' + esc(r.vessel || 'Vessel not entered yet') + '</span>' + pill(r.status) +
      '<span class="m">IMO <b>' + esc(r.imo || '—') + '</b></span><span class="m">Surveyor <b>' + esc(r.surveyor || '—') + '</b>' + (r.signed_in_as ? ' (signed in: ' + esc(r.signed_in_as) + ')' : ' (no sign-in)') + '</span>' +
      '<span class="m">' + esc(r.kind) + ' · started ' + esc(when(r.started_at)) + ' (' + esc(r.ago) + ') · last saved ' + esc(when(r.updated_at)) + '</span>' +
      '<a class="lv-btn" href="survey_report.php?id=' + r.id + '" data-testid="lv-open"><i class="fa-solid fa-up-right-from-square"></i> Open report</a></div></div>';
    h += lockHtml(d.lock);
    if (!d.grades.length) h += '<div class="lv-sec"><span class="lv-note">No fuel grades chosen yet.</span></div>';
    d.grades.forEach(function (g) { h += gradeHtml(g, hist, calc); });
    h += diffHtml(d, calc);
    h += filesHtml(d.files || []);
    $('[data-detail]').innerHTML = h;
  }
  function lockHtml(l) {
    var now = Date.now(), at = l && l.lock_at ? new Date(String(l.lock_at).replace(' ', 'T')).getTime() : 0, h;
    if (l && l.locked) h = '<span class="st l" data-testid="lv-lock-state"><i class="fa-solid fa-lock"></i> Read only on the link since ' + esc(when(l.lock_at)) + '</span><button type="button" class="lv-btn ok" data-unlock data-testid="lv-unlock"><i class="fa-solid fa-lock-open"></i> Give edit access</button>';
    else if (at && at > now) h = '<span class="st s" data-testid="lv-lock-state"><i class="fa-solid fa-clock"></i> Becomes read only at ' + esc(when(l.lock_at)) + '</span><button type="button" class="lv-btn" data-lock="0" data-testid="lv-lock-0">Lock now</button><button type="button" class="lv-btn" data-unlock data-testid="lv-unlock">Cancel</button>';
    else h = '<span class="st o" data-testid="lv-lock-state"><i class="fa-solid fa-pen"></i> Editable on the link.</span> Make it read only: ' +
      [[0, 'Now'], [1, 'after 1 h'], [4, 'after 4 h'], [24, 'after 24 h']].map(function (x) { return '<button type="button" class="lv-btn danger" data-lock="' + x[0] + '" data-testid="lv-lock-' + x[0] + '">' + x[1] + '</button>'; }).join('');
    return '<div class="lv-sec"><h3><i class="fa-solid fa-user-lock"></i> Link access</h3><div class="lv-lock">' + h + '</div><div class="lv-note">Read only: whoever opens the link sees the report but cannot change anything until you give edit access again.</div></div>';
  }
  /** The tanks as they are now; a reading changed since version 1 is highlighted, every version of it on hover. */
  function gradeHtml(g, hist, calc) {
    var rows = g.tanks.map(function (t) {
      var H = hist[t.id], vers = H ? H.versions : [], last = vers.length ? vers[vers.length - 1].version : 0;
      var cell = function (field, shown, dec) {
        if (vers.length > 1) {
          var vals = vers.map(function (v) { return v.values[field]; });
          if (vals.some(function (x) { return x !== vals[0]; })) {
            var tip = vers.map(function (v) { return 'V' + v.version + ': ' + (v.values[field] == null ? '—' : v.values[field]) + '  (' + when(v.at) + ')'; }).join('\n');
            var lastV = vers.filter(function (v, i) { return i > 0 && v.values[field] !== vers[i - 1].values[field]; }).pop();
            return '<td class="chg" title="' + esc(tip) + '" data-v="V' + (lastV ? lastV.version : last) + '" data-testid="lv-chg-' + g.key + '-' + esc(t.name) + '-' + field + '">' + esc(shown) + '</td>';
          }
        }
        return '<td>' + esc(shown) + '</td>';
      };
      var mt = mtOf({ volume: t.volume, density: t.density, temp: t.temp }, calc);
      return '<tr class="' + (H ? '' : 'open') + '"><td class="n">' + esc(t.name || '—') + '</td><td>' + esc(t.ref_height) + '</td>' + cell('sounding', t.sounding) + cell('volume', t.volume) + cell('density', t.density) + cell('temperature', t.temp) +
        '<td class="mt">' + f3(mt) + '</td><td>' + (H ? '<span class="lv-ver' + (last > 1 ? ' c' : '') + '">V' + last + '</span>' : '<span class="lv-note">not saved yet</span>') + '</td></tr>';
    });
    // tanks saved once and since removed from the report
    Object.keys(hist).forEach(function (id) {
      var H = hist[id]; if (H.grade !== g.key || H.in_report) return;
      var v = H.versions[H.versions.length - 1].values;
      rows.push('<tr class="gone"><td class="n">' + esc(H.name) + '</td><td></td><td>' + esc(v.sounding || '') + '</td><td>' + esc(v.volume || '') + '</td><td>' + esc(v.density || '') + '</td><td>' + esc(v.temperature || '') + '</td><td class="mt"></td><td><span class="lv-note">removed</span></td></tr>');
    });
    return '<div class="lv-sec" data-testid="lv-grade-' + g.key + '"><h3><i class="fa-solid fa-droplet" style="color:#1d6ff2"></i> ' + esc(g.name) + '</h3><div class="lv-tw"><table class="lv-t"><thead><tr><th style="text-align:left">Tank</th><th>Ref. Height</th><th>Sdg. Corr\'d</th><th>Volume</th><th>Density @15°C</th><th>Temp °C</th><th class="mt">Metric Tons</th><th>Version</th></tr></thead><tbody>' +
      (rows.join('') || '<tr><td colspan="8" class="lv-note">No tanks.</td></tr>') + '</tbody></table></div>' +
      '<div class="lv-gt">Log book ROB: <b>' + esc(g.logbook || '—') + '</b> MT</div></div>';
  }
  /** Totals at version 1 (the first readings of every saved tank) and after each later change, against the log book. */
  function diffHtml(d, calc) {
    var tanks = (d.history || []), grades = d.grades;
    if (!tanks.length || !grades.length) return '<div class="lv-sec"><h3><i class="fa-solid fa-scale-balanced"></i> Totals and differences</h3><span class="lv-note">No tank saved yet — the totals appear when the surveyor completes the first tanks.</span></div>';
    var state = {}, events = [];
    tanks.forEach(function (t) {
      state[t.tank_id] = { grade: t.grade, name: t.name, v: t.versions[0].values };
      t.versions.slice(1).forEach(function (v, i) { events.push({ id: t.tank_id, name: t.name, grade: t.grade, prev: t.versions[i].values, v: v.values, ver: v.version, at: v.at, changed: v.changed }); });
    });
    events.sort(function (a, b) { return a.at < b.at ? -1 : a.at > b.at ? 1 : 0; });
    var totals = function () {
      var o = {}; grades.forEach(function (g) { o[g.key] = 0; });
      Object.keys(state).forEach(function (id) { var s = state[id]; if (!(s.grade in o)) return; var m = mtOf({ volume: s.v.volume, density: s.v.density, temp: s.v.temperature }, calc); if (m != null) o[s.grade] = ReportCalc.round(o[s.grade] + m, 3); });
      return o;
    };
    var log = {}; grades.forEach(function (g) { log[g.key] = num(g.logbook); });
    var row = function (cls, label, what, tot, prevTot, changedGrade) {
      return '<tr class="' + cls + '"><td style="text-align:left;font-weight:700">' + label + '</td><td class="what">' + what + '</td>' + grades.map(function (g) {
        var t = tot[g.key], df = log[g.key] == null ? null : ReportCalc.round(t - log[g.key], 3), up = changedGrade === g.key;
        return '<td class="' + (up ? 'up' : '') + '">' + f3(t) + '</td><td class="dif ' + (df < 0 ? 'neg' : df > 0 ? 'pos' : '') + (up ? ' up' : '') + '">' + sg(df) + '</td>';
      }).join('') + '</tr>';
    };
    var t0 = totals(), out = [row('v1', 'V1', 'First readings of every saved tank', t0)];
    events.forEach(function (e, i) {
      state[e.id].v = e.v;
      var what = '<b>' + esc(e.name) + '</b> (' + esc((grades.filter(function (g) { return g.key === e.grade; })[0] || { short: e.grade }).short) + ', V' + e.ver + '): ' +
        (e.changed || []).map(function (f) { return f + ' ' + esc(e.prev[f] == null ? '—' : e.prev[f]) + ' → <b>' + esc(e.v[f] == null ? '—' : e.v[f]) + '</b>'; }).join(', ');
      out.push(row('', 'Change ' + (i + 1) + '<br><small class="lv-note">' + esc(when(e.at)) + '</small>', what, totals(), null, e.grade));
    });
    var head = '<tr><th rowspan="2" style="text-align:left">Version</th><th rowspan="2" style="text-align:left">What changed</th>' + grades.map(function (g) { return '<th colspan="2">' + esc(g.short) + ' <small style="font-weight:500">(log book ' + esc(g.logbook || '—') + ')</small></th>'; }).join('') + '</tr>' +
      '<tr>' + grades.map(function () { return '<th class="mt">Total MT</th><th>Difference</th>'; }).join('') + '</tr>';
    return '<div class="lv-sec" data-testid="lv-diff"><h3><i class="fa-solid fa-scale-balanced"></i> Totals and differences</h3><div class="lv-tw"><table class="lv-t lv-d">' + head + out.join('') + '</table></div>' +
      '<div class="lv-note">Total MT = sum of Metric Tons of the saved tanks. Difference = Total MT − Log book ROB (at survey start). Highlighted: the grade that changed.</div></div>';
  }
  function filesHtml(files) {
    var rows = files.map(function (f) {
      var dls = f.downloads || [];
      return '<tr data-testid="lv-file-' + f.id + '"><td style="text-align:left"><i class="fa-solid ' + (f.type === 'pdf' ? 'fa-file-pdf" style="color:#dc2626' : 'fa-file-excel" style="color:#16a34a') + '"></i> ' + esc(f.file_name) + '</td><td>' + esc(when(f.created_at)) + '</td>' +
        '<td><b data-testid="lv-dl-count-' + f.id + '">' + dls.length + '</b></td><td class="dl">' + (dls.length ? dls.map(function (x) { return esc(when(x.at)) + ' · ' + esc(x.by); }).join('<br>') : '—') + '</td>' +
        '<td><a class="lv-btn" href="ajax/survey_reports.php?a=file&id=' + f.id + '"><i class="fa-solid fa-download"></i></a></td></tr>';
    }).join('');
    return '<div class="lv-sec" data-testid="lv-files"><h3><i class="fa-solid fa-folder-open"></i> Reports made and downloads</h3>' +
      (rows ? '<div class="lv-tw"><table class="lv-t lv-f"><thead><tr><th style="text-align:left">File</th><th>Made</th><th>Downloads</th><th style="text-align:left">Downloaded</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>' : '<span class="lv-note">No report made yet.</span>') + '</div>';
  }

  // ---------- events
  document.addEventListener('click', function (e) {
    var b;
    if ((b = e.target.closest('.lv-tile'))) { var f = b.getAttribute('data-f'); S.filter = S.filter === f ? '' : f; list(); return; }
    if ((b = e.target.closest('.lv-item'))) { S.sel = +b.getAttribute('data-id'); history.replaceState(null, '', '?id=' + S.sel); list(); detail(S.sel); if (innerWidth < 992) $('[data-detail]').scrollIntoView({ behavior: 'smooth' }); return; }
    if ((b = e.target.closest('[data-lock],[data-unlock]'))) {
      var fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('id', S.sel);
      if (b.hasAttribute('data-lock')) { fd.append('a', 'lock'); fd.append('hours', b.getAttribute('data-lock')); } else fd.append('a', 'unlock');
      b.disabled = true;
      fetch(API, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF } }).then(function (r) { return r.json(); }).then(function (j) {
        if (!j.ok) { alert(j.error || 'Could not change the access.'); b.disabled = false; return; }
        S.det.lock = { lock_at: j.lock_at, locked: j.locked }; render();
      }).catch(function () { b.disabled = false; alert('No connection.'); });
    }
  });
  load();
  setInterval(function () { if (!document.hidden) load(); }, 60000);
})();
</script>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>

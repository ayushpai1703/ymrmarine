<?php
/**
 * Report Templates (Admin): the company's Excel report formats used by Survey Reports.
 * Upload a template (each upload is a new version, the file is kept exactly as uploaded), see
 * what the system found in it and what needs review, adjust the cell mapping, choose the sheets
 * printed to PDF, generate the template's own data as a test (Excel + PDF + comparison) and
 * choose the version used for new reports. Data: ajax/report_templates.php.
 */
require_once 'config/config.php';
require_once 'includes/report_templates.php';
checkAuth();
if (($_SESSION['role'] ?? '') !== 'Admin') { header('Location: index.php'); exit; }
$page_title = 'Report Templates';
include 'includes/header.php';
?>
<style>
.rtp { padding: 14px 18px 40px; max-width: 1300px; }
.rtp h1 { font-size: 20px; font-weight: 800; margin: 0; color: #0f172a; }
.rtp .sub { color: #64748b; font-size: 13px; margin: 2px 0 14px; }
.rtp-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px 16px; margin-bottom: 14px; box-shadow: 0 1px 2px rgba(16,24,40,.04); }
.rtp-card h2 { font-size: 14px; font-weight: 800; margin: 0 0 10px; color: #0f2b5b; display: flex; align-items: center; gap: 8px; }
.rtp-up { display: grid; grid-template-columns: 1fr 1fr auto; gap: 10px; align-items: end; }
.rtp-up label { font-size: 12px; font-weight: 700; color: #475569; display: grid; gap: 4px; margin: 0; }
.rtp-up input { border: 1px solid #d1d5db; border-radius: 8px; padding: 6px 8px; font-size: 13px; }
.rtp-btn { border: 1px solid #d1d5db; background: #fff; border-radius: 8px; height: 32px; padding: 0 12px; font-size: 12.5px; font-weight: 700; color: #334155; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; cursor: pointer; }
.rtp-btn:hover { border-color: #00A3DF; color: #0077a8; }
.rtp-btn.pri { background: #00A3DF; border-color: #00A3DF; color: #fff; }
.rtp-btn[disabled] { opacity: .5; pointer-events: none; }
.rtp-t { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.rtp-t th { background: #f6f7f9; text-align: left; padding: 7px 8px; border-bottom: 1px solid #e5e7eb; font-weight: 700; color: #475569; white-space: nowrap; }
.rtp-t td { padding: 7px 8px; border-bottom: 1px solid #f0f1f3; vertical-align: top; }
.rtp-t tr.sel td { background: #f0faff; }
.rtp-pill { font-size: 11px; font-weight: 800; border-radius: 999px; padding: 2px 9px; }
.rtp-pill.on { background: #dcfce7; color: #166534; } .rtp-pill.off { background: #f3f4f6; color: #6b7280; }
.rtp-flag { display: grid; grid-template-columns: 70px 170px 1fr; gap: 8px; padding: 7px 8px; border-radius: 8px; font-size: 12.5px; margin-bottom: 6px; border: 1px solid; }
.rtp-flag.error { background: #fef2f2; border-color: #fecaca; } .rtp-flag.warn { background: #fffbeb; border-color: #fde68a; } .rtp-flag.info { background: #f8fafc; border-color: #e2e8f0; }
.rtp-flag b { text-transform: uppercase; font-size: 11px; } .rtp-flag.error b { color: #b91c1c; } .rtp-flag.warn b { color: #b45309; } .rtp-flag.info b { color: #475569; }
.rtp-flag code { font-size: 11.5px; color: #0f2b5b; word-break: break-all; }
.rtp-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.rtp-map input { width: 170px; border: 1px solid #d1d5db; border-radius: 6px; padding: 3px 6px; font-size: 12px; font-family: monospace; }
.rtp-map input.chg { border-color: #00A3DF; background: #f0faff; }
.rtp-map td.g { color: #94a3b8; font-size: 11.5px; }
.rtp-res { padding: 10px 12px; border-radius: 10px; font-size: 13px; margin-top: 10px; }
.rtp-res.ok { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; } .rtp-res.bad { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
.rtp-frame { width: 100%; height: 760px; border: 1px solid #e5e7eb; border-radius: 10px; margin-top: 10px; }
.rtp code.f { display: block; font-size: 11.5px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 4px 6px; margin: 2px 0; word-break: break-all; }
.rtp .muted { color: #94a3b8; }
@media (max-width: 991.98px) { .rtp-up, .rtp-grid2 { grid-template-columns: 1fr; } .rtp-flag { grid-template-columns: 1fr; } .rtp-t { display: block; overflow-x: auto; } }
</style>
<div class="scroll-content" data-testid="report-templates-page">
<?php $back_url = ''; $page_testid = 'report-templates'; include 'includes/top_app_bar.php'; ?>
<div class="rtp">
    <h1><i class="fa-solid fa-file-excel" style="color:#16a34a"></i> Report Templates</h1>
    <div class="sub">The company's Excel report formats. Survey Reports fill the active version; every upload is kept as its own version and never changed.</div>

    <div class="rtp-card">
        <h2><i class="fa-solid fa-upload"></i> Upload a template version — Offhire</h2>
        <form class="rtp-up" data-upload onsubmit="return false">
            <label>Excel workbook (.xlsx)<input type="file" accept=".xlsx" name="file" required data-testid="rt-file"></label>
            <label>Notes (optional)<input name="notes" maxlength="500" placeholder="e.g. HSFO totals added" data-testid="rt-notes"></label>
            <button type="submit" class="rtp-btn pri" data-testid="rt-upload"><i class="fa-solid fa-cloud-arrow-up"></i> Upload new version</button>
        </form>
        <div class="sub" style="margin:8px 0 0">The workbook must have the SHIP DETAILS and TABLES 54B sheets. The first version becomes active; later versions are activated by you after the test.</div>
    </div>

    <div class="rtp-card">
        <h2><i class="fa-solid fa-code-branch"></i> Versions</h2>
        <div data-versions data-testid="rt-versions"><span class="muted">Loading…</span></div>
    </div>
    <div data-detail></div>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js"></script>
<script>
(function () {
  var CSRF = <?= json_encode(csrf_token()) ?>, API = 'ajax/report_templates.php', KIND = 'offhire';
  var S = { list: [], groups: {}, sel: null, soffice: false };
  var $ = function (s, r) { return (r || document).querySelector(s); };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function post(d) {
    var f = new FormData(); f.append('csrf_token', CSRF); Object.keys(d).forEach(function (k) { f.append(k, d[k]); });
    return fetch(API, { method: 'POST', body: f, credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF } }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Server error (' + r.status + ').' }; }); });
  }
  function load(keepSel) {
    return fetch(API + '?a=list&kind=' + KIND, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
      S.list = res.templates || []; S.groups = res.groups || {}; S.soffice = res.soffice;
      if (!keepSel || !S.list.some(function (t) { return t.id === S.sel; })) S.sel = (S.list.filter(function (t) { return t.is_active; })[0] || S.list[0] || {}).id || null;
      renderList(); renderDetail();
    });
  }
  function fdt(s) { return s ? String(s).slice(0, 16) : ''; }
  function renderList() {
    if (!S.list.length) { $('[data-versions]').innerHTML = '<span class="muted">No template uploaded yet — upload the Offhire report workbook above.</span>'; return; }
    $('[data-versions]').innerHTML = '<table class="rtp-t"><thead><tr><th>Version</th><th>File</th><th>Uploaded</th><th>Checks</th><th>Status</th><th></th></tr></thead><tbody>' + S.list.map(function (t) {
      var fl = t.analysis.flags || [], e = fl.filter(function (x) { return x.level === 'error'; }).length, w = fl.filter(function (x) { return x.level === 'warn'; }).length;
      return '<tr class="' + (t.id === S.sel ? 'sel' : '') + '" data-testid="rt-v-' + t.version + '"><td><b>v' + t.version + '</b></td><td>' + esc(t.original_name) + '<br><small class="muted">' + Math.round(t.file_size / 1024) + ' KB · sha256 ' + esc(t.sha256.slice(0, 12)) + '…</small>' + (t.notes ? '<br><small>' + esc(t.notes) + '</small>' : '') + '</td>' +
        '<td>' + fdt(t.uploaded_at) + '<br><small class="muted">' + esc(t.uploaded_by_name || '') + '</small></td>' +
        '<td>' + (e ? '<span style="color:#b91c1c;font-weight:700">' + e + ' error' + (e > 1 ? 's' : '') + '</span> ' : '') + (w ? '<span style="color:#b45309;font-weight:700">' + w + ' to review</span>' : (e ? '' : '<span style="color:#166534">OK</span>')) + '</td>' +
        '<td>' + (t.is_active ? '<span class="rtp-pill on">Active</span><br><small class="muted">' + fdt(t.activated_at) + '</small>' : '<span class="rtp-pill off">Stored</span>') + '</td>' +
        '<td style="white-space:nowrap"><button class="rtp-btn" data-open="' + t.id + '" data-testid="rt-open-' + t.version + '"><i class="fa-solid fa-magnifying-glass"></i> Details</button> ' +
        '<a class="rtp-btn" href="' + API + '?a=file&which=original&id=' + t.id + '" data-testid="rt-dl-' + t.version + '"><i class="fa-solid fa-download"></i> Original</a> ' +
        (t.is_active ? '' : '<button class="rtp-btn" data-activate="' + t.id + '" data-testid="rt-activate-' + t.version + '"><i class="fa-solid fa-check"></i> Make active</button>') + '</td></tr>';
    }).join('') + '</tbody></table>';
  }
  function cur() { return S.list.filter(function (t) { return t.id === S.sel; })[0]; }
  function renderDetail() {
    var t = cur(), box = $('[data-detail]');
    if (!t) { box.innerHTML = ''; return; }
    var a = t.analysis, eff = t.effective_fields, map = (t.mapping || {}).fields || {};
    var flags = (a.flags || []).map(function (f) { return '<div class="rtp-flag ' + f.level + '" data-testid="rt-flag"><b>' + esc(f.level === 'warn' ? 'review' : f.level) + '</b><code>' + esc(f.where) + '</code><span>' + esc(f.text) + '</span></div>'; }).join('') || '<span class="muted">Nothing to review.</span>';
    var secs = Object.keys(a.sections).map(function (k) { var s = a.sections[k]; return '<tr><td><b>' + esc(s.label) + '</b></td><td>rows ' + s.first + '–' + s.last + ' (' + (s.last - s.first + 1) + ' in the template; more are inserted as needed)</td><td>totals ' + s.calc_cols.gsv + s.gsv_total + ' / ' + s.calc_cols.mt + s.mt_total + '</td></tr>'; }).join('');
    var pat = Object.keys(a.pattern).map(function (c) { return '<code class="f">' + c + ' ' + esc(a.pattern[c].replace(/\{r\}/g, 'n')) + '</code>'; }).join('');
    var calc = a.calc && a.calc.ok ? 'Recognised: V.C.F. = EXP(−α·Δt·(1+0.8·α·Δt)), α = K0/ρ² + K1/ρ with K0 = ' + a.calc.k0 + ', K1 = ' + a.calc.k1 + ' (' + a.calc.k0_cell + ', ' + a.calc.k1_cell + '), ρ rounded to 2 kg/m³, base ' + a.calc.base + ' °C; G.S.V. = volume × V.C.F.; metric tons = G.S.V. × (density − ' + a.calc.wcf + '). The browser preview and the offline file use the same.' : '<span style="color:#b45309">Not recognised (' + esc((a.calc || {}).why || '') + ') — the Excel file still uses the template\'s formulas; preview / offline calculation is off.</span>';
    var groups = {};
    Object.keys(a.fields).forEach(function (k) { var g = a.fields[k].group; (groups[g] = groups[g] || []).push(k); });
    var mapRows = Object.keys(groups).map(function (g) {
      return '<tr><td colspan="4" class="g"><b>' + esc(S.groups[g] || g) + '</b></td></tr>' + groups[g].map(function (k) {
        var f = a.fields[k], det = f.sheet + '!' + f.cell, ov = map[k] === undefined ? '' : (map[k] === '' ? '-' : map[k]);
        return '<tr><td>' + esc(f.label) + '</td><td><code>' + esc(det) + '</code></td><td><input data-map="' + k + '" value="' + esc(ov) + '" placeholder="detected" class="' + (ov ? 'chg' : '') + '" data-testid="rt-map-' + k + '"></td><td class="g">' + esc(f.type) + '</td></tr>';
      }).join('');
    }).join('');
    var printSel = t.effective_print || [];
    box.innerHTML =
      '<div class="rtp-card" data-testid="rt-detail"><h2><i class="fa-solid fa-clipboard-check"></i> v' + t.version + ' — checks before use</h2>' + flags + '</div>' +
      '<div class="rtp-card"><h2><i class="fa-solid fa-vial"></i> Test generation</h2><div class="sub" style="margin-top:0">Fills a copy of this version with the data already in the workbook (' + Object.keys(a.sections).map(function (k) { return esc(a.sections[k].label.trim()); }).join(', ') + '), recalculates, prints the PDF and compares every tank result and total with the values Excel calculated in the uploaded file.</div>' +
      '<button class="rtp-btn pri" data-test data-testid="rt-test"><i class="fa-solid fa-play"></i> Generate test report</button>' + (S.soffice ? '' : ' <small class="muted">LibreOffice is not installed here — Excel only, no PDF.</small>') + '<div data-test-out></div></div>' +
      '<div class="rtp-grid2"><div class="rtp-card"><h2><i class="fa-solid fa-table"></i> Workbook</h2><table class="rtp-t"><tr><th>Sheets</th><td>' + a.sheets.map(esc).join(', ') + '</td></tr>' +
      '<tr><th>Printed to PDF</th><td>' + a.sheets.map(function (s) { return '<label style="margin-right:10px;font-weight:600"><input type="checkbox" data-print value="' + esc(s) + '"' + (printSel.indexOf(s) >= 0 ? ' checked' : '') + '> ' + esc(s.trim()) + '</label>'; }).join('') + '</td></tr></table>' +
      '<h2 style="margin-top:12px">Tank sections (TABLES 54B)</h2><table class="rtp-t">' + secs + '</table>' +
      '<h2 style="margin-top:12px">Calculation</h2><div style="font-size:12.5px">' + calc + '</div><div style="margin-top:6px">' + pat + '</div></div>' +
      '<div class="rtp-card rtp-map"><h2><i class="fa-solid fa-diagram-project"></i> Cell mapping</h2><div class="sub" style="margin-top:0">Found by the labels in the workbook. To use another cell, type it (e.g. <code>SHIP DETAILS!C12</code>); <code>-</code> leaves the field out.</div>' +
      '<div style="max-height:620px;overflow:auto"><table class="rtp-t"><thead><tr><th>Form field</th><th>Detected</th><th>Override</th><th>Type</th></tr></thead><tbody>' + mapRows + '</tbody></table></div>' +
      '<div style="margin-top:10px"><button class="rtp-btn pri" data-save-map data-testid="rt-save-map"><i class="fa-solid fa-floppy-disk"></i> Save mapping &amp; PDF sheets</button></div></div></div>';
  }
  document.addEventListener('click', function (ev) {
    var o = ev.target.closest('[data-open]'), act = ev.target.closest('[data-activate]');
    if (o) { S.sel = +o.getAttribute('data-open'); renderList(); renderDetail(); $('[data-detail]').scrollIntoView({ behavior: 'smooth' }); }
    if (act) {
      var t = S.list.filter(function (x) { return x.id === +act.getAttribute('data-activate'); })[0];
      Swal.fire({ icon: 'question', title: 'Make v' + t.version + ' active?', text: 'New survey reports will use this version. Reports already made keep their version.', showCancelButton: true, confirmButtonText: 'Make active', confirmButtonColor: '#00A3DF' })
        .then(function (r) { if (r.isConfirmed) post({ a: 'activate', id: t.id }).then(function () { load(true); }); });
    }
    if (ev.target.closest('[data-test]')) {
      var b = ev.target.closest('[data-test]'), out = $('[data-test-out]'), t2 = cur();
      b.disabled = true; out.innerHTML = '<div class="rtp-res">Generating…</div>';
      post({ a: 'test', id: t2.id }).then(function (r) {
        b.disabled = false;
        if (!r.ok) { out.innerHTML = '<div class="rtp-res bad">' + esc(r.error || 'Failed.') + '</div>'; return; }
        var tot = Object.keys(r.totals).map(function (k) { return esc(t2.analysis.sections[k].label.trim()) + ': <b>' + (r.totals[k].mt == null ? '—' : r.totals[k].mt.toFixed(3)) + ' MT</b>'; }).join(' · ');
        var link = function (p, label, ic) { return p ? '<a class="rtp-btn" href="' + API + '?a=file&which=test&id=' + t2.id + '&path=' + encodeURIComponent(p) + '"><i class="fa-solid ' + ic + '"></i> ' + label + '</a> ' : ''; };
        out.innerHTML = '<div class="rtp-res ' + (r.match ? 'ok' : 'bad') + '" data-testid="rt-test-result">' + (r.match ? '<i class="fa-solid fa-circle-check"></i> All ' + r.tank_count + ' tank rows and the section totals match the values Excel calculated in the uploaded file.' : '<i class="fa-solid fa-triangle-exclamation"></i> ' + r.differences.length + ' value(s) differ:<br>' + r.differences.map(function (d) { return esc(d.where) + ': expected ' + d.expected + ', got ' + d.got; }).join('<br>')) +
          '<br>' + tot + (Object.keys(r.errors || {}).length ? '<br><small>Template values the form would not accept: ' + esc(JSON.stringify(r.errors)) + '</small>' : '') + '</div>' +
          '<div style="margin-top:10px">' + link(r.xlsx, 'Test Excel', 'fa-file-excel') + link(r.pdf, 'Test PDF', 'fa-file-pdf') + (r.pdf_error ? '<small class="muted">' + esc(r.pdf_error) + '</small>' : '') + '</div>' +
          (r.pdf ? '<iframe class="rtp-frame" src="' + API + '?a=file&which=test&inline=1&id=' + t2.id + '&path=' + encodeURIComponent(r.pdf) + '" title="Test PDF" data-testid="rt-preview"></iframe>' : '');
      });
    }
    if (ev.target.closest('[data-save-map]')) {
      var t3 = cur(), fields = {}, print = [];
      document.querySelectorAll('[data-map]').forEach(function (i) { fields[i.getAttribute('data-map')] = i.value.trim(); });
      document.querySelectorAll('[data-print]:checked').forEach(function (i) { print.push(i.value); });
      post({ a: 'mapping', id: t3.id, fields: JSON.stringify(fields), print_sheets: JSON.stringify(print) }).then(function (r) {
        if (!r.ok) { Swal.fire({ icon: 'error', title: 'Not saved', text: r.error }); return; }
        Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Mapping saved', showConfirmButton: false, timer: 2000 });
        load(true);
      });
    }
  });
  document.addEventListener('input', function (ev) { if (ev.target.matches('[data-map]')) ev.target.classList.toggle('chg', ev.target.value.trim() !== ''); });
  $('[data-upload]').addEventListener('submit', function (ev) {
    var f = ev.target.file.files[0]; if (!f) return;
    var btn = $('[data-testid=rt-upload]'); btn.disabled = true;
    post({ a: 'upload', kind: KIND, file: f, notes: ev.target.notes.value }).then(function (r) {
      btn.disabled = false;
      if (!r.ok) { Swal.fire({ icon: 'error', title: 'Not uploaded', text: r.error }); return; }
      ev.target.reset();
      Swal.fire({ icon: 'success', title: 'Version ' + r.version + ' stored', text: r.active ? 'It is the active version.' : 'Check it and run the test, then make it active.' });
      S.sel = r.id; load(true);
    });
  });
  load();
})();
</script>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>

<?php
/**
 * Survey Reports — the bunker survey reports entered on the website and filled into the company's
 * Excel report (Admin uploads it under Report Templates). List + "New report"; each report opens in
 * survey_report.php. Data: ajax/survey_reports.php.
 */
require_once 'config/config.php';
require_once 'includes/survey_reports.php';
checkAuth();
if (!srCanUse()) { header('Location: index.php'); exit; }
$page_title = 'Survey Reports';
include 'includes/header.php';
?>
<link rel="stylesheet" href="assets/css/survey-report.css?v=<?= @filemtime(__DIR__ . '/assets/css/survey-report.css') ?>">
<div class="scroll-content" data-testid="survey-reports-page">
<?php $back_url = ''; $page_testid = 'survey-reports'; include 'includes/top_app_bar.php'; ?>
<div class="sr-wrap">
    <div class="sr-head">
        <div><h1><i class="fa-solid fa-file-signature"></i> Survey Reports</h1>
            <div class="sr-sub">Enter the bunker survey on the website; the report is made in the company's own Excel format (and PDF).</div></div>
        <button type="button" class="sr-btn pri" data-new hidden data-testid="sr-new"><i class="fa-solid fa-plus"></i> New report</button>
    </div>
    <div class="sr-note" data-no-template hidden data-testid="sr-no-template"><i class="fa-solid fa-circle-info"></i> No report template is active yet.<?= ($_SESSION['role'] ?? '') === 'Admin' ? ' Upload the Offhire report workbook under <a href="admin_report_templates.php">Report Templates</a>.' : ' An Admin has to upload it first.' ?></div>
    <div class="sr-card"><div data-list data-testid="sr-list"><span class="sr-muted">Loading…</span></div></div>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js"></script>
<script>
(function () {
  var CSRF = <?= json_encode(csrf_token()) ?>, API = 'ajax/survey_reports.php';
  var $ = function (s) { return document.querySelector(s); };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  var ST = { 'Pending Vessel': 'pv', 'Pending Report': 'pr', 'Completed': 'co', 'Cancelled': 'ca' };
  fetch(API + '?a=list', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
    if (!res.ok) { $('[data-list]').textContent = res.error || 'Could not load.'; return; }
    $('[data-new]').hidden = !res.can_create || !res.active;
    $('[data-no-template]').hidden = res.active;
    $('[data-list]').innerHTML = res.reports.length ? '<table class="sr-t"><thead><tr><th>Vessel</th><th>Report No</th><th>Status</th><th>Template</th><th>Last saved</th><th>Files</th><th></th></tr></thead><tbody>' +
      res.reports.map(function (r) {
        return '<tr data-testid="sr-row-' + r.id + '"><td><b>' + esc(r.vessel_name) + '</b></td><td>' + esc(r.report_number || '—') + '</td><td><span class="sr-st ' + (ST[r.status] || '') + '">' + esc(r.status) + '</span></td>' +
          '<td>Offhire v' + esc(r.template_version) + '</td><td>' + esc(String(r.updated_at).slice(0, 16)) + '<br><small class="sr-muted">' + esc(r.updated_by_name || '') + '</small></td>' +
          '<td>' + (+r.files ? r.files + ' file' + (+r.files === 1 ? '' : 's') : '<span class="sr-muted">none yet</span>') + '</td>' +
          '<td><a class="sr-btn" href="survey_report.php?id=' + r.id + '" data-testid="sr-open-' + r.id + '"><i class="fa-solid fa-pen-to-square"></i> Open</a></td></tr>';
      }).join('') + '</tbody></table>' : '<div class="sr-empty"><i class="fa-solid fa-file-circle-plus"></i><div>No survey reports yet.</div></div>';
  });
  $('[data-new]').addEventListener('click', function () {
    Swal.fire({
      title: 'New survey report', width: 560,
      html: '<div style="text-align:left;font-size:13px"><label style="font-weight:700">Vessel</label><input id="srQ" class="swal2-input" style="margin:6px 0;width:100%" placeholder="Search vessel or report no…" data-testid="sr-q">' +
        '<div id="srList" class="sr-pick" data-testid="sr-pick"></div><div style="color:#64748b;font-size:12px;margin-top:8px">Report format: <b>Offhire</b> (active template version).</div></div>',
      showCancelButton: true, confirmButtonText: 'Create report', confirmButtonColor: '#00A3DF', showLoaderOnConfirm: true,
      didOpen: function () {
        var q = document.getElementById('srQ'), t;
        var find = function () { fetch(API + '?a=surveys&q=' + encodeURIComponent(q.value), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
          document.getElementById('srList').innerHTML = (res.surveys || []).map(function (s) { return '<label><input type="radio" name="srS" value="' + s.id + '"> <b>' + esc(s.vessel_name) + '</b> <small>' + esc(s.report_number || '') + ' · ' + esc(s.port_name || '') + ' · ' + esc(s.status) + '</small></label>'; }).join('') || '<div class="sr-muted">No vessel found.</div>';
        }); };
        q.addEventListener('input', function () { clearTimeout(t); t = setTimeout(find, 250); }); find();
      },
      preConfirm: function () {
        var s = document.querySelector('input[name=srS]:checked');
        if (!s) { Swal.showValidationMessage('Choose the vessel.'); return false; }
        var f = new FormData(); f.append('a', 'create'); f.append('survey_id', s.value); f.append('kind', 'offhire'); f.append('csrf_token', CSRF);
        return fetch(API, { method: 'POST', body: f, credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF } }).then(function (r) { return r.json(); })
          .then(function (res) { if (!res.ok) { Swal.showValidationMessage(res.error || 'Not created.'); return false; } return res; });
      }
    }).then(function (r) { if (r.isConfirmed && r.value) location.href = 'survey_report.php?id=' + r.value.id; });
  });
})();
</script>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>

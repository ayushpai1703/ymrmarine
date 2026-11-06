<?php
/**
 * Survey Reports — the bunker survey reports entered on the website and filled into the company's
 * Excel report (Admin uploads it under Report Templates). List only — a report is started from the
 * vessel's page ("Start Survey", includes/survey_report_card.php); each opens in survey_report.php.
 * Not in the menu. Data: ajax/survey_reports.php.
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
            <div class="sr-sub">A survey is started from the vessel's page (Pending Vessels → vessel → Start Survey); the report is made in the company's own Excel format (and PDF).</div></div>
    </div>
    <div class="sr-note" data-no-template hidden data-testid="sr-no-template"><i class="fa-solid fa-circle-info"></i> No report template is uploaded yet.<?= ($_SESSION['role'] ?? '') === 'Admin' ? ' Upload the report workbooks under <a href="admin_report_templates.php">Report Templates</a>.' : ' An Admin has to upload it first.' ?></div>
    <div class="sr-card"><div data-list data-testid="sr-list"><span class="sr-muted">Loading…</span></div></div>
</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js"></script>
<script>
(function () {
  var API = 'ajax/survey_reports.php', KINDS = [];
  var $ = function (s) { return document.querySelector(s); };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  fetch(API + '?a=list', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
    if (!res.ok) { $('[data-list]').textContent = res.error || 'Could not load.'; return; }
    KINDS = res.kinds || [];
    $('[data-no-template]').hidden = res.active;
    var admin = res.is_admin;
    $('[data-list]').innerHTML = res.reports.length ? '<div style="overflow-x:auto"><table class="sr-t"><thead><tr><th>Vessel</th><th>Report type</th><th>Made by</th><th>Last saved</th><th>Files</th>' + (admin ? '<th>Tank changes</th>' : '') + '<th></th></tr></thead><tbody>' +
      res.reports.map(function (r) {
        var dl = (r.last_pdf ? '<a href="' + API + '?a=file&id=' + r.last_pdf + '" data-testid="sr-list-pdf-' + r.id + '"><i class="fa-solid fa-file-pdf" style="color:#dc2626"></i> PDF</a> ' : '') +
          (r.last_xlsx ? '<a href="' + API + '?a=file&id=' + r.last_xlsx + '" data-testid="sr-list-xlsx-' + r.id + '"><i class="fa-solid fa-file-excel" style="color:#16a34a"></i> Excel</a>' : '');
        return '<tr data-testid="sr-row-' + r.id + '"><td><b>' + esc(r.vessel_name || 'Not entered yet') + '</b>' + (r.report_number ? '<br><small class="sr-muted">' + esc(r.report_number) + '</small>' : '') + '</td>' +
          '<td>' + esc(r.kind_label) + ' <small class="sr-muted">v' + esc(r.template_version) + '</small></td><td>' + esc(r.created_by_name || '') + '</td>' +
          '<td>' + esc(String(r.updated_at).slice(0, 16)) + '<br><small class="sr-muted">' + esc(r.updated_by_name || '') + '</small></td>' +
          '<td>' + (+r.files ? dl + '<br><small class="sr-muted">' + r.files + ' file' + (+r.files === 1 ? '' : 's') + ', latest ' + esc(String(r.last_file).slice(0, 16)) + '</small>' : '<span class="sr-muted">none yet</span>') + '</td>' +
          (admin ? '<td data-testid="sr-list-changes-' + r.id + '">' + (+r.tank_changes ? '<b style="color:#b45309">' + r.tank_changes + '</b> change' + (+r.tank_changes === 1 ? '' : 's') : '<span class="sr-muted">0</span>') + '</td>' : '') +
          '<td><a class="sr-btn" href="survey_report.php?id=' + r.id + '" data-testid="sr-open-' + r.id + '"><i class="fa-solid fa-pen-to-square"></i> Open</a></td></tr>';
      }).join('') + '</tbody></table></div>' : '<div class="sr-empty"><i class="fa-solid fa-file-circle-plus"></i><div>No survey reports yet.</div></div>';
  });
})();
</script>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>

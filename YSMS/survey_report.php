<?php
/**
 * One survey report, as a wizard: vessel particulars (IMO lookup) → survey particulars → one
 * step of tank cards per fuel grade on board → ROB summary with the PDF / Excel downloads
 * (company template, includes/survey_reports.php srBuildBook).
 * Works offline after it has been opened once online (survey_report_sw.js): changes are kept on
 * the device and saved when the connection is back; a draft PDF can be made on the device too
 * (jsPDF), the final one is made when the connection is back. Editor: assets/js/survey-report.js.
 */
require_once 'config/config.php';
require_once 'includes/survey_reports.php';
checkAuth();
if (!srCanUse()) { header('Location: index.php'); exit; }
$id = (int)($_GET['id'] ?? 0);
// Opened without an id: choose the report type and a new report (not tied to a vessel) is made, then it opens.
if ($id <= 0) {
    $srKinds = [];
    try { $srDb = getDB(); rtEnsureTables($srDb); foreach (RT_KINDS as $k => $l) if (rtActive($srDb, $k)) $srKinds[$k] = $l; }
    catch (Throwable $e) { error_log('survey_report new: ' . $e->getMessage()); }
    $srCanNew = in_array(srRole(), ['Admin', 'Surveyor'], true);
    $page_title = 'New Survey Report';
    include 'includes/header.php';
    ?>
<link rel="stylesheet" href="assets/css/survey-report.css?v=<?= @filemtime(__DIR__ . '/assets/css/survey-report.css') ?>">
<div class="scroll-content" data-testid="survey-report-new-page">
<?php $back_url = 'survey_reports.php'; $page_testid = 'survey-report-new'; include 'includes/top_app_bar.php'; ?>
<div class="sr-wrap sr-v4">
    <section class="sr-card sr-newrep" data-testid="sr-new">
        <h3><i class="fa-solid fa-file-circle-plus"></i> New Survey Report</h3>
        <?php if (!$srCanNew): ?>
            <div class="sr-note warn">Only an Admin or a Surveyor can make a report.</div>
        <?php elseif (!$srKinds): ?>
            <div class="sr-note warn">No report template is uploaded yet. An Admin uploads it under Report Templates.</div>
        <?php else: ?>
            <p class="sr-muted">Choose the report type. The report is not tied to a vessel; enter the vessel in the first step.</p>
            <div class="sr-newrep-k">
                <?php foreach ($srKinds as $k => $l): ?>
                    <button type="button" class="sr-btn" data-new-kind="<?= sanitize($k) ?>" data-testid="sr-new-<?= sanitize($k) ?>"><i class="fa-solid fa-file-lines"></i> <?= sanitize($l) ?></button>
                <?php endforeach; ?>
            </div>
            <div class="sr-note err" data-new-err hidden data-testid="sr-new-err"></div>
        <?php endif; ?>
    </section>
</div>
</div>
<script>
(function () {
  var csrf = <?= json_encode(csrf_token()) ?>, busy = false;
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-new-kind]'); if (!b || busy) return;
    busy = true; b.disabled = true;
    var f = new FormData(); f.append('csrf_token', csrf); f.append('a', 'new'); f.append('kind', b.getAttribute('data-new-kind'));
    fetch('ajax/survey_reports.php', { method: 'POST', body: f, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf } })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok && j.id) { location.replace('survey_report.php?id=' + j.id); return; } throw new Error(j && j.error || 'Could not make the report.'); })
      .catch(function (err) { busy = false; b.disabled = false; var m = document.querySelector('[data-new-err]'); if (m) { m.hidden = false; m.textContent = err.message || 'No connection.'; } });
  });
})();
</script>
<?php include 'includes/nav.php'; include 'includes/footer.php'; exit;
}
// back to the vessel the survey belongs to
$srBack = 'vessels.php'; $srBackLabel = 'Pending Vessels';
try {
    $srR = srReport(getDB(), $id);
    if ($srR && !empty($srR['survey'])) {
        $sv = $srR['survey'];
        [$srBack, $srBackLabel] = match ((string)$sv['status']) {
            'Pending Report' => ['report_detail.php?id=' . (int)$sv['id'], (string)$sv['vessel_name']],
            'Completed' => ['completed_detail.php?id=' . (int)$sv['id'], (string)$sv['vessel_name']],
            default => ['vessel_detail.php?id=' . (int)$sv['id'], (string)$sv['vessel_name']],
        };
    } elseif ($srR) { $srBack = 'survey_reports.php'; $srBackLabel = 'Survey Reports'; }
} catch (Throwable $e) { error_log('survey_report back link: ' . $e->getMessage()); }
$page_title = 'Survey Report';
include 'includes/header.php';
?>
<link rel="stylesheet" href="assets/css/survey-report.css?v=<?= @filemtime(__DIR__ . '/assets/css/survey-report.css') ?>">
<div class="scroll-content" data-testid="survey-report-page">
<?php $back_url = $srBack; $page_testid = 'survey-report'; include 'includes/top_app_bar.php'; ?>
<div class="sr-wrap sr-v4">
    <div class="sr-head">
        <div class="sr-crumbs"><a href="<?= sanitize($srBack) ?>" class="sr-back" data-testid="sr-back" title="Back to <?= sanitize($srBackLabel) ?>"><i class="fa-solid fa-arrow-left"></i></a>
            <span class="sr-crumb"><span data-kindcrumb>Survey</span> Report <i>/</i> <b data-crumb>Vessel Details</b></span>
            <h1 data-title hidden>Survey report</h1><span data-subtitle hidden></span></div>
        <div class="sr-head-r">
            <span class="sr-chip-h"><span>Vessel:</span> <b data-vchip data-testid="sr-vchip">—</b></span>
            <span class="sr-chip-h"><span>Report:</span> <b data-kchip>—</b></span>
            <span class="sr-chip-h"><i class="fa-regular fa-calendar"></i> <span>Date:</span> <b data-dchip>—</b></span>
            <span class="sr-net" data-net data-testid="sr-net"></span>
            <span class="sr-save" data-save-state data-testid="sr-save-state"></span>
            <span class="sr-live" data-live hidden data-testid="sr-live"></span>
            <button type="button" class="sr-pv-fab" data-pv="open" data-testid="sr-pv-open"><i class="fa-solid fa-eye"></i> Preview</button>
        </div>
    </div>
    <div class="sr-split">
        <div class="sr-split-l">
            <div data-banners></div>
            <div data-form data-testid="sr-form"></div>
        </div>
        <aside class="sr-pv" data-testid="sr-preview" aria-label="Report preview">
            <div class="sr-pv-tabs">
                <button type="button" class="sr-pv-tab" data-pv="load" data-pv-tab="pdf" data-testid="sr-preview-report"><i class="fa-regular fa-file-lines"></i> Report Preview</button>
                <button type="button" class="sr-pv-tab" data-pv="excel" data-pv-tab="excel" data-testid="sr-excel-view"><i class="fa-solid fa-table"></i> Excel View</button>
                <span class="sr-pv-sp"></span>
                <button type="button" class="sr-pv-b sr-pv-exp" data-pv="open" title="Full screen" aria-label="Full screen" data-testid="sr-pv-expand"><i class="fa-solid fa-up-right-and-down-left-from-center"></i></button>
                <button type="button" class="sr-pv-b sr-pv-x" data-pv="close" title="Close" aria-label="Close preview" data-testid="sr-pv-close"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="sr-pv-bar" data-pv-bar hidden>
                <span class="sr-pv-st" data-pv-status data-testid="sr-pv-status"></span>
                <span class="sr-pv-sp"></span>
                <button type="button" class="sr-pv-b" data-pv="prev" title="Previous page" aria-label="Previous page" data-testid="sr-pv-prev"><i class="fa-solid fa-chevron-left"></i></button>
                <span class="sr-pv-n" data-pv-page data-testid="sr-pv-pageno">– / –</span>
                <button type="button" class="sr-pv-b" data-pv="next" title="Next page" aria-label="Next page" data-testid="sr-pv-next"><i class="fa-solid fa-chevron-right"></i></button>
                <button type="button" class="sr-pv-b" data-pv="out" title="Zoom out" aria-label="Zoom out" data-testid="sr-pv-out"><i class="fa-solid fa-magnifying-glass-minus"></i></button>
                <button type="button" class="sr-pv-b sr-pv-z" data-pv="fit" title="Fit width" data-pv-zoomv data-testid="sr-pv-zoom">100%</button>
                <button type="button" class="sr-pv-b" data-pv="in" title="Zoom in" aria-label="Zoom in" data-testid="sr-pv-in"><i class="fa-solid fa-magnifying-glass-plus"></i></button>
            </div>
            <div class="sr-pv-c" data-pv-canvas data-testid="sr-pv-canvas"><div class="sr-pv-empty" data-testid="sr-pv-empty"><i class="fa-regular fa-file-lines"></i><b>Report preview</b><span>Press <b>Report Preview</b> to see the report as it will print, or <b>Excel View</b> for the sheets. It is made from the saved values when you press it — not while you type.</span></div></div>
            <div class="sr-pv-note">Preview only — nothing is stored, the vessel's status does not change.</div>
        </aside>
    </div>
</div>
</div>
<script>window.SR_PAGE = <?= json_encode(['id' => $id, 'csrf' => csrf_token(), 'api' => 'ajax/survey_reports.php',
    'pdfjs' => 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js', 'pdfjsWorker' => 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
<script src="assets/js/report-calc.js?v=<?= @filemtime(__DIR__ . '/assets/js/report-calc.js') ?>"></script>
<script src="assets/js/survey-report.js?v=<?= @filemtime(__DIR__ . '/assets/js/survey-report.js') ?>"></script>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>

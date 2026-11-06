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
<div class="sr-wrap">
    <div class="sr-head">
        <div><a href="<?= sanitize($srBack) ?>" class="sr-back" data-testid="sr-back"><i class="fa-solid fa-chevron-left"></i> <?= sanitize($srBackLabel) ?></a>
            <h1 data-title>Survey report</h1><div class="sr-sub" data-subtitle>Loading…</div></div>
        <div class="sr-head-r">
            <span class="sr-net" data-net data-testid="sr-net"></span>
            <span class="sr-save" data-save-state data-testid="sr-save-state"></span>
            <span class="sr-live" data-live hidden data-testid="sr-live"></span>
            <button type="button" class="sr-btn" data-save-now data-testid="sr-save-now"><i class="fa-solid fa-floppy-disk"></i> Save Progress</button>
            <button type="button" class="sr-btn pri" data-pv="open" data-testid="sr-preview-report"><i class="fa-solid fa-eye"></i> Preview Report</button>
        </div>
    </div>
    <div class="sr-split">
        <div class="sr-split-l">
            <div data-banners></div>
            <div data-form data-testid="sr-form"></div>
        </div>
        <aside class="sr-pv" data-testid="sr-preview" aria-label="Report preview" role="dialog">
            <div class="sr-pv-bar">
                <b class="sr-pv-t"><i class="fa-solid fa-eye"></i> Report preview</b>
                <span class="sr-pv-st" data-pv-status data-testid="sr-pv-status">Loading…</span>
                <span class="sr-pv-sp"></span>
                <button type="button" class="sr-pv-b" data-pv="prev" title="Previous page" aria-label="Previous page" data-testid="sr-pv-prev"><i class="fa-solid fa-chevron-left"></i></button>
                <span class="sr-pv-n" data-pv-page data-testid="sr-pv-pageno">– / –</span>
                <button type="button" class="sr-pv-b" data-pv="next" title="Next page" aria-label="Next page" data-testid="sr-pv-next"><i class="fa-solid fa-chevron-right"></i></button>
                <button type="button" class="sr-pv-b" data-pv="out" title="Zoom out" aria-label="Zoom out" data-testid="sr-pv-out"><i class="fa-solid fa-magnifying-glass-minus"></i></button>
                <button type="button" class="sr-pv-b sr-pv-z" data-pv="fit" title="Fit width" data-pv-zoomv data-testid="sr-pv-zoom">100%</button>
                <button type="button" class="sr-pv-b" data-pv="in" title="Zoom in" aria-label="Zoom in" data-testid="sr-pv-in"><i class="fa-solid fa-magnifying-glass-plus"></i></button>
                <button type="button" class="sr-pv-b" data-pv="refresh" title="Refresh" aria-label="Refresh preview" data-testid="sr-pv-refresh"><i class="fa-solid fa-rotate"></i></button>
                <button type="button" class="sr-pv-b sr-pv-x" data-pv="close" title="Close" aria-label="Close preview" data-testid="sr-pv-close"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="sr-pv-c" data-pv-canvas data-testid="sr-pv-canvas"></div>
            <div class="sr-pv-note">Preview only — not a report: nothing is stored, the vessel's status does not change. Download the final PDF on the Summary step.</div>
        </aside>
    </div>
    <button type="button" class="sr-pv-fab" data-pv="open" data-testid="sr-pv-open"><i class="fa-solid fa-eye"></i> Preview Report</button>
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

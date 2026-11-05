<?php
/**
 * One survey report, as a wizard: vessel particulars (IMO lookup) → survey particulars → one
 * step of tank cards per fuel grade on board → ROB summary with the PDF / Excel downloads
 * (company template, includes/survey_reports.php srBuildBook).
 * Works offline after it has been opened once online (survey_report_sw.js): changes are kept on
 * the device and saved when the connection is back; the Excel can be made on the device too
 * (assets/js/report-offline-xlsx.js). Editor: assets/js/survey-report.js.
 */
require_once 'config/config.php';
require_once 'includes/survey_reports.php';
checkAuth();
if (!srCanUse()) { header('Location: index.php'); exit; }
$id = (int)($_GET['id'] ?? 0);
$page_title = 'Survey Report';
include 'includes/header.php';
?>
<link rel="stylesheet" href="assets/css/survey-report.css?v=<?= @filemtime(__DIR__ . '/assets/css/survey-report.css') ?>">
<div class="scroll-content" data-testid="survey-report-page">
<?php $back_url = 'survey_reports.php'; $page_testid = 'survey-report'; include 'includes/top_app_bar.php'; ?>
<div class="sr-wrap">
    <div class="sr-head">
        <div><a href="survey_reports.php" class="sr-back"><i class="fa-solid fa-chevron-left"></i> Survey Reports</a>
            <h1 data-title>Survey report</h1><div class="sr-sub" data-subtitle>Loading…</div></div>
        <div class="sr-head-r">
            <span class="sr-net" data-net data-testid="sr-net"></span>
            <span class="sr-save" data-save-state data-testid="sr-save-state"></span>
        </div>
    </div>
    <div data-banners></div>
    <div data-form data-testid="sr-form"></div>
</div>
</div>
<script>window.SR_PAGE = <?= json_encode(['id' => $id, 'csrf' => csrf_token(), 'api' => 'ajax/survey_reports.php'], JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js"></script>
<script src="assets/vendor/exceljs/exceljs.min.js?v=4.4.0"></script>
<script src="assets/js/report-calc.js?v=<?= @filemtime(__DIR__ . '/assets/js/report-calc.js') ?>"></script>
<script src="assets/js/report-offline-xlsx.js?v=<?= @filemtime(__DIR__ . '/assets/js/report-offline-xlsx.js') ?>"></script>
<script src="assets/js/survey-report.js?v=<?= @filemtime(__DIR__ . '/assets/js/survey-report.js') ?>"></script>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>

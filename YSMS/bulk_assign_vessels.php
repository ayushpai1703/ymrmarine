<?php
require_once 'config/config.php';
require_once 'includes/bulk_assign_helpers.php';
checkAuth();
if (($_SESSION['role'] ?? '') !== 'Admin') {
    header('Location: index.php');
    exit;
}
$db = getDB();
ensureBulkAssignmentTables($db);

include 'includes/header.php';
?>
<style>
    .ba-hero {
        position: relative; overflow: hidden; border-radius: 22px;
        background: linear-gradient(120deg, #0f172a 0%, #1E1B6B 48%, #4B2FD6 100%);
        padding: 22px 24px; margin: 12px 12px 16px;
        display: flex; align-items: center; gap: 16px;
        box-shadow: 0 14px 34px rgba(30,27,107,.22);
    }
    .ba-hero-icon-bg { position: absolute; right: -22px; top: -34px; font-size: 150px; color: rgba(255,255,255,.07); pointer-events: none; line-height: 1; }
    .ba-hero-ico { width: 50px; height: 50px; border-radius: 15px; flex: none; position: relative; background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.18); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 21px; }
    .ba-hero-title { font-size: 20px; font-weight: 800; color: #fff; letter-spacing: -.01em; position: relative; }
    .ba-hero-sub { font-size: 12px; color: rgba(255,255,255,.66); margin-top: 3px; font-weight: 500; position: relative; }

    .ba-toolbar { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 12px 16px; }
    .ba-pill { display: inline-flex; align-items: center; gap: 7px; padding: 9px 16px; border-radius: 999px; font-size: 12.5px; font-weight: 700; text-decoration: none; border: 1.5px solid #e2e8f0; color: #334155; background: #fff; transition: all .15s ease; }
    .ba-pill:hover { border-color: #A78BFA; color: #4B2FD6; }
    .ba-pill.active { background: linear-gradient(120deg, #1E1B6B, #4B2FD6); color: #fff; border-color: transparent; box-shadow: 0 6px 16px rgba(75,47,214,.28); }

    .ba-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 18px; padding: 22px; margin: 0 12px 16px; box-shadow: 0 4px 20px rgba(15,23,42,.05); }
    .ba-card-head { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; }
    .ba-card-head-ico { width: 30px; height: 30px; border-radius: 9px; flex: none; background: linear-gradient(135deg, #8B7CF6, #4B2FD6); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 13px; }
    .ba-card-head-title { font-size: 15px; font-weight: 800; color: #0f172a; }
    .ba-card-head-sub { font-size: 11.5px; color: #94a3b8; font-weight: 500; margin-top: 1px; }

    .ba-drop {
        border: 2px dashed #cbd5e1; border-radius: 16px; padding: 32px 20px; text-align: center; cursor: pointer;
        background: #F8FAFC; transition: border-color .15s ease, background .15s ease;
    }
    .ba-drop:hover, .ba-drop.drag-over { border-color: #A78BFA; background: #F5F3FF; }
    .ba-drop i { font-size: 30px; color: #A78BFA; margin-bottom: 10px; display: block; }
    .ba-drop-title { font-size: 13.5px; font-weight: 700; color: #334155; }
    .ba-drop-sub { font-size: 11.5px; color: #94a3b8; margin-top: 4px; }
    .ba-file-chosen { display: none; align-items: center; gap: 10px; background: #F0FDF4; border: 1.5px solid #BBF7D0; border-radius: 12px; padding: 10px 14px; margin-top: 12px; font-size: 12.5px; font-weight: 600; color: #166534; }
    .ba-file-chosen i { color: #16A34A; }

    .ba-btn { display: inline-flex; align-items: center; gap: 8px; border: none; border-radius: 12px; padding: 11px 20px; font-size: 13px; font-weight: 700; cursor: pointer; transition: all .15s ease; }
    .ba-btn-primary { background: linear-gradient(120deg, #1E1B6B, #4B2FD6); color: #fff; box-shadow: 0 6px 16px rgba(75,47,214,.28); }
    .ba-btn-primary:hover { box-shadow: 0 10px 22px rgba(75,47,214,.38); transform: translateY(-1px); }
    .ba-btn-primary:disabled { opacity: .5; cursor: not-allowed; transform: none; box-shadow: none; }
    .ba-btn-ghost { background: #F1F5F9; color: #334155; }
    .ba-btn-ghost:hover { background: #E2E8F0; }
    .ba-btn-success { background: linear-gradient(120deg, #14532D, #16A34A); color: #fff; box-shadow: 0 6px 16px rgba(22,163,74,.28); }

    .ba-meta-row { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 16px; }
    .ba-meta-chip { background: #F8FAFC; border: 1px solid #e2e8f0; border-radius: 12px; padding: 8px 14px; font-size: 12px; }
    .ba-meta-chip b { color: #0f172a; font-weight: 700; }
    .ba-meta-chip span.lbl { color: #94a3b8; font-weight: 600; display: block; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 2px; }

    .ba-sheet-select { border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 8px 12px; font-size: 12.5px; font-weight: 600; color: #334155; background: #F8FAFC; }

    .ba-map-list { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 4px; }
    .ba-map-chip { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 999px; font-size: 11.5px; font-weight: 600; }
    .ba-map-chip.ok { background: #F0FDF4; color: #166534; border: 1px solid #BBF7D0; }
    .ba-map-chip.no { background: #F8FAFC; color: #94a3b8; border: 1px solid #e2e8f0; }
    .ba-map-chip i { font-size: 10px; }

    .ba-missing { background: #FEF2F2; border: 1.5px solid #FECACA; border-radius: 12px; padding: 12px 14px; font-size: 12.5px; color: #991B1B; font-weight: 600; margin-top: 10px; }

    .ba-summary-row { display: flex; flex-wrap: wrap; gap: 10px; margin: 16px 0; }
    .ba-stat { flex: 1 1 120px; border-radius: 14px; padding: 12px 14px; text-align: center; }
    .ba-stat .n { font-size: 20px; font-weight: 800; }
    .ba-stat .l { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; margin-top: 2px; }
    .ba-stat.total { background: #F1F5F9; } .ba-stat.total .n { color: #334155; }
    .ba-stat.valid { background: #F0FDF4; } .ba-stat.valid .n { color: #16A34A; }
    .ba-stat.dup { background: #FFFBEB; } .ba-stat.dup .n { color: #D97706; }
    .ba-stat.err { background: #FEF2F2; } .ba-stat.err .n { color: #DC2626; }

    .ba-table-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 14px; max-height: 480px; overflow-y: auto; }
    table.ba-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
    table.ba-table thead th { position: sticky; top: 0; background: #F8FAFC; text-align: left; padding: 10px 12px; font-weight: 700; color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: .03em; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
    table.ba-table tbody td { padding: 9px 12px; border-bottom: 1px solid #f1f5f9; color: #334155; white-space: nowrap; }
    table.ba-table tbody tr:hover { background: #F8FAFC; }
    .ba-status-badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 999px; font-size: 10.5px; font-weight: 700; white-space: nowrap; }
    .ba-status-badge.s-valid, .ba-status-badge.s-assigned { background: #F0FDF4; color: #166534; }
    .ba-status-badge.s-dup, .ba-status-badge.s-already { background: #FFFBEB; color: #B45309; }
    .ba-status-badge.s-err { background: #FEF2F2; color: #B91C1C; }
    .ba-row-msg { max-width: 260px; white-space: normal; color: #94a3b8; font-size: 11.5px; }

    .ba-progress-wrap { background: #F1F5F9; border-radius: 999px; height: 10px; overflow: hidden; margin: 14px 0 8px; }
    .ba-progress-bar { height: 100%; background: linear-gradient(120deg, #1E1B6B, #4B2FD6); width: 0%; transition: width .2s ease; border-radius: 999px; }
    .ba-progress-label { font-size: 12px; font-weight: 700; color: #64748b; text-align: center; }

    .ba-result-hero { text-align: center; padding: 20px 10px; }
    .ba-result-hero i { font-size: 44px; color: #16A34A; margin-bottom: 10px; }
    .ba-result-hero h3 { font-size: 18px; font-weight: 800; color: #0f172a; margin-bottom: 4px; }
    .ba-result-actions { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; margin-top: 16px; }

    #baStep2, #baStep3, #baStep4 { display: none; }

    @media (min-width: 992px) {
        .top-app-bar { display: none !important; }
        .scroll-content { max-width: 1200px; margin: 0 auto; padding: 8px 20px 40px; }
        .ba-hero, .ba-toolbar, .ba-card { margin-left: 0; margin-right: 0; }
    }
</style>

<?php
$page_title = 'Bulk Upload Excel';
$back_url = 'assign_vessel.php';
$page_testid = 'bulk-assign';
include 'includes/top_app_bar.php';
?>

<div class="scroll-content">
    <div class="ba-hero">
        <i class="fa-solid fa-file-excel ba-hero-icon-bg" aria-hidden="true"></i>
        <div class="ba-hero-ico"><i class="fa-solid fa-file-excel"></i></div>
        <div>
            <div class="ba-hero-title">Bulk Upload Excel</div>
            <div class="ba-hero-sub">Upload your existing vessel-assignment Excel and assign many vessels at once</div>
        </div>
    </div>

    <div class="ba-toolbar">
        <a href="assign_vessel.php" class="ba-pill" data-testid="ba-nav-assign"><i class="fa-solid fa-plus"></i> Assign Vessel</a>
        <a href="bulk_assign_vessels.php" class="ba-pill active" data-testid="ba-nav-bulk"><i class="fa-solid fa-file-excel"></i> Bulk Upload Excel</a>
        <a href="bulk_assignment_history.php" class="ba-pill" data-testid="ba-nav-history"><i class="fa-solid fa-clock-rotate-left"></i> Assignment History</a>
    </div>

    <!-- STEP 1: Upload -->
    <div class="ba-card" id="baStep1">
        <div class="ba-card-head">
            <div class="ba-card-head-ico"><i class="fa-solid fa-cloud-arrow-up"></i></div>
            <div>
                <div class="ba-card-head-title">Upload your Excel file</div>
                <div class="ba-card-head-sub">.xlsx, .xls or .csv — use the file you already have, no template needed</div>
            </div>
        </div>
        <label class="ba-drop" id="baDropZone" data-testid="ba-drop-zone">
            <i class="fa-solid fa-file-arrow-up"></i>
            <div class="ba-drop-title">Click to choose a file, or drag it here</div>
            <div class="ba-drop-sub">Your existing vessel/surveyor Excel sheet — columns are detected automatically</div>
            <input type="file" id="baFileInput" accept=".xlsx,.xls,.csv" style="display:none;" data-testid="ba-file-input">
        </label>
        <div class="ba-file-chosen" id="baFileChosen"><i class="fa-solid fa-circle-check"></i> <span id="baFileChosenName"></span></div>
        <div class="mt-3">
            <button type="button" class="ba-btn ba-btn-primary" id="baUploadBtn" disabled data-testid="ba-upload-btn">
                <i class="fa-solid fa-magnifying-glass-chart"></i> Upload &amp; Preview
            </button>
        </div>
        <div id="baUploadError" class="ba-missing" style="display:none;"></div>
    </div>

    <!-- STEP 2: Mapping + Preview -->
    <div class="ba-card" id="baStep2" data-testid="ba-step2">
        <div class="ba-card-head">
            <div class="ba-card-head-ico"><i class="fa-solid fa-table-columns"></i></div>
            <div>
                <div class="ba-card-head-title">Column mapping &amp; preview</div>
                <div class="ba-card-head-sub">Review how your columns were understood, then check each row</div>
            </div>
        </div>

        <div class="ba-meta-row">
            <div class="ba-meta-chip"><span class="lbl">File</span><b id="baMetaFile">—</b></div>
            <div class="ba-meta-chip"><span class="lbl">Sheet</span><b id="baMetaSheetWrap">—</b></div>
            <div class="ba-meta-chip"><span class="lbl">Rows</span><b id="baMetaRows">—</b></div>
        </div>

        <div class="ba-map-list" id="baMapList"></div>
        <div id="baMissingBox" class="ba-missing" style="display:none;"></div>

        <div class="ba-summary-row" id="baSummaryRow" style="display:none;">
            <div class="ba-stat total"><div class="n" id="baSumTotal">0</div><div class="l">Total Rows</div></div>
            <div class="ba-stat valid"><div class="n" id="baSumValid">0</div><div class="l">Valid</div></div>
            <div class="ba-stat dup"><div class="n" id="baSumDup">0</div><div class="l">Already Assigned</div></div>
            <div class="ba-stat err"><div class="n" id="baSumErr">0</div><div class="l">Errors</div></div>
        </div>

        <div class="ba-table-wrap" id="baPreviewTableWrap" style="display:none;">
            <table class="ba-table">
                <thead>
                    <tr>
                        <th>#</th><th>Vessel</th><th>IMO</th><th>Surveyor</th><th>Client</th><th>Port</th><th>Survey Type</th><th>Status</th><th>Message</th>
                    </tr>
                </thead>
                <tbody id="baPreviewTbody"></tbody>
            </table>
        </div>

        <div class="mt-3 d-flex flex-wrap gap-2">
            <button type="button" class="ba-btn ba-btn-ghost" id="baBackBtn" data-testid="ba-back-btn"><i class="fa-solid fa-arrow-left"></i> Choose a different file</button>
            <button type="button" class="ba-btn ba-btn-primary" id="baConfirmBtn" disabled data-testid="ba-confirm-btn">
                <i class="fa-solid fa-check-double"></i> Confirm Bulk Assignment
            </button>
        </div>
    </div>

    <!-- STEP 3: Progress -->
    <div class="ba-card" id="baStep3" data-testid="ba-step3">
        <div class="ba-card-head">
            <div class="ba-card-head-ico"><i class="fa-solid fa-spinner fa-spin"></i></div>
            <div>
                <div class="ba-card-head-title">Processing your assignments…</div>
                <div class="ba-card-head-sub">Please keep this tab open until it finishes</div>
            </div>
        </div>
        <div class="ba-progress-wrap"><div class="ba-progress-bar" id="baProgressBar"></div></div>
        <div class="ba-progress-label" id="baProgressLabel">Processing 0 / 0</div>
    </div>

    <!-- STEP 4: Result -->
    <div class="ba-card" id="baStep4" data-testid="ba-step4">
        <div class="ba-result-hero">
            <i class="fa-solid fa-circle-check"></i>
            <h3>Bulk Assignment Completed</h3>
            <div class="ba-summary-row" id="baResultSummary"></div>
            <div class="ba-result-actions">
                <a href="bulk_assignment_history.php" class="ba-btn ba-btn-ghost"><i class="fa-solid fa-clock-rotate-left"></i> View Assignment History</a>
                <button type="button" class="ba-btn ba-btn-ghost" id="baDownloadErrorsBtn" style="display:none;"><i class="fa-solid fa-file-arrow-down"></i> Download Error Excel</button>
                <a href="bulk_assign_vessels.php" class="ba-btn ba-btn-primary"><i class="fa-solid fa-arrow-rotate-left"></i> Upload Another File</a>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
// 🌟 includes/footer.php loads its own copy of jQuery again further down
// this page — that second <script src> creates a brand new jQuery instance
// and silently discards any $.ajaxSetup() defaults set here (proven: same
// URL loaded twice via <script src> does NOT return the same $ object, and
// the new instance's ajaxSettings.headers comes back empty). So this
// ajaxSetup call alone is NOT reliable for a request made after the page
// has fully loaded — every $.ajax() call below also passes this header
// explicitly, which is what actually keeps CSRF working.
$.ajaxSetup({ headers: { 'X-CSRF-Token': CSRF_TOKEN } });

let baBatchToken = null;
let baBatchId = null;
let baTotalRows = 0;
let baFile = null;

const $step1 = $('#baStep1'), $step2 = $('#baStep2'), $step3 = $('#baStep3'), $step4 = $('#baStep4');

$('#baDropZone').on('click', function (e) { /* label already triggers file input */ });
$('#baDropZone').on('dragover', function (e) { e.preventDefault(); $(this).addClass('drag-over'); });
$('#baDropZone').on('dragleave', function (e) { $(this).removeClass('drag-over'); });
$('#baDropZone').on('drop', function (e) {
    e.preventDefault(); $(this).removeClass('drag-over');
    const files = e.originalEvent.dataTransfer.files;
    if (files && files.length) { $('#baFileInput')[0].files = files; handleFileChosen(files[0]); }
});
$('#baFileInput').on('change', function () {
    if (this.files && this.files[0]) handleFileChosen(this.files[0]);
});

function handleFileChosen(file) {
    const ext = file.name.split('.').pop().toLowerCase();
    if (!['xlsx', 'xls', 'csv'].includes(ext)) {
        showUploadError('Only .xlsx, .xls or .csv files are allowed.');
        return;
    }
    baFile = file;
    $('#baFileChosenName').text(file.name);
    $('#baFileChosen').css('display', 'flex');
    $('#baUploadBtn').prop('disabled', false);
    $('#baUploadError').hide();
}

function showUploadError(msg) {
    $('#baUploadError').text(msg).show();
}

// Turns a failed $.ajax() call into a real, specific reason instead of a
// generic "check your connection" message — shows the server's own JSON
// message when there is one, otherwise the HTTP status, so a genuine server
// error (500, session expired -> redirected to login, CSRF mismatch, etc.)
// is actually visible instead of always looking like a network problem.
function describeAjaxFailure(jqXHR) {
    if (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.message) {
        return jqXHR.responseJSON.message;
    }
    if (jqXHR && typeof jqXHR.responseText === 'string' && /^\s*</.test(jqXHR.responseText)) {
        // Got an HTML page back instead of JSON — most often means the
        // session expired and this request was redirected to the login
        // page, or a server error page was returned.
        return 'Your session may have expired. Please refresh the page, log in again, and retry.';
    }
    if (jqXHR && jqXHR.status === 0) {
        return 'Could not reach the server. Please check your internet connection and try again.';
    }
    if (jqXHR && jqXHR.status === 403) {
        return 'Your session may have expired, or this action is not permitted. Please refresh the page and log in again, then retry.';
    }
    if (jqXHR && jqXHR.status === 413) {
        return 'This file is too large for the server to accept. Please try a smaller file.';
    }
    if (jqXHR && jqXHR.status >= 500) {
        return 'The server hit an error while processing this (HTTP ' + jqXHR.status + '). Please try again, or contact support if it keeps happening.';
    }
    if (jqXHR && jqXHR.status) {
        return 'Request failed (HTTP ' + jqXHR.status + '). Please try again.';
    }
    return 'Upload failed. Please check your connection and try again.';
}

$('#baUploadBtn').on('click', function () {
    if (!baFile) return;
    const fd = new FormData();
    fd.append('excel_file', baFile);
    runPreview(fd, 'Reading your file…');
});

function runPreview(fd, loadingText) {
    Swal.fire({ title: loadingText || 'Working…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    $.ajax({
        url: 'ajax/bulk_assign_preview.php',
        method: 'POST',
        data: fd,
        contentType: false,
        processData: false,
        dataType: 'json',
        headers: { 'X-CSRF-Token': CSRF_TOKEN },
    }).done(function (res) {
        Swal.close();
        if (!res.success) {
            showUploadError(res.message || 'Could not process this file.');
            return;
        }
        baBatchToken = res.batch_token;
        renderPreview(res);
        $step1.hide(); $step2.show();
    }).fail(function (jqXHR) {
        Swal.close();
        showUploadError(describeAjaxFailure(jqXHR));
    });
}

function renderPreview(res) {
    $('#baMetaFile').text(res.filename);
    $('#baMetaRows').text(res.row_count);

    let sheetHtml;
    if (res.sheet_names.length > 1) {
        sheetHtml = '<select class="ba-sheet-select" id="baSheetSelect">' +
            res.sheet_names.map(function (s) {
                return '<option value="' + $('<div>').text(s).html() + '"' + (s === res.selected_sheet ? ' selected' : '') + '>' + $('<div>').text(s).html() + '</option>';
            }).join('') + '</select>';
    } else {
        sheetHtml = $('<div>').text(res.selected_sheet).html();
    }
    $('#baMetaSheetWrap').html(sheetHtml);
    $('#baSheetSelect').off('change').on('change', function () {
        const fd = new FormData();
        fd.append('batch_token', baBatchToken);
        fd.append('sheet', this.value);
        runPreview(fd, 'Reading sheet…');
    });

    const mapHtml = res.mapping.map(function (m) {
        if (m.mapped) {
            return '<span class="ba-map-chip ok"><i class="fa-solid fa-check"></i> ' + escapeHtml(m.column) + ' → ' + escapeHtml(m.field) + '</span>';
        }
        return '<span class="ba-map-chip no"><i class="fa-solid fa-minus"></i> ' + escapeHtml(m.column) + ' → Not Used</span>';
    }).join('');
    $('#baMapList').html(mapHtml);

    if (res.missing_required && res.missing_required.length) {
        $('#baMissingBox').html('<i class="fa-solid fa-triangle-exclamation me-1"></i> Required field missing: ' + res.missing_required.map(escapeHtml).join(', ') + '. Please fix your Excel headers and upload again.').show();
        $('#baSummaryRow, #baPreviewTableWrap').hide();
        $('#baConfirmBtn').prop('disabled', true);
        return;
    }
    $('#baMissingBox').hide();

    $('#baSumTotal').text(res.row_count);
    $('#baSumValid').text(res.summary.valid);
    $('#baSumDup').text(res.summary.duplicate + res.summary.already_assigned);
    $('#baSumErr').text(res.summary.errors);
    $('#baSummaryRow').show();

    const rowsHtml = res.rows.map(function (r) {
        return '<tr>' +
            '<td>' + r.row_number + '</td>' +
            '<td>' + escapeHtml(r.vessel_name || '—') + '</td>' +
            '<td>' + escapeHtml(r.imo_number || '—') + '</td>' +
            '<td>' + escapeHtml(r.surveyor_label || '—') + '</td>' +
            '<td>' + escapeHtml(r.client_label || '—') + '</td>' +
            '<td>' + escapeHtml(r.port_label || '—') + '</td>' +
            '<td>' + escapeHtml(r.survey_type_label || '—') + '</td>' +
            '<td>' + statusBadge(r.status) + '</td>' +
            '<td class="ba-row-msg">' + escapeHtml(r.message || '') + '</td>' +
            '</tr>';
    }).join('');
    $('#baPreviewTbody').html(rowsHtml);
    $('#baPreviewTableWrap').show();

    baTotalRows = res.row_count;
    $('#baConfirmBtn').prop('disabled', res.summary.valid === 0);
}

function statusBadge(status) {
    let cls = 's-err';
    if (status === 'VALID' || status === 'ASSIGNED') cls = 's-valid';
    else if (status === 'DUPLICATE') cls = 's-dup';
    else if (status === 'ALREADY ASSIGNED' || status === 'ALREADY ASSIGNED TO OTHER SURVEYOR') cls = 's-already';
    return '<span class="ba-status-badge ' + cls + '">' + escapeHtml(status) + '</span>';
}

function escapeHtml(s) {
    return $('<div>').text(s === null || s === undefined ? '' : String(s)).html();
}

$('#baBackBtn').on('click', function () {
    $step2.hide(); $step1.show();
    $('#baFileInput').val(''); $('#baFileChosen').hide(); $('#baUploadBtn').prop('disabled', true);
    baFile = null; baBatchToken = null;
});

$('#baConfirmBtn').on('click', function () {
    Swal.fire({
        title: 'Confirm bulk assignment?',
        html: 'This will insert all <b>valid</b> rows as new vessel assignments and notify their surveyors.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, assign them',
        confirmButtonColor: '#4B2FD6',
    }).then(function (r) {
        if (r.isConfirmed) startProcessing();
    });
});

function startProcessing() {
    $step2.hide(); $step3.show();
    processChunk(0);
}

function processChunk(offset) {
    $.ajax({
        url: 'ajax/bulk_assign_confirm.php',
        method: 'POST',
        data: { batch_token: baBatchToken, offset: offset, limit: 15 },
        dataType: 'json',
        headers: { 'X-CSRF-Token': CSRF_TOKEN },
    }).done(function (res) {
        if (!res.success) {
            Swal.fire('Error', res.message || 'Something went wrong while processing.', 'error');
            return;
        }
        const pct = res.total > 0 ? Math.round((res.processed / res.total) * 100) : 100;
        $('#baProgressBar').css('width', pct + '%');
        $('#baProgressLabel').text('Processing ' + res.processed + ' / ' + res.total);
        if (res.done) {
            finishProcessing();
        } else {
            processChunk(res.processed);
        }
    }).fail(function (jqXHR) {
        Swal.fire('Error', describeAjaxFailure(jqXHR) + ' Check Assignment History for what was completed, then retry any remaining rows.', 'error');
    });
}

function finishProcessing() {
    $.get('ajax/bulk_assign_batch_summary.php', { batch_token: baBatchToken }, function (res) {
        $step3.hide(); $step4.show();
        if (res && res.success) {
            baBatchId = res.batch.id;
            const s = res.batch;
            $('#baResultSummary').html(
                '<div class="ba-stat valid"><div class="n">' + s.inserted_rows + '</div><div class="l">Assigned</div></div>' +
                '<div class="ba-stat dup"><div class="n">' + (parseInt(s.duplicate_rows) + parseInt(s.already_assigned_rows)) + '</div><div class="l">Already Assigned</div></div>' +
                '<div class="ba-stat err"><div class="n">' + s.error_rows + '</div><div class="l">Failed</div></div>'
            );
            if (parseInt(s.error_rows) > 0 || parseInt(s.duplicate_rows) > 0 || parseInt(s.already_assigned_rows) > 0) {
                $('#baDownloadErrorsBtn').show().off('click').on('click', function () {
                    window.location = 'ajax/bulk_assign_error_excel.php?batch_id=' + s.id;
                });
            }
        }
    }, 'json');
}
</script>

<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>

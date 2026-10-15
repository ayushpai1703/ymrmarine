<?php
require_once 'config/config.php';
require_once 'includes/document_replace_helpers.php';
checkAuth();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$db = getDB();

// 🌟 SAFETY NET: hsfo_recovery column, same as ajax/upload_handler.php —
// covers an Admin editing recovery here before any file has been uploaded
// since this column was added.
try {
    $cols = $db->query("SHOW COLUMNS FROM surveys LIKE 'hsfo_recovery'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE surveys ADD COLUMN hsfo_recovery DECIMAL(12,3) DEFAULT NULL");
    }
} catch (Exception $e) { error_log('report_detail.php hsfo_recovery column: ' . $e->getMessage()); }

$stmt = $db->prepare("
    SELECT s.*, c.company_name, u.full_name as surveyor_name, p.port_name, t.type_name
    FROM surveys s 
    JOIN clients c ON s.client_id = c.id 
    JOIN users u ON s.surveyor_id = u.id
    JOIN ports p ON s.port_id = p.id
    JOIN survey_types t ON s.survey_type_id = t.id
    WHERE s.id = ?
");
$stmt->execute([$id]);
$survey = $stmt->fetch();

if (!$survey) { die("Report asset not found."); }

// 🌟 Recovery doesn't apply to some survey types (Draft Survey, BQS, etc.) —
// see RECOVERY_NOT_APPLICABLE_TYPES in config/config.php.
$recoveryNA = isRecoveryNotApplicable($db, (string)($survey['survey_type_ids'] ?? ''), (string)($survey['type_name'] ?? ''));

$current_user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$is_admin = ($role === 'Admin');
$is_super_admin = ($role === 'Super Admin');
// Super Admin can view every report like Admin (read-only — no Upload Final
// Documentation), so it must not be scoped down like a Surveyor/Client.
$has_full_access = ($is_admin || $is_super_admin);
if (!$has_full_access && !isSurveyorAssignedToSurvey($db, $survey['id'], $current_user_id)) {
    $isOwnClient = (($_SESSION['role'] ?? '') === 'Client') && (int)$survey['client_id'] === getClientIdForUser($db, $current_user_id);
    if (!$isOwnClient) {
    http_response_code(403);
    include 'includes/header.php';
    ?>
    <style>
        .no-access-wrap { min-height: calc(100vh - 160px); padding: 30px 20px; display: flex; align-items: center; justify-content: center; }
        .no-access-card { max-width: 520px; width: 100%; padding: 38px 24px; border-radius: 20px; background: #fff; border: 1px solid var(--border-color); box-shadow: 0 12px 28px rgba(15,23,42,.07); text-align: center; }
        .no-access-icon { width: 68px; height: 68px; margin: 0 auto 18px; border-radius: 18px; display: flex; align-items: center; justify-content: center; background: #fef2f2; color: #b91c1c; font-size: 28px; }
        .no-access-card .blue-action-btn { margin-left: auto; margin-right: auto; }
    </style>
    <div class="scroll-content">
        <?php $page_title = 'Access Denied'; $back_url = 'index.php'; $page_testid = 'no-access'; include 'includes/top_app_bar.php'; ?>
        <main class="no-access-wrap" data-testid="no-access-page">
            <section class="no-access-card">
                <div class="no-access-icon"><i class="fa-solid fa-lock"></i></div>
                <h2 class="fw-bold text-dark" style="font-size:22px;" data-testid="no-access-heading">Access Denied</h2>
                <p class="text-muted mb-4" style="font-size:13px;" data-testid="no-access-message">
                    You don't have permission to view this report. It isn't assigned to your account.
                </p>
                <a href="index.php" class="blue-action-btn text-decoration-none" data-testid="no-access-home-link"><i class="fa-solid fa-house"></i> Return Home</a>
            </section>
        </main>
    </div>
    <?php
    include 'includes/nav.php';
    include 'includes/footer.php';
    exit;
    }
}

// 🌟 Recovery edit (Admin only): Total / VLSFO / LSMGO recovery for this
// vessel. No redirect — re-renders the same request with the saved values.
$recovery_message = '';
$recovery_error = '';
if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_recovery']) && !$recoveryNA) {
    $parseRecovery = function ($v) {
        $v = trim((string)$v);
        if ($v === '') return null;
        if (!is_numeric($v) || (float)$v < 0) return false;
        return round((float)$v, 3);
    };
    $totalVal = $parseRecovery($_POST['recovery_amount'] ?? '');
    $vlsfoVal = $parseRecovery($_POST['vlsfo_recovery'] ?? '');
    $lsmgoVal = $parseRecovery($_POST['lsmgo_recovery'] ?? '');
    $hsfoVal = $parseRecovery($_POST['hsfo_recovery'] ?? '');
    if ($totalVal === false || $vlsfoVal === false || $lsmgoVal === false || $hsfoVal === false) {
        $recovery_error = 'Recovery values must be zero or a positive number.';
    } else {
        try {
            $upd = $db->prepare("UPDATE surveys SET recovery_amount = ?, vlsfo_recovery = ?, lsmgo_recovery = ?, hsfo_recovery = ? WHERE id = ?");
            $upd->execute([$totalVal, $vlsfoVal, $lsmgoVal, $hsfoVal, $id]);
            $survey['recovery_amount'] = $totalVal;
            $survey['vlsfo_recovery'] = $vlsfoVal;
            $survey['lsmgo_recovery'] = $lsmgoVal;
            $survey['hsfo_recovery'] = $hsfoVal;
            $recovery_message = 'Recovery values updated.';
        } catch (Throwable $e) {
            error_log('report_detail recovery update: ' . $e->getMessage());
            $recovery_error = 'Could not save recovery values. Please try again.';
        }
    }
}

// 🌟 మునుపటి స్టేజ్ లో అప్‌లోడ్ చేసిన ఫైల్స్ లిస్ట్ తీసుకోవడం
$uploads_stmt = $db->prepare("SELECT * FROM uploads WHERE survey_id = ?");
$uploads_stmt->execute([$id]);
$uploaded_files = $uploads_stmt->fetchAll();

// 🌟 Single source of truth for the Replace button — Admin always,
// Surveyor only if actually assigned to this survey; Super Admin/Client
// never (re-checked independently in ajax/document_replace.php too).
$can_replace_docs = canReplaceDocument($db, $role, (int)$current_user_id, (int)$survey['id']);

// 🌟 "Send Email to Master for Redelivery" — always opens Gmail's own
// compose window (mail.google.com), not mailto:, since mailto: opens
// whatever the OS/browser has set as its default mail handler (Outlook,
// in practice, for this deployment) rather than Gmail specifically. To/Cc
// are left out of the URL so they stay blank for the user to fill in
// themselves — no auto-sending, no recipient automation.
$redelivery_type = getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? 'N/A');
$redelivery_surveyor = getCombinedSurveyorNames($db, $survey['id'] ?? 0, $survey['surveyor_name'] ?? 'N/A');
$redelivery_date = !empty($survey['survey_completed_date']) ? date('d M Y', strtotime($survey['survey_completed_date'])) : '';
$redelivery_subject = 'Redelivery Details Needed for ' . $survey['vessel_name'];
$redelivery_body = "Dear Captain,\n\nGood day.\n\nWith reference to the {$redelivery_type} carried out on board  {$survey['vessel_name']}  at {$survey['port_name']} on {$redelivery_date}, kindly send us the redelivery details to make a final report.\n\nWe would appreciate it if you could please forward the same at your earliest convenience.\n\n\n\nThank you for your cooperation.\n\nBest regards,\n{$redelivery_surveyor}\nYMR Marine Solutions LLP";
$redelivery_gmail_url = 'https://mail.google.com/mail/?view=cm&fs=1&su=' . rawurlencode($redelivery_subject) . '&body=' . rawurlencode($redelivery_body);

// Mobile: open the Gmail app itself (not a browser tab) so the user lands in
// an already-signed-in app instead of being asked to log in again, with To/Cc
// still editable by hand inside the app. iOS uses Gmail's own "co" (compose)
// URL scheme. Android Gmail has no such custom scheme — it only registers
// itself as a handler for the standard mailto: intent — so we route a
// mailto: intent and force it to Gmail specifically via package=, falling
// back to the web compose URL only if Gmail isn't installed.
$redelivery_gmail_ios_url = 'googlegmail://co?subject=' . rawurlencode($redelivery_subject) . '&body=' . rawurlencode($redelivery_body);
$redelivery_gmail_android_url = 'intent://send?subject=' . rawurlencode($redelivery_subject) . '&body=' . rawurlencode($redelivery_body) . '#Intent;scheme=mailto;package=com.google.android.gm;S.browser_fallback_url=' . rawurlencode($redelivery_gmail_url) . ';end';

// Pre-fill the "from account" field with the logged-in user's own email
// (same column/safety-net pattern as profile.php), so it defaults to
// whoever is actually clicking the button instead of starting blank.
$has_email_col = $db->query("SHOW COLUMNS FROM users LIKE 'email'")->fetch();
$my_email = '';
if ($has_email_col) {
    $myEmailStmt = $db->prepare("SELECT email FROM users WHERE id = ?");
    $myEmailStmt->execute([$current_user_id]);
    $my_email = (string)($myEmailStmt->fetchColumn() ?: '');
}

include 'includes/header.php';
?>

<style>
/* 🌟 Document Replace — View/Download/Replace row actions. Unconditional
   (not inside the @media blocks below): View/Download render for every
   role that can reach this page, including Client — only the Replace
   button itself is PHP-gated to Admin/assigned-Surveyor. */
.doc-replace-btn {
    display: inline-flex; align-items: center; gap: 5px; border: 1px solid var(--border-color);
    background: #fff; color: #3b32b3; border-radius: 8px; padding: 0 10px; height: 32px; font-size: 11.5px; font-weight: 650;
}
.doc-replace-btn:hover { border-color: #3b32b3; background: #eef2ff; }
.doc-view-btn {
    display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--border-color);
    background: #fff; color: var(--text-muted); border-radius: 8px; width: 32px; height: 32px; font-size: 13px;
}
.doc-view-btn:hover { color: #3b32b3; border-color: #3b32b3; }
.doc-actions-row { display: flex; align-items: center; gap: 6px; flex: 0 0 auto; }
@media (min-width: 992px) {
    .detail-bottom-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
        max-width: none;
        width: calc(100% - 32px);
        margin: 8px 16px 20px;
        align-items: stretch; /* equal height cards */
    }
    .detail-bottom-row > .info-table-list,
    .detail-bottom-row > .detail-files-panel,
    .detail-bottom-row > .form-box {
        margin: 0 !important;
        max-width: none;
        height: 100%;
        display: flex;
        flex-direction: column;
        background: #fff;
        border: 1px solid var(--border-color);
        border-radius: 14px;
        padding: 16px 18px;
        box-shadow: 0 2px 8px rgba(15,23,42,.04);
    }
    .detail-bottom-row > .form-box form {
        display: flex;
        flex-direction: column;
        flex: 1;
    }
    .detail-actions-row {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: auto;
        padding-top: 12px;
    }
    .detail-actions-row > a,
    .detail-actions-row > button {
        flex: 1 1 140px;
        width: auto !important;
        max-width: none;
        margin: 0 !important;
    }
    .info-row { padding: 10px 16px; }
}
/* Mobile alignment fixes */
@media (max-width: 991.98px) {
    .scroll-content { padding-bottom: 90px; }
    .info-table-list {
        margin: 8px 12px !important;
        width: calc(100% - 24px) !important;
        max-width: none !important;
        border-radius: 12px;
        overflow: hidden;
    }
    .info-row {
        display: flex;
        flex-direction: column;
        align-items: flex-start !important;
        gap: 4px;
        padding: 12px 14px !important;
    }
    .info-row .info-label {
        font-size: 11px !important;
        width: 100%;
    }
    .info-row .info-value {
        font-size: 13px !important;
        width: 100%;
        text-align: left !important;
        word-break: break-word;
    }
    .detail-bottom-row {
        display: flex;
        flex-direction: column;
        gap: 12px;
        margin: 8px 12px 24px !important;
        width: calc(100% - 24px) !important;
        max-width: none !important;
    }
    .detail-bottom-row > .detail-files-panel,
    .detail-bottom-row > .form-box {
        margin: 0 !important;
        width: 100% !important;
        max-width: none !important;
        border-radius: 12px;
        padding: 14px !important;
    }
    .detail-actions-row {
        display: flex;
        flex-direction: column;
        gap: 10px;
        margin-top: 12px;
    }
    .detail-actions-row > a,
    .detail-actions-row > button {
        width: 100% !important;
        max-width: none !important;
        margin: 0 !important;
        text-align: center;
        justify-content: center;
    }
    .form-box .form-control,
    .form-box .form-control-sm {
        font-size: 14px;
    }
}
</style>


<div class="scroll-content">
    <?php $page_title = 'Pending Report Details'; $back_url = 'reports.php'; $page_testid = 'report-detail'; include 'includes/top_app_bar.php'; ?>

    <div class="detail-bottom-row">
    <!-- Read-Only Info Table -->
    <div class="info-table-list shadow-sm">
        <div class="info-row"><span class="info-label">Vessel Name</span><span class="info-value"><?= sanitize($survey['vessel_name']) ?></span></div>
        <div class="info-row" data-testid="rd-port-mobile"><span class="info-label">Port</span><span class="info-value"><?= sanitize($survey['port_name'] ?? '—') ?></span></div>
        <div class="info-row"><span class="info-label">Report No</span><span class="info-value fw-bold text-dark"><?= !empty($survey['report_number']) ? sanitize($survey['report_number']) : '—' ?></span></div>
        <div class="info-row"><span class="info-label">Client Name</span><span class="info-value"><?= sanitize($survey['company_name']) ?></span></div>
        <div class="info-row"><span class="info-label">Surveyor Name</span><span class="info-value"><?= sanitize(getCombinedSurveyorNames($db, $survey['id'] ?? 0, $survey['surveyor_name'] ?? 'N/A')) ?></span></div>
        <div class="info-row"><span class="info-label">Survey Type</span><span class="info-value text-primary"><?= sanitize(getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? 'N/A')) ?></span></div>
        <div class="info-row"><span class="info-label">Survey Completed</span><span class="info-value text-success fw-bold"><?= date('d M Y', strtotime($survey['survey_completed_date'])) ?></span></div>
        <?php if (!$recoveryNA): // Recovery rows are left out entirely for N/A survey types ?>
        <div class="info-row"><span class="info-label">Total Recovery</span><span class="info-value"><?= $survey['recovery_amount'] !== null ? sanitize(number_format((float)$survey['recovery_amount'], 3)) . ' MT' : '—' ?></span></div>
        <div class="info-row"><span class="info-label">VLSFO Recovery</span><span class="info-value"><?= $survey['vlsfo_recovery'] !== null ? sanitize(number_format((float)$survey['vlsfo_recovery'], 3)) . ' MT' : '—' ?></span></div>
        <div class="info-row"><span class="info-label">LSMGO Recovery</span><span class="info-value"><?= $survey['lsmgo_recovery'] !== null ? sanitize(number_format((float)$survey['lsmgo_recovery'], 3)) . ' MT' : '—' ?></span></div>
        <div class="info-row"><span class="info-label">HSFO Recovery</span><span class="info-value"><?= $survey['hsfo_recovery'] !== null ? sanitize(number_format((float)$survey['hsfo_recovery'], 3)) . ' MT' : '—' ?></span></div>
        <?php else: ?>
        <div class="info-row" data-testid="recovery-na-mobile"><span class="info-label">Recovery</span><span class="info-value fw-bold"><?= sanitize(RECOVERY_NA_LABEL) ?></span></div>
        <?php endif; ?>
    </div>

    <?php if ($is_admin && !$recoveryNA): ?>
    <div class="form-box mx-3 p-3 bg-white rounded-3 border shadow-sm" data-testid="recovery-edit-box">
        <div class="fw-bold text-dark mb-2" style="font-size: 13px;"><i class="fa-solid fa-droplet text-primary me-1"></i> Edit Recovery</div>
        <?php if ($recovery_message): ?><div class="alert alert-success py-2 mb-2" style="font-size:12px;" data-testid="recovery-success"><?= sanitize($recovery_message) ?></div><?php endif; ?>
        <?php if ($recovery_error): ?><div class="alert alert-danger py-2 mb-2" style="font-size:12px;" data-testid="recovery-error"><?= sanitize($recovery_error) ?></div><?php endif; ?>
        <form method="POST"><?= csrf_field() ?>
            <div class="mb-2">
                <label class="form-label fw-bold text-secondary" style="font-size:11px;">Total Recovery (MT)</label>
                <input type="number" step="0.001" min="0" name="recovery_amount" class="form-control form-control-sm" value="<?= $survey['recovery_amount'] !== null ? sanitize((string)$survey['recovery_amount']) : '' ?>" data-testid="recovery-field-total">
            </div>
            <div class="mb-2">
                <label class="form-label fw-bold text-secondary" style="font-size:11px;">VLSFO Recovery (MT)</label>
                <input type="number" step="0.001" min="0" name="vlsfo_recovery" class="form-control form-control-sm" value="<?= $survey['vlsfo_recovery'] !== null ? sanitize((string)$survey['vlsfo_recovery']) : '' ?>" data-testid="recovery-field-vlsfo">
            </div>
            <div class="mb-2">
                <label class="form-label fw-bold text-secondary" style="font-size:11px;">LSMGO Recovery (MT)</label>
                <input type="number" step="0.001" min="0" name="lsmgo_recovery" class="form-control form-control-sm" value="<?= $survey['lsmgo_recovery'] !== null ? sanitize((string)$survey['lsmgo_recovery']) : '' ?>" data-testid="recovery-field-lsmgo">
            </div>
            <div class="mb-2">
                <label class="form-label fw-bold text-secondary" style="font-size:11px;">HSFO Recovery (MT)</label>
                <input type="number" step="0.001" min="0" name="hsfo_recovery" class="form-control form-control-sm" value="<?= $survey['hsfo_recovery'] !== null ? sanitize((string)$survey['hsfo_recovery']) : '' ?>" data-testid="recovery-field-hsfo">
            </div>
            <button type="submit" name="update_recovery" class="btn btn-primary btn-sm w-100" data-testid="save-recovery-button">Save Recovery</button>
        </form>
    </div>
    <?php endif; ?>

    <!-- 🌟 DOWNLOAD SECTION — visible to every role that can reach this page,
         including Client (their own company's reports only, enforced above). -->
    <div class="detail-files-panel px-3 my-3">
        <div class="fw-bold text-dark mb-2" style="font-size: 13px;"><i class="fa-solid fa-folder-open text-warning me-1"></i> Download Pre-Uploaded Survey Files</div>
        <?php if(count($uploaded_files) > 0): ?>
            <?php foreach($uploaded_files as $file): ?>
                <?php
                    $raw_name = $file['file_name'] ?? '';
                    $clean_name = preg_replace('/^[0-9]+_/', '', $raw_name);
                    if ($clean_name === '' || $clean_name === null) $clean_name = $raw_name;
                    $icon = 'fa-file-pdf text-danger';
                    $lower = strtolower($raw_name . ' ' . ($file['file_type'] ?? ''));
                    if (str_contains($lower, 'xlsx') || str_contains($lower, 'xls') || str_contains($lower, 'excel')) $icon = 'fa-file-excel text-success';
                    elseif (str_contains($lower, 'doc') || str_contains($lower, 'word')) $icon = 'fa-file-word text-primary';
                    if (str_ends_with(strtolower($raw_name), '.zip')) $icon = 'fa-file-zipper text-warning';
                ?>
                <div class="bg-white p-2 rounded-3 border d-flex align-items-center justify-content-between mb-2 shadow-sm" style="font-size: 12px;" data-doc-row data-doc-id="<?= (int)$file['id'] ?>">
                    <div class="d-flex align-items-center gap-2" style="max-width:62%;">
                        <i class="fa-regular <?= $icon ?> fs-5"></i>
                        <span class="text-dark fw-semibold text-truncate" data-doc-name-display title="<?= sanitize($clean_name) ?>"><?= sanitize($clean_name) ?></span>
                    </div>
                    <div class="doc-actions-row">
                        <a href="download.php?upload_id=<?= (int)$file['id'] ?>&inline=1" target="_blank" rel="noopener" class="doc-view-btn" title="View"><i class="fa-solid fa-eye"></i></a>
                        <a href="download.php?upload_id=<?= (int)$file['id'] ?>" data-doc-download-link class="doc-view-btn" title="Download"><i class="fa-solid fa-download"></i></a>
                        <?php if ($can_replace_docs): ?>
                        <button type="button" class="doc-replace-btn" title="Replace / Re-upload"
                                data-doc-id="<?= (int)$file['id'] ?>"
                                data-doc-name="<?= sanitize($clean_name) ?>"
                                data-doc-type="<?= sanitize($file['file_type']) ?>"
                                data-doc-size="<?= sanitize($file['file_size']) ?>"
                                data-doc-uploaded-at="<?= sanitize(fmtDocumentDateTime($file['uploaded_at'] ?? '')) ?>"
                                data-doc-uploaded-by="<?= sanitize($file['uploaded_by'] ? getUserFullNameById($db, (int)$file['uploaded_by']) : '—') ?>">
                            <i class="fa-solid fa-rotate"></i> Replace
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="text-muted small ps-2">No files attached to this vessel yet.</div>
        <?php endif; ?>
    </div>

    <?php if (!$is_super_admin && $role !== 'Client'): ?>
    <!-- ✍️ UPLOAD FORM SECTION: Final Report (PDF) is the required document -->
    <div class="form-box mx-3 p-3 bg-white rounded-3 border shadow-sm">
        <div class="fw-bold text-dark mb-3" style="font-size: 14px;"><i class="fa-solid fa-cloud-arrow-up text-primary"></i> Upload Final Documentation</div>

        <form action="ajax/upload_handler.php" method="POST" enctype="multipart/form-data" id="reportUploadForm"><?= csrf_field() ?>
            <input type="hidden" name="survey_id" value="<?= $survey['id'] ?>">
            <input type="hidden" name="current_status" value="<?= $survey['status'] ?>">
            <input type="hidden" name="no_report_confirmed" id="noReportConfirmed" value="0">

            <div class="mb-3">
                <label class="form-label fw-bold text-secondary" style="font-size:12px;" for="finalReportInput">1. Final Report (PDF)</label>
                <input type="file" name="final_report_pdf" id="finalReportInput" class="form-control form-control-sm final-report-input" accept=".pdf,application/pdf" data-testid="final-report-pdf-input">
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold text-secondary" style="font-size:12px;" for="zipFolderInput">2. ZIP Folder (optional) — photos / supporting documents, max 30 MB</label>
                <input type="file" name="zip_folder" id="zipFolderInput" class="form-control form-control-sm zip-folder-input" accept=".zip,application/zip,application/x-zip-compressed" data-testid="zip-folder-input">
            </div>

            <div id="additionalFilesContainer"></div>

            <button type="button" class="btn btn-light btn-sm text-primary fw-bold mb-3 border w-100 py-2" id="addMoreFilesBtn" style="font-size: 12px;">
                <i class="fa-solid fa-plus-circle"></i> + Add More Files (Optional)
            </button>

            <div class="detail-actions-row">
            <button type="submit" class="btn btn-primary mb-0" id="uploadAndMoveBtn" data-testid="complete-report-submit-button">Complete & Move</button>
            <a href="report_generator.php?id=<?= $survey['id'] ?>"
               class="btn btn-success mb-0"
               style="background-color: #28a745; border: none; font-weight: 600; padding: 10px; border-radius: 8px; color: #fff; text-decoration: none; text-align: center;"
               data-testid="generate-word-report-button">
                <i class="fa-solid fa-file-lines me-2"></i> Generate Word Report
            </a>
            <a href="photo_report_generator.php?id=<?= $survey['id'] ?>"
               class="btn btn-primary mb-0"
               style="border: none; font-weight: 600; padding: 10px; border-radius: 8px; color: #fff; text-decoration: none; text-align: center;"
               data-testid="generate-photo-report-button">
                <i class="fa-regular fa-images me-2"></i> Generate Photo Report
            </a>
            </div>

        </form>
    </div>
    <?php endif; ?>

    <?php if ($role !== 'Client'): ?>
    <div class="action-btn-container redelivery-block mb-4 px-3" style="display:flex;flex-direction:column;gap:8px;" data-testid="redelivery-block-mobile">
        <input type="email" class="form-control form-control-sm" placeholder="Your Gmail address (optional — picks which account composes)" value="<?= sanitize($my_email) ?>" data-redelivery-from data-testid="redelivery-from-input">
        <a href="#" data-redelivery-open data-base-url="<?= sanitize($redelivery_gmail_url) ?>" data-ios-url="<?= sanitize($redelivery_gmail_ios_url) ?>" data-android-url="<?= sanitize($redelivery_gmail_android_url) ?>" class="blue-action-btn text-decoration-none d-inline-flex align-items-center justify-content-center" style="background:#0f766e;" data-testid="redelivery-email-button">
            <i class="fa-solid fa-envelope me-1"></i> Send Email to Master for Redelivery
        </a>
    </div>
    <?php endif; ?>

    <?php if (($_SESSION['role'] ?? '') === 'Admin'): ?>
    <div class="action-btn-container mb-4 px-3" style="display:flex;flex-direction:column;gap:10px;">
        <a href="invoice_generator.php?id=<?= (int)$survey['id'] ?>" class="blue-action-btn text-decoration-none d-inline-flex align-items-center justify-content-center" data-testid="generate-invoice-button">
            <i class="fa-solid fa-file-invoice-dollar me-1"></i> Generate Invoice
        </a>
    </div>
    <?php endif; ?>
    </div><!-- /.detail-bottom-row -->

    <!-- 🌟 Confirmation modal shown when Upload and Move is clicked without a report document -->
    <div class="modal fade" id="noReportConfirmModal" tabindex="-1" aria-hidden="true" data-testid="no-report-confirm-modal">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body text-center py-4">
                    <i class="fa-solid fa-triangle-exclamation text-warning fs-2 mb-2"></i>
                    <p class="fw-semibold mb-0" style="font-size: 14px;">Do you really want to continue without the Final Report (PDF)?</p>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal" data-testid="no-report-confirm-cancel">Cancel</button>
                    <button type="button" class="btn btn-primary flex-fill" id="noReportContinueBtn" data-testid="no-report-confirm-continue">Yes, Continue</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    $(document).ready(function() {
        let fileIndex = 1;
        $('#addMoreFilesBtn').on('click', function() {
            let html = `
                <div class="mb-3 p-2 border rounded bg-light position-relative">
                    <label class="form-label fw-bold text-muted" style="font-size:11px;">Extra Documents #${fileIndex} <span style="font-weight:500;">— select one or more files (PDF, Word, Excel, PowerPoint, images, ZIP…)</span></label>
                    <input type="file" name="extra_files[]" class="form-control form-control-sm extra-files-input" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.xlsm,.csv,.txt,.rtf,.ppt,.pptx,.odt,.ods,.jpg,.jpeg,.png,.gif,.webp,.heic,.heif,.tif,.tiff,.bmp,.zip,.rar,.7z,.msg,.eml" data-testid="extra-files-input">
                    <button type="button" class="btn btn-sm text-danger position-absolute end-0 top-0 mt-1 remove-file-btn"><i class="fa-solid fa-trash"></i></button>
                </div>`;
            $('#additionalFilesContainer').append(html);
            fileIndex++;
        });

        $(document).on('click', '.remove-file-btn', function() {
            $(this).parent().remove();
        });

        // 🌟 If no Final Report (PDF) is selected, confirm before continuing the workflow
        $('#reportUploadForm').on('submit', function(e) {
            const hasFile = document.getElementById('finalReportInput').files.length > 0;
            const alreadyConfirmed = $('#noReportConfirmed').val() === '1';
            if (!hasFile && !alreadyConfirmed) {
                e.preventDefault();
                const confirmModal = new bootstrap.Modal(document.getElementById('noReportConfirmModal'));
                confirmModal.show();
                return;
            }
            // Then ask for the Report Completed date (saved as the report date).
            if (this.getAttribute('data-rcd-done') !== '1' && window.ysmsAskReportDate) {
                e.preventDefault();
                window.ysmsAskReportDate(this);
            }
        });

        $('#noReportContinueBtn').on('click', function() {
            $('#noReportConfirmed').val('1');
            bootstrap.Modal.getInstance(document.getElementById('noReportConfirmModal')).hide();
            $('#reportUploadForm').trigger('submit');
        });
    });
</script>

<script>
// "Send Email to Master for Redelivery" — an optional "Your Gmail address"
// field lets the user pick which signed-in Google account the compose
// window opens under (Gmail's own &authuser= param), purely by what they
// type here. Nothing is sent automatically and To/Cc stay blank either way.
// On phones this opens the Gmail app directly (already signed in) instead
// of a browser tab that would ask the user to log in again; desktop keeps
// opening the Gmail web compose tab as before.
document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-redelivery-open]');
    if (!btn) return;
    ev.preventDefault();
    var block = btn.closest('.redelivery-block');
    var input = block ? block.querySelector('[data-redelivery-from]') : null;
    var email = input ? input.value.trim() : '';
    var webUrl = btn.getAttribute('data-base-url') + (email ? '&authuser=' + encodeURIComponent(email) : '');
    var ua = navigator.userAgent || '';

    if (/Android/i.test(ua)) {
        window.location.href = btn.getAttribute('data-android-url');
        return;
    }
    if (/iPhone|iPad|iPod/i.test(ua)) {
        var appOpened = false;
        var onBlur = function () { appOpened = true; window.removeEventListener('blur', onBlur); };
        window.addEventListener('blur', onBlur);
        window.location.href = btn.getAttribute('data-ios-url');
        setTimeout(function () {
            window.removeEventListener('blur', onBlur);
            if (!appOpened) window.location.href = webUrl;
        }, 1500);
        return;
    }
    window.open(webUrl, '_blank', 'noopener');
});
</script>

<script>
// ZIP folder + upload size guard. Cloud Run refuses any request over 32 MB
// before it reaches PHP (the user would just see a generic error page), so
// oversized selections are stopped here with a clear message instead.
(function () {
    var ZIP_MAX = 30 * 1024 * 1024, TOTAL_MAX = 31 * 1024 * 1024;
    function warn(title, text) {
        if (window.Swal) Swal.fire({ icon: 'warning', title: title, text: text, confirmButtonColor: '#1e3a8a' });
        else alert(title + '\n\n' + text);
    }
    function mb(b) { return (b / 1048576).toFixed(1) + ' MB'; }
    document.addEventListener('change', function (e) {
        var input = e.target;
        if (input.classList && input.classList.contains('final-report-input') && input.files && input.files[0] && !/\.pdf$/i.test(input.files[0].name)) {
            warn('PDF only', 'The Final Report must be a PDF (.pdf) file. Please choose the PDF version of the report.');
            input.value = '';
            input.dispatchEvent(new Event('ysms:cleared', { bubbles: true }));
            return;
        }
        if (!input.classList || !input.classList.contains('zip-folder-input') || !input.files || !input.files[0]) return;
        var f = input.files[0];
        if (!/\.zip$/i.test(f.name)) {
            warn('Only .zip files', 'Please choose a .zip file for the ZIP Folder.');
            input.value = '';
        } else if (f.size > ZIP_MAX) {
            warn('ZIP folder too large', f.name + ' is ' + mb(f.size) + '. The maximum is 30 MB — please split it into smaller ZIP files or reduce photo sizes.');
            input.value = '';
        }
        if (!input.value) input.dispatchEvent(new Event('ysms:cleared', { bubbles: true }));
    });
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.matches || !form.matches('#reportUploadForm, .report-upload-form')) return;
        var total = 0;
        form.querySelectorAll('input[type=file]').forEach(function (i) {
            for (var k = 0; i.files && k < i.files.length; k++) total += i.files[k].size;
        });
        if (total > TOTAL_MAX) {
            e.preventDefault();
            e.stopImmediatePropagation();
            warn('Files too large to upload together', 'The selected files add up to ' + mb(total) + ' (limit 31 MB per upload). Please upload them in smaller batches.');
        }
    }, true);
})();
</script>

<?php $rcdMin = !empty($survey['survey_completed_date']) && strpos((string)$survey['survey_completed_date'], '0000') !== 0 ? date('Y-m-d', strtotime($survey['survey_completed_date'])) : ''; ?>
<div class="rcd-modal" id="rcdModal" hidden data-testid="report-date-modal" role="dialog" aria-modal="true" aria-labelledby="rcdTitle">
    <div class="rcd-box">
        <div class="rcd-head">
            <div class="rcd-ico"><i class="fa-solid fa-file-circle-check"></i></div>
            <div>
                <div class="rcd-title" id="rcdTitle">Report Completed Date</div>
                <div class="rcd-sub"><?= sanitize($survey['vessel_name']) ?></div>
            </div>
        </div>
        <label class="rcd-label" for="rcdDate">Report Completed Date <span class="req">*</span></label>
        <input type="date" id="rcdDate" class="rcd-input" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"<?= $rcdMin !== '' && $rcdMin <= date('Y-m-d') ? ' min="' . sanitize($rcdMin) . '"' : '' ?> data-testid="report-date-input">
        <?php if ($rcdMin !== ''): ?><div class="rcd-hint">Survey completed on <?= sanitize(date('d M Y', strtotime($rcdMin))) ?>. The Completed list is ordered by this date.</div><?php endif; ?>
        <div class="rcd-error" data-rcd-error hidden></div>
        <div class="rcd-actions">
            <button type="button" class="rcd-btn ghost" data-rcd-cancel>Cancel</button>
            <button type="button" class="rcd-btn primary" data-rcd-ok data-testid="report-date-submit"><i class="fa-solid fa-check"></i> Complete &amp; Move</button>
        </div>
    </div>
</div>
<style>
.rcd-modal { position: fixed; inset: 0; z-index: 9998; background: rgba(15,23,42,.55); display: flex; align-items: center; justify-content: center; padding: 16px; }
.rcd-modal[hidden] { display: none; }
.rcd-box { background: #fff; border-radius: 18px; width: 100%; max-width: 400px; padding: 20px; box-shadow: 0 24px 60px rgba(0,0,0,.25); }
.rcd-head { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
.rcd-ico { width: 42px; height: 42px; border-radius: 12px; background: linear-gradient(135deg,#14532D,#16A34A); color: #fff; display: flex; align-items: center; justify-content: center; flex: none; }
.rcd-title { font-size: 16px; font-weight: 800; color: #0f172a; }
.rcd-sub { font-size: 12px; color: #64748b; }
.rcd-label { display: block; font-size: 12px; font-weight: 700; color: #334155; margin: 8px 0 6px; }
.rcd-label .req { color: #DC2626; }
.rcd-input { width: 100%; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 9px 11px; font-size: 14px; background: #F8FAFC; }
.rcd-input:focus { outline: none; border-color: #A78BFA; background: #fff; }
.rcd-hint { font-size: 11px; color: #94a3b8; margin-top: 6px; }
.rcd-error { margin-top: 10px; font-size: 12px; color: #B91C1C; background: #FEF2F2; border-radius: 10px; padding: 8px 10px; font-weight: 600; }
.rcd-actions { display: flex; gap: 10px; margin-top: 18px; }
.rcd-btn { flex: 1; border: none; border-radius: 12px; padding: 11px 14px; font-size: 13px; font-weight: 700; }
.rcd-btn.primary { background: linear-gradient(120deg,#14532D,#16A34A); color: #fff; }
.rcd-btn.ghost { background: #F1F5F9; color: #334155; }
</style>
<script>
// Pending Report → Completed: ask the Report Completed date before the form
// is sent. The upload form's own submit handler calls ysmsAskReportDate().
(function () {
    var modal = document.getElementById('rcdModal'), input = document.getElementById('rcdDate');
    var err = modal.querySelector('[data-rcd-error]'), pending = null;
    function close() { modal.hidden = true; pending = null; }
    function showErr(m) { err.textContent = m; err.hidden = !m; }
    window.ysmsAskReportDate = function (form) {
        pending = form; showErr(''); modal.hidden = false;
        setTimeout(function () { input.focus(); }, 30);
    };
    modal.querySelector('[data-rcd-cancel]').addEventListener('click', function () {
        if (pending) { var nr = pending.querySelector('.no-report-confirmed'); if (nr) nr.value = '0'; }
        close();
    });
    modal.addEventListener('click', function (e) { if (e.target === modal) modal.querySelector('[data-rcd-cancel]').click(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) modal.querySelector('[data-rcd-cancel]').click(); });
    modal.querySelector('[data-rcd-ok]').addEventListener('click', function () {
        if (!pending) return;
        var v = input.value;
        if (!v) return showErr('Please choose the Report Completed date.');
        if (input.max && v > input.max) return showErr('Report Completed date cannot be in the future.');
        if (input.min && v < input.min) return showErr('Report Completed date cannot be before the Survey Completed date.');
        var form = pending;
        var h = form.querySelector('input[name=report_completed_date]');
        if (!h) { h = document.createElement('input'); h.type = 'hidden'; h.name = 'report_completed_date'; form.appendChild(h); }
        h.value = v;
        form.setAttribute('data-rcd-done', '1');
        modal.hidden = true; pending = null;
        if (form.requestSubmit) form.requestSubmit(); else form.submit();
    });
})();
</script>

<?php
include 'includes/nav.php';
include 'includes/footer.php';

// 🌟 Document Replace / Re-upload — modal + behavior, Admin/Surveyor only.
// Included after footer.php's own jQuery/Bootstrap <script> tags — same
// "last jQuery load wins" fix already used elsewhere in this app (see
// assign_vessel.php) — so $.ajaxSetup here and document_replace.js's own
// jQuery reference both bind to the SAME final jQuery instance.
if (in_array($role, ['Admin', 'Surveyor'], true)) {
    include 'includes/document_replace_modal.php';
    ?>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
        $.ajaxSetup({ headers: { 'X-CSRF-Token': CSRF_TOKEN } });
    </script>
    <script src="assets/js/document_replace.js"></script>
    <?php
}
?>
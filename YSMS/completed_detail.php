<?php
require_once 'config/config.php';
require_once __DIR__ . '/../includes/upload_validation.php';
require_once 'includes/completed_remarks_helpers.php';
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
} catch (Exception $e) { error_log('completed_detail.php hsfo_recovery column: ' . $e->getMessage()); }

// 1. వెసెల్ పూర్తి వివరాలు డేటాబేస్ నుండి లోడ్ చేయడం
$stmt = $db->prepare("
    SELECT s.*, c.company_name, p.port_name, t.type_name, uploader.full_name AS uploader_name, surveyor.full_name AS surveyor_name
    FROM surveys s 
    JOIN clients c ON s.client_id = c.id 
    JOIN ports p ON s.port_id = p.id
    JOIN survey_types t ON s.survey_type_id = t.id
    LEFT JOIN users uploader ON s.status_updated_by = uploader.id
    LEFT JOIN users surveyor ON s.surveyor_id = surveyor.id
    WHERE s.id = ? AND s.status = 'Completed'
");
$stmt->execute([$id]);
$survey = $stmt->fetch();

if (!$survey) {
    die("Completed survey asset parameters not found or mission is uncompleted.");
}

// 🔒 Authorization check (same IDOR-prevention pattern as vessel_detail.php /
// report_detail.php): a Surveyor may only view their own assigned surveys, and
// a Client only their own company's — otherwise any authenticated user could
// view another company's completed vessel by changing ?id= in the URL.
$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$role = $_SESSION['role'] ?? '';
$is_admin = ($role === 'Admin');
$has_full_access = in_array($role, ['Admin', 'Super Admin'], true);
if (!$has_full_access && !isSurveyorAssignedToSurvey($db, $survey['id'], $current_user_id)) {
    $isOwnClient = ($role === 'Client') && (int)$survey['client_id'] === getClientIdForUser($db, $current_user_id);
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
                        You don't have permission to view this vessel. It isn't assigned to your account.
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
$recoveryNA = isRecoveryNotApplicable($db, (string)($survey['survey_type_ids'] ?? ''), (string)($survey['type_name'] ?? ''));
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
            error_log('completed_detail recovery update: ' . $e->getMessage());
            $recovery_error = 'Could not save recovery values. Please try again.';
        }
    }
}
// Re-open the inline edit form automatically only when the save just failed
// (so the user sees their input + the error right where they were editing);
// a successful save goes back to the plain display view.
$recovery_edit_open = ($recovery_error !== '');
// No recovery recorded at all on a completed vessel -> show only NOT APPLICABLE
// (Admin can still open the edit pencil and enter values; computed after the
// save above so a fresh save shows its values straight away).
$recoveryEmpty = !$recoveryNA && $survey['recovery_amount'] === null && $survey['vlsfo_recovery'] === null
    && $survey['lsmgo_recovery'] === null && ($survey['hsfo_recovery'] ?? null) === null;

// 🌟 Add Remarks (+ optional attachments) — Admin or the assigned surveyor(s)
// only (Client stays read-only, same boundary the rest of this page already
// draws). Post/Redirect/Get so a page refresh never re-submits the remark.
$can_add_remark = canAddCompletedRemark($db, $survey, $role, $current_user_id);
$remark_error = '';
if ($can_add_remark && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_remark'])) {
    $result = saveCompletedRemark($db, $id, $current_user_id, (string)($_POST['remarks'] ?? ''), $_FILES['remark_attachments'] ?? []);
    if ($result['ok']) {
        $_SESSION['flash_msg'] = $result['error'] !== '' ? $result['error'] : 'Remark saved.';
        if ($result['error'] !== '') $_SESSION['flash_type'] = 'error';
        header('Location: completed_detail.php?id=' . $id . '#remarks-section');
        exit;
    }
    $remark_error = $result['error'];
}
$completed_remarks = getCompletedRemarks($db, $id);
$can_delete_remark = canDeleteCompletedRemark($role);

if ($can_delete_remark && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_remark_id'])) {
    $delRemarkId = (int)$_POST['delete_remark_id'];
    try {
        // Only ever deletes a remark that actually belongs to this survey — a
        // forged id for another survey's remark can't be used to delete it.
        $own = $db->prepare("SELECT a.file_path FROM completed_remark_attachments a JOIN completed_remarks r ON r.id = a.remark_id WHERE a.remark_id = ? AND r.survey_id = ?");
        $own->execute([$delRemarkId, $id]);
        foreach ($own->fetchAll(PDO::FETCH_COLUMN) as $relPath) {
            $abs = __DIR__ . '/' . ltrim((string)$relPath, '/');
            if (is_file($abs)) { @unlink($abs); }
        }
        $delAtt = $db->prepare("DELETE a FROM completed_remark_attachments a JOIN completed_remarks r ON r.id = a.remark_id WHERE a.remark_id = ? AND r.survey_id = ?");
        $delAtt->execute([$delRemarkId, $id]);
        $delRow = $db->prepare("DELETE FROM completed_remarks WHERE id = ? AND survey_id = ?");
        $delRow->execute([$delRemarkId, $id]);
    } catch (Throwable $e) {
        error_log('completed_detail delete remark: ' . $e->getMessage());
    }
    header('Location: completed_detail.php?id=' . $id . '#remarks-section');
    exit;
}

// 2. ఈ వెసెల్ కి సంబంధించి అప్‌లోడ్ చేసిన అన్ని రకాల ఫైల్స్ (PDF, Excel, Word, Extra) ఒకేసారి లోడ్ చేయడం
$uploads_stmt = $db->prepare("SELECT * FROM uploads WHERE survey_id = ? ORDER BY id ASC");
$uploads_stmt->execute([$id]);
$all_files = $uploads_stmt->fetchAll();

// 🌟 Single source of truth for the Replace button — Admin always,
// Surveyor only if actually assigned to this survey; Super Admin/Client
// never (re-checked independently in ajax/document_replace.php too).
$can_replace_docs = canReplaceDocument($db, $role, $current_user_id, (int)$survey['id']);


function fmtDateTime($val) {
    if (empty($val) || $val === '0000-00-00' || $val === '0000-00-00 00:00:00') return '—';
    $ts = strtotime($val);
    if (!$ts) return '—';
    // Always show date + time (HH:MM)
    return date('d M Y, H:i', $ts);
}

// Dates the user picks (assign form / survey completed / report completed) — date only.
function fmtDateOnly($val) {
    if (empty($val) || strpos((string)$val, '0000-00-00') === 0) return '—';
    $ts = strtotime(substr((string)$val, 0, 10));
    return $ts ? date('d M Y', $ts) : '—';
}

// Survey Assigned Date = the date entered on the Assign Vessel form
// (appointment_date); older records without one fall back to assign_date.
$cdAssignedDate = fmtDateOnly(!empty($survey['appointment_date']) ? $survey['appointment_date'] : ($survey['assign_date'] ?? ''));

include 'includes/header.php';
?>

<style>
/* Recovery: one card, display by default, an Admin-only edit-pencil swaps
   in the same Save form in place. Unguarded by any media query — this
   page's Recovery block is a single markup block shared by mobile and
   desktop views alike (no separate desktop-only DOM here). */
.recovery-block-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 16px; font-weight: 700; font-size: 13px; color: #0f172a;
    border-bottom: 1px solid #f1f5f9;
}
.recovery-edit-toggle {
    border: none; background: #F3EEFE; color: #5B2DEE; width: 30px; height: 30px;
    border-radius: 9px; font-size: 12px; cursor: pointer; flex: none; margin-left: auto;
}
.recovery-edit-toggle:hover { background: #E9E2FE; }
.recovery-block .recovery-edit-form { display: none; }
.recovery-block.editing .recovery-display { display: none; }
.recovery-block.editing .recovery-edit-form { display: block; }

/* 🌟 Document Replace — View/Download/Replace row actions. Unconditional:
   View/Download render for every role that can reach this page, including
   Client — only the Replace button itself is PHP-gated to
   Admin/assigned-Surveyor. */
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
    .scroll-content .detail-header-card {
        max-width: none;
        width: calc(100% - 32px);
        margin: 10px 16px 8px;
        border-radius: 14px;
        border: 1px solid var(--border-color);
        text-align: left;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 16px;
        padding: 16px 20px;
        background: #fff;
    }
    .scroll-content .detail-header-card .vessel-initial-avatar {
        margin: 0 !important;
    }
    .scroll-content .detail-header-card h3,
    .scroll-content .detail-header-card p { text-align: left; margin: 0 !important; }
    .detail-top-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px;
        max-width: none;
        width: calc(100% - 32px);
        margin: 0 16px 8px;
    }
    .detail-top-grid > .info-table-list,
    .detail-top-grid > .form-box {
        margin: 0 !important;
        max-width: none;
    }
    .detail-files-wrap {
        max-width: none;
        width: calc(100% - 32px);
        margin: 8px 16px 8px;
        padding: 0 !important;
    }
    .detail-files-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }
    .scroll-content .action-btn-container {
        max-width: none;
        width: calc(100% - 32px);
        margin: 12px 16px 20px !important;
        padding: 0 !important;
        display: block !important;
        visibility: visible !important;
    }
    .scroll-content .action-btn-container .blue-action-btn {
        width: auto !important;
        max-width: 320px;
        display: inline-flex !important;
        visibility: visible !important;
        padding: 12px 22px;
    }
    .info-row { padding: 10px 16px; }
}
</style>

<div class="scroll-content">
    <?php $page_title = 'Vessel Complete History'; $back_url = 'completed.php'; $page_testid = 'completed-detail'; include 'includes/top_app_bar.php'; ?>

    <!-- Top Summary Card -->
    <div class="detail-header-card">
        <div class="vessel-initial-avatar mx-auto mb-2" style="width: 55px; height: 55px; background:#e0f2fe; color:#0369a1; font-weight:700; display:flex; justify-content:center; align-items:center; border-radius:50%;">
            <?= strtoupper(substr($survey['vessel_name'], 3, 1)) ?>
        </div>
        <h3 class="fw-bold text-dark m-0" style="font-size: 18px;"><?= sanitize($survey['vessel_name']) ?></h3>
        <p class="text-muted m-0 mt-1" style="font-size: 12px;">Client: <span class="text-primary fw-bold"><?= sanitize($survey['company_name']) ?></span></p>
        <?php if (!empty($survey['report_number'])): ?>
        <p class="text-muted m-0 mt-1" style="font-size: 12px;">Report No: <span class="text-dark fw-bold"><?= sanitize($survey['report_number']) ?></span></p>
        <?php endif; ?>
        <div class="mt-2"><span class="badge bg-success px-2 py-1" style="font-size:10px;"><i class="fa-solid fa-circle-check"></i> Survey Fully Completed</span></div>
        <p class="text-muted m-0 mt-2" style="font-size:12px;" data-testid="completed-detail-uploaded-by">Uploaded By: <span class="text-dark fw-bold"><?= sanitize($survey['uploader_name'] ?: $survey['surveyor_name'] ?: 'Admin') ?></span></p>
    </div>

    <div class="detail-top-grid">
    <!-- Timeline Dates -->
    <div class="info-table-list shadow-sm">
        <div class="info-row">
            <span class="info-label"><i class="fa-regular fa-calendar-plus text-primary"></i> Survey Assigned Date</span>
            <span class="info-value" data-testid="cd-assigned-mobile"><span class="badge bg-light text-dark border"><?= $cdAssignedDate ?></span></span>
        </div>
        <div class="info-row">
            <span class="info-label"><i class="fa-regular fa-calendar-check text-warning"></i> Survey Completed Date</span>
            <span class="info-value"><span class="badge bg-light text-dark border"><?= fmtDateOnly($survey['survey_completed_date'] ?? '') ?></span></span>
        </div>
        <div class="info-row">
            <span class="info-label"><i class="fa-solid fa-calendar-check text-success"></i> Report Completed Date</span>
            <span class="info-value"><span class="badge bg-light text-dark border"><?= fmtDateOnly($survey['report_uploaded_date'] ?? '') ?></span></span>
        </div>
    </div>

    <!-- Other records -->
    <div class="info-table-list shadow-sm">
        <div class="info-row"><span class="info-label">Report No</span><span class="info-value fw-bold text-dark"><?= !empty($survey['report_number']) ? sanitize($survey['report_number']) : '—' ?></span></div>
        <div class="info-row"><span class="info-label">Survey Operation Type</span><span class="info-value text-primary"><?= sanitize(getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? 'N/A')) ?></span></div>
        <div class="info-row"><span class="info-label">Port of Operation</span><span class="info-value"><?= sanitize($survey['port_name']) ?></span></div>
        <div class="info-row"><span class="info-label">Agent Handle</span><span class="info-value"><?= sanitize($survey['agent_name']) ?></span></div>
        <?php if ($recoveryNA): ?>
        <div class="info-row" data-testid="recovery-na-mobile"><span class="info-label">Recovery</span><span class="info-value fw-bold"><?= sanitize(RECOVERY_NA_LABEL) ?></span></div>
        <?php endif; ?>
    </div>

    <!-- Recovery — single card; Admin gets an inline edit-pencil that swaps
         the display rows for the same Save form in place, instead of a
         separate "Edit Recovery" box further down the page. -->
    <?php if (!$recoveryNA): // no Recovery card at all for N/A survey types ?>
    <div class="info-table-list shadow-sm recovery-block<?= $recovery_edit_open ? ' editing' : '' ?>" id="recoveryBlock" data-testid="recovery-block">
        <div class="recovery-block-head">
            <span><i class="fa-solid fa-droplet text-primary me-1"></i> Recovery</span>
            <?php if ($is_admin && !$recoveryNA): ?>
            <button type="button" class="recovery-edit-toggle" data-recovery-edit-toggle="recoveryBlock" title="Edit Recovery" data-testid="recovery-edit-toggle"><i class="fa-solid fa-pen"></i></button>
            <?php endif; ?>
        </div>
        <?php if ($recovery_message): ?><div class="alert alert-success py-2 mx-2 mt-2 mb-0" style="font-size:12px;" data-testid="recovery-success"><?= sanitize($recovery_message) ?></div><?php endif; ?>
        <?php if ($recovery_error): ?><div class="alert alert-danger py-2 mx-2 mt-2 mb-0" style="font-size:12px;" data-testid="recovery-error"><?= sanitize($recovery_error) ?></div><?php endif; ?>

        <div class="recovery-display">
            <?php if ($recoveryEmpty): ?>
            <div class="info-row" data-testid="recovery-empty-mobile"><span class="info-label">Recovery</span><span class="info-value fw-bold"><?= sanitize(RECOVERY_NA_LABEL) ?></span></div>
            <?php else: ?>
            <div class="info-row"><span class="info-label">Total Recovery</span><span class="info-value"><?= $survey['recovery_amount'] !== null ? sanitize(number_format((float)$survey['recovery_amount'], 3)) . ' MT' : '—' ?></span></div>
            <div class="info-row"><span class="info-label">VLSFO Recovery</span><span class="info-value"><?= $survey['vlsfo_recovery'] !== null ? sanitize(number_format((float)$survey['vlsfo_recovery'], 3)) . ' MT' : '—' ?></span></div>
            <div class="info-row"><span class="info-label">LSMGO Recovery</span><span class="info-value"><?= $survey['lsmgo_recovery'] !== null ? sanitize(number_format((float)$survey['lsmgo_recovery'], 3)) . ' MT' : '—' ?></span></div>
            <div class="info-row"><span class="info-label">HSFO Recovery</span><span class="info-value"><?= $survey['hsfo_recovery'] !== null ? sanitize(number_format((float)$survey['hsfo_recovery'], 3)) . ' MT' : '—' ?></span></div>
            <?php endif; ?>
        </div>

        <?php if ($is_admin && !$recoveryNA): ?>
        <div class="recovery-edit-form">
            <form method="POST" class="p-3"><?= csrf_field() ?>
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
                <div class="d-flex gap-2">
                    <button type="submit" name="update_recovery" class="btn btn-primary btn-sm w-100" data-testid="save-recovery-button">Save Recovery</button>
                    <button type="button" class="btn btn-light btn-sm w-100" data-recovery-edit-toggle="recoveryBlock">Cancel</button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    </div><!-- /.detail-top-grid -->

<?php
// "Add Files" box under the Dossier Archive — same people who may replace
// documents here (Admin / assigned Surveyor). Posts to the shared upload
// handler, which keeps a Completed vessel Completed and returns here.
$renderCompletedAddFiles = function (string $variant) use ($survey) {
    $id = (int)$survey['id'];
    ob_start(); ?>
    <form action="ajax/upload_handler.php" method="POST" enctype="multipart/form-data" class="completed-add-files-form caf-<?= $variant ?>" data-testid="completed-add-files-form-<?= $variant ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="survey_id" value="<?= $id ?>">
        <input type="hidden" name="current_status" value="Completed">
        <div class="caf-title"><i class="fa-solid fa-folder-plus"></i> Add Files</div>
        <label class="caf-label" for="cafFiles-<?= $variant ?>">Documents / photos <span>(PDF, Word, Excel, PowerPoint, images, ZIP… — you can pick several)</span></label>
        <input type="file" name="extra_files[]" id="cafFiles-<?= $variant ?>" class="form-control form-control-sm caf-files" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.xlsm,.csv,.txt,.rtf,.ppt,.pptx,.odt,.ods,.jpg,.jpeg,.png,.gif,.webp,.heic,.heif,.tif,.tiff,.bmp,.zip,.rar,.7z,.msg,.eml">
        <label class="caf-label mt-2" for="cafZip-<?= $variant ?>">ZIP Folder <span>(optional, max 30 MB)</span></label>
        <input type="file" name="zip_folder" id="cafZip-<?= $variant ?>" class="form-control form-control-sm zip-folder-input" accept=".zip,application/zip,application/x-zip-compressed">
        <button type="submit" class="caf-submit" data-testid="completed-add-files-submit-<?= $variant ?>"><i class="fa-solid fa-cloud-arrow-up"></i> Upload Files</button>
    </form>
    <?php return ob_get_clean();
};
?>
<style>
    .completed-add-files-form { margin-top: 12px; background: #F8FAFF; border: 1px dashed #C7D2FE; border-radius: 14px; padding: 14px; }
    .caf-title { font-weight: 700; font-size: 14px; color: #1e3a8a; margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
    .caf-label { display: block; font-size: 12px; font-weight: 600; color: #334155; margin-bottom: 4px; }
    .caf-label span { font-weight: 500; color: #64748b; }
    .caf-submit { margin-top: 12px; width: 100%; border: 0; border-radius: 10px; padding: 10px 14px; background: #1e3a8a; color: #fff; font-weight: 600; font-size: 14px; display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
    .caf-submit:hover { background: #0b1e46; }
</style>
    <!-- Files -->
    <div class="px-3 mt-3 detail-files-wrap" id="dossier-files">
        <div class="fw-bold text-dark mb-2" style="font-size: 14px;">
            <i class="fa-solid fa-cloud-arrow-down text-primary me-1"></i> Dossier Archive (All Uploaded Files)
        </div>
        <div class="detail-files-grid">
        <?php if(count($all_files) > 0): ?>
            <?php foreach($all_files as $file): ?>
                <?php 
                    // ఫైల్ ఎక్స్‌టెన్షన్‌ను బట్టి క్లీన్ ఐకాన్ మరియు కలర్ సెట్ చేయడం
                    $icon = 'fa-file-lines text-secondary';
                    if (str_contains($file['file_type'], 'PDF')) {
                        $icon = 'fa-file-pdf text-danger';
                    } elseif (str_contains($file['file_type'], 'Excel')) {
                        $icon = 'fa-file-excel text-success';
                    } elseif (str_contains($file['file_type'], 'Word')) {
                        $icon = 'fa-file-word text-primary';
                    } elseif (str_contains($file['file_type'], 'ZIP') || str_ends_with(strtolower((string)$file['file_name']), '.zip')) {
                        $icon = 'fa-file-zipper text-warning';
                    }
                ?>
                <div class="bg-white p-3 rounded-3 border d-flex align-items-center justify-content-between mb-2 shadow-sm" data-doc-row data-doc-id="<?= (int)$file['id'] ?>">
                    <div class="d-flex align-items-center gap-3" style="max-width: 58%;">
                        <i class="fa-regular <?= $icon ?> fs-3"></i>
                        <div style="overflow: hidden;">
                            <div class="fw-bold text-dark text-truncate" style="font-size:13px; max-width: 100%;" data-doc-name-display title="<?= sanitize(preg_replace('/^[0-9]+_/', '', $file['file_name'])) ?>">
                                <?= sanitize(preg_replace('/^[0-9]+_/', '', $file['file_name'])) ?>
                            </div>
                            <div class="text-muted d-flex gap-2 align-items-center" style="font-size:11px; margin-top:2px;">
                                <span class="badge bg-light text-secondary border py-0.5"><?= sanitize($file['file_type']) ?></span>
                                <span data-doc-size-display><?= sanitize($file['file_size']) ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="doc-actions-row">
                        <a href="download.php?upload_id=<?= (int)$file['id'] ?>&inline=1" target="_blank" rel="noopener" class="doc-view-btn" title="View"><i class="fa-solid fa-eye"></i></a>
                        <a href="download.php?upload_id=<?= (int)$file['id'] ?>" data-doc-download-link class="doc-view-btn" title="Download"><i class="fa-solid fa-download"></i></a>
                        <?php if ($can_replace_docs): ?>
                        <button type="button" class="doc-replace-btn" title="Replace / Re-upload"
                                data-doc-id="<?= (int)$file['id'] ?>"
                                data-doc-name="<?= sanitize(preg_replace('/^[0-9]+_/', '', $file['file_name'])) ?>"
                                data-doc-type="<?= sanitize($file['file_type']) ?>"
                                data-doc-size="<?= sanitize($file['file_size']) ?>"
                                data-doc-uploaded-at="<?= sanitize(fmtDateTime($file['uploaded_at'] ?? '')) ?>"
                                data-doc-uploaded-by="<?= sanitize($file['uploaded_by'] ? getUserFullNameById($db, (int)$file['uploaded_by']) : '—') ?>">
                            <i class="fa-solid fa-rotate"></i> Replace
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="bg-white p-3 text-center text-muted rounded-3 border small">
                <i class="fa-solid fa-folder-open d-block mb-1 fs-4 text-secondary"></i> No files archived for this vessel.
            </div>
        <?php endif; ?>
        </div><!-- /.detail-files-grid -->
        <?php if ($can_replace_docs) echo $renderCompletedAddFiles('mobile'); ?>
    </div>

       <!-- Generate Invoice: Admin only. Generate Expenses: Surveyor only (Coming Soon for now).
            Client and Super Admin see neither, and are blocked server-side too
            (invoice_generator.php / generate_invoice.php are Admin-only). -->
    <div class="action-btn-container mb-4 px-3" style="display:flex;flex-direction:column;gap:10px;">
        <?php if (($_SESSION['role'] ?? '') === 'Admin'): ?>
        <a href="invoice_generator.php?id=<?= (int)$survey['id'] ?>" class="blue-action-btn text-decoration-none d-inline-flex align-items-center justify-content-center" data-testid="generate-invoice-button">
            <i class="fa-solid fa-file-invoice-dollar me-1"></i> Generate Invoice
        </a>
        <?php endif; ?>
        <?php if (($_SESSION['role'] ?? '') === 'Surveyor'): ?>
        <a href="coming_soon.php?feature=Generate+Expenses" class="blue-action-btn text-decoration-none d-inline-flex align-items-center justify-content-center" style="background:#0f766e;" data-testid="generate-expenses-button">
            <i class="fa-solid fa-receipt me-1"></i> Generate Expenses
        </a>
        <?php endif; ?>
    </div>

    <!-- Add Remarks — shared section, its own simple responsive CSS. -->
    <section class="remarks-section" id="remarks-section" data-testid="remarks-section">
        <div class="remarks-card">
            <div class="remarks-card-head">
                <div class="remarks-card-head-icon"><i class="fa-solid fa-comment-dots"></i></div>
                <div>
                    <div class="remarks-card-head-title">Add Remarks</div>
                    <div class="remarks-card-head-sub">Notes about this completed vessel/report</div>
                </div>
            </div>

            <?php if ($can_add_remark): ?>
            <?php if ($remark_error): ?><div class="remarks-alert" data-testid="remark-error"><?= sanitize($remark_error) ?></div><?php endif; ?>
            <form method="POST" enctype="multipart/form-data" id="remarkForm" data-testid="remark-form"><?= csrf_field() ?>
                <input type="hidden" name="add_remark" value="1">
                <textarea name="remarks" class="remarks-textarea" placeholder="Write your remarks here..." required data-testid="remark-textarea"></textarea>

                <div class="remarks-attach-row">
                    <label class="remarks-attach-btn">
                        <i class="fa-solid fa-paperclip"></i> Attachments (Optional)
                        <input type="file" name="remark_attachments[]" id="remarkFiles" multiple
                               accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx" style="display:none;" data-testid="remark-file-input">
                    </label>
                    <span class="remarks-attach-hint">PDF, JPG, PNG, DOC/DOCX, XLS/XLSX — up to 15MB each</span>
                </div>
                <div class="remarks-file-list" id="remarkFileList"></div>

                <button type="submit" class="remarks-save-btn" data-testid="save-remark-button">
                    <i class="fa-solid fa-floppy-disk"></i> Save Remarks
                </button>
            </form>
            <?php else: ?>
            <div class="remarks-readonly-note"><i class="fa-solid fa-eye"></i> You can view remarks here, but only Admin or the assigned surveyor can add new ones.</div>
            <?php endif; ?>

            <div class="remarks-list">
                <?php if (empty($completed_remarks)): ?>
                    <div class="remarks-empty">No remarks yet.</div>
                <?php else: foreach ($completed_remarks as $rem): ?>
                    <div class="remarks-item" data-testid="remark-item-<?= (int)$rem['id'] ?>">
                        <div class="remarks-item-head">
                            <span class="remarks-item-author"><?= sanitize($rem['author_name'] ?: 'User') ?></span>
                            <span class="remarks-item-date"><?= sanitize(fmtDateTime($rem['created_at'])) ?></span>
                            <?php if ($can_delete_remark): ?>
                            <form method="POST" class="remarks-delete-form" onsubmit="return confirm('Delete this remark?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="delete_remark_id" value="<?= (int)$rem['id'] ?>">
                                <button type="submit" class="remarks-delete-btn" title="Delete remark" data-testid="delete-remark-<?= (int)$rem['id'] ?>"><i class="fa-solid fa-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <div class="remarks-item-text"><?= nl2br(sanitize($rem['remarks'])) ?></div>
                        <?php if (!empty($rem['attachments'])): ?>
                        <div class="remarks-item-attachments">
                            <?php foreach ($rem['attachments'] as $att): ?>
                                <a href="download.php?remark_attachment_id=<?= (int)$att['id'] ?>" class="remarks-attachment-chip">
                                    <i class="fa-solid fa-paperclip"></i> <?= sanitize($att['file_name']) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </section>
</div>

<style>
    .remarks-section { padding: 0 12px 24px; }
    .remarks-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 18px; padding: 20px; box-shadow: 0 4px 20px rgba(15,23,42,.05); max-width: 100%; }
    .remarks-card-head { display: flex; align-items: center; gap: 10px; margin-bottom: 16px; }
    .remarks-card-head-icon { width: 34px; height: 34px; border-radius: 10px; flex: none; background: linear-gradient(135deg, #8B7CF6, #4B2FD6); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 14px; }
    .remarks-card-head-title { font-size: 15px; font-weight: 800; color: #0f172a; }
    .remarks-card-head-sub { font-size: 11.5px; color: #94a3b8; font-weight: 500; margin-top: 1px; }

    .remarks-alert { background: #FEF2F2; border: 1.5px solid #FECACA; color: #991B1B; border-radius: 10px; padding: 10px 14px; font-size: 12.5px; font-weight: 600; margin-bottom: 12px; }
    .remarks-textarea { width: 100%; min-height: 110px; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 12px 14px; font-size: 13.5px; font-family: inherit; color: #1e293b; background: #F8FAFC; resize: vertical; }
    .remarks-textarea:focus { outline: none; border-color: #A78BFA; background: #fff; box-shadow: 0 0 0 4px rgba(91,45,238,.12); }

    .remarks-attach-row { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-top: 12px; }
    .remarks-attach-btn { display: inline-flex; align-items: center; gap: 7px; padding: 8px 14px; border-radius: 10px; background: #F1F5F9; color: #334155; font-size: 12.5px; font-weight: 700; cursor: pointer; border: 1.5px dashed #cbd5e1; }
    .remarks-attach-btn:hover { background: #E2E8F0; }
    .remarks-attach-hint { font-size: 11px; color: #94a3b8; }
    .remarks-file-list { display: flex; flex-direction: column; gap: 6px; margin-top: 10px; }
    .remarks-file-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; background: #F8FAFC; border: 1px solid #e2e8f0; border-radius: 8px; padding: 6px 10px; font-size: 12px; color: #334155; }
    .remarks-file-row .name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .remarks-file-row .remove-btn { border: none; background: none; color: #DC2626; cursor: pointer; font-size: 12px; flex: none; padding: 2px 6px; }

    .remarks-save-btn { display: inline-flex; align-items: center; gap: 8px; border: none; border-radius: 12px; padding: 11px 20px; margin-top: 14px; font-size: 13px; font-weight: 700; cursor: pointer; background: linear-gradient(120deg, #1E1B6B, #4B2FD6); color: #fff; box-shadow: 0 6px 16px rgba(75,47,214,.28); }
    .remarks-save-btn:hover { box-shadow: 0 10px 22px rgba(75,47,214,.38); }
    .remarks-readonly-note { font-size: 12.5px; color: #64748b; background: #F8FAFC; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px 14px; }

    .remarks-list { margin-top: 18px; display: flex; flex-direction: column; gap: 12px; }
    .remarks-empty { text-align: center; color: #94a3b8; font-size: 12.5px; padding: 20px 0; }
    .remarks-item { border: 1px solid #f1f5f9; border-radius: 12px; padding: 12px 14px; background: #FAFBFF; }
    .remarks-item-head { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; flex-wrap: wrap; }
    .remarks-item-author { font-size: 12.5px; font-weight: 700; color: #0f172a; }
    .remarks-item-date { font-size: 11px; color: #94a3b8; }
    .remarks-delete-form { margin-left: auto; }
    .remarks-delete-btn { border: none; background: none; color: #cbd5e1; cursor: pointer; padding: 2px 4px; }
    .remarks-delete-btn:hover { color: #DC2626; }
    .remarks-item-text { font-size: 13px; color: #334155; line-height: 1.55; white-space: pre-wrap; word-break: break-word; }
    .remarks-item-attachments { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
    .remarks-attachment-chip { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 999px; background: #EEF2FF; color: #4338CA; font-size: 11.5px; font-weight: 600; text-decoration: none; }
    .remarks-attachment-chip:hover { background: #E0E7FF; }

    @media (min-width: 992px) {
        .remarks-section { max-width: 1400px; margin: 0 auto; padding: 0 20px 40px; }
        .remarks-card { max-width: 900px; }
    }
</style>
<script>
(function () {
    var input = document.getElementById('remarkFiles');
    var list = document.getElementById('remarkFileList');
    if (!input || !list) return;
    var dt = new DataTransfer();

    input.addEventListener('change', function () {
        Array.prototype.forEach.call(input.files, function (f) { dt.items.add(f); });
        input.files = dt.files;
        render();
    });

    function render() {
        list.innerHTML = '';
        Array.prototype.forEach.call(dt.files, function (f, idx) {
            var row = document.createElement('div');
            row.className = 'remarks-file-row';
            var name = document.createElement('span');
            name.className = 'name';
            name.textContent = f.name;
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'remove-btn';
            btn.innerHTML = '<i class="fa-solid fa-xmark"></i> Remove';
            btn.addEventListener('click', function () {
                var newDt = new DataTransfer();
                Array.prototype.forEach.call(dt.files, function (ff, i2) { if (i2 !== idx) newDt.items.add(ff); });
                dt = newDt;
                input.files = dt.files;
                render();
            });
            row.appendChild(name);
            row.appendChild(btn);
            list.appendChild(row);
        });
    }
})();
</script>

<script>
// Recovery inline edit toggle — no page reload needed to switch between
// display and edit.
(function () {
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-recovery-edit-toggle]');
        if (!btn) return;
        var block = document.getElementById(btn.getAttribute('data-recovery-edit-toggle'));
        if (block) block.classList.toggle('editing');
    });
})();
</script>

<script>
// Add Files box: needs at least one file, and stays under Cloud Run's 32 MB
// request limit (anything bigger is refused before it reaches the app).
(function () {
    var ZIP_MAX = 30 * 1024 * 1024, TOTAL_MAX = 31 * 1024 * 1024;
    function warn(title, text) {
        if (window.Swal) Swal.fire({ icon: 'warning', title: title, text: text, confirmButtonColor: '#1e3a8a' });
        else alert(title + '\n\n' + text);
    }
    function mb(b) { return (b / 1048576).toFixed(1) + ' MB'; }
    document.addEventListener('change', function (e) {
        var input = e.target;
        if (!input.classList || !input.classList.contains('zip-folder-input') || !input.files || !input.files[0]) return;
        var f = input.files[0];
        if (!/\.zip$/i.test(f.name)) {
            warn('Only .zip files', 'Please choose a .zip file for the ZIP Folder.');
            input.value = '';
        } else if (f.size > ZIP_MAX) {
            warn('ZIP folder too large', f.name + ' is ' + mb(f.size) + '. The maximum is 30 MB — please split it into smaller ZIP files or reduce photo sizes.');
            input.value = '';
        }
    });
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.classList || !form.classList.contains('completed-add-files-form')) return;
        var total = 0, count = 0;
        form.querySelectorAll('input[type=file]').forEach(function (i) {
            for (var k = 0; i.files && k < i.files.length; k++) { total += i.files[k].size; count++; }
        });
        if (count === 0) {
            e.preventDefault(); e.stopImmediatePropagation();
            warn('No files selected', 'Choose at least one file or a ZIP folder to upload.');
        } else if (total > TOTAL_MAX) {
            e.preventDefault(); e.stopImmediatePropagation();
            warn('Files too large to upload together', 'The selected files add up to ' + mb(total) + ' (limit 31 MB per upload). Please upload them in smaller batches.');
        }
    }, true);
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
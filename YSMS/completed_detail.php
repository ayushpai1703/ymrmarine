<?php
require_once 'config/config.php';
checkAuth();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$db = getDB();

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
$recovery_message = '';
$recovery_error = '';
if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_recovery'])) {
    $parseRecovery = function ($v) {
        $v = trim((string)$v);
        if ($v === '') return null;
        if (!is_numeric($v) || (float)$v < 0) return false;
        return round((float)$v, 3);
    };
    $totalVal = $parseRecovery($_POST['recovery_amount'] ?? '');
    $vlsfoVal = $parseRecovery($_POST['vlsfo_recovery'] ?? '');
    $lsmgoVal = $parseRecovery($_POST['lsmgo_recovery'] ?? '');
    if ($totalVal === false || $vlsfoVal === false || $lsmgoVal === false) {
        $recovery_error = 'Recovery values must be zero or a positive number.';
    } else {
        try {
            $upd = $db->prepare("UPDATE surveys SET recovery_amount = ?, vlsfo_recovery = ?, lsmgo_recovery = ? WHERE id = ?");
            $upd->execute([$totalVal, $vlsfoVal, $lsmgoVal, $id]);
            $survey['recovery_amount'] = $totalVal;
            $survey['vlsfo_recovery'] = $vlsfoVal;
            $survey['lsmgo_recovery'] = $lsmgoVal;
            $recovery_message = 'Recovery values updated.';
        } catch (Throwable $e) {
            error_log('completed_detail recovery update: ' . $e->getMessage());
            $recovery_error = 'Could not save recovery values. Please try again.';
        }
    }
}

// 2. ఈ వెసెల్ కి సంబంధించి అప్‌లోడ్ చేసిన అన్ని రకాల ఫైల్స్ (PDF, Excel, Word, Extra) ఒకేసారి లోడ్ చేయడం
$uploads_stmt = $db->prepare("SELECT * FROM uploads WHERE survey_id = ? ORDER BY id ASC");
$uploads_stmt->execute([$id]);
$all_files = $uploads_stmt->fetchAll();


function fmtDateTime($val) {
    if (empty($val) || $val === '0000-00-00' || $val === '0000-00-00 00:00:00') return '—';
    $ts = strtotime($val);
    if (!$ts) return '—';
    // Always show date + time (HH:MM)
    return date('d M Y, H:i', $ts);
}

include 'includes/header.php';
?>

<style>
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
            <span class="info-value"><span class="badge bg-light text-dark border"><?= fmtDateTime($survey['assign_date'] ?? '') ?></span></span>
        </div>
        <div class="info-row">
            <span class="info-label"><i class="fa-regular fa-calendar-check text-warning"></i> Survey Completed Date</span>
            <span class="info-value"><span class="badge bg-light text-dark border"><?= fmtDateTime($survey['survey_completed_date'] ?? '') ?></span></span>
        </div>
        <div class="info-row">
            <span class="info-label"><i class="fa-solid fa-calendar-check text-success"></i> Report Completed Date</span>
            <span class="info-value"><span class="badge bg-light text-dark border"><?= fmtDateTime($survey['report_uploaded_date'] ?? '') ?></span></span>
        </div>
    </div>

    <!-- Other records -->
    <div class="info-table-list shadow-sm">
        <div class="info-row"><span class="info-label">Report No</span><span class="info-value fw-bold text-dark"><?= !empty($survey['report_number']) ? sanitize($survey['report_number']) : '—' ?></span></div>
        <div class="info-row"><span class="info-label">Survey Operation Type</span><span class="info-value text-primary"><?= sanitize(getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? 'N/A')) ?></span></div>
        <div class="info-row"><span class="info-label">Port of Operation</span><span class="info-value"><?= sanitize($survey['port_name']) ?></span></div>
        <div class="info-row"><span class="info-label">Agent Handle</span><span class="info-value"><?= sanitize($survey['agent_name']) ?></span></div>
    </div>

    <!-- Recovery -->
    <div class="info-table-list shadow-sm">
        <div class="info-row"><span class="info-label">Total Recovery</span><span class="info-value"><?= $survey['recovery_amount'] !== null ? sanitize(number_format((float)$survey['recovery_amount'], 3)) . ' MT' : '—' ?></span></div>
        <div class="info-row"><span class="info-label">VLSFO Recovery</span><span class="info-value"><?= $survey['vlsfo_recovery'] !== null ? sanitize(number_format((float)$survey['vlsfo_recovery'], 3)) . ' MT' : '—' ?></span></div>
        <div class="info-row"><span class="info-label">LSMGO Recovery</span><span class="info-value"><?= $survey['lsmgo_recovery'] !== null ? sanitize(number_format((float)$survey['lsmgo_recovery'], 3)) . ' MT' : '—' ?></span></div>
    </div>

    <?php if ($is_admin): ?>
    <div class="form-box mx-3 my-3 p-3 bg-white rounded-3 border shadow-sm" data-testid="recovery-edit-box">
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
            <button type="submit" name="update_recovery" class="btn btn-primary btn-sm w-100" data-testid="save-recovery-button">Save Recovery</button>
        </form>
    </div>
    <?php endif; ?>
    </div><!-- /.detail-top-grid -->

    <!-- Files -->
    <div class="px-3 mt-3 detail-files-wrap">
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
                    }
                ?>
                <div class="bg-white p-3 rounded-3 border d-flex align-items-center justify-content-between mb-2 shadow-sm">
                    <div class="d-flex align-items-center gap-3" style="max-width: 80%;">
                        <i class="fa-regular <?= $icon ?> fs-3"></i>
                        <div style="overflow: hidden;">
                            <div class="fw-bold text-dark text-truncate" style="font-size:13px; max-width: 100%;" title="<?= sanitize(preg_replace('/^[0-9]+_/', '', $file['file_name'])) ?>">
                                <?= sanitize(preg_replace('/^[0-9]+_/', '', $file['file_name'])) ?>
                            </div>
                            <div class="text-muted d-flex gap-2 align-items-center" style="font-size:11px; margin-top:2px;">
                                <span class="badge bg-light text-secondary border py-0.5"><?= sanitize($file['file_type']) ?></span>
                                <span><?= sanitize($file['file_size']) ?></span>
                            </div>
                        </div>
                    </div>
                    <?php
                        $clean_name = preg_replace('/^[0-9]+_/', '', $file['file_name']);
                    ?>
                    <a href="<?= $file['file_path'] ?>" download="<?= sanitize($clean_name) ?>" class="btn btn-sm btn-light border py-1.5 text-primary">
                        <i class="fa-solid fa-download"></i>
                    </a>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="bg-white p-3 text-center text-muted rounded-3 border small">
                <i class="fa-solid fa-folder-open d-block mb-1 fs-4 text-secondary"></i> No files archived for this vessel.
            </div>
        <?php endif; ?>
        </div><!-- /.detail-files-grid -->
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
</div>

<?php 
include 'includes/nav.php';
include 'includes/footer.php';
?>
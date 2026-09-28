<?php
require_once 'config/config.php';
require_once __DIR__ . '/../includes/upload_validation.php';
require_once 'includes/completed_remarks_helpers.php';
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

// 🌟 Add Remarks (+ optional attachments) — Admin or the assigned surveyor(s)
// only (Client stays read-only, same boundary the rest of this page already
// draws). Post/Redirect/Get so a page refresh never re-submits the remark.
$can_add_remark = canAddCompletedRemark($db, $survey, $role, $current_user_id);
$remark_error = '';
if ($can_add_remark && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_remark'])) {
    $result = saveCompletedRemark($db, $id, $current_user_id, (string)($_POST['remarks'] ?? ''), $_FILES['remark_attachments'] ?? []);
    if ($result['ok']) {
        $_SESSION['flash_msg'] = $result['error'] !== '' ? $result['error'] : 'Remark saved.';
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

    /* Recovery: one card, display by default, an Admin-only edit-pencil
       swaps in the same Save form in place. Deliberately unguarded by any
       media query — the .recovery-block class is shared by both the mobile
       (.info-table-list) and desktop (.vdd-card) markup below, so these
       rules must apply at every width, not just inside one layout's own
       media query. */
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

    /* ═══ Desktop modern redesign — same hero + icon-chip card visual
       language as vessel_detail.php / report_detail.php. Mobile
       (".detail-mobile-view", the markup above/below unchanged) keeps the
       existing layout; only >=992px swaps to this. Page-scoped <style>. */
    .detail-mobile-view { display: block; }
    .detail-desktop-view { display: none; }
    @media (min-width: 992px) {
        .detail-mobile-view { display: none !important; }
        .detail-desktop-view { display: block !important; }
        .top-app-bar { display: none !important; }

        .vdd-wrap { padding: 8px 20px 40px; max-width: 1400px; margin: 0 auto; }

        .vdd-back {
            display: flex; align-items: center; gap: 10px; margin-bottom: 10px;
            font-size: 13px; font-weight: 600; color: #64748b; text-decoration: none;
        }
        .vdd-back:hover { color: #5B2DEE; }
        .vdd-back-icon {
            width: 32px; height: 32px; border-radius: 10px; background: #fff;
            border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: center;
            color: #475569; flex: none;
        }

        .vdd-hero {
            position: relative; overflow: hidden; border-radius: 22px;
            background: linear-gradient(120deg, #0f172a 0%, #14532D 55%, #16A34A 100%);
            padding: 26px 30px; margin-bottom: 18px;
            display: flex; align-items: center; justify-content: space-between; gap: 18px; flex-wrap: wrap;
            box-shadow: 0 14px 34px rgba(20,83,45,.22);
        }
        .vdd-hero-icon-bg {
            position: absolute; right: -26px; top: -40px; font-size: 190px; color: rgba(255,255,255,.07);
            pointer-events: none; line-height: 1;
        }
        .vdd-hero-left { display: flex; align-items: center; gap: 16px; min-width: 0; position: relative; }
        .vdd-hero-ship {
            width: 56px; height: 56px; border-radius: 16px; flex: none;
            background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.18);
            display: flex; align-items: center; justify-content: center; color: #fff; font-size: 24px;
        }
        .vdd-hero-title-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .vdd-hero-title { font-size: 24px; font-weight: 800; color: #fff; letter-spacing: -.01em; }
        .vdd-hero-status {
            display: inline-flex; align-items: center; gap: 6px; background: rgba(255,255,255,.16);
            border: 1px solid rgba(255,255,255,.24); color: #fff; font-size: 10.5px; font-weight: 800;
            padding: 5px 12px; border-radius: 999px; text-transform: uppercase; letter-spacing: .04em; white-space: nowrap;
        }
        .vdd-hero-sub { font-size: 13px; color: rgba(255,255,255,.7); margin-top: 6px; font-weight: 500; }
        .vdd-hero-sub b { color: #fff; font-weight: 700; }

        .vdd-grid { display: grid; grid-template-columns: 1.15fr .85fr; gap: 18px; align-items: start; }
        .vdd-card {
            background: #fff; border: 1px solid #e2e8f0; border-radius: 20px;
            box-shadow: 0 4px 20px rgba(15,23,42,.05); overflow: hidden;
        }
        .vdd-card + .vdd-card { margin-top: 18px; }
        .vdd-card-head {
            padding: 18px 22px; border-bottom: 1px solid #f1f5f9;
            display: flex; align-items: center; gap: 10px;
        }
        .vdd-card-head-icon {
            width: 30px; height: 30px; border-radius: 9px; flex: none;
            display: flex; align-items: center; justify-content: center; font-size: 14px;
        }
        .vdd-card-head-title { font-size: 15px; font-weight: 800; color: #0f172a; }
        .vdd-card-body { padding: 20px 22px 22px; }

        .vdd-info-rows { padding: 8px 10px; }
        .vdd-info-row {
            display: flex; align-items: center; gap: 14px; padding: 13px 12px; border-radius: 12px;
        }
        .vdd-info-row.alt { background: #FAFAFE; }
        .vdd-info-icon {
            width: 34px; height: 34px; border-radius: 10px; flex: none;
            display: flex; align-items: center; justify-content: center; font-size: 14px;
        }
        .vdd-info-label { flex: 0 0 150px; font-size: 12.5px; color: #64748b; font-weight: 600; }
        .vdd-info-value { font-size: 13.5px; color: #0f172a; font-weight: 700; min-width: 0; }
        .vdd-badge {
            display: inline-flex; align-items: center; gap: 5px;
            background: #EFF6FF; color: #1D4ED8; font-size: 10.5px; font-weight: 800;
            padding: 4px 12px; border-radius: 999px; text-transform: uppercase; letter-spacing: .03em;
        }
        .vdd-badge .dot { width: 5px; height: 5px; border-radius: 50%; background: #2563EB; flex: none; }
        .vdd-badge-soft {
            display: inline-flex; align-items: center; background: #F8FAFC; color: #334155;
            font-size: 11.5px; font-weight: 700; padding: 4px 11px; border-radius: 999px; border: 1px solid #e2e8f0;
        }

        /* Recovery figures — 2x2 stat-tile grid */
        .vdd-recovery-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; padding: 18px 22px 22px; }
        .vdd-recovery-tile {
            background: #F8FAFC; border: 1px solid #e2e8f0; border-radius: 14px; padding: 14px 16px;
            display: flex; align-items: center; gap: 12px;
        }
        .vdd-recovery-icon {
            width: 36px; height: 36px; border-radius: 10px; flex: none;
            display: flex; align-items: center; justify-content: center; font-size: 15px;
        }
        .vdd-recovery-value { font-size: 16px; font-weight: 800; color: #0f172a; line-height: 1.15; }
        .vdd-recovery-label { font-size: 10.5px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .04em; margin-top: 1px; }

        /* Modern form fields (Edit Recovery) */
        .vdd-field { margin-bottom: 12px; }
        .vdd-field label { display: block; font-size: 11.5px; font-weight: 700; color: #64748b; margin-bottom: 5px; }
        .vdd-field input {
            width: 100%; box-sizing: border-box; border: 1.5px solid #e2e8f0; border-radius: 12px;
            padding: 10px 14px; font-size: 13.5px; color: #0f172a; font-family: inherit; background: #F8FAFC;
        }
        .vdd-field input:focus { outline: none; border-color: #A78BFA; box-shadow: 0 0 0 4px rgba(91,45,238,.12); background: #fff; }
        .vdd-save-btn {
            width: 100%; border: none; background: linear-gradient(120deg, #1E1B6B, #4B2FD6); color: #fff;
            height: 44px; border-radius: 12px; font-weight: 800; font-size: 13px; cursor: pointer;
            box-shadow: 0 8px 18px rgba(75,47,214,.28);
        }
        .vdd-alert { border-radius: 12px; padding: 10px 14px; font-size: 12.5px; font-weight: 600; margin-bottom: 12px; }
        .vdd-alert-success { background: #D1FAE5; color: #065f46; }
        .vdd-alert-danger { background: #FEE2E2; color: #991b1b; }

        /* File list (Dossier Archive downloads) */
        .vdd-file-row {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 12px 14px;
        }
        .vdd-file-row + .vdd-file-row { margin-top: 10px; }
        .vdd-file-icon { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex: none; font-size: 15px; }
        .vdd-file-name { font-size: 12.5px; font-weight: 700; color: #0f172a; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .vdd-file-meta { display: flex; align-items: center; gap: 6px; margin-top: 3px; }
        .vdd-file-dl {
            flex: none; width: 34px; height: 34px; border-radius: 10px; background: #F8FAFC; border: 1px solid #e2e8f0;
            color: #5B2DEE; display: flex; align-items: center; justify-content: center; text-decoration: none !important;
        }
        .vdd-file-dl:hover { background: #5B2DEE; color: #fff; border-color: #5B2DEE; }
        .vdd-file-empty { text-align: center; padding: 30px 20px; color: #94a3b8; font-size: 12.5px; }

        .vdd-invoice-btn {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            width: 100%; border: none; border-radius: 14px; height: 48px;
            background: linear-gradient(120deg, #1E1B6B, #4B2FD6); color: #fff !important; font-weight: 800; font-size: 13.5px;
            text-decoration: none !important; box-shadow: 0 10px 22px rgba(75,47,214,.3);
        }
        .vdd-expenses-btn {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            width: 100%; border: none; border-radius: 14px; height: 48px;
            background: #0f766e; color: #fff !important; font-weight: 800; font-size: 13.5px;
            text-decoration: none !important; box-shadow: 0 10px 22px rgba(15,118,110,.3);
        }
    }
</style>

<div class="scroll-content">
    <?php $page_title = 'Vessel Complete History'; $back_url = 'completed.php'; $page_testid = 'completed-detail'; include 'includes/top_app_bar.php'; ?>

    <div class="detail-mobile-view">

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

    <!-- Recovery — single card; Admin gets an inline edit-pencil that swaps
         the display rows for the same Save form in place, instead of a
         separate "Edit Recovery" box further down the page. -->
    <div class="info-table-list shadow-sm recovery-block<?= $recovery_edit_open ? ' editing' : '' ?>" id="recoveryBlockMobile" data-testid="recovery-block-mobile">
        <div class="recovery-block-head">
            <span><i class="fa-solid fa-droplet text-primary me-1"></i> Recovery</span>
            <?php if ($is_admin): ?>
            <button type="button" class="recovery-edit-toggle" data-recovery-edit-toggle="recoveryBlockMobile" title="Edit Recovery" data-testid="recovery-edit-toggle-mobile"><i class="fa-solid fa-pen"></i></button>
            <?php endif; ?>
        </div>
        <?php if ($recovery_message): ?><div class="alert alert-success py-2 mx-2 mt-2 mb-0" style="font-size:12px;" data-testid="recovery-success"><?= sanitize($recovery_message) ?></div><?php endif; ?>
        <?php if ($recovery_error): ?><div class="alert alert-danger py-2 mx-2 mt-2 mb-0" style="font-size:12px;" data-testid="recovery-error"><?= sanitize($recovery_error) ?></div><?php endif; ?>

        <div class="recovery-display">
            <div class="info-row"><span class="info-label">Total Recovery</span><span class="info-value"><?= $survey['recovery_amount'] !== null ? sanitize(number_format((float)$survey['recovery_amount'], 3)) . ' MT' : '—' ?></span></div>
            <div class="info-row"><span class="info-label">VLSFO Recovery</span><span class="info-value"><?= $survey['vlsfo_recovery'] !== null ? sanitize(number_format((float)$survey['vlsfo_recovery'], 3)) . ' MT' : '—' ?></span></div>
            <div class="info-row"><span class="info-label">LSMGO Recovery</span><span class="info-value"><?= $survey['lsmgo_recovery'] !== null ? sanitize(number_format((float)$survey['lsmgo_recovery'], 3)) . ' MT' : '—' ?></span></div>
            <div class="info-row"><span class="info-label">HSFO Recovery</span><span class="info-value"><?= $survey['hsfo_recovery'] !== null ? sanitize(number_format((float)$survey['hsfo_recovery'], 3)) . ' MT' : '—' ?></span></div>
        </div>

        <?php if ($is_admin): ?>
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
                    <button type="button" class="btn btn-light btn-sm w-100" data-recovery-edit-toggle="recoveryBlockMobile">Cancel</button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
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
                    <a href="download.php?upload_id=<?= (int)$file['id'] ?>" class="btn btn-sm btn-light border py-1.5 text-primary">
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
    </div><!-- /.detail-mobile-view -->

    <div class="detail-desktop-view">
        <div class="vdd-wrap">
            <a href="<?= sanitize($back_url ?: 'completed.php') ?>" class="vdd-back">
                <span class="vdd-back-icon"><i class="fa-solid fa-chevron-left"></i></span>
                Back
            </a>

            <div class="vdd-hero">
                <i class="fa-solid fa-circle-check vdd-hero-icon-bg" aria-hidden="true"></i>
                <div class="vdd-hero-left">
                    <div class="vdd-hero-ship"><i class="fa-solid fa-ship"></i></div>
                    <div>
                        <div class="vdd-hero-title-row">
                            <div class="vdd-hero-title"><?= sanitize($survey['vessel_name']) ?></div>
                            <span class="vdd-hero-status"><i class="fa-solid fa-circle-check"></i>&nbsp; Fully Completed</span>
                        </div>
                        <div class="vdd-hero-sub">
                            Client: <b><?= sanitize($survey['company_name']) ?></b>
                            <?php if (!empty($survey['report_number'])): ?>&nbsp;·&nbsp; Report No: <b><?= sanitize($survey['report_number']) ?></b><?php endif; ?>
                            &nbsp;·&nbsp; Uploaded by <b><?= sanitize($survey['uploader_name'] ?: $survey['surveyor_name'] ?: 'Admin') ?></b>
                        </div>
                    </div>
                </div>
            </div>

            <div class="vdd-grid">
                <div>
                    <div class="vdd-card">
                        <div class="vdd-card-head">
                            <div class="vdd-card-head-icon" style="background:#EEF2FF;color:#4F46E5;"><i class="fa-solid fa-timeline"></i></div>
                            <div class="vdd-card-head-title">Timeline</div>
                        </div>
                        <div class="vdd-info-rows">
                            <div class="vdd-info-row">
                                <div class="vdd-info-icon" style="background:#F1F5FF;color:#4F46E5;"><i class="fa-regular fa-calendar-plus"></i></div>
                                <div class="vdd-info-label">Survey Assigned</div>
                                <div><span class="vdd-badge-soft"><?= fmtDateTime($survey['assign_date'] ?? '') ?></span></div>
                            </div>
                            <div class="vdd-info-row alt">
                                <div class="vdd-info-icon" style="background:#FFFBEB;color:#B45309;"><i class="fa-regular fa-calendar-check"></i></div>
                                <div class="vdd-info-label">Survey Completed</div>
                                <div><span class="vdd-badge-soft"><?= fmtDateTime($survey['survey_completed_date'] ?? '') ?></span></div>
                            </div>
                            <div class="vdd-info-row">
                                <div class="vdd-info-icon" style="background:#D1FAE5;color:#047857;"><i class="fa-solid fa-calendar-check"></i></div>
                                <div class="vdd-info-label">Report Completed</div>
                                <div><span class="vdd-badge-soft"><?= fmtDateTime($survey['report_uploaded_date'] ?? '') ?></span></div>
                            </div>
                        </div>
                    </div>

                    <div class="vdd-card">
                        <div class="vdd-card-head">
                            <div class="vdd-card-head-icon" style="background:#F3EEFE;color:#5B2DEE;"><i class="fa-solid fa-file-lines"></i></div>
                            <div class="vdd-card-head-title">Survey Records</div>
                        </div>
                        <div class="vdd-info-rows">
                            <div class="vdd-info-row">
                                <div class="vdd-info-icon" style="background:#F1F5FF;color:#4F46E5;"><i class="fa-solid fa-hashtag"></i></div>
                                <div class="vdd-info-label">Report No</div>
                                <div class="vdd-info-value"><?= !empty($survey['report_number']) ? sanitize($survey['report_number']) : '—' ?></div>
                            </div>
                            <div class="vdd-info-row alt">
                                <div class="vdd-info-icon" style="background:#F3EEFE;color:#5B2DEE;"><i class="fa-solid fa-file-circle-check"></i></div>
                                <div class="vdd-info-label">Survey Type</div>
                                <div><span class="vdd-badge"><span class="dot"></span><?= sanitize(getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? 'N/A')) ?></span></div>
                            </div>
                            <div class="vdd-info-row">
                                <div class="vdd-info-icon" style="background:#ECFEFF;color:#0891B2;"><i class="fa-solid fa-location-dot"></i></div>
                                <div class="vdd-info-label">Port</div>
                                <div class="vdd-info-value"><?= sanitize($survey['port_name']) ?></div>
                            </div>
                            <div class="vdd-info-row alt">
                                <div class="vdd-info-icon" style="background:#FFF7ED;color:#EA580C;"><i class="fa-solid fa-people-group"></i></div>
                                <div class="vdd-info-label">Agent</div>
                                <div class="vdd-info-value"><?= sanitize($survey['agent_name']) ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="vdd-card recovery-block<?= $recovery_edit_open ? ' editing' : '' ?>" id="recoveryBlockDesktop" data-testid="recovery-block-desktop">
                        <div class="vdd-card-head">
                            <div class="vdd-card-head-icon" style="background:#ECFEFF;color:#0891B2;"><i class="fa-solid fa-droplet"></i></div>
                            <div class="vdd-card-head-title">Recovery Summary</div>
                            <?php if ($is_admin): ?>
                            <button type="button" class="recovery-edit-toggle" data-recovery-edit-toggle="recoveryBlockDesktop" title="Edit Recovery" data-testid="recovery-edit-toggle-desktop"><i class="fa-solid fa-pen"></i></button>
                            <?php endif; ?>
                        </div>
                        <?php if ($recovery_message): ?><div class="vdd-alert vdd-alert-success mx-3 mt-3" data-testid="recovery-success-desktop"><?= sanitize($recovery_message) ?></div><?php endif; ?>
                        <?php if ($recovery_error): ?><div class="vdd-alert vdd-alert-danger mx-3 mt-3" data-testid="recovery-error-desktop"><?= sanitize($recovery_error) ?></div><?php endif; ?>

                        <div class="recovery-display">
                        <div class="vdd-recovery-grid">
                            <div class="vdd-recovery-tile">
                                <div class="vdd-recovery-icon" style="background:#F3EEFE;color:#5B2DEE;"><i class="fa-solid fa-droplet"></i></div>
                                <div>
                                    <div class="vdd-recovery-value"><?= $survey['recovery_amount'] !== null ? sanitize(number_format((float)$survey['recovery_amount'], 3)) . ' MT' : '—' ?></div>
                                    <div class="vdd-recovery-label">Total</div>
                                </div>
                            </div>
                            <div class="vdd-recovery-tile">
                                <div class="vdd-recovery-icon" style="background:#EFF6FF;color:#1D4ED8;"><i class="fa-solid fa-droplet"></i></div>
                                <div>
                                    <div class="vdd-recovery-value"><?= $survey['vlsfo_recovery'] !== null ? sanitize(number_format((float)$survey['vlsfo_recovery'], 3)) . ' MT' : '—' ?></div>
                                    <div class="vdd-recovery-label">VLSFO</div>
                                </div>
                            </div>
                            <div class="vdd-recovery-tile">
                                <div class="vdd-recovery-icon" style="background:#D1FAE5;color:#047857;"><i class="fa-solid fa-droplet"></i></div>
                                <div>
                                    <div class="vdd-recovery-value"><?= $survey['lsmgo_recovery'] !== null ? sanitize(number_format((float)$survey['lsmgo_recovery'], 3)) . ' MT' : '—' ?></div>
                                    <div class="vdd-recovery-label">LSMGO</div>
                                </div>
                            </div>
                            <div class="vdd-recovery-tile">
                                <div class="vdd-recovery-icon" style="background:#FFF7ED;color:#EA580C;"><i class="fa-solid fa-droplet"></i></div>
                                <div>
                                    <div class="vdd-recovery-value"><?= $survey['hsfo_recovery'] !== null ? sanitize(number_format((float)$survey['hsfo_recovery'], 3)) . ' MT' : '—' ?></div>
                                    <div class="vdd-recovery-label">HSFO</div>
                                </div>
                            </div>
                        </div>
                        </div>

                        <?php if ($is_admin): ?>
                        <div class="recovery-edit-form">
                            <div class="vdd-card-body">
                                <form method="POST">
                                    <?= csrf_field() ?>
                                    <div class="vdd-field">
                                        <label>Total Recovery (MT)</label>
                                        <input type="number" step="0.001" min="0" name="recovery_amount" value="<?= $survey['recovery_amount'] !== null ? sanitize((string)$survey['recovery_amount']) : '' ?>" data-testid="recovery-field-total-desktop">
                                    </div>
                                    <div class="vdd-field">
                                        <label>VLSFO Recovery (MT)</label>
                                        <input type="number" step="0.001" min="0" name="vlsfo_recovery" value="<?= $survey['vlsfo_recovery'] !== null ? sanitize((string)$survey['vlsfo_recovery']) : '' ?>" data-testid="recovery-field-vlsfo-desktop">
                                    </div>
                                    <div class="vdd-field">
                                        <label>LSMGO Recovery (MT)</label>
                                        <input type="number" step="0.001" min="0" name="lsmgo_recovery" value="<?= $survey['lsmgo_recovery'] !== null ? sanitize((string)$survey['lsmgo_recovery']) : '' ?>" data-testid="recovery-field-lsmgo-desktop">
                                    </div>
                                    <div class="vdd-field">
                                        <label>HSFO Recovery (MT)</label>
                                        <input type="number" step="0.001" min="0" name="hsfo_recovery" value="<?= $survey['hsfo_recovery'] !== null ? sanitize((string)$survey['hsfo_recovery']) : '' ?>" data-testid="recovery-field-hsfo-desktop">
                                    </div>
                                    <div style="display:flex;gap:8px;">
                                        <button type="submit" name="update_recovery" class="vdd-save-btn" style="flex:1;" data-testid="save-recovery-button-desktop">Save Recovery</button>
                                        <button type="button" class="vdd-save-btn" style="flex:1;background:#F1F5F9;color:#334155;" data-recovery-edit-toggle="recoveryBlockDesktop">Cancel</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="vdd-card">
                        <div class="vdd-card-head">
                            <div class="vdd-card-head-icon" style="background:#EEF2FF;color:#4F46E5;"><i class="fa-solid fa-cloud-arrow-down"></i></div>
                            <div class="vdd-card-head-title">Dossier Archive (All Uploaded Files)</div>
                        </div>
                        <div class="vdd-card-body">
                            <?php if (count($all_files) > 0): foreach ($all_files as $file):
                                $vdd_icon = 'fa-file-lines'; $vdd_icon_bg = '#F1F5F9'; $vdd_icon_fg = '#475569';
                                if (str_contains($file['file_type'], 'PDF')) { $vdd_icon = 'fa-file-pdf'; $vdd_icon_bg = '#FEE2E2'; $vdd_icon_fg = '#B91C1C'; }
                                elseif (str_contains($file['file_type'], 'Excel')) { $vdd_icon = 'fa-file-excel'; $vdd_icon_bg = '#D1FAE5'; $vdd_icon_fg = '#047857'; }
                                elseif (str_contains($file['file_type'], 'Word')) { $vdd_icon = 'fa-file-word'; $vdd_icon_bg = '#EEF2FF'; $vdd_icon_fg = '#4F46E5'; }
                            ?>
                            <div class="vdd-file-row">
                                <div style="display:flex;align-items:center;gap:12px;min-width:0;">
                                    <div class="vdd-file-icon" style="background:<?= $vdd_icon_bg ?>;color:<?= $vdd_icon_fg ?>;"><i class="fa-solid <?= $vdd_icon ?>"></i></div>
                                    <div style="min-width:0;">
                                        <div class="vdd-file-name" title="<?= sanitize(preg_replace('/^[0-9]+_/', '', $file['file_name'])) ?>"><?= sanitize(preg_replace('/^[0-9]+_/', '', $file['file_name'])) ?></div>
                                        <div class="vdd-file-meta">
                                            <span class="vdd-badge-soft" style="font-size:10px;padding:2px 8px;"><?= sanitize($file['file_type']) ?></span>
                                            <span style="font-size:11px;color:#94a3b8;"><?= sanitize($file['file_size']) ?></span>
                                        </div>
                                    </div>
                                </div>
                                <a href="download.php?upload_id=<?= (int)$file['id'] ?>" class="vdd-file-dl"><i class="fa-solid fa-download"></i></a>
                            </div>
                            <?php endforeach; else: ?>
                            <div class="vdd-file-empty"><i class="fa-solid fa-folder-open d-block mb-2" style="font-size:22px;opacity:.5;"></i>No files archived for this vessel.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div>

                    <?php if (($_SESSION['role'] ?? '') === 'Admin'): ?>
                    <div class="vdd-card">
                        <div class="vdd-card-body">
                            <a href="invoice_generator.php?id=<?= (int)$survey['id'] ?>" class="vdd-invoice-btn" data-testid="generate-invoice-button-desktop">
                                <i class="fa-solid fa-file-invoice-dollar"></i> Generate Invoice
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if (($_SESSION['role'] ?? '') === 'Surveyor'): ?>
                    <div class="vdd-card">
                        <div class="vdd-card-body">
                            <a href="coming_soon.php?feature=Generate+Expenses" class="vdd-expenses-btn" data-testid="generate-expenses-button-desktop">
                                <i class="fa-solid fa-receipt"></i> Generate Expenses
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div><!-- /.detail-desktop-view -->

    <!-- Add Remarks — shared section, not part of the mobile/desktop toggle
         above, so it always renders once with its own simple responsive CSS. -->
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
// Recovery inline edit toggle (mobile card + desktop card, same button
// pattern) — no page reload needed to switch between display and edit.
(function () {
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-recovery-edit-toggle]');
        if (!btn) return;
        var block = document.getElementById(btn.getAttribute('data-recovery-edit-toggle'));
        if (block) block.classList.toggle('editing');
    });
})();
</script>

<?php
include 'includes/nav.php';
include 'includes/footer.php';
?>
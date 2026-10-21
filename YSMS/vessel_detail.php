<?php
require_once 'config/config.php';
require_once 'includes/notifications.php';
require_once 'includes/agents_mail.php';
require_once 'includes/mailer.php';
require_once 'includes/document_replace_helpers.php';
checkAuth();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$db = getDB();
try { ensureAgentsTable($db); } catch (Throwable $e) {}
$error = '';
$success = '';
$current_user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$is_admin = ($role === 'Admin');
$is_super_admin = ($role === 'Super Admin');
// Super Admin can view every survey like Admin (read-only — no Edit, no Upload,
// no Send-Email-to-Agent), so it must not be scoped down like a Surveyor/Client.
$has_full_access = ($is_admin || $is_super_admin);

// Safety net: ensure live-status columns exist (see database/migration_custom_live_status.sql)
try {
    $cols = $db->query("SHOW COLUMNS FROM surveys LIKE 'custom_live_status'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE surveys ADD COLUMN custom_live_status TEXT NULL DEFAULT NULL");
    }
    $cols = $db->query("SHOW COLUMNS FROM surveys LIKE 'status_updated_by'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE surveys ADD COLUMN status_updated_by INT(11) NULL DEFAULT NULL");
    }
    $cols = $db->query("SHOW COLUMNS FROM surveys LIKE 'status_updated_at'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE surveys ADD COLUMN status_updated_at DATETIME NULL DEFAULT NULL");
    }
} catch (Exception $e) {
    error_log('vessel_detail.php live-status column check: ' . $e->getMessage());
}

// 🌟 సర్వేయర్ సబ్మిట్ చేసినప్పుడు custom_live_status అప్‌డేట్ చేసే పక్కా లాజిక్
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_latest_status'])) {
    $latest_status = trim($_POST['latest_status']);
    
    try {
        $update_status = $db->prepare("
            UPDATE surveys 
            SET custom_live_status = ?, status_updated_by = ?, status_updated_at = NOW() 
            WHERE id = ?
        ");
        if ($update_status->execute([$latest_status, $current_user_id, $id])) {
            $success = "Latest update registered successfully!";
            try {
                // Surveyor live status → notify admins
                $vname = '';
                try {
                    $vs = $db->prepare('SELECT vessel_name, surveyor_id FROM surveys WHERE id = ?');
                    $vs->execute([$id]);
                    $vr = $vs->fetch(PDO::FETCH_ASSOC) ?: [];
                    $vname = $vr['vessel_name'] ?? ('#' . $id);
                } catch (Throwable $x) {}
                $who = $_SESSION['full_name'] ?? 'Surveyor';
                if (($_SESSION['role'] ?? '') === 'Surveyor') {
                    notifyAllAdmins($db, 'Live status updated',
                        $who . ' updated ' . $vname . ': ' . $latest_status,
                        'status', 'vessel_detail.php?id=' . (int)$id, (int)$current_user_id);
                } else {
                    // Admin updated status → notify assigned surveyor
                    $sid = (int)($vr['surveyor_id'] ?? 0);
                    if ($sid > 0) {
                        createNotification($db, $sid, 'Live status updated by admin',
                            $who . ' set status on ' . $vname . ': ' . $latest_status,
                            'status', 'vessel_detail.php?id=' . (int)$id, (int)$current_user_id);
                    }
                }
            } catch (Throwable $ne) { error_log('status notif: '.$ne->getMessage()); }
        } else {
            $error = "Failed to update status.";
        }
    } catch (Exception $e) {
        $error = "Database Error: " . $e->getMessage();
    }
}

// వెసెల్ పూర్తి వివరాలు లోడ్ చేయడం (LEFT JOIN తో మోడిఫైయర్ పేరు తెచ్చుకోవడం)
$stmt = $db->prepare("
    SELECT s.*, c.company_name, u.full_name as surveyor_name, p.port_name, t.type_name,
           uu.full_name as modifier_name
    FROM surveys s 
    JOIN clients c ON s.client_id = c.id 
    JOIN users u ON s.surveyor_id = u.id
    JOIN ports p ON s.port_id = p.id
    JOIN survey_types t ON s.survey_type_id = t.id
    LEFT JOIN users uu ON s.status_updated_by = uu.id
    WHERE s.id = ?
");
$stmt->execute([$id]);
$survey = $stmt->fetch();

if (!$survey) { die("Survey details missing."); }

// Submit popup (Pending Vessel): asks the Survey Completed date, and the
// recovery figures when no Calculation Sheet is uploaded.
$vdRecoveryNA = isRecoveryNotApplicable($db, (string)($survey['survey_type_ids'] ?? ''), (string)($survey['type_name'] ?? ''));
$vdHasExcel = false;
try {
    $xs = $db->prepare("SELECT 1 FROM uploads WHERE survey_id = ? AND file_type = 'Formal Report Excel' LIMIT 1");
    $xs->execute([$survey['id']]);
    $vdHasExcel = (bool)$xs->fetchColumn();
} catch (Throwable $e) { error_log('vessel_detail excel check: ' . $e->getMessage()); }

// 🔒 Authorization check (IDOR fix): a non-admin (Surveyor) may only view
// surveys assigned to their own account. Previously checkAuth() only verified
// that *someone* was logged in, so any authenticated user could view any
// other surveyor's survey — including client and financial details — just by
// changing the ?id= in the URL. Admins are unrestricted, matching existing
// admin permissions elsewhere in this file.
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
        /* .blue-action-btn is display:flex/width:100% by default (mobile-first),
           so on desktop it hugs the left edge of this centered card instead of
           centering itself — force it to center within this card specifically. */
        .no-access-card .blue-action-btn { margin-left: auto; margin-right: auto; }
    </style>
    <div class="scroll-content">
        <?php $page_title = 'Access Denied'; $back_url = 'index.php'; $page_testid = 'no-access'; include 'includes/top_app_bar.php'; ?>
        <main class="no-access-wrap" data-testid="no-access-page">
            <section class="no-access-card">
                <div class="no-access-icon"><i class="fa-solid fa-lock"></i></div>
                <h2 class="fw-bold text-dark" style="font-size:22px;" data-testid="no-access-heading">Access Denied</h2>
                <p class="text-muted mb-4" style="font-size:13px;" data-testid="no-access-message">
                    You don't have permission to view this survey. It isn't assigned to your account.
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
$is_client_viewer = ($role === 'Client');

// డేట్ వాలిడేషన్ చెక్
$display_date = '--/--/----';
if (!empty($survey['assign_date']) && $survey['assign_date'] != '0000-00-00 00:00:00' && $survey['assign_date'] != '0000-00-00') {
    $display_date = date('d M Y', strtotime($survey['assign_date']));
}

// 🌟 Shared appointment details (assign-vessel data) for WhatsApp + Email
$combined_survey_type = getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? 'N/A');
$combined_surveyor_names = getCombinedSurveyorNames($db, $survey['id'] ?? 0, $survey['surveyor_name'] ?? 'N/A');
$client_name = $survey['company_name'] ?? 'N/A';

$appointment_lines = [
    "Vessel Name: " . ($survey['vessel_name'] ?? 'N/A'),
    "Report No: " . (!empty($survey['report_number']) ? $survey['report_number'] : 'N/A'),
    "Client: " . $client_name,
    "Survey Type: " . $combined_survey_type,
    "Assigned Date: " . $display_date,
    "Agent: " . ($survey['agent_name'] ?? 'N/A'),
    "Surveyor: " . $combined_surveyor_names,
    "Port: " . ($survey['port_name'] ?? 'N/A'),
    "Status: " . ($survey['status'] ?? 'N/A'),
];
if (!empty($survey['custom_live_status'])) {
    $appointment_lines[] = "Latest Update: " . $survey['custom_live_status'];
}
if (!empty($survey['remarks'])) {
    $appointment_lines[] = "Admin Remarks: " . $survey['remarks'];
}
$appointment_block = implode("\n", $appointment_lines);

// WhatsApp — heading: New Survey Appointment
$whatsapp_text = "*New Survey Appointment*\n\n" . $appointment_block;
$whatsapp_number = preg_replace('/\D+/', '', WHATSAPP_NUMBER);
$whatsapp_share_url = $whatsapp_number !== ''
    ? 'https://wa.me/' . $whatsapp_number . '?text=' . rawurlencode($whatsapp_text)
    : 'https://api.whatsapp.com/send?text=' . rawurlencode($whatsapp_text);

// Email — always opens Gmail's own compose window (mail.google.com), not
// mailto:, since mailto: opens whatever the OS/browser has set as its
// default mail handler (Outlook, in practice, for this deployment) rather
// than Gmail specifically. "To" is left out of the URL so it stays blank
// for manual entry.
$mail_subject = "New Survey Appointment - " . ($survey['vessel_name'] ?? 'Vessel') . " (" . $client_name . ")";
$mail_body = "Dear Sir,\n\n"
    . "Good day to you.\n\n"
    . "Please find the below appointment from client (" . $client_name . ").\n\n"
    . $appointment_block . "\n\n"
    . "Thank you.\n"
    . "YMR Marine Solutions";
$mail_share_url = 'https://mail.google.com/mail/?view=cm&fs=1&su=' . rawurlencode($mail_subject) . '&body=' . rawurlencode($mail_body);

// Mobile: open the Gmail app itself (not a browser tab) so the user lands in
// an already-signed-in app instead of being asked to log in again, with To/Cc
// still editable by hand inside the app. iOS uses Gmail's own "co" (compose)
// URL scheme. Android Gmail has no such custom scheme — it only registers
// itself as a handler for the standard mailto: intent — so we route a
// mailto: intent and force it to Gmail specifically via package=, falling
// back to the web compose URL only if Gmail isn't installed.
$mail_ios_url = 'googlegmail://co?subject=' . rawurlencode($mail_subject) . '&body=' . rawurlencode($mail_body);
$mail_android_url = 'intent://send?subject=' . rawurlencode($mail_subject) . '&body=' . rawurlencode($mail_body) . '#Intent;scheme=mailto;package=com.google.android.gm;S.browser_fallback_url=' . rawurlencode($mail_share_url) . ';end';

// Pre-fill the "from account" prompt with the logged-in user's own email
// (same column/safety-net pattern as profile.php), so it defaults to
// whoever is actually clicking the button instead of starting blank.
$has_email_col = $db->query("SHOW COLUMNS FROM users LIKE 'email'")->fetch();
$my_email = '';
if ($has_email_col) {
    $myEmailStmt = $db->prepare("SELECT email FROM users WHERE id = ?");
    $myEmailStmt->execute([$current_user_id]);
    $my_email = (string)($myEmailStmt->fetchColumn() ?: '');
}

require_once 'includes/admin_inline_edit.php';
aieInit($db, $survey);

include 'includes/header.php';
?>

<style>
    .status-update-card { background: #ffffff; margin: 15px 20px; border-radius: 16px; padding: 20px; border: 1px solid var(--border-color); }
    .small-status-btn { background: #3b32b3; color: white; border: none; padding: 8px 16px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; }
    .status-input { width: 100%; border: 1px solid var(--border-color); border-radius: 8px; padding: 10px 12px; font-size: 13px; background: #f8fafc; outline: none; resize: none; }
    .vessel-edit-btn { display: inline-flex; align-items: center; gap: 6px; background: #fff; color: #00a3df; border: 1px solid #00a3df; border-radius: 8px; padding: 5px 12px; font-size: 11px; font-weight: 700; text-decoration: none; }
    .vessel-edit-btn:hover { background: #00a3df; color: #fff; }
    /* WhatsApp brand green */
    .btn-whatsapp {
        display: inline-flex; align-items: center; gap: 6px;
        background: #25D366; color: #fff !important; border: 1px solid #128C7E;
        border-radius: 8px; padding: 6px 12px; font-size: 11px; font-weight: 700;
        text-decoration: none !important; box-shadow: 0 1px 3px rgba(18,140,126,.25);
    }
    .btn-whatsapp:hover { background: #128C7E; color: #fff !important; }
    /* Gmail red */
    .btn-email {
        display: inline-flex; align-items: center; gap: 6px;
        background: #EA4335; color: #fff !important; border: 1px solid #D93025;
        border-radius: 8px; padding: 6px 12px; font-size: 11px; font-weight: 700;
        text-decoration: none !important; box-shadow: 0 1px 3px rgba(234,67,53,.25);
    }
    .btn-email:hover { background: #D93025; color: #fff !important; }

    /* 🌟 Document Replace — View/Download/Replace row actions (unconditional:
       View/Download render for every role that can see a document list,
       only the Replace button itself is PHP-gated to Admin/assigned-Surveyor). */
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

    /* 🖥️ Desktop: main details | upload side-by-side; live status content-height only */
    @media (min-width: 992px) {
        .detail-main-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            width: 100%;
            max-width: none;
            margin: 10px 16px 12px;
            align-items: stretch;
            box-sizing: border-box;
            padding-right: 16px;
        }
        .detail-main-row > .info-table-list,
        .detail-main-row > .form-box {
            margin: 0 !important;
            max-width: none !important;
            height: 100%;
        }
        .detail-main-row > .form-box {
            background: #fff;
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 16px 18px;
            box-shadow: 0 2px 8px rgba(15,23,42,.04);
        }
        .detail-main-row > .status-update-card {
            margin: 0 !important;
            max-width: none !important;
            padding: 14px 18px !important;
            height: auto !important;
            align-self: start;
        }
        .info-row { padding: 10px 16px; }
        .blue-action-btn { max-width: none; width: 100%; }
    }
</style>

<div class="scroll-content">
    <?php $page_title = 'Vessel Survey Details'; $back_url = 'vessels.php'; $page_testid = 'vessel-detail'; include 'includes/top_app_bar.php'; ?>

    <?php if($success): ?><div class="alert alert-success mx-3 mt-3 py-2" style="font-size:12px;"><?= $success ?></div><?php endif; ?>
    <?php if($error): ?><div class="alert alert-danger mx-3 mt-3 py-2" style="font-size:12px;"><?= $error ?></div><?php endif; ?>

        <div class="detail-main-row">
        <div class="info-table-list shadow-sm">
            <div class="text-end p-2 d-flex justify-content-end gap-2 flex-wrap">
                <a href="<?= sanitize($whatsapp_share_url) ?>" target="_blank" rel="noopener" class="btn-whatsapp" data-testid="vessel-detail-whatsapp-link" title="Send via WhatsApp"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
                <a href="<?= sanitize($mail_share_url) ?>" target="_blank" rel="noopener" class="btn-email" data-mail-from-picker data-base-url="<?= sanitize($mail_share_url) ?>" data-ios-url="<?= sanitize($mail_ios_url) ?>" data-android-url="<?= sanitize($mail_android_url) ?>" data-my-email="<?= sanitize($my_email) ?>" data-testid="vessel-detail-mail-link" title="Send via Email (Gmail)"><i class="fa-solid fa-envelope"></i> Email</a>
                <?php if ($is_admin): ?>
                    <a href="assign_vessel.php?edit_id=<?= (int)$survey['id'] ?>" class="vessel-edit-btn" data-testid="vessel-detail-edit-link"><i class="fa-solid fa-pen"></i> Edit</a>
                <?php endif; ?>
            </div>
            <div class="info-row"><span class="info-label">Vessel Name<?= aie('vessel_name') ?></span><span class="info-value"><?= sanitize($survey['vessel_name']) ?></span></div>
            <div class="info-row"><span class="info-label">Report No<?= aie('report_number') ?></span><span class="info-value fw-bold text-dark"><?= !empty($survey['report_number']) ? sanitize($survey['report_number']) : '—' ?></span></div>
            <div class="info-row"><span class="info-label">Client Name<?= aie('client_id') ?></span><span class="info-value"><?= sanitize($survey['company_name']) ?></span></div>
            <div class="info-row"><span class="info-label">Survey Type<?= aie('survey_type_ids') ?></span><span class="info-value text-primary"><?= sanitize($combined_survey_type) ?></span></div>
            <div class="info-row"><span class="info-label">Assigned Date<?= aie('assign_date') ?></span><span class="info-value fw-bold text-dark"><?= $display_date ?></span></div>
            <div class="info-row" style="align-items:flex-start;">
                <span class="info-label">Agent Name<?= aie('agent_name') ?></span>
                <span class="info-value">
                    <?= sanitize($survey['agent_name']) ?>
                    <?php if ($is_admin && !empty($survey['agent_name'])): ?>
                        <?php
                            $agent_sent_at = $survey['agent_email_sent_at'] ?? null;
                            $agent_sent_label = $agent_sent_at ? date('d M Y, h:i A', strtotime($agent_sent_at)) : '';
                        ?>
                        <div id="agentEmailActions" style="margin-top:6px;font-size:11.5px;line-height:1.45;" data-survey-id="<?= (int)$survey['id'] ?>">
                            <span id="agentEmailStatusWrap" style="<?= $agent_sent_at ? '' : 'display:none;' ?>">
                                <span class="text-success" id="agentEmailSentLabel"><i class="fa-solid fa-circle-check"></i> Email sent to agent on <span id="agentEmailSentAt"><?= sanitize($agent_sent_label) ?></span></span>
                                <button type="button" id="btnAgentEmailAgain" class="btn btn-link p-0 ms-1" style="font-size:11.5px;vertical-align:baseline;">Send email again</button>
                            </span>
                            <button type="button" id="btnAgentEmailSend" class="btn btn-link p-0" style="font-size:11.5px;<?= $agent_sent_at ? 'display:none;' : '' ?>">Send email to agent for latest update</button>
                        </div>
                    <?php endif; ?>
                </span>
            </div>
            <div class="info-row"><span class="info-label">Surveyor Name<?= aie('surveyors') ?></span><span class="info-value text-primary"><?= sanitize($combined_surveyor_names) ?></span></div>
            <div class="info-row"><span class="info-label">Port Name<?= aie('port_id') ?></span><span class="info-value"><?= sanitize($survey['port_name']) ?></span></div>

            <div class="info-row"><span class="info-label" style="color: #ea580c;">Admin Remarks<?= aie('remarks') ?></span><span class="info-value text-dark fw-semibold">
                <?php
                    $remarks_full = !empty($survey['remarks']) ? $survey['remarks'] : '';
                    if ($remarks_full === '') {
                        echo 'N/A';
                    } elseif (strlen($remarks_full) > 150) {
                        echo sanitize(substr($remarks_full, 0, 150)) . '... <a href="#" class="text-primary fw-bold" data-bs-toggle="modal" data-bs-target="#remarksModal" onclick="event.stopPropagation();" data-testid="vessel-detail-read-more-remarks">Read More</a>';
                    } else {
                        echo sanitize($remarks_full);
                    }
                ?>
            </span></div>
        </div>
    <?php if (!$is_client_viewer && !$is_super_admin): ?>
    <div class="form-box mx-3 p-3 bg-white rounded-3 border shadow-sm mt-2">
        <div class="fw-bold text-dark mb-3" style="font-size: 14px;"><i class="fa-solid fa-cloud-arrow-up text-primary"></i> Upload Required Reports</div>
        
    <?php
    // 🌟 One or more files uploaded at assignment time (Assign Vessel form
    // now allows selecting multiple at once). getSurveyAttachments() reads
    // the new survey_attachments table, falling back to the legacy single
    // surveys.attachment_path column for vessels assigned before multi-file
    // upload existed, so older assignments still show their one attachment.
    $assign_attachments = getSurveyAttachments($db, $survey['id'], $survey['attachment_path'] ?? '');

    // 🌟 Documents the Surveyor has uploaded via the "Upload Required
    // Reports" form below (ajax/upload_handler.php writes these into
    // `uploads`) — this page never listed them before (only
    // report_detail.php/completed_detail.php did), even though a Pending
    // Vessel can already hold some (e.g. one of the two required files, or
    // an extra Field Report, before the required pair completes and moves
    // it to Pending Report).
    $surveyor_uploaded_docs = [];
    try {
        $udStmt = $db->prepare("SELECT * FROM uploads WHERE survey_id = ? ORDER BY id ASC");
        $udStmt->execute([$survey['id']]);
        $surveyor_uploaded_docs = $udStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('vessel_detail.php uploads fetch: ' . $e->getMessage()); }

    // 🌟 Single source of truth for the Replace button — Admin always,
    // Surveyor only if actually assigned to this survey (page access above
    // already guarantees that for any Surveyor who got this far, but this
    // is re-derived independently rather than assumed).
    $can_replace_docs = canReplaceDocument($db, $role, (int)$current_user_id, (int)$survey['id']);

    foreach ($assign_attachments as $assign_att_row):
        $assign_att = $assign_att_row['file_path'];
        $att_fs = (strpos($assign_att, '/') === 0 || preg_match('#^[A-Za-z]:#', $assign_att)) ? $assign_att : (__DIR__ . '/' . ltrim($assign_att, '/'));
        $att_url = $assign_att;
        $att_label = $assign_att_row['file_name'] ?: basename($assign_att);
        // Strip numeric time (+ index) prefix for display if present
        $att_label_clean = preg_replace('/^[0-9]+(_[0-9]+)?_/', '', $att_label);
        if (is_file($att_fs)):
?>
    <div class="px-3 mb-3">
        <div class="bg-white p-3 rounded-3 border shadow-sm d-flex align-items-center justify-content-between">
            <div>
                <div class="fw-bold text-dark" style="font-size:13px;"><i class="fa-solid fa-paperclip text-primary me-1"></i> Assignment Attachment</div>
                <div class="text-muted" style="font-size:11px;"><?= sanitize($att_label_clean) ?></div>
            </div>
            <a href="<?= sanitize($att_url) ?>" download="<?= sanitize($att_label_clean) ?>" class="btn btn-sm btn-light border text-primary"><i class="fa-solid fa-download"></i></a>
        </div>
    </div>
    <?php endif; endforeach; ?>

    <?php if (!empty($surveyor_uploaded_docs)): ?>
    <div class="px-3 mb-3">
        <div class="fw-bold text-dark mb-2" style="font-size: 12.5px;"><i class="fa-solid fa-folder-open text-warning me-1"></i> Surveyor Uploaded Documents</div>
        <?php foreach ($surveyor_uploaded_docs as $doc):
            $doc_clean = preg_replace('/^[0-9]+_/', '', $doc['file_name'] ?? '');
            if ($doc_clean === '' || $doc_clean === null) $doc_clean = $doc['file_name'];
            $doc_icon = 'fa-file-lines text-secondary';
            if (str_contains((string)$doc['file_type'], 'PDF')) $doc_icon = 'fa-file-pdf text-danger';
            elseif (str_contains((string)$doc['file_type'], 'Excel')) $doc_icon = 'fa-file-excel text-success';
            elseif (str_contains((string)$doc['file_type'], 'Word')) $doc_icon = 'fa-file-word text-primary';
            $doc_uploader = $doc['uploaded_by'] ? getUserFullNameById($db, (int)$doc['uploaded_by']) : '—';
        ?>
        <div class="bg-white p-2 rounded-3 border d-flex align-items-center justify-content-between mb-2 shadow-sm" style="font-size:12px;" data-doc-row data-doc-id="<?= (int)$doc['id'] ?>">
            <div class="d-flex align-items-center gap-2" style="max-width:62%;">
                <i class="fa-regular <?= $doc_icon ?>"></i>
                <div style="overflow:hidden;">
                    <div class="text-dark fw-semibold text-truncate" data-doc-name-display title="<?= sanitize($doc_clean) ?>"><?= sanitize($doc_clean) ?></div>
                    <div class="text-muted" style="font-size:10.5px;"><?= sanitize($doc['file_type']) ?> · <span data-doc-size-display><?= sanitize($doc['file_size']) ?></span></div>
                </div>
            </div>
            <div class="doc-actions-row">
                <a href="download.php?upload_id=<?= (int)$doc['id'] ?>&inline=1" target="_blank" rel="noopener" class="doc-view-btn" title="View"><i class="fa-solid fa-eye"></i></a>
                <a href="download.php?upload_id=<?= (int)$doc['id'] ?>" data-doc-download-link class="doc-view-btn" title="Download"><i class="fa-solid fa-download"></i></a>
                <?php if ($can_replace_docs): ?>
                <button type="button" class="doc-replace-btn" title="Replace / Re-upload"
                        data-doc-id="<?= (int)$doc['id'] ?>"
                        data-doc-name="<?= sanitize($doc_clean) ?>"
                        data-doc-type="<?= sanitize($doc['file_type']) ?>"
                        data-doc-size="<?= sanitize($doc['file_size']) ?>"
                        data-doc-uploaded-at="<?= sanitize(fmtDocumentDateTime($doc['uploaded_at'] ?? '')) ?>"
                        data-doc-uploaded-by="<?= sanitize($doc_uploader) ?>">
                    <i class="fa-solid fa-rotate"></i> Replace
                </button>
                <?php endif; ?><?= aieFileButtons($doc) ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

<form action="ajax/upload_handler.php" method="POST" enctype="multipart/form-data" data-report-upload-form>
                    <?= csrf_field() ?>
            <input type="hidden" name="survey_id" value="<?= $survey['id'] ?>">
            <input type="hidden" name="current_status" value="<?= $survey['status'] ?>">
            <div class="mb-2">
                <label class="form-label fw-bold text-secondary" style="font-size:11px;">1. Formal Report (PDF) *</label>
                <input type="file" name="pdf_report" class="form-control form-control-sm" accept=".pdf" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold text-secondary" style="font-size:11px;">2. Calculation Sheet (Excel) <span class="text-muted fw-normal">(optional)</span></label>
                <input type="file" name="excel_report" class="form-control form-control-sm" accept=".xlsx, .xls">
            </div>
            <div class="mt-3 mb-2">
                <label class="form-label small fw-semibold text-muted">Additional Files (optional)</label>
                <input type="file" name="additional_files[]" class="form-control form-control-sm" accept=".xlsx,.xls,.docx,.doc,.pdf" multiple>
                <div class="text-muted" style="font-size:11px;">Excel, Word or PDF. You can select multiple files.</div>
            </div>
            <button type="submit" class="blue-action-btn w-100" style="background: #1e3a8a;" data-report-upload-submit>Submit & Send</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="status-update-card shadow-sm">
        <form action="vessel_detail.php?id=<?= $survey['id'] ?>" method="POST">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="text-dark fw-bold mb-2" style="font-size:13px;">
                    <i class="fa-solid fa-pen-fancy text-primary me-1"></i> Latest Update / Live Status
                </label>
                <textarea name="latest_status" class="status-input" rows="2" placeholder="Update the current operational event..." required><?= !empty($survey['custom_live_status']) ? sanitize($survey['custom_live_status']) : '' ?></textarea>
            </div>

            <div class="d-flex justify-content-between align-items-center">
                <div style="font-size: 11px; color: var(--text-muted);">
                    <?php if (!empty($survey['status_updated_at'])): ?>
                        <span class="text-dark fw-bold"><?= formatLatestUpdateWhen($survey['status_updated_at']) ?></span> By <span class="text-primary fw-bold"><?= sanitize($survey['modifier_name'] ?? 'User') ?></span>
                    <?php endif; ?>
                </div>
                <button type="submit" name="update_latest_status" class="small-status-btn">Update</button>
            </div>
        </form>
    </div>
        </div><!-- /.detail-main-row -->

        <?php
        // Same vessel surveyed before (any client) — for the surveyor's reference. Admin / Super Admin / Surveyor only.
        if (in_array($role, ['Admin', 'Super Admin', 'Surveyor'], true)) {
            require_once 'includes/vessel_history.php';
            try { $vesselHistoryRows = vesselHistory($db, $survey); } catch (Throwable $e) { error_log('vessel history: ' . $e->getMessage()); $vesselHistoryRows = []; }
            include 'includes/vessel_history_card.php';
        }
        ?>
        <?php if (!empty($remarks_full) && strlen($remarks_full) > 150): ?>
        <div class="modal fade" id="remarksModal" tabindex="-1" aria-hidden="true" data-testid="vessel-detail-remarks-modal">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title fw-bold">Admin Remarks</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" style="font-size: 13.5px; white-space: pre-wrap;"><?= sanitize($remarks_full) ?></div>
                </div>
            </div>
        </div>
        <?php endif; ?>



</div>

<?php if ($is_admin): ?>
<style>
    .agent-email-modal-backdrop {
        display: none; position: fixed; inset: 0; background: rgba(15,23,42,.45);
        z-index: 2000; align-items: center; justify-content: center; padding: 16px;
    }
    .agent-email-modal-backdrop.show { display: flex; }
    .agent-email-modal {
        background: #fff; border-radius: 16px; width: min(420px, 100%);
        box-shadow: 0 20px 40px rgba(15,23,42,.2); overflow: hidden;
    }
    .agent-email-modal-head {
        padding: 14px 16px; border-bottom: 1px solid #e2e8f0;
        font-weight: 700; font-size: 15px; color: #0f172a;
        display: flex; justify-content: space-between; align-items: center;
    }
    .agent-email-modal-body { padding: 16px; }
    .agent-email-modal-body label { font-size: 12px; font-weight: 600; color: #475569; display: block; margin-bottom: 4px; }
    .agent-email-modal-body input {
        width: 100%; border: 1px solid #cbd5e1; border-radius: 10px;
        padding: 10px 12px; font-size: 14px; margin-bottom: 12px;
    }
    .agent-email-modal-foot {
        padding: 12px 16px; border-top: 1px solid #e2e8f0;
        display: flex; gap: 8px; justify-content: flex-end;
    }
    .agent-email-modal-foot .btn-cancel {
        border: 1px solid #e2e8f0; background: #fff; border-radius: 10px; padding: 8px 14px; font-weight: 600;
    }
    .agent-email-modal-foot .btn-send {
        border: 0; background: #3b32b3; color: #fff; border-radius: 10px; padding: 8px 14px; font-weight: 600;
    }
    #agentEmailActions .btn-link { color: #3b32b3; text-decoration: none; font-weight: 600; }
    #agentEmailActions .btn-link:hover { text-decoration: underline; }
</style>
<div class="agent-email-modal-backdrop" id="agentEmailModal" role="dialog" aria-modal="true">
    <div class="agent-email-modal">
        <div class="agent-email-modal-head">
            <span><i class="fa-solid fa-envelope me-1 text-primary"></i> Agent email</span>
            <button type="button" id="agentEmailModalClose" style="border:0;background:transparent;font-size:18px;line-height:1;">&times;</button>
        </div>
        <div class="agent-email-modal-body">
            <p class="text-muted mb-2" style="font-size:12px;">No email on file for this agent. Enter email(s) to send the latest update. They will also be saved under Admin Controls → Agents.</p>
            <label>Agent email *</label>
            <input type="email" id="agentEmailPrimary" placeholder="agent@example.com" required>
            <label>Additional emails <span class="text-muted fw-normal">(optional, comma-separated)</span></label>
            <input type="text" id="agentEmailOptional" placeholder="cc1@example.com, cc2@example.com">
            <div id="agentEmailModalError" class="text-danger" style="font-size:12px;display:none;"></div>
        </div>
        <div class="agent-email-modal-foot">
            <button type="button" class="btn-cancel" id="agentEmailModalCancel">Cancel</button>
            <button type="button" class="btn-send" id="agentEmailModalSend">Save &amp; Send</button>
        </div>
    </div>
</div>
<script>
(function() {
    var surveyId = <?= (int)$survey['id'] ?>;
    var $modal = document.getElementById('agentEmailModal');
    if (!$modal) return;

    function showSent(label) {
        var wrap = document.getElementById('agentEmailStatusWrap');
        var sendBtn = document.getElementById('btnAgentEmailSend');
        var at = document.getElementById('agentEmailSentAt');
        if (at) at.textContent = label || '';
        if (wrap) wrap.style.display = '';
        if (sendBtn) sendBtn.style.display = 'none';
    }

    function openModal() {
        document.getElementById('agentEmailModalError').style.display = 'none';
        document.getElementById('agentEmailPrimary').value = '';
        document.getElementById('agentEmailOptional').value = '';
        $modal.classList.add('show');
    }
    function closeModal() { $modal.classList.remove('show'); }

    function doSend(extraData) {
        var body = new URLSearchParams();
        body.set('action', 'send');
        body.set('survey_id', String(surveyId));
        if (extraData) {
            Object.keys(extraData).forEach(function(k) { body.set(k, extraData[k]); });
        }
        return fetch('ajax/send_agent_update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function(r) { return r.json(); });
    }

    function startSendFlow() {
        var sendBtn = document.getElementById('btnAgentEmailSend');
        var againBtn = document.getElementById('btnAgentEmailAgain');
        [sendBtn, againBtn].forEach(function(b) { if (b) b.disabled = true; });

        fetch('ajax/send_agent_update.php?action=lookup&survey_id=' + surveyId, { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d || !d.success) {
                    alert((d && d.message) ? d.message : 'Could not load agent emails');
                    return;
                }
                if (d.has_emails) {
                    if (!confirm('Send latest update email to:\n' + (d.emails || []).join(', ') + ' ?')) return;
                    return doSend({}).then(function(res) {
                        if (res && res.success) {
                            showSent(res.sent_label);
                            alert(res.message || 'Email sent.');
                        } else if (res && res.need_email) {
                            openModal();
                        } else {
                            alert((res && res.message) ? res.message : 'Send failed');
                        }
                    });
                } else {
                    openModal();
                }
            })
            .catch(function() { alert('Network error'); })
            .finally(function() {
                [sendBtn, againBtn].forEach(function(b) { if (b) b.disabled = false; });
            });
    }

    var btn = document.getElementById('btnAgentEmailSend');
    var again = document.getElementById('btnAgentEmailAgain');
    if (btn) btn.addEventListener('click', function(e) { e.preventDefault(); startSendFlow(); });
    if (again) again.addEventListener('click', function(e) { e.preventDefault(); startSendFlow(); });

    document.getElementById('agentEmailModalClose').addEventListener('click', closeModal);
    document.getElementById('agentEmailModalCancel').addEventListener('click', closeModal);
    $modal.addEventListener('click', function(e) { if (e.target === $modal) closeModal(); });

    document.getElementById('agentEmailModalSend').addEventListener('click', function() {
        var primary = (document.getElementById('agentEmailPrimary').value || '').trim();
        var optional = (document.getElementById('agentEmailOptional').value || '').trim();
        var err = document.getElementById('agentEmailModalError');
        if (!primary) {
            err.textContent = 'Agent email is required.';
            err.style.display = 'block';
            return;
        }
        var btnSend = this;
        btnSend.disabled = true;
        doSend({ email_primary: primary, email_optional: optional })
            .then(function(res) {
                if (res && res.success) {
                    closeModal();
                    showSent(res.sent_label);
                    alert(res.message || 'Email sent.');
                } else {
                    err.textContent = (res && res.message) ? res.message : 'Send failed';
                    err.style.display = 'block';
                }
            })
            .catch(function() {
                err.textContent = 'Network error';
                err.style.display = 'block';
            })
            .finally(function() { btnSend.disabled = false; });
    });
})();
</script>
<?php endif; ?>

<script>
// Email button: an optional prompt for "Your Gmail address" picks which
// signed-in Google account the compose window opens under (Gmail's own
// &authuser= param). Cancel/leave it blank and the link opens exactly as
// before — this is purely what the user types, nothing automatic.
// On phones this opens the Gmail app directly (already signed in) instead
// of a browser tab that would ask the user to log in again; desktop keeps
// opening the Gmail web compose tab as before.
document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-mail-from-picker]');
    if (!btn) return;
    ev.preventDefault();
    var email = (window.prompt('Your Gmail address (optional — picks which account composes; leave blank to use the default account):', btn.getAttribute('data-my-email') || '') || '').trim();
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

<div class="vdc-modal" id="vdCompleteModal" hidden data-recovery-na="<?= $vdRecoveryNA ? '1' : '0' ?>" data-has-excel="<?= $vdHasExcel ? '1' : '0' ?>" data-testid="complete-modal" role="dialog" aria-modal="true" aria-labelledby="vdCompleteTitle">
    <div class="vdc-box">
        <div class="vdc-head">
            <div class="vdc-ico"><i class="fa-solid fa-flag-checkered"></i></div>
            <div>
                <div class="vdc-title" id="vdCompleteTitle">Survey details</div>
                <div class="vdc-sub"><?= sanitize($survey['vessel_name']) ?></div>
            </div>
        </div>
        <label class="vdc-label" for="vdcDate">Survey Completed Date <span class="req">*</span></label>
        <input type="date" id="vdcDate" class="vdc-input" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" data-testid="complete-date">
        <div class="vdc-note" data-vdc-excel-note hidden><i class="fa-solid fa-file-excel"></i> Recovery will be read from the Calculation Sheet.</div>
        <div data-vdc-recovery hidden>
            <div class="vdc-label">Recovery (MT) <span class="req">*</span></div>
            <div class="vdc-grid">
                <div><span>VLSFO</span><input type="number" step="0.001" inputmode="decimal" class="vdc-input" data-vdc-part name="vdc_vlsfo" data-testid="complete-vlsfo"></div>
                <div><span>HSFO</span><input type="number" step="0.001" inputmode="decimal" class="vdc-input" data-vdc-part name="vdc_hsfo" data-testid="complete-hsfo"></div>
                <div><span>LSMGO</span><input type="number" step="0.001" inputmode="decimal" class="vdc-input" data-vdc-part name="vdc_lsmgo" data-testid="complete-lsmgo"></div>
                <div><span>Total</span><input type="number" step="0.001" inputmode="decimal" class="vdc-input vdc-total" id="vdcTotal" data-testid="complete-total"></div>
            </div>
            <div class="vdc-hint">Total fills in from VLSFO + HSFO + LSMGO; enter 0 if there was no recovery.</div>
        </div>
        <div class="vdc-error" data-vdc-error hidden></div>
        <div class="vdc-actions">
            <button type="button" class="vdc-btn ghost" data-vdc-cancel>Cancel</button>
            <button type="button" class="vdc-btn primary" data-vdc-ok data-testid="complete-submit"><i class="fa-solid fa-paper-plane"></i> Submit &amp; Send</button>
        </div>
    </div>
</div>
<style>
.vdc-modal { position: fixed; inset: 0; z-index: 9998; background: rgba(15,23,42,.55); display: flex; align-items: center; justify-content: center; padding: 16px; }
.vdc-modal[hidden] { display: none; }
.vdc-box { background: #fff; border-radius: 18px; width: 100%; max-width: 420px; padding: 20px; box-shadow: 0 24px 60px rgba(0,0,0,.25); max-height: calc(100vh - 32px); overflow: auto; }
.vdc-head { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
.vdc-ico { width: 42px; height: 42px; border-radius: 12px; background: linear-gradient(135deg,#1E1B6B,#4B2FD6); color: #fff; display: flex; align-items: center; justify-content: center; flex: none; }
.vdc-title { font-size: 16px; font-weight: 800; color: #0f172a; }
.vdc-sub { font-size: 12px; color: #64748b; }
.vdc-label { display: block; font-size: 12px; font-weight: 700; color: #334155; margin: 10px 0 6px; }
.vdc-label .req { color: #DC2626; }
.vdc-input { width: 100%; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 9px 11px; font-size: 14px; background: #F8FAFC; min-width: 0; }
.vdc-input:focus { outline: none; border-color: #A78BFA; background: #fff; }
.vdc-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.vdc-grid span { display: block; font-size: 11px; font-weight: 700; color: #64748b; margin-bottom: 4px; }
.vdc-total { font-weight: 800; color: #1E1B6B; }
.vdc-hint { font-size: 11px; color: #94a3b8; margin-top: 6px; }
.vdc-note { margin-top: 12px; font-size: 12px; color: #047857; background: #ECFDF5; border-radius: 10px; padding: 8px 10px; }
.vdc-error { margin-top: 12px; font-size: 12px; color: #B91C1C; background: #FEF2F2; border-radius: 10px; padding: 8px 10px; font-weight: 600; }
.vdc-actions { display: flex; gap: 10px; margin-top: 18px; }
.vdc-btn { flex: 1; border: none; border-radius: 12px; padding: 11px 14px; font-size: 13px; font-weight: 700; }
.vdc-btn.primary { background: linear-gradient(120deg,#1E1B6B,#4B2FD6); color: #fff; }
.vdc-btn.ghost { background: #F1F5F9; color: #334155; }
</style>
<script>
// Pending Vessel submit: before uploading, ask for the Survey Completed date
// and — when no Calculation Sheet (Excel) is attached or already uploaded and
// recovery applies to this survey type — the recovery figures.
(function () {
    var modal = document.getElementById('vdCompleteModal');
    if (!modal) return;
    var pendingForm = null;
    var recBox = modal.querySelector('[data-vdc-recovery]'), note = modal.querySelector('[data-vdc-excel-note]');
    var err = modal.querySelector('[data-vdc-error]'), total = document.getElementById('vdcTotal');
    var parts = modal.querySelectorAll('[data-vdc-part]');
    var totalTouched = false;
    function close() { modal.hidden = true; pendingForm = null; }
    function showErr(m) { err.textContent = m; err.hidden = !m; }
    parts.forEach(function (p) { p.addEventListener('input', function () {
        if (totalTouched) return;
        var any = false, sum = 0;
        parts.forEach(function (x) { if (x.value !== '') { any = true; sum += parseFloat(x.value) || 0; } });
        total.value = any ? (Math.round(sum * 1000) / 1000) : '';
    }); });
    total.addEventListener('input', function () { totalTouched = total.value !== ''; });
    modal.querySelector('[data-vdc-cancel]').addEventListener('click', close);
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });

    document.addEventListener('submit', function (ev) {
        var form = ev.target.closest('[data-report-upload-form]');
        if (!form || form.dataset.vdcConfirmed === '1') return;
        var st = form.querySelector('[name=current_status]');
        if (!st || st.value !== 'Pending Vessel') return;
        ev.preventDefault();
        ev.stopImmediatePropagation();
        var excelInput = form.querySelector('[name=excel_report]');
        var hasExcel = modal.dataset.hasExcel === '1' || (excelInput && excelInput.files.length > 0);
        var needRecovery = modal.dataset.recoveryNa !== '1' && !hasExcel;
        recBox.hidden = !needRecovery;
        note.hidden = !(hasExcel && modal.dataset.recoveryNa !== '1');
        showErr('');
        pendingForm = form;
        modal.hidden = false;
        setTimeout(function () { document.getElementById('vdcDate').focus(); }, 30);
    }, true);

    modal.querySelector('[data-vdc-ok]').addEventListener('click', function () {
        if (!pendingForm) return;
        var date = document.getElementById('vdcDate').value;
        var max = document.getElementById('vdcDate').getAttribute('max');
        if (!date) return showErr('Please choose the Survey Completed date.');
        if (date > max) return showErr('Survey Completed date cannot be in the future.');
        var form = pendingForm;
        form.querySelectorAll('[data-vdc-hidden]').forEach(function (h) { h.remove(); });
        var add = function (name, value) {
            var h = document.createElement('input');
            h.type = 'hidden'; h.name = name; h.value = value; h.setAttribute('data-vdc-hidden', '');
            form.appendChild(h);
        };
        add('survey_completed_date', date);
        if (!recBox.hidden) {
            if (total.value === '') return showErr('Please enter the recovery (enter 0 if none).');
            add('manual_recovery', '1');
            add('manual_vlsfo', modal.querySelector('[name=vdc_vlsfo]').value);
            add('manual_hsfo', modal.querySelector('[name=vdc_hsfo]').value);
            add('manual_lsmgo', modal.querySelector('[name=vdc_lsmgo]').value);
            add('manual_total', total.value);
        }
        form.dataset.vdcConfirmed = '1';
        modal.hidden = true;
        pendingForm = null;
        if (form.requestSubmit) form.requestSubmit(); else form.submit();
    });
})();
</script>

<script>
// Upload PDF/Excel form: show a "please wait" overlay and lock the submit
// button the instant Submit is pressed — the actual upload+email can still
// take several seconds (admin notification email, Excel recovery parsing),
// and with no feedback the page looked frozen, tempting a second tap that
// re-submitted the same files. The browser's own "required" validation
// still runs first, so this only fires once the form is really submitting.
document.addEventListener('submit', function (ev) {
    var form = ev.target.closest('[data-report-upload-form]');
    if (!form) return;
    var btn = form.querySelector('[data-report-upload-submit]');
    if (btn && btn.dataset.uploading === '1') {
        ev.preventDefault();
        return;
    }
    if (btn) {
        btn.dataset.uploading = '1';
        btn.disabled = true;
        btn.dataset.originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Uploading, please wait…';
    }
    var overlay = document.createElement('div');
    overlay.className = 'ysms-upload-overlay';
    overlay.innerHTML =
        '<div class="ysms-upload-overlay-box">' +
            '<i class="fa-solid fa-spinner fa-spin"></i>' +
            '<div>Uploading files and sending notifications…<br>This can take a few seconds, please don\'t close this page.</div>' +
        '</div>';
    document.body.appendChild(overlay);
}, true);
</script>
<style>
.ysms-upload-overlay {
    position: fixed; inset: 0; z-index: 9999;
    background: rgba(15,23,42,.55);
    display: flex; align-items: center; justify-content: center;
    padding: 20px;
}
.ysms-upload-overlay-box {
    background: #fff; border-radius: 16px; padding: 28px 24px;
    max-width: 320px; text-align: center; font-size: 14px; font-weight: 600;
    color: #0b1e46; box-shadow: 0 20px 50px rgba(0,0,0,.25);
}
.ysms-upload-overlay-box i {
    display: block; font-size: 28px; color: #1e3a8a; margin-bottom: 12px;
}
</style>

<?php
aieRender();
include 'includes/nav.php';
include 'includes/footer.php';

// 🌟 Document Replace / Re-upload — modal + behavior, Admin/Surveyor only
// (page access above already guarantees any Surveyor here is the assigned
// one). Included after footer.php's own jQuery/Bootstrap <script> tags —
// same "last jQuery load wins" fix already used elsewhere in this app
// (see assign_vessel.php) — so $.ajaxSetup here and document_replace.js's
// own jQuery reference both bind to the SAME final jQuery instance.
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

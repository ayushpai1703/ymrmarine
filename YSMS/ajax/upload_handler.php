<?php
require_once __DIR__ . '/../config/config.php';
checkAuth();

// Only Admin and Surveyor may upload survey/report files (matches the UI, which
// hides this form from Client and Super Admin). Enforced here too so a direct
// POST can't bypass the UI restriction.
if (!in_array($_SESSION['role'] ?? '', ['Admin', 'Surveyor'], true)) {
    header('Location: ../index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $survey_id = (int)$_POST['survey_id'];
    $current_status = $_POST['current_status'];
    $target_dir = __DIR__ . "/../uploads/";
    
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0755, true);
    }

    $db = getDB();

    // The survey's real status and access come from the database — never from
    // the posted form, which a user could edit. Surveyors may only upload to
    // vessels assigned to them.
    $svStmt = $db->prepare('SELECT status FROM surveys WHERE id = ? LIMIT 1');
    $svStmt->execute([$survey_id]);
    $svStatus = $svStmt->fetchColumn();
    if ($svStatus === false) {
        $_SESSION['flash_msg'] = 'That vessel could not be found.';
        $_SESSION['flash_type'] = 'error';
        header('Location: ../index.php');
        exit;
    }
    if (($_SESSION['role'] ?? '') === 'Surveyor' && !isSurveyorAssignedToSurvey($db, $survey_id, (int)$_SESSION['user_id'])) {
        $_SESSION['flash_msg'] = 'You can only upload files to vessels assigned to you.';
        $_SESSION['flash_type'] = 'error';
        header('Location: ../index.php');
        exit;
    }
    $current_status = (string)$svStatus;

// Ensure date columns hold time (DATETIME)
try {
    $db->exec("ALTER TABLE surveys MODIFY COLUMN assign_date DATETIME NULL");
    $db->exec("ALTER TABLE surveys MODIFY COLUMN survey_completed_date DATETIME NULL");
    $db->exec("ALTER TABLE surveys MODIFY COLUMN report_uploaded_date DATETIME NULL");
} catch (Exception $e) { /* ignore if already datetime or no permission */ }

// 🌟 SAFETY NET: hsfo_recovery column (TABLES 54B!N20) alongside the
// existing vlsfo_recovery/lsmgo_recovery columns.
try {
    $cols = $db->query("SHOW COLUMNS FROM surveys LIKE 'hsfo_recovery'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE surveys ADD COLUMN hsfo_recovery DECIMAL(12,3) DEFAULT NULL");
    }
} catch (Exception $e) { error_log('upload_handler.php hsfo_recovery column: ' . $e->getMessage()); }

    // 🌟 Recovery doesn't apply to some survey types (Draft Survey, BQS, etc.)
    // — skip auto-extracting a number from the Excel report for those;
    // recovery_amount/vlsfo/lsmgo/hsfo stay NULL ("N/A") instead.
    $recoveryNA = false;
    try {
        $rtStmt = $db->prepare('SELECT survey_type_id, survey_type_ids FROM surveys WHERE id = ? LIMIT 1');
        $rtStmt->execute([$survey_id]);
        $rtRow = $rtStmt->fetch(PDO::FETCH_ASSOC);
        if ($rtRow) {
            $rtFallback = '';
            if (!empty($rtRow['survey_type_id'])) {
                $rtF = $db->prepare('SELECT type_name FROM survey_types WHERE id = ? LIMIT 1');
                $rtF->execute([(int)$rtRow['survey_type_id']]);
                $rtFallback = (string)($rtF->fetchColumn() ?: '');
            }
            $recoveryNA = isRecoveryNotApplicable($db, (string)($rtRow['survey_type_ids'] ?? ''), $rtFallback);
        }
    } catch (Throwable $e) {
        error_log('upload_handler.php recoveryNA check: ' . $e->getMessage());
    }

    require_once __DIR__ . '/../includes/mailer.php';
    require_once __DIR__ . '/../includes/recovery_excel.php';
    require_once __DIR__ . '/../../includes/upload_validation.php';
require_once __DIR__ . '/../includes/notifications.php';
    $files_uploaded = 0;
    $uploaded_labels = [];
    $uploaded_attachments = []; // full paths for email attachments

    // అప్‌లోడ్ అయ్యే ఫైల్ ఎక్స్‌టెన్షన్ అనుమతించదగినదో కాదో చెక్ చేసే హెల్పర్ (మాలిషియస్ స్క్రిప్ట్ అప్‌లోడ్ ఆపడానికి)
    $isAllowedExt = function ($filename, $allowed) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, $allowed, true);
    };

    // 1. PDF ప్రాసెసింగ్
    if (isset($_FILES['pdf_report']) && $_FILES['pdf_report']['error'] == 0 && $isAllowedExt($_FILES['pdf_report']['name'], ['pdf'])) {
        $pdf_orig = basename($_FILES['pdf_report']['name']);
        $pdf_name = time() . "_" . preg_replace('/[^A-Za-z0-9._-]/', '_', $pdf_orig);
        if (move_uploaded_file($_FILES['pdf_report']['tmp_name'], $target_dir . $pdf_name)) {
            // Re-uploading (e.g. to complete a stuck Pending Vessel after the
            // other required file failed earlier) replaces the previous PDF
            // row instead of adding a duplicate entry to the file list.
            $db->prepare("DELETE FROM uploads WHERE survey_id = ? AND file_type = 'Formal Report PDF'")->execute([$survey_id]);
            $stmt = $db->prepare("INSERT INTO uploads (survey_id, file_name, file_type, file_path, file_size) VALUES (?, ?, 'Formal Report PDF', ?, '1.2 MB')");
            $stmt->execute([$survey_id, $pdf_orig, "uploads/" . $pdf_name]);
            $files_uploaded++;
            $uploaded_labels[] = 'PDF: ' . $pdf_orig;
            $uploaded_attachments[] = ['path' => $target_dir . $pdf_name, 'name' => $pdf_orig];
        }
    }

    // 2. 📂 ఆప్షనల్ EXCEL ప్రాసెసింగ్ మరియు TABLES 54 ట్యాబ్ లోని O21 (Recovery), M20 (VLSFO), O20 (LSMGO), N20 (HSFO) వాల్యూస్ రీడింగ్ — ఫైల్ ఎంచుకోకపోతే ఈ బ్లాక్ మొత్తం స్కిప్ అవుతుంది, రికవరీ కాలమ్స్ ఎప్పటిలాగే NULL గా ఉంటాయి (అడ్మిన్ తర్వాత మాన్యువల్‌గా Edit Recovery లో నింపుకోవచ్చు).
    $recovery_val = 0.00;
    $vlsfo_val = 0.00;
    $lsmgo_val = 0.00;
    $hsfo_val = 0.00;
    if (isset($_FILES['excel_report']) && $_FILES['excel_report']['error'] == 0 && $isAllowedExt($_FILES['excel_report']['name'], ['xlsx', 'xls', 'xlsm'])) {
        $excel_orig = basename($_FILES['excel_report']['name']);
        $excel_name = time() . "_" . preg_replace('/[^A-Za-z0-9._-]/', '_', $excel_orig);
        $full_target_path = $target_dir . $excel_name;
        
        if (move_uploaded_file($_FILES['excel_report']['tmp_name'], $full_target_path)) {
            
            // TABLES 54B tab: O21 total, M20 VLSFO, N20 HSFO, O20 LSMGO (see includes/recovery_excel.php).
            $rec = extractRecoveryFromExcel($full_target_path);
            $recovery_val = $rec['recovery'] ?? 0.00;
            $vlsfo_val = $rec['vlsfo'] ?? 0.00;
            $lsmgo_val = $rec['lsmgo'] ?? 0.00;
            $hsfo_val = $rec['hsfo'] ?? 0.00;

            // అప్‌లోడ్స్ టేబుల్ లో ఎంట్రీ (re-upload replaces, doesn't duplicate — see PDF note above)
            $db->prepare("DELETE FROM uploads WHERE survey_id = ? AND file_type = 'Formal Report Excel'")->execute([$survey_id]);
            $stmt = $db->prepare("INSERT INTO uploads (survey_id, file_name, file_type, file_path, file_size) VALUES (?, ?, 'Formal Report Excel', ?, '245 KB')");
            $stmt->execute([$survey_id, $excel_orig, "uploads/" . $excel_name]);
            
            // surveys టేబుల్ లో recovery_amount, vlsfo_recovery, lsmgo_recovery, hsfo_recovery ని అప్‌డేట్ చేయడం
            // (Recovery not applicable for this survey's type(s) — store NULL instead of the extracted number)
            $stmt_rec = $db->prepare("UPDATE surveys SET recovery_amount = ?, vlsfo_recovery = ?, lsmgo_recovery = ?, hsfo_recovery = ? WHERE id = ?");
            $stmt_rec->execute($recoveryNA
                ? [null, null, null, null, $survey_id]
                : [$recovery_val, $vlsfo_val, $lsmgo_val, $hsfo_val, $survey_id]);
            
            $files_uploaded++;
            $uploaded_labels[] = 'Excel: ' . $excel_orig;
            $uploaded_attachments[] = ['path' => $full_target_path, 'name' => $excel_orig];
        }
    }

    // 3. WORD (.docx) ప్రాసెసింగ్
    if (isset($_FILES['word_report']) && $_FILES['word_report']['error'] == 0 && $isAllowedExt($_FILES['word_report']['name'], ['doc', 'docx'])) {
        $word_orig = basename($_FILES['word_report']['name']);
        $word_name = time() . "_" . preg_replace('/[^A-Za-z0-9._-]/', '_', $word_orig);
        if (move_uploaded_file($_FILES['word_report']['tmp_name'], $target_dir . $word_name)) {
            $db->prepare("DELETE FROM uploads WHERE survey_id = ? AND file_type = 'Formal Report Word'")->execute([$survey_id]);
            $stmt = $db->prepare("INSERT INTO uploads (survey_id, file_name, file_type, file_path, file_size) VALUES (?, ?, 'Formal Report Word', ?, '512 KB')");
            $stmt->execute([$survey_id, $word_orig, "uploads/" . $word_name]);
            $files_uploaded = 3; // Word అప్‌లోడ్ అయిందని గుర్తుగా 3 ఇస్తున్నాం
            $uploaded_labels[] = 'Word: ' . $word_orig;
            $uploaded_attachments[] = ['path' => $target_dir . $word_name, 'name' => $word_orig];
        }
    }

    // 3b. Final Report as PDF (Pending Report stage — replaces the old
    // mandatory Word report). Stored as a normal 'Formal Report PDF' row and
    // added ALONGSIDE the signed-documents PDF from the Pending Vessel stage
    // (no DELETE here, so that earlier PDF is never replaced).
    $final_pdf_saved = false;
    $final_pdf_error = '';
    if (isset($_FILES['final_report_pdf']) && (int)($_FILES['final_report_pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $fp_orig = basename((string)$_FILES['final_report_pdf']['name']);
        $fp_err = (int)$_FILES['final_report_pdf']['error'];
        if ($fp_err === UPLOAD_ERR_INI_SIZE || $fp_err === UPLOAD_ERR_FORM_SIZE) {
            $final_pdf_error = 'it is too large';
        } elseif (!$isAllowedExt($fp_orig, ['pdf'])) {
            $final_pdf_error = 'only a PDF (.pdf) file is allowed';
        } else {
            $final_pdf_error = upload_validate($_FILES['final_report_pdf'], UPLOAD_MIMES_PDF, 30 * 1024 * 1024);
        }
        if ($final_pdf_error === '') {
            $fp_name = time() . "_" . preg_replace('/[^A-Za-z0-9._-]/', '_', $fp_orig);
            $fp_bytes = (int)$_FILES['final_report_pdf']['size'];
            if (move_uploaded_file($_FILES['final_report_pdf']['tmp_name'], $target_dir . $fp_name)) {
                $fp_size = $fp_bytes >= 1048576 ? round($fp_bytes / 1048576, 1) . ' MB' : max(1, (int)round($fp_bytes / 1024)) . ' KB';
                $stmt = $db->prepare("INSERT INTO uploads (survey_id, file_name, file_type, file_path, file_size) VALUES (?, ?, 'Formal Report PDF', ?, ?)");
                $stmt->execute([$survey_id, $fp_orig, "uploads/" . $fp_name, $fp_size]);
                $final_pdf_saved = true;
                $uploaded_labels[] = 'Final Report PDF: ' . $fp_orig;
                $uploaded_attachments[] = ['path' => $target_dir . $fp_name, 'name' => $fp_orig];
            } else {
                $final_pdf_error = 'it could not be stored, please try again';
            }
        }
        if ($final_pdf_error !== '') {
            $final_pdf_error = 'Final report "' . $fp_orig . '" was not saved: ' . rtrim($final_pdf_error, '.') . '.';
        }
    }

    $extra_saved = 0;
    $extra_rejected = [];
    // 4. ఎక్స్‌ట్రా ఫైల్స్ — several per "Add More Files" row, common office /
    // image / archive formats. Extension allowlist + the shared executable /
    // script content check (upload_validate).
    $extra_allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'xlsm', 'csv', 'txt', 'rtf', 'ppt', 'pptx', 'odt', 'ods',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'tif', 'tiff', 'bmp', 'zip', 'rar', '7z', 'msg', 'eml'];
    if (isset($_FILES['extra_files']) && is_array($_FILES['extra_files']['name'])) {
        foreach ($_FILES['extra_files']['name'] as $key => $name) {
            if ($_FILES['extra_files']['error'][$key] == 0 && !$isAllowedExt($name, $extra_allowed)) {
                $extra_rejected[] = basename($name);
                continue;
            }
            if ($_FILES['extra_files']['error'][$key] == 0 && upload_validate([
                    'name' => $name, 'type' => $_FILES['extra_files']['type'][$key] ?? '',
                    'tmp_name' => $_FILES['extra_files']['tmp_name'][$key], 'error' => 0,
                    'size' => $_FILES['extra_files']['size'][$key]], [], 30 * 1024 * 1024) !== '') {
                $extra_rejected[] = basename($name);
                continue;
            }
            if ($_FILES['extra_files']['error'][$key] == 0 && $isAllowedExt($name, $extra_allowed)) {
                $extra_orig = basename($name);
                $extra_name = time() . "_" . preg_replace('/[^A-Za-z0-9._-]/', '_', $extra_orig);
                if (move_uploaded_file($_FILES['extra_files']['tmp_name'][$key], $target_dir . $extra_name)) {
                    $stmt = $db->prepare("INSERT INTO uploads (survey_id, file_name, file_type, file_path, file_size) VALUES (?, ?, 'Field Report', ?, '512 KB')");
                    $stmt->execute([$survey_id, $extra_orig, "uploads/" . $extra_name]);
                    $uploaded_labels[] = 'Extra: ' . $extra_orig;
                    $extra_saved++;
                    $uploaded_attachments[] = ['path' => $target_dir . $extra_name, 'name' => $extra_orig];
                }
            }
        }
    }

    // 5. Optional ZIP folder (photos / supporting documents). Capped at 30 MB:
    // Cloud Run rejects request bodies over 32 MB before PHP ever sees them.
    $zip_error = '';
    if (isset($_FILES['zip_folder']) && (int)($_FILES['zip_folder']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $zip_orig = basename((string)$_FILES['zip_folder']['name']);
        $zip_upload_err = (int)$_FILES['zip_folder']['error'];
        if ($zip_upload_err === UPLOAD_ERR_INI_SIZE || $zip_upload_err === UPLOAD_ERR_FORM_SIZE) {
            $zip_error = 'it is larger than 30 MB';
        } elseif (!$isAllowedExt($zip_orig, ['zip'])) {
            $zip_error = 'only .zip files are allowed';
        } else {
            $zip_error = upload_validate($_FILES['zip_folder'], UPLOAD_MIMES_ZIP, 30 * 1024 * 1024);
            if ($zip_error === '' && class_exists('ZipArchive')) {
                $zip_check = new ZipArchive();
                if ($zip_check->open($_FILES['zip_folder']['tmp_name'], ZipArchive::RDONLY) !== true) {
                    $zip_error = 'it is not a valid ZIP file';
                } else {
                    $zip_check->close();
                }
            }
        }
        if ($zip_error === '') {
            $zip_name = time() . "_" . preg_replace('/[^A-Za-z0-9._-]/', '_', $zip_orig);
            $zip_bytes = (int)$_FILES['zip_folder']['size'];
            if (move_uploaded_file($_FILES['zip_folder']['tmp_name'], $target_dir . $zip_name)) {
                $zip_size_label = $zip_bytes >= 1048576 ? round($zip_bytes / 1048576, 1) . ' MB' : max(1, (int)round($zip_bytes / 1024)) . ' KB';
                $stmt = $db->prepare("INSERT INTO uploads (survey_id, file_name, file_type, file_path, file_size) VALUES (?, ?, 'Field Report', ?, ?)");
                $stmt->execute([$survey_id, $zip_orig, "uploads/" . $zip_name, $zip_size_label]);
                $uploaded_labels[] = 'ZIP: ' . $zip_orig;
                $uploaded_attachments[] = ['path' => $target_dir . $zip_name, 'name' => $zip_orig];
                $extra_saved++;
            } else {
                $zip_error = 'it could not be stored, please try again';
            }
        }
        if ($zip_error !== '') {
            $zip_error = 'ZIP folder "' . $zip_orig . '" was not saved: ' . rtrim($zip_error, '.') . '.';
        }
    }

    // Helper: build app base URL for email links
    $ysmsAppBase = (function () {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['PHP_SELF'] ?? ''))), '/');
        return $scheme . '://' . $host . $base;
    })();

    // 🌟 Required-files check is now cumulative (from the uploads table),
    // not just "both succeeded in this one request". Previously the
    // Pending Vessel -> Pending Report move needed $files_uploaded to
    // reach 2 in a SINGLE submission — if one file silently failed (wrong
    // extension, over the server's upload size limit) while the other
    // succeeded, the survey could sit in Pending Vessel forever even after
    // both files eventually made it in across separate attempts, since no
    // single request ever saw both at once. Checking what's actually saved
    // for this survey fixes that regardless of how many attempts it took.
    $has_pdf_uploaded = false;
    $has_excel_uploaded = false;
    try {
        $chk = $db->prepare("SELECT DISTINCT file_type FROM uploads WHERE survey_id = ? AND file_type IN ('Formal Report PDF', 'Formal Report Excel')");
        $chk->execute([$survey_id]);
        foreach ($chk->fetchAll(PDO::FETCH_COLUMN) as $ft) {
            if ($ft === 'Formal Report PDF') $has_pdf_uploaded = true;
            if ($ft === 'Formal Report Excel') $has_excel_uploaded = true;
        }
    } catch (Throwable $e) { error_log('upload_handler required-files check: ' . $e->getMessage()); }

    // 🌟 Tell the user plainly when a required file they selected did NOT
    // actually get saved (wrong extension, upload error, move failed) —
    // previously this failed completely silently, so the page just showed
    // "success" implicitly by redirecting with no message at all, leaving
    // no clue as to why the vessel never left Pending Vessel.
    $pdf_selected = isset($_FILES['pdf_report']['name']) && $_FILES['pdf_report']['name'] !== '';
    $pdf_failed_this_request = $pdf_selected && !$isAllowedExt($_FILES['pdf_report']['name'], ['pdf']) || ($pdf_selected && $_FILES['pdf_report']['error'] != 0);
    $excel_selected = isset($_FILES['excel_report']['name']) && $_FILES['excel_report']['name'] !== '';
    $excel_failed_this_request = $excel_selected && !$isAllowedExt($_FILES['excel_report']['name'], ['xlsx', 'xls', 'xlsm']) || ($excel_selected && $_FILES['excel_report']['error'] != 0);
    // 🌟 PDF Report is mandatory; the Calculation Sheet (Excel) is optional —
    // a Pending Vessel can move to Pending Report with just the PDF. Only a
    // missing/failed PDF blocks the move; a failed Excel just gets a note.
    if ($current_status === 'Pending Vessel' && ($pdf_failed_this_request || $excel_failed_this_request) && !$has_pdf_uploaded) {
        $problems = [];
        if ($pdf_failed_this_request) $problems[] = 'PDF Report (must be a .pdf file, check its size)';
        if ($excel_failed_this_request) $problems[] = 'Calculation Sheet (must be .xlsx/.xls, check its size)';
        $_SESSION['flash_msg'] = 'Could not save: ' . implode(' and ', $problems) . '. Please try uploading it again.';
        $_SESSION['flash_type'] = 'error';
        header('Location: ../vessel_detail.php?id=' . $survey_id);
        exit;
    }

    // ⚙️ వర్క్‌ఫ్లో & సెషన్ అలర్ట్ మెసేజ్ లాజిక్
    if ($current_status === 'Pending Vessel' && $has_pdf_uploaded) {
        $update = $db->prepare("UPDATE surveys SET status = 'Pending Report', survey_completed_date = NOW(), status_updated_by = ? WHERE id = ?");
        $update->execute([$_SESSION['user_id'], $survey_id]);
        $_SESSION['flash_msg'] = "Pending vessel is uploaded and moved to pending report";
        if ($excel_failed_this_request) {
            $_SESSION['flash_msg'] .= ' (Calculation Sheet could not be saved — must be .xlsx/.xls; you can add it later.)';
        }
        try {
            $ctx = loadSurveyNotifyContext($db, $survey_id);
            $ctx['new_status'] = 'Pending Report';
            $ctx['stage_label'] = 'Survey completed — formal report pending';
            $ctx['files'] = $uploaded_labels;
            $ctx['attachments'] = $uploaded_attachments;
            $ctx['app_url'] = $ysmsAppBase . '/reports.php';
            // Prefer logged-in uploader profile for Reply-To
            if (!empty($_SESSION['user_id'])) {
                try {
                    $u = $db->prepare('SELECT full_name, email FROM users WHERE id = ? LIMIT 1');
                    $u->execute([(int)$_SESSION['user_id']]);
                    $ur = $u->fetch(PDO::FETCH_ASSOC);
                    if ($ur) {
                        if (!empty($ur['full_name'])) $ctx['surveyor_name'] = $ur['full_name'];
                        if (!empty($ur['email'])) $ctx['surveyor_email'] = trim($ur['email']);
                    }
                } catch (Throwable $ignore) {}
            }
            $note = notifyAdminsOfReportUpload($db, $ctx);
            if ($note !== '') {
                $_SESSION['flash_msg'] .= ' · ' . $note;
            }
            try {
                $v = $ctx['vessel_name'] ?? 'Vessel';
                $who = $_SESSION['full_name'] ?? 'Surveyor';
                notifyAllAdmins($db, 'Survey documents uploaded',
                    $who . ' uploaded files for ' . $v . ' (Pending Report).',
                    'upload', 'vessel_detail.php?id=' . (int)$survey_id, (int)($_SESSION['user_id'] ?? 0));
            } catch (Throwable $ne) { error_log('upload in-app notif: '.$ne->getMessage()); }
        } catch (Throwable $e) {
            error_log('upload_handler admin notify (pending report): ' . $e->getMessage());
        }
        header("Location: ../reports.php");
        exit;
    } 
    
    // 🌟 వర్డ్ రిపోర్ట్ అప్‌లోడ్ చేసినా, లేదా యూజర్ "Continue without report" కన్ఫర్మ్ చేసినా ముందుకు వెళ్లేలా
    $no_report_confirmed = isset($_POST['no_report_confirmed']) && $_POST['no_report_confirmed'] === '1';
    $word_report_missing = !isset($_FILES['word_report']) || $_FILES['word_report']['error'] != 0 || empty($_FILES['word_report']['name']);
    $final_pdf_missing = !isset($_FILES['final_report_pdf']) || (int)$_FILES['final_report_pdf']['error'] === UPLOAD_ERR_NO_FILE;

    if ($current_status === 'Pending Report' && ($files_uploaded === 3 || $final_pdf_saved || ($word_report_missing && $final_pdf_missing && $no_report_confirmed))) {
        $update = $db->prepare("UPDATE surveys SET status = 'Completed', report_uploaded_date = NOW(), notes = 'All surveys and reports are verified and uploaded successfully.', status_updated_by = ? WHERE id = ?");
        $update->execute([$_SESSION['user_id'], $survey_id]);
        $_SESSION['flash_msg'] = "Pending report is completed";
        if ($zip_error !== '') {
            $_SESSION['flash_msg'] .= ' · ' . $zip_error;
            $_SESSION['flash_type'] = 'warning';
        }
        if ($extra_rejected) {
            $_SESSION['flash_msg'] .= ' · Not saved (file type not allowed): ' . implode(', ', $extra_rejected) . '.';
            $_SESSION['flash_type'] = 'warning';
        }
        try {
            $ctx = loadSurveyNotifyContext($db, $survey_id);
            $ctx['new_status'] = 'Completed';
            $ctx['stage_label'] = 'Report uploaded — survey completed';
            $ctx['files'] = $uploaded_labels;
            $ctx['attachments'] = $uploaded_attachments;
            $ctx['app_url'] = $ysmsAppBase . '/completed.php';
            if (!empty($_SESSION['user_id'])) {
                try {
                    $u = $db->prepare('SELECT full_name, email FROM users WHERE id = ? LIMIT 1');
                    $u->execute([(int)$_SESSION['user_id']]);
                    $ur = $u->fetch(PDO::FETCH_ASSOC);
                    if ($ur) {
                        if (!empty($ur['full_name'])) $ctx['surveyor_name'] = $ur['full_name'];
                        if (!empty($ur['email'])) $ctx['surveyor_email'] = trim($ur['email']);
                    }
                } catch (Throwable $ignore) {}
            }
            $note = notifyAdminsOfReportUpload($db, $ctx);
            if ($note !== '') {
                $_SESSION['flash_msg'] .= ' · ' . $note;
            }
            try {
                $v = $ctx['vessel_name'] ?? 'Vessel';
                $who = $_SESSION['full_name'] ?? 'Surveyor';
                notifyAllAdmins($db, 'Report uploaded — completed',
                    $who . ' completed report upload for ' . $v . '.',
                    'report', 'vessel_detail.php?id=' . (int)$survey_id, (int)($_SESSION['user_id'] ?? 0));
            } catch (Throwable $ne) { error_log('report in-app notif: '.$ne->getMessage()); }
        } catch (Throwable $e) {
            error_log('upload_handler admin notify (completed): ' . $e->getMessage());
        }
        header("Location: ../completed.php");
        exit;
    }

    // 🌟 Anything that reaches here uploaded successfully (files_uploaded > 0)
    // but didn't complete a status transition — e.g. only the PDF or only the
    // Excel has been provided so far for a Pending Vessel, or extra files were
    // added to a survey that's already Completed/Cancelled. Previously this
    // fell through to a bare redirect to index.php with zero explanation,
    // which looked identical to nothing having happened at all. Send the user
    // back to the same vessel with an accurate status message instead.
    if ($files_uploaded > 0) {
        if ($current_status === 'Pending Vessel') {
            // Reaching here while still 'Pending Vessel' means the PDF (the
            // only mandatory file) hasn't been saved yet — the Excel sheet is
            // optional, so it's never listed as something still needed.
            $_SESSION['flash_msg'] = !$has_pdf_uploaded
                ? 'File saved. Still need: PDF Report before this moves to Pending Report.'
                : 'File saved.';
            if (!$has_pdf_uploaded) $_SESSION['flash_type'] = 'warning';
        } else {
            $_SESSION['flash_msg'] = 'File(s) uploaded successfully.';
        }
        if ($zip_error !== '') {
            $_SESSION['flash_msg'] .= ' · ' . $zip_error;
            $_SESSION['flash_type'] = 'warning';
        }
        header('Location: ../vessel_detail.php?id=' . $survey_id);
        exit;
    }

    // Only extra files / a ZIP folder were sent (no status change): say what happened instead of dropping to the dashboard.
    if ($extra_saved > 0 || $zip_error !== '' || $extra_rejected || $final_pdf_error !== '') {
        $problems = [];
        if ($final_pdf_error !== '') $problems[] = $final_pdf_error;
        if ($extra_rejected) $problems[] = 'Not allowed: ' . implode(', ', $extra_rejected) . '. Use PDF, Word, Excel, PowerPoint, text/CSV, images or ZIP/RAR/7Z.';
        if ($zip_error !== '') $problems[] = $zip_error;
        if ($extra_saved > 0) {
            $_SESSION['flash_msg'] = $extra_saved . ' file' . ($extra_saved === 1 ? '' : 's') . ' uploaded successfully.' . ($problems ? ' · ' . implode(' · ', $problems) : '');
            if ($problems) $_SESSION['flash_type'] = 'warning';
        } else {
            $_SESSION['flash_msg'] = implode(' · ', $problems);
            $_SESSION['flash_type'] = 'error';
        }
        $back = ['Pending Report' => 'report_detail.php', 'Completed' => 'completed_detail.php'][$current_status] ?? 'vessel_detail.php';
        header('Location: ../' . $back . '?id=' . $survey_id . ($current_status === 'Completed' ? '#dossier-files' : ''));
        exit;
    }
}

header("Location: ../index.php");
exit;
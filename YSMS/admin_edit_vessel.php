<?php
/**
 * Admin — edit every field of a vessel / survey record, whatever its stage
 * (Pending Vessel, Pending Report, Completed, Cancelled).
 *
 * One form for all four lists: vessel & client details, survey types,
 * surveyors, port, all dates, status, recovery (typed or re-read from a new
 * Calculation Sheet), report number, remarks, notes and the latest status
 * update. The status only changes when the Admin picks a new one AND ticks
 * the confirmation box; every other save leaves the workflow stage alone.
 */
require_once 'config/config.php';
require_once __DIR__ . '/../includes/upload_validation.php';
require_once 'includes/recovery_excel.php';
checkAuth();
if (($_SESSION['role'] ?? '') !== 'Admin') {
    header('Location: index.php');
    exit;
}

$db = getDB();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

const AEV_STATUSES = ['Pending Vessel', 'Pending Report', 'Completed', 'Cancelled'];
const AEV_UNASSIGNED_SURVEYOR = 1; // outsourcing / unassigned placeholder (see assign_vessel.php)

/** Detail page that shows a record in the given status. */
function aevDetailPage(string $status, int $id): string {
    $page = ['Pending Report' => 'report_detail.php', 'Completed' => 'completed_detail.php'][$status] ?? 'vessel_detail.php';
    return $page . '?id=' . $id;
}

/** Only our own list / detail pages are accepted as the "back" target. */
function aevSafeReturn(string $r): string {
    return preg_match('/^(vessels|reports|completed|cancelled|vessel_detail|report_detail|completed_detail|admin_controls)\.php(\?id=\d+)?$/', $r) ? $r : '';
}

/** Y-m-d (or Y-m-dTH:i for datetime) → normalised string, '' for blank, null when invalid. */
function aevDate(string $v, bool $withTime = false): ?string {
    $v = trim($v);
    if ($v === '') return '';
    $fmt = $withTime ? '!Y-m-d\TH:i' : '!Y-m-d';
    $d = DateTime::createFromFormat($fmt, $v);
    if (!$d || $d->format($withTime ? 'Y-m-d\TH:i' : 'Y-m-d') !== $v) return null;
    $y = (int)$d->format('Y');
    if ($y < 2000 || $y > 2100) return null;
    return $d->format($withTime ? 'Y-m-d H:i:s' : 'Y-m-d');
}

/** Blank → null, number → rounded float, anything else → false. */
function aevQty(string $v) {
    $v = str_replace([',', ' '], '', trim($v));
    if ($v === '') return null;
    return is_numeric($v) ? round((float)$v, 3) : false;
}

$load = function () use ($db, $id) {
    $st = $db->prepare('SELECT * FROM surveys WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
};
$survey = $id > 0 ? $load() : null;
if (!$survey) {
    $_SESSION['flash_msg'] = 'That vessel record could not be found.';
    $_SESSION['flash_type'] = 'error';
    header('Location: vessels.php');
    exit;
}

$surveyCols = array_flip(array_column($db->query('SHOW COLUMNS FROM surveys')->fetchAll(PDO::FETCH_ASSOC), 'Field'));
$clients = $db->query('SELECT id, company_name FROM clients ORDER BY company_name ASC')->fetchAll(PDO::FETCH_ASSOC);
$ports = $db->query('SELECT id, port_name, country FROM ports ORDER BY port_name ASC')->fetchAll(PDO::FETCH_ASSOC);
$types = $db->query('SELECT id, type_name FROM survey_types ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
$curSurveyorIds = array_map('intval', (function () use ($db, $id) {
    try {
        $s = $db->prepare('SELECT surveyor_id FROM survey_surveyors WHERE survey_id = ? ORDER BY id ASC');
        $s->execute([$id]);
        return $s->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { return []; }
})());
if (!$curSurveyorIds && (int)$survey['surveyor_id'] !== AEV_UNASSIGNED_SURVEYOR) $curSurveyorIds = [(int)$survey['surveyor_id']];
// Active surveyors, plus any inactive one still assigned to this record so it isn't silently dropped.
$svIn = $curSurveyorIds ? implode(',', array_map('intval', $curSurveyorIds)) : '0';
$surveyors = $db->query("SELECT u.id, u.full_name, u.status FROM users u JOIN roles r ON r.id = u.role_id
    WHERE r.name = 'Surveyor' AND u.id <> " . AEV_UNASSIGNED_SURVEYOR . " AND (u.status = 'Active' OR u.id IN ($svIn))
    ORDER BY u.full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$typeNA = [];
foreach ($types as $t) $typeNA[(int)$t['id']] = isRecoveryNotApplicable($db, (string)$t['id']);

$return = aevSafeReturn((string)($_GET['return'] ?? $_POST['return'] ?? '')) ?: aevDetailPage((string)$survey['status'], $id);
$errors = [];
$curTypeIds = array_values(array_filter(array_map('intval', explode(',', (string)($survey['survey_type_ids'] ?? '')))));
if (!$curTypeIds && !empty($survey['survey_type_id'])) $curTypeIds = [(int)$survey['survey_type_id']];

$dateOnly = fn($v) => (!empty($v) && strpos((string)$v, '0000') !== 0) ? substr((string)$v, 0, 10) : '';
$form = [
    'vessel_name' => (string)$survey['vessel_name'],
    'imo_number' => (string)($survey['imo_number'] ?? ''),
    'report_number' => (string)($survey['report_number'] ?? ''),
    'client_id' => (int)$survey['client_id'],
    'agent_name' => (string)$survey['agent_name'],
    'port_id' => (int)$survey['port_id'],
    'type_ids' => $curTypeIds,
    'surveyor_ids' => $curSurveyorIds,
    'outsourcing' => !$curSurveyorIds,
    'assign_date' => (!empty($survey['assign_date']) && strpos((string)$survey['assign_date'], '0000') !== 0) ? date('Y-m-d\TH:i', strtotime($survey['assign_date'])) : '',
    'appointment_date' => $dateOnly($survey['appointment_date'] ?? ''),
    'survey_completed_date' => $dateOnly($survey['survey_completed_date'] ?? ''),
    'report_uploaded_date' => $dateOnly($survey['report_uploaded_date'] ?? ''),
    'status' => (string)$survey['status'],
    'recovery_amount' => $survey['recovery_amount'] === null ? '' : rtrim(rtrim((string)$survey['recovery_amount'], '0'), '.'),
    'vlsfo_recovery' => ($survey['vlsfo_recovery'] ?? null) === null ? '' : rtrim(rtrim((string)$survey['vlsfo_recovery'], '0'), '.'),
    'hsfo_recovery' => ($survey['hsfo_recovery'] ?? null) === null ? '' : rtrim(rtrim((string)$survey['hsfo_recovery'], '0'), '.'),
    'lsmgo_recovery' => ($survey['lsmgo_recovery'] ?? null) === null ? '' : rtrim(rtrim((string)$survey['lsmgo_recovery'], '0'), '.'),
    'remarks' => (string)($survey['remarks'] ?? ''),
    'notes' => (string)($survey['notes'] ?? ''),
    'custom_live_status' => (string)($survey['custom_live_status'] ?? ''),
];
foreach (['recovery_amount', 'vlsfo_recovery', 'hsfo_recovery', 'lsmgo_recovery'] as $k) if ($form[$k] === '-0') $form[$k] = '0';
foreach (['recovery_amount', 'vlsfo_recovery', 'hsfo_recovery', 'lsmgo_recovery'] as $k) if ($form[$k] === '' && isset($survey[$k]) && $survey[$k] !== null) $form[$k] = '0';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p = fn($k) => trim((string)($_POST[$k] ?? ''));
    $form = array_merge($form, [
        'vessel_name' => $p('vessel_name'), 'imo_number' => $p('imo_number'), 'report_number' => $p('report_number'),
        'client_id' => (int)$p('client_id'), 'agent_name' => $p('agent_name'), 'port_id' => (int)$p('port_id'),
        'type_ids' => array_values(array_unique(array_filter(array_map('intval', (array)($_POST['survey_type_ids'] ?? []))))),
        'surveyor_ids' => array_values(array_unique(array_filter(array_map('intval', (array)($_POST['surveyor_ids'] ?? []))))),
        'outsourcing' => !empty($_POST['outsourcing']),
        'assign_date' => $p('assign_date'), 'appointment_date' => $p('appointment_date'),
        'survey_completed_date' => $p('survey_completed_date'), 'report_uploaded_date' => $p('report_uploaded_date'),
        'status' => $p('status'),
        'recovery_amount' => $p('recovery_amount'), 'vlsfo_recovery' => $p('vlsfo_recovery'),
        'hsfo_recovery' => $p('hsfo_recovery'), 'lsmgo_recovery' => $p('lsmgo_recovery'),
        'remarks' => trim((string)($_POST['remarks'] ?? '')), 'notes' => trim((string)($_POST['notes'] ?? '')),
        'custom_live_status' => trim((string)($_POST['custom_live_status'] ?? '')),
    ]);
    $f = $form;

    // --- validation -------------------------------------------------------
    if ($f['vessel_name'] === '') $errors[] = 'Vessel Name is required.';
    elseif (mb_strlen($f['vessel_name']) > 255) $errors[] = 'Vessel Name is too long.';
    if (mb_strlen($f['imo_number']) > 20) $errors[] = 'IMO Number is too long (20 characters max).';
    if (mb_strlen($f['report_number']) > 50) $errors[] = 'Report No is too long (50 characters max).';
    if ($f['agent_name'] === '') $errors[] = 'Agent Name is required.';
    elseif (mb_strlen($f['agent_name']) > 255) $errors[] = 'Agent Name is too long.';
    if (!in_array($f['client_id'], array_map('intval', array_column($clients, 'id')), true)) $errors[] = 'Please choose a client.';
    if (!in_array($f['port_id'], array_map('intval', array_column($ports, 'id')), true)) $errors[] = 'Please choose a port.';
    $validTypes = array_map('intval', array_column($types, 'id'));
    if (!$f['type_ids']) $errors[] = 'Choose at least one survey type.';
    elseif (array_diff($f['type_ids'], $validTypes)) $errors[] = 'Unknown survey type selected.';
    $validSurveyors = array_map('intval', array_column($surveyors, 'id'));
    if ($f['outsourcing']) $f['surveyor_ids'] = [];
    if (!$f['outsourcing'] && !$f['surveyor_ids']) $errors[] = 'Choose at least one surveyor (or Outsourcing / Unassigned).';
    elseif (array_diff($f['surveyor_ids'], $validSurveyors)) $errors[] = 'Unknown surveyor selected.';
    if (!in_array($f['status'], AEV_STATUSES, true)) $errors[] = 'Unknown status.';

    $assignAt = aevDate($f['assign_date'], true);
    $apptAt = aevDate($f['appointment_date']);
    $scdAt = aevDate($f['survey_completed_date']);
    $rptAt = aevDate($f['report_uploaded_date']);
    foreach (['Assign date' => $assignAt, 'Appointment date' => $apptAt, 'Survey Completed date' => $scdAt, 'Report Completed date' => $rptAt] as $lbl => $v) {
        if ($v === null) $errors[] = $lbl . ' is not a valid date.';
    }
    if ($scdAt && $rptAt && $rptAt < $scdAt) $errors[] = 'Report Completed date cannot be before the Survey Completed date.';

    $statusChanged = $f['status'] !== (string)$survey['status'];
    if ($statusChanged && empty($_POST['confirm_status_change'])) {
        $errors[] = 'You changed the Status from "' . $survey['status'] . '" to "' . $f['status'] . '". Tick "Yes, change the status" to confirm, or set it back.';
    }
    if (in_array($f['status'], ['Pending Report', 'Completed'], true) && !$scdAt) $errors[] = 'A ' . $f['status'] . ' record needs a Survey Completed date.';
    if ($f['status'] === 'Completed' && !$rptAt) $errors[] = 'A Completed record needs a Report Completed date.';

    $qty = [];
    foreach (['recovery_amount' => 'Total recovery', 'vlsfo_recovery' => 'VLSFO', 'hsfo_recovery' => 'HSFO', 'lsmgo_recovery' => 'LSMGO'] as $k => $lbl) {
        $q = aevQty($f[$k]);
        if ($q === false) $errors[] = $lbl . ' must be a number.';
        $qty[$k] = $q === false ? null : $q;
    }

    if ($f['report_number'] !== '') {
        $dup = $db->prepare('SELECT id FROM surveys WHERE report_number = ? AND id <> ? LIMIT 1');
        $dup->execute([$f['report_number'], $id]);
        if ($dup->fetchColumn()) $errors[] = 'Report No "' . $f['report_number'] . '" is already used by another vessel.';
    }

    // Optional new Calculation Sheet (Excel) — recovery is re-read from it.
    $excel = $_FILES['excel_report'] ?? null;
    $excelChosen = $excel && ($excel['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($excelChosen) {
        $ext = strtolower(pathinfo((string)$excel['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls', 'xlsm'], true)) $errors[] = 'Calculation Sheet must be an .xlsx, .xls or .xlsm file.';
        elseif (($ve = upload_validate($excel, UPLOAD_MIMES_XLSX, 20 * 1024 * 1024)) !== '') $errors[] = 'Calculation Sheet: ' . $ve;
    }

    if (!$errors) {
        sort($f['type_ids']);
        $typeCsv = implode(',', $f['type_ids']);
        $typeNames = getSurveyTypeNamesArray($db, $typeCsv, '');
        $recNA = isRecoveryNotApplicable($db, $typeCsv);
        $notes = [];

        // Keep the original time of day when only the date is unchanged / re-picked.
        $withTime = function (?string $date, $old) {
            if ($date === '' || $date === null) return null;
            if (!empty($old) && strpos((string)$old, $date) === 0) return (string)$old;
            return $date . ' ' . (!empty($old) && strlen((string)$old) >= 19 ? substr((string)$old, 11, 8) : date('H:i:s'));
        };

        $excelRel = null; $excelOrig = '';
        try {
            if ($excelChosen) {
                $excelOrig = basename((string)$excel['name']);
                $excelName = time() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $excelOrig);
                $dir = __DIR__ . '/uploads/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                if (!move_uploaded_file($excel['tmp_name'], $dir . $excelName)) throw new RuntimeException('Could not store the Calculation Sheet.');
                $excelRel = 'uploads/' . $excelName;
                if (!$recNA) {
                    $rec = extractRecoveryFromExcel($dir . $excelName);
                    if (($rec['recovery'] ?? null) !== null || ($rec['vlsfo'] ?? null) !== null) {
                        $qty = ['recovery_amount' => $rec['recovery'] ?? null, 'vlsfo_recovery' => $rec['vlsfo'] ?? null,
                                'hsfo_recovery' => $rec['hsfo'] ?? null, 'lsmgo_recovery' => $rec['lsmgo'] ?? null];
                        $notes[] = 'recovery read from the Calculation Sheet';
                    } else {
                        $notes[] = 'no recovery figures found in the Calculation Sheet — typed values kept';
                    }
                }
            }
            if ($recNA) {
                $qty = array_fill_keys(array_keys($qty), null);
            } elseif ($qty['recovery_amount'] === null && ($qty['vlsfo_recovery'] !== null || $qty['hsfo_recovery'] !== null || $qty['lsmgo_recovery'] !== null)) {
                $qty['recovery_amount'] = round((float)$qty['vlsfo_recovery'] + (float)$qty['hsfo_recovery'] + (float)$qty['lsmgo_recovery'], 3);
            }

            $set = [
                'vessel_name' => $f['vessel_name'],
                'report_number' => $f['report_number'] !== '' ? $f['report_number'] : null,
                'imo_number' => $f['imo_number'] !== '' ? $f['imo_number'] : null,
                'client_id' => $f['client_id'],
                'agent_name' => $f['agent_name'],
                'surveyor_id' => $f['surveyor_ids'] ? $f['surveyor_ids'][0] : AEV_UNASSIGNED_SURVEYOR,
                'survey_type_id' => $f['type_ids'][0],
                'survey_type_ids' => $typeCsv,
                'port_id' => $f['port_id'],
                'assign_date' => $assignAt !== '' ? $assignAt : null,
                'appointment_date' => $apptAt !== '' ? $apptAt : null,
                'survey_completed_date' => $withTime($scdAt, $survey['survey_completed_date'] ?? null),
                'report_uploaded_date' => $withTime($rptAt, $survey['report_uploaded_date'] ?? null),
                'remarks' => $f['remarks'] !== '' ? $f['remarks'] : null,
                'notes' => $f['notes'] !== '' ? $f['notes'] : null,
                'custom_live_status' => $f['custom_live_status'] !== '' ? $f['custom_live_status'] : null,
                'recovery_amount' => $qty['recovery_amount'],
                'vlsfo_recovery' => $qty['vlsfo_recovery'],
                'hsfo_recovery' => $qty['hsfo_recovery'],
                'lsmgo_recovery' => $qty['lsmgo_recovery'],
            ];
            if ($statusChanged) {
                if ($f['status'] === 'Cancelled') {
                    // Same safety net the Cancel action uses (ajax/admin_surveys.php).
                    try { $db->exec("ALTER TABLE surveys MODIFY COLUMN status ENUM('Pending Vessel','Pending Report','Completed','Cancelled') DEFAULT 'Pending Vessel'"); } catch (Throwable $e) {}
                }
                $set['status'] = $f['status'];
                $set['status_updated_by'] = (int)$_SESSION['user_id'];
                $set['status_updated_at'] = date('Y-m-d H:i:s');
            }
            if ((string)$f['custom_live_status'] !== (string)($survey['custom_live_status'] ?? '') && !$statusChanged) {
                $set['status_updated_by'] = (int)$_SESSION['user_id'];
                $set['status_updated_at'] = date('Y-m-d H:i:s');
            }
            $set = array_intersect_key($set, $surveyCols); // only columns this database actually has

            $db->beginTransaction();
            $sql = 'UPDATE surveys SET ' . implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($set))) . ' WHERE id = ?';
            $db->prepare($sql)->execute([...array_values($set), $id]);
            $db->prepare('DELETE FROM survey_surveyors WHERE survey_id = ?')->execute([$id]);
            if ($f['surveyor_ids']) {
                $ins = $db->prepare('INSERT INTO survey_surveyors (survey_id, surveyor_id) VALUES (?, ?)');
                foreach ($f['surveyor_ids'] as $sid) $ins->execute([$id, $sid]);
            }
            if ($excelRel !== null) {
                $db->prepare("DELETE FROM uploads WHERE survey_id = ? AND file_type = 'Formal Report Excel'")->execute([$id]);
                $size = (int)($excel['size'] ?? 0);
                $label = $size >= 1048576 ? round($size / 1048576, 1) . ' MB' : max(1, (int)round($size / 1024)) . ' KB';
                $db->prepare("INSERT INTO uploads (survey_id, file_name, file_type, file_path, file_size) VALUES (?, ?, 'Formal Report Excel', ?, ?)")
                   ->execute([$id, $excelOrig, $excelRel, $label]);
            }
            $db->commit();

            error_log(sprintf('admin_edit_vessel: user %d edited survey %d%s', (int)$_SESSION['user_id'], $id,
                $statusChanged ? ' (status ' . $survey['status'] . ' -> ' . $f['status'] . ')' : ''));
            $_SESSION['flash_msg'] = 'Vessel details saved' . ($statusChanged ? ' · status changed to ' . $f['status'] : '')
                . ($recNA ? ' · recovery not applicable for ' . implode(' + ', $typeNames) : '')
                . ($notes ? ' · ' . implode(' · ', $notes) : '') . '.';
            $_SESSION['flash_type'] = 'success';
            header('Location: ' . ($statusChanged ? aevDetailPage($f['status'], $id) : $return));
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            if ($excelRel !== null && is_file(__DIR__ . '/' . $excelRel)) @unlink(__DIR__ . '/' . $excelRel);
            error_log('admin_edit_vessel save ' . $id . ': ' . $e->getMessage());
            $errors[] = 'Could not save the changes. Please try again.';
        }
    }
}

$uploads = [];
try {
    $us = $db->prepare('SELECT id, file_name, file_type, file_size, uploaded_at FROM uploads WHERE survey_id = ? ORDER BY id ASC');
    $us->execute([$id]);
    $uploads = $us->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    try {
        $us = $db->prepare('SELECT id, file_name, file_type, file_size FROM uploads WHERE survey_id = ? ORDER BY id ASC');
        $us->execute([$id]);
        $uploads = $us->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e2) { $uploads = []; }
}

$statusColors = ['Pending Vessel' => '#2563EB', 'Pending Report' => '#D97706', 'Completed' => '#16A34A', 'Cancelled' => '#DC2626'];
include 'includes/header.php';
?>
<style>
    .aev-wrap { max-width: 1100px; margin: 0 auto; padding: 0 12px 110px; }
    .aev-hero { position: relative; overflow: hidden; border-radius: 22px; background: linear-gradient(120deg, #0f172a 0%, #1E1B6B 48%, #4B2FD6 100%); padding: 20px 22px; margin: 12px 0 16px; display: flex; align-items: center; gap: 14px; box-shadow: 0 14px 34px rgba(30,27,107,.22); }
    .aev-hero-ico { width: 48px; height: 48px; border-radius: 14px; flex: none; background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.18); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 20px; }
    .aev-hero-title { font-size: 19px; font-weight: 800; color: #fff; word-break: break-word; }
    .aev-hero-sub { font-size: 12px; color: rgba(255,255,255,.72); margin-top: 3px; display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
    .aev-pill { display: inline-flex; align-items: center; gap: 5px; padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; background: #fff; }
    .aev-errors { background: #FEF2F2; border: 1.5px solid #FECACA; border-radius: 14px; padding: 12px 16px; color: #991B1B; font-size: 13px; margin-bottom: 14px; }
    .aev-errors ul { margin: 6px 0 0; padding-left: 18px; }
    .aev-grid { display: grid; grid-template-columns: 1fr; gap: 14px; }
    .aev-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 18px; padding: 16px 16px 6px; box-shadow: 0 4px 18px rgba(15,23,42,.05); }
    .aev-card-title { display: flex; align-items: center; gap: 10px; font-size: 14px; font-weight: 800; color: #0f172a; margin-bottom: 12px; }
    .aev-card-title i { width: 30px; height: 30px; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center; font-size: 13px; background: #EEF2FF; color: #4F46E5; }
    .aev-fields { display: grid; grid-template-columns: 1fr; gap: 0 14px; }
    .aev-f { margin-bottom: 12px; min-width: 0; }
    .aev-f.span-2 { grid-column: 1 / -1; }
    .aev-f label, .aev-label { display: block; font-size: 11.5px; font-weight: 700; color: #475569; margin-bottom: 5px; }
    .aev-f label .req { color: #DC2626; }
    .aev-input { width: 100%; border: 1.5px solid #e2e8f0; border-radius: 11px; padding: 9px 11px; font-size: 13.5px; background: #F8FAFC; color: #0f172a; min-width: 0; }
    .aev-input:focus { outline: none; border-color: #A78BFA; background: #fff; box-shadow: 0 0 0 3px rgba(91,45,238,.1); }
    textarea.aev-input { min-height: 74px; resize: vertical; }
    .aev-hint { font-size: 11px; color: #94a3b8; margin-top: 4px; }
    .aev-chips { display: flex; flex-wrap: wrap; gap: 7px; }
    .aev-chip { position: relative; }
    .aev-chip input { position: absolute; opacity: 0; pointer-events: none; }
    .aev-chip span { display: inline-flex; align-items: center; gap: 6px; border: 1.5px solid #e2e8f0; border-radius: 999px; padding: 6px 12px; font-size: 12.5px; font-weight: 600; color: #475569; background: #fff; cursor: pointer; user-select: none; }
    .aev-chip input:checked + span { background: #1E1B6B; border-color: #1E1B6B; color: #fff; }
    .aev-chip input:focus-visible + span { box-shadow: 0 0 0 3px rgba(91,45,238,.25); }
    .aev-chip .na { font-size: 9.5px; font-weight: 800; opacity: .75; }
    .aev-chip.inactive span { border-style: dashed; }
    .aev-rec-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 12px; }
    .aev-note { font-size: 12px; border-radius: 10px; padding: 8px 11px; margin-bottom: 12px; }
    .aev-note.info { background: #EFF6FF; color: #1E40AF; }
    .aev-note.warn { background: #FFFBEB; color: #92400E; }
    .aev-confirm { display: none; align-items: flex-start; gap: 8px; background: #FFF7ED; border: 1.5px solid #FED7AA; border-radius: 12px; padding: 10px 12px; font-size: 12.5px; color: #9A3412; margin-bottom: 12px; }
    .aev-confirm.show { display: flex; }
    .aev-files { list-style: none; padding: 0; margin: 0 0 12px; }
    .aev-files li { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 12.5px; }
    .aev-files li:last-child { border-bottom: none; }
    .aev-files .t { font-size: 10.5px; font-weight: 700; color: #64748b; background: #F1F5F9; border-radius: 6px; padding: 2px 7px; white-space: nowrap; }
    .aev-files .n { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #0f172a; }
    .aev-files a { color: #4B2FD6; }
    .aev-bar { position: fixed; left: 0; right: 0; bottom: 0; z-index: 1030; background: rgba(255,255,255,.96); border-top: 1px solid #e2e8f0; padding: 10px 16px calc(10px + env(safe-area-inset-bottom)); display: flex; gap: 10px; justify-content: flex-end; backdrop-filter: blur(6px); }
    .aev-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: none; border-radius: 12px; padding: 11px 20px; font-size: 13.5px; font-weight: 700; text-decoration: none; }
    .aev-btn.primary { background: linear-gradient(120deg, #1E1B6B, #4B2FD6); color: #fff; box-shadow: 0 8px 18px rgba(75,47,214,.28); }
    .aev-btn.ghost { background: #F1F5F9; color: #334155; }
    @media (max-width: 991.98px) {
        /* sit above the app's bottom navigation bar */
        .aev-bar { bottom: 76px; border-radius: 16px 16px 0 0; box-shadow: 0 -6px 18px rgba(15,23,42,.08); }
        .aev-wrap { padding-bottom: 190px; }
        .fnotes-fab { bottom: 156px !important; }
    }
    @media (min-width: 992px) { .fnotes-fab { bottom: 88px !important; } }
    @media (max-width: 767.98px) { .aev-bar .aev-btn { flex: 1; } }
    @media (min-width: 768px) {
        .aev-fields { grid-template-columns: 1fr 1fr; }
        .aev-rec-grid { grid-template-columns: repeat(4, 1fr); }
    }
    @media (min-width: 992px) {
        .top-app-bar { display: none !important; }
        .aev-wrap { padding: 8px 24px 110px; }
        .aev-grid { grid-template-columns: 1fr 1fr; align-items: start; }
        .aev-grid .full { grid-column: 1 / -1; }
        .aev-bar { left: 260px; }
    }
</style>

<?php
$page_title = 'Edit Vessel';
$back_url = $return;
$page_testid = 'admin-edit-vessel';
include 'includes/top_app_bar.php';
?>
<div class="scroll-content" data-testid="admin-edit-vessel-page">
<div class="aev-wrap">
    <div class="aev-hero">
        <div class="aev-hero-ico"><i class="fa-solid fa-pen-to-square"></i></div>
        <div style="min-width:0">
            <div class="aev-hero-title">Edit — <?= sanitize($survey['vessel_name']) ?></div>
            <div class="aev-hero-sub">
                <span class="aev-pill" style="color:<?= $statusColors[$survey['status']] ?? '#334155' ?>" data-testid="aev-current-status"><?= sanitize($survey['status']) ?></span>
                <?php if (!empty($survey['report_number'])): ?><span><?= sanitize($survey['report_number']) ?></span><?php endif; ?>
                <span>All fields can be changed by Admin</span>
            </div>
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="aev-errors" data-testid="aev-errors" role="alert"><b><i class="fa-solid fa-triangle-exclamation"></i> Nothing was saved — please fix:</b>
            <ul><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" id="aevForm" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <input type="hidden" name="return" value="<?= sanitize($return) ?>">
        <div class="aev-grid">

            <div class="aev-card">
                <div class="aev-card-title"><i class="fa-solid fa-ship"></i> Vessel &amp; Client</div>
                <div class="aev-fields">
                    <div class="aev-f span-2"><label for="aevVessel">Vessel Name <span class="req">*</span></label>
                        <input class="aev-input" id="aevVessel" name="vessel_name" maxlength="255" required value="<?= sanitize($form['vessel_name']) ?>" data-testid="aev-vessel-name"></div>
                    <div class="aev-f"><label for="aevImo">IMO Number</label>
                        <input class="aev-input" id="aevImo" name="imo_number" maxlength="20" value="<?= sanitize($form['imo_number']) ?>" data-testid="aev-imo"></div>
                    <div class="aev-f"><label for="aevReport">Report No</label>
                        <input class="aev-input" id="aevReport" name="report_number" maxlength="50" value="<?= sanitize($form['report_number']) ?>" data-testid="aev-report-number"></div>
                    <div class="aev-f span-2"><label for="aevClient">Client <span class="req">*</span></label>
                        <select class="aev-input" id="aevClient" name="client_id" required data-testid="aev-client">
                            <option value="">— Choose client —</option>
                            <?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (int)$c['id'] === (int)$form['client_id'] ? ' selected' : '' ?>><?= sanitize($c['company_name']) ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="aev-f span-2"><label for="aevAgent">Agent Name <span class="req">*</span></label>
                        <input class="aev-input" id="aevAgent" name="agent_name" maxlength="255" required value="<?= sanitize($form['agent_name']) ?>" data-testid="aev-agent"></div>
                </div>
            </div>

            <div class="aev-card">
                <div class="aev-card-title"><i class="fa-solid fa-file-circle-check" style="background:#F3EEFE;color:#5B2DEE"></i> Survey</div>
                <div class="aev-fields">
                    <div class="aev-f span-2"><label for="aevPort">Port <span class="req">*</span></label>
                        <select class="aev-input" id="aevPort" name="port_id" required data-testid="aev-port">
                            <option value="">— Choose port —</option>
                            <?php foreach ($ports as $pt): ?><option value="<?= (int)$pt['id'] ?>"<?= (int)$pt['id'] === (int)$form['port_id'] ? ' selected' : '' ?>><?= sanitize($pt['port_name'] . ($pt['country'] ? ' (' . $pt['country'] . ')' : '')) ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="aev-f span-2"><span class="aev-label">Survey Type(s) <span class="req" style="color:#DC2626">*</span></span>
                        <div class="aev-chips" data-testid="aev-types">
                            <?php foreach ($types as $t): $tid = (int)$t['id']; ?>
                            <label class="aev-chip"><input type="checkbox" name="survey_type_ids[]" value="<?= $tid ?>" data-na="<?= $typeNA[$tid] ? '1' : '0' ?>"<?= in_array($tid, $form['type_ids'], true) ? ' checked' : '' ?>><span><?= sanitize($t['type_name']) ?><?php if ($typeNA[$tid]): ?><em class="na">N/A REC</em><?php endif; ?></span></label>
                            <?php endforeach; ?>
                        </div></div>
                    <div class="aev-f span-2"><span class="aev-label">Surveyor(s) <span style="color:#DC2626">*</span></span>
                        <div class="aev-chips" data-testid="aev-surveyors">
                            <label class="aev-chip"><input type="checkbox" name="outsourcing" value="1" id="aevOutsourcing"<?= $form['outsourcing'] ? ' checked' : '' ?>><span><i class="fa-solid fa-user-slash"></i> Outsourcing / Unassigned</span></label>
                            <?php foreach ($surveyors as $sv): $sid = (int)$sv['id']; ?>
                            <label class="aev-chip<?= $sv['status'] !== 'Active' ? ' inactive' : '' ?>"><input type="checkbox" name="surveyor_ids[]" value="<?= $sid ?>" class="aev-sv"<?= in_array($sid, $form['surveyor_ids'], true) ? ' checked' : '' ?>><span><?= sanitize($sv['full_name']) ?><?= $sv['status'] !== 'Active' ? ' (inactive)' : '' ?></span></label>
                            <?php endforeach; ?>
                        </div>
                        <div class="aev-hint">The first selected surveyor is the primary one.</div></div>
                </div>
            </div>

            <div class="aev-card">
                <div class="aev-card-title"><i class="fa-solid fa-calendar-days" style="background:#ECFEFF;color:#0891B2"></i> Dates</div>
                <div class="aev-fields">
                    <div class="aev-f"><label for="aevAppt">Survey Assigned Date (Assign form)</label>
                        <input type="date" class="aev-input" id="aevAppt" name="appointment_date" value="<?= sanitize($form['appointment_date']) ?>" data-testid="aev-appointment-date"></div>
                    <div class="aev-f"><label for="aevAssign">Created on (system)</label>
                        <input type="datetime-local" class="aev-input" id="aevAssign" name="assign_date" value="<?= sanitize($form['assign_date']) ?>" data-testid="aev-assign-date"></div>
                    <div class="aev-f"><label for="aevScd">Survey Completed Date</label>
                        <input type="date" class="aev-input" id="aevScd" name="survey_completed_date" value="<?= sanitize($form['survey_completed_date']) ?>" data-testid="aev-survey-completed-date"></div>
                    <div class="aev-f"><label for="aevRpt">Report Completed Date</label>
                        <input type="date" class="aev-input" id="aevRpt" name="report_uploaded_date" value="<?= sanitize($form['report_uploaded_date']) ?>" data-testid="aev-report-date"></div>
                </div>
                <div class="aev-hint" style="margin:-4px 0 12px">Pending Vessels are ordered by Survey Assigned date, Pending Reports by Survey Completed date, Completed by Report Completed date.</div>
            </div>

            <div class="aev-card">
                <div class="aev-card-title"><i class="fa-solid fa-flag" style="background:#FFF7ED;color:#EA580C"></i> Status</div>
                <div class="aev-fields">
                    <div class="aev-f span-2"><label for="aevStatus">Status</label>
                        <select class="aev-input" id="aevStatus" name="status" data-original="<?= sanitize($survey['status']) ?>" data-testid="aev-status">
                            <?php foreach (AEV_STATUSES as $s): ?><option value="<?= sanitize($s) ?>"<?= $s === $form['status'] ? ' selected' : '' ?>><?= sanitize($s) ?></option><?php endforeach; ?>
                        </select>
                        <div class="aev-hint">Changing this moves the vessel to another list. It only changes when you confirm below.</div></div>
                </div>
                <label class="aev-confirm<?= $form['status'] !== $survey['status'] ? ' show' : '' ?>" id="aevConfirm" data-testid="aev-confirm-box">
                    <input type="checkbox" name="confirm_status_change" value="1" data-testid="aev-confirm-status">
                    <span><b>Yes, change the status</b> from <b><?= sanitize($survey['status']) ?></b> to <b id="aevNewStatus"><?= sanitize($form['status']) ?></b>. The vessel will move to that list.</span>
                </label>
                <div class="aev-fields">
                    <div class="aev-f span-2"><label for="aevLive">Latest Status Update</label>
                        <textarea class="aev-input" id="aevLive" name="custom_live_status" maxlength="2000" data-testid="aev-live-status"><?= sanitize($form['custom_live_status']) ?></textarea></div>
                </div>
            </div>

            <div class="aev-card full">
                <div class="aev-card-title"><i class="fa-solid fa-droplet" style="background:#ECFEFF;color:#0891B2"></i> Recovery (MT)</div>
                <div class="aev-note info" id="aevNaNote" style="display:none" data-testid="aev-na-note"><i class="fa-solid fa-circle-info"></i> Recovery is not applicable for the selected survey type(s) — it will be saved as <?= sanitize(RECOVERY_NA_LABEL) ?>.</div>
                <div class="aev-rec-grid" id="aevRecFields">
                    <div class="aev-f"><label for="aevVlsfo">VLSFO</label><input type="text" inputmode="decimal" class="aev-input aev-part" id="aevVlsfo" name="vlsfo_recovery" value="<?= sanitize($form['vlsfo_recovery']) ?>" data-testid="aev-vlsfo"></div>
                    <div class="aev-f"><label for="aevHsfo">HSFO</label><input type="text" inputmode="decimal" class="aev-input aev-part" id="aevHsfo" name="hsfo_recovery" value="<?= sanitize($form['hsfo_recovery']) ?>" data-testid="aev-hsfo"></div>
                    <div class="aev-f"><label for="aevLsmgo">LSMGO</label><input type="text" inputmode="decimal" class="aev-input aev-part" id="aevLsmgo" name="lsmgo_recovery" value="<?= sanitize($form['lsmgo_recovery']) ?>" data-testid="aev-lsmgo"></div>
                    <div class="aev-f"><label for="aevTotal">Total</label><input type="text" inputmode="decimal" class="aev-input" id="aevTotal" name="recovery_amount" value="<?= sanitize($form['recovery_amount']) ?>" style="font-weight:800;color:#1E1B6B" data-testid="aev-total"></div>
                </div>
                <div class="aev-hint" style="margin:-4px 0 12px">Leave a box empty for "not recorded". A blank Total is filled from VLSFO + HSFO + LSMGO.</div>
            </div>

            <div class="aev-card">
                <div class="aev-card-title"><i class="fa-solid fa-paperclip" style="background:#D1FAE5;color:#047857"></i> Report &amp; Files</div>
                <?php if ($uploads): ?>
                <ul class="aev-files" data-testid="aev-files">
                    <?php foreach ($uploads as $u): ?>
                    <li><span class="t"><?= sanitize($u['file_type']) ?></span><span class="n" title="<?= sanitize($u['file_name']) ?>"><?= sanitize($u['file_name']) ?></span>
                        <a href="download.php?upload_id=<?= (int)$u['id'] ?>&inline=1" target="_blank" rel="noopener" title="View"><i class="fa-solid fa-eye"></i></a></li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?><div class="aev-note warn">No files uploaded yet.</div><?php endif; ?>
                <div class="aev-f"><label for="aevExcel">Replace Calculation Sheet (Excel)</label>
                    <input type="file" class="aev-input" id="aevExcel" name="excel_report" accept=".xlsx,.xls,.xlsm" data-testid="aev-excel">
                    <div class="aev-hint">Optional. Recovery is re-read from the new sheet (TABLES 54B) and replaces the values above.</div></div>
                <div class="aev-hint" style="margin-bottom:12px">Other documents can be replaced from the vessel's detail page (<a href="<?= sanitize(aevDetailPage((string)$survey['status'], $id)) ?>">open</a>).</div>
            </div>

            <div class="aev-card">
                <div class="aev-card-title"><i class="fa-solid fa-note-sticky" style="background:#FEF3C7;color:#B45309"></i> Remarks &amp; Notes</div>
                <div class="aev-fields">
                    <div class="aev-f span-2"><label for="aevRemarks">Remarks</label>
                        <textarea class="aev-input" id="aevRemarks" name="remarks" maxlength="5000" data-testid="aev-remarks"><?= sanitize($form['remarks']) ?></textarea></div>
                    <div class="aev-f span-2"><label for="aevNotes">Notes</label>
                        <textarea class="aev-input" id="aevNotes" name="notes" maxlength="5000" data-testid="aev-notes"><?= sanitize($form['notes']) ?></textarea></div>
                </div>
            </div>
        </div>

        <div class="aev-bar">
            <a href="<?= sanitize($return) ?>" class="aev-btn ghost" data-testid="aev-cancel">Cancel</a>
            <button type="submit" class="aev-btn primary" data-testid="aev-save"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
        </div>
    </form>
</div>
</div>
<script>
(function () {
    var form = document.getElementById('aevForm');
    var status = document.getElementById('aevStatus'), box = document.getElementById('aevConfirm');
    status.addEventListener('change', function () {
        var changed = status.value !== status.getAttribute('data-original');
        box.classList.toggle('show', changed);
        document.getElementById('aevNewStatus').textContent = status.value;
        if (!changed) box.querySelector('input').checked = false;
    });
    // Outsourcing and named surveyors are mutually exclusive.
    var out = document.getElementById('aevOutsourcing');
    out.addEventListener('change', function () { if (out.checked) form.querySelectorAll('.aev-sv').forEach(function (c) { c.checked = false; }); });
    form.querySelectorAll('.aev-sv').forEach(function (c) { c.addEventListener('change', function () { if (c.checked) out.checked = false; }); });
    // Recovery N/A hint — mirrors the server rule (every selected type is N/A).
    function naCheck() {
        var sel = form.querySelectorAll('input[name="survey_type_ids[]"]:checked');
        var allNa = sel.length > 0;
        sel.forEach(function (c) { if (c.getAttribute('data-na') !== '1') allNa = false; });
        document.getElementById('aevNaNote').style.display = allNa ? '' : 'none';
        document.getElementById('aevRecFields').style.opacity = allNa ? '.45' : '';
    }
    form.querySelectorAll('input[name="survey_type_ids[]"]').forEach(function (c) { c.addEventListener('change', naCheck); });
    naCheck();
    form.addEventListener('submit', function (e) {
        if (form.dataset.sent) { e.preventDefault(); return; }
        form.dataset.sent = '1';
    });
})();
</script>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>

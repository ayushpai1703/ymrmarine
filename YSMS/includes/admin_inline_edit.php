<?php
/**
 * Admin inline edit — a small pencil next to every field on the vessel
 * detail pages (vessel_detail / report_detail / completed_detail, which also
 * shows Cancelled vessels). Clicking it opens a popup for just that field and
 * saves through ajax/admin_inline_edit.php. Files get Rename / Change type /
 * Delete next to the existing Replace button.
 *
 * Page usage (Admin only — every helper returns '' for other roles):
 *   aieInit($db, $survey);           once, after the survey row is loaded
 *   <?= aie('vessel_name') ?>        pencil button beside a label
 *   <?= aieFileButtons($file) ?>     file actions beside Replace
 *   <?php aieRender(); ?>            popup + script, once near the footer
 */

const AIE_UNASSIGNED_SURVEYOR = 1; // outsourcing / unassigned placeholder (assign_vessel.php)

const AIE_FIELDS = [
    'vessel_name' => 'Vessel Name', 'report_number' => 'Report No',
    'client_id' => 'Client', 'agent_name' => 'Agent Name', 'port_id' => 'Port',
    'survey_type_ids' => 'Survey Type(s)', 'surveyors' => 'Surveyor(s)',
    'appointment_date' => 'Survey Assigned Date', 'assign_date' => 'Assigned Date',
    'survey_completed_date' => 'Survey Completed Date', 'report_uploaded_date' => 'Report Completed Date',
    'recovery' => 'Recovery', 'remarks' => 'Remarks',
];

/**
 * Fields an Admin may edit, per stage — exactly what that stage's detail
 * page displays. Later-stage fields (e.g. Survey / Report Completed dates on
 * a Pending Vessel) are not editable from an earlier stage. Enforced both
 * when drawing the pencils and again on save.
 */
const AIE_STAGE_FIELDS = [
    // vessel_detail.php (Pending Vessels; Cancelled vessels open the same page)
    'Pending Vessel' => ['vessel_name', 'report_number', 'client_id', 'survey_type_ids', 'assign_date', 'agent_name', 'surveyors', 'port_id', 'remarks'],
    'Cancelled'      => ['vessel_name', 'report_number', 'client_id', 'survey_type_ids', 'assign_date', 'agent_name', 'surveyors', 'port_id', 'remarks'],
    // report_detail.php
    'Pending Report' => ['vessel_name', 'port_id', 'report_number', 'client_id', 'surveyors', 'survey_type_ids', 'survey_completed_date', 'recovery'],
    // completed_detail.php
    'Completed'      => ['vessel_name', 'client_id', 'appointment_date', 'survey_completed_date', 'report_uploaded_date', 'report_number', 'survey_type_ids', 'port_id', 'agent_name', 'recovery'],
];

function aieAllowedFields(string $status): array { return AIE_STAGE_FIELDS[$status] ?? []; }

function aieIsAdmin(): bool { return ($_SESSION['role'] ?? '') === 'Admin'; }

function aieInit(PDO $db, array $survey): void {
    $GLOBALS['__aie'] = ['db' => $db, 'survey' => $survey];
}

function aie(string $field): string {
    if (!aieIsAdmin() || empty($GLOBALS['__aie']) || !isset(AIE_FIELDS[$field])) return '';
    if (!in_array($field, aieAllowedFields((string)$GLOBALS['__aie']['survey']['status']), true)) return '';
    $label = AIE_FIELDS[$field];
    return '<button type="button" class="aie-btn" data-aie="' . htmlspecialchars($field, ENT_QUOTES) . '" title="Edit ' . htmlspecialchars($label, ENT_QUOTES)
        . '" aria-label="Edit ' . htmlspecialchars($label, ENT_QUOTES) . '" data-testid="aie-' . htmlspecialchars($field, ENT_QUOTES) . '"><i class="fa-solid fa-pen"></i></button>';
}

function aieFileButtons(array $file): string {
    if (!aieIsAdmin()) return '';
    $id = (int)$file['id'];
    $name = htmlspecialchars((string)$file['file_name'], ENT_QUOTES);
    $type = htmlspecialchars((string)$file['file_type'], ENT_QUOTES);
    return '<button type="button" class="aie-file-btn" data-aie-file="rename" data-file-id="' . $id . '" data-file-name="' . $name . '" data-file-type="' . $type . '" title="Rename" data-testid="aie-file-rename-' . $id . '"><i class="fa-solid fa-pen"></i></button>'
         . '<button type="button" class="aie-file-btn" data-aie-file="type" data-file-id="' . $id . '" data-file-name="' . $name . '" data-file-type="' . $type . '" title="Change file type" data-testid="aie-file-type-' . $id . '"><i class="fa-solid fa-tag"></i></button>'
         . '<button type="button" class="aie-file-btn danger" data-aie-file="delete" data-file-id="' . $id . '" data-file-name="' . $name . '" data-file-type="' . $type . '" title="Delete file" data-testid="aie-file-delete-' . $id . '"><i class="fa-solid fa-trash"></i></button>';
}

function aieFileTypes(PDO $db): array {
    try {
        $c = $db->query("SHOW COLUMNS FROM uploads LIKE 'file_type'")->fetch(PDO::FETCH_ASSOC);
        if ($c && preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", (string)$c['Type'], $m)) return $m[1];
    } catch (Throwable $e) {}
    return ['Field Report', 'Formal Report PDF', 'Formal Report Excel', 'Formal Report Word'];
}

/** Everything the popup needs: current values and the option lists. */
function aieData(PDO $db, array $survey): array {
    $id = (int)$survey['id'];
    $sv = [];
    try {
        $st = $db->prepare('SELECT surveyor_id FROM survey_surveyors WHERE survey_id = ? ORDER BY id ASC');
        $st->execute([$id]);
        $sv = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {}
    if (!$sv && (int)$survey['surveyor_id'] !== AIE_UNASSIGNED_SURVEYOR) $sv = [(int)$survey['surveyor_id']];
    $types = array_values(array_filter(array_map('intval', explode(',', (string)($survey['survey_type_ids'] ?? '')))));
    if (!$types && !empty($survey['survey_type_id'])) $types = [(int)$survey['survey_type_id']];
    $d = fn($v) => (!empty($v) && strpos((string)$v, '0000') !== 0) ? substr((string)$v, 0, 10) : '';
    $q = fn($v) => $v === null ? '' : rtrim(rtrim(number_format((float)$v, 3, '.', ''), '0'), '.');
    $svIn = $sv ? implode(',', $sv) : '0';
    $typeRows = $db->query('SELECT id, type_name FROM survey_types ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($typeRows as &$t) { $t['id'] = (int)$t['id']; $t['na'] = isRecoveryNotApplicable($db, (string)$t['id']); }
    unset($t);
    return [
        'id' => $id,
        'values' => [
            'vessel_name' => (string)$survey['vessel_name'],
            'report_number' => (string)($survey['report_number'] ?? ''), 'client_id' => (int)$survey['client_id'],
            'agent_name' => (string)$survey['agent_name'], 'port_id' => (int)$survey['port_id'],
            'survey_type_ids' => $types, 'surveyor_ids' => $sv, 'outsourcing' => !$sv,
            'appointment_date' => $d($survey['appointment_date'] ?? ''),
            'assign_date' => (!empty($survey['assign_date']) && strpos((string)$survey['assign_date'], '0000') !== 0) ? date('Y-m-d\TH:i', strtotime($survey['assign_date'])) : '',
            'survey_completed_date' => $d($survey['survey_completed_date'] ?? ''), 'report_uploaded_date' => $d($survey['report_uploaded_date'] ?? ''),
            'recovery_amount' => $q($survey['recovery_amount'] ?? null), 'vlsfo_recovery' => $q($survey['vlsfo_recovery'] ?? null),
            'hsfo_recovery' => $q($survey['hsfo_recovery'] ?? null), 'lsmgo_recovery' => $q($survey['lsmgo_recovery'] ?? null),
            'remarks' => (string)($survey['remarks'] ?? ''),
        ],
        'labels' => AIE_FIELDS,
        'clients' => $db->query('SELECT id, company_name AS name FROM clients ORDER BY company_name ASC')->fetchAll(PDO::FETCH_ASSOC),
        'ports' => $db->query("SELECT id, CONCAT(port_name, IF(country IS NULL OR country = '', '', CONCAT(' (', country, ')'))) AS name FROM ports ORDER BY port_name ASC")->fetchAll(PDO::FETCH_ASSOC),
        'types' => $typeRows,
        'surveyors' => $db->query("SELECT u.id, u.full_name AS name, u.status FROM users u JOIN roles r ON r.id = u.role_id
            WHERE r.name = 'Surveyor' AND u.id <> " . AIE_UNASSIGNED_SURVEYOR . " AND (u.status = 'Active' OR u.id IN ($svIn)) ORDER BY u.full_name ASC")->fetchAll(PDO::FETCH_ASSOC),
        'fileTypes' => aieFileTypes($db),
        'today' => date('Y-m-d'),
        'naLabel' => defined('RECOVERY_NA_LABEL') ? RECOVERY_NA_LABEL : 'N/A',
        'csrf' => function_exists('csrf_token') ? csrf_token() : '',
    ];
}

/** "Add files" for the Pre-Uploaded Survey Files list (Pending Report stage only). */
function aieAddFilesButton(): string {
    if (!aieIsAdmin() || empty($GLOBALS['__aie']) || ($GLOBALS['__aie']['survey']['status'] ?? '') !== 'Pending Report') return '';
    return '<button type="button" class="aie-add-files" data-aie-add-files data-testid="aie-add-files"><i class="fa-solid fa-plus"></i> Add Files</button>';
}

const AIE_ADD_FILE_EXT = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'xlsm', 'jpg', 'jpeg', 'png', 'zip'];
const AIE_ADD_FILE_MAX = 30 * 1024 * 1024;

/**
 * Add one or more files to a Pending Report's pre-uploaded files.
 * $files is $_FILES['files'] (multiple). Optionally re-reads recovery from
 * an added Calculation Sheet.
 */
function aieAddFiles(PDO $db, array $s, array $files, string $type, bool $readRecovery, int $actorId): array {
    if (($s['status'] ?? '') !== 'Pending Report') return ['ok' => false, 'message' => 'Files can be added here only while the vessel is in Pending Reports.'];
    if (!in_array($type, aieFileTypes($db), true)) return ['ok' => false, 'message' => 'Unknown file type.'];
    $list = [];
    if (isset($files['name']) && is_array($files['name'])) {
        foreach ($files['name'] as $i => $n) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            $list[] = ['name' => $n, 'type' => $files['type'][$i] ?? '', 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
        }
    }
    if (!$list) return ['ok' => false, 'message' => 'Please choose at least one file.'];
    $typeExt = ['Formal Report PDF' => ['pdf'], 'Formal Report Excel' => ['xls', 'xlsx', 'xlsm'], 'Formal Report Word' => ['doc', 'docx']];
    foreach ($list as $f) {
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, AIE_ADD_FILE_EXT, true)) return ['ok' => false, 'message' => '"' . $f['name'] . '": file type not allowed (PDF, Word, Excel, JPG/PNG or ZIP).'];
        if (isset($typeExt[$type]) && !in_array($ext, $typeExt[$type], true)) return ['ok' => false, 'message' => '"' . $f['name'] . '" is not a ' . $type . ' file.'];
        $mimes = function_exists('documentReplaceMimeSetFor') ? documentReplaceMimeSetFor($ext) : [];
        if (($ve = upload_validate($f, $mimes, AIE_ADD_FILE_MAX)) !== '') return ['ok' => false, 'message' => '"' . $f['name'] . '": ' . $ve];
    }
    $dir = dirname(__DIR__) . '/uploads/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $hasBy = (bool)$db->query("SHOW COLUMNS FROM uploads LIKE 'uploaded_by'")->fetch();
    $ins = $db->prepare($hasBy
        ? 'INSERT INTO uploads (survey_id, file_name, file_type, file_path, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)'
        : 'INSERT INTO uploads (survey_id, file_name, file_type, file_path, file_size) VALUES (?, ?, ?, ?, ?)');
    $saved = []; $recMsg = '';
    foreach ($list as $i => $f) {
        $orig = basename((string)$f['name']);
        $name = time() . '_' . $i . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $orig);
        if (!move_uploaded_file($f['tmp_name'], $dir . $name)) continue;
        $size = (int)$f['size'];
        $label = $size >= 1048576 ? round($size / 1048576, 1) . ' MB' : max(1, (int)round($size / 1024)) . ' KB';
        $args = [(int)$s['id'], $orig, $type, 'uploads/' . $name, $label];
        if ($hasBy) $args[] = $actorId;
        $ins->execute($args);
        $saved[] = $orig;
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if ($readRecovery && $recMsg === '' && in_array($ext, ['xls', 'xlsx', 'xlsm'], true)
            && !isRecoveryNotApplicable($db, (string)($s['survey_type_ids'] ?: $s['survey_type_id']))) {
            $rec = extractRecoveryFromExcel($dir . $name);
            if (($rec['recovery'] ?? null) !== null || ($rec['vlsfo'] ?? null) !== null) {
                $db->prepare('UPDATE surveys SET recovery_amount = ?, vlsfo_recovery = ?, hsfo_recovery = ?, lsmgo_recovery = ? WHERE id = ?')
                   ->execute([$rec['recovery'] ?? null, $rec['vlsfo'] ?? null, $rec['hsfo'] ?? null, $rec['lsmgo'] ?? null, (int)$s['id']]);
                $recMsg = ' · recovery read from ' . $orig;
            } else {
                $recMsg = ' · no recovery figures found in ' . $orig;
            }
        }
    }
    if (!$saved) return ['ok' => false, 'message' => 'Could not store the files. Please try again.'];
    error_log(sprintf('admin inline edit: user %d added %d file(s) to survey %d', $actorId, count($saved), (int)$s['id']));
    return ['ok' => true, 'message' => count($saved) . ' file' . (count($saved) === 1 ? '' : 's') . ' added (' . $type . ')' . $recMsg . '.'];
}

/* ------------------------------------------------------------------ */
/* Server side: validate + save ONE field (used by the AJAX endpoint).  */
/* ------------------------------------------------------------------ */

function aieDate(string $v, bool $withTime = false): ?string {
    $v = trim($v);
    if ($v === '') return '';
    $fmt = $withTime ? 'Y-m-d\TH:i' : 'Y-m-d';
    $d = DateTime::createFromFormat('!' . $fmt, $v);
    if (!$d || $d->format($fmt) !== $v || (int)$d->format('Y') < 2000 || (int)$d->format('Y') > 2100) return null;
    return $d->format($withTime ? 'Y-m-d H:i:s' : 'Y-m-d');
}

/** Keep the stored time of day when only the date is (re)picked. */
function aieWithTime(string $date, $old): ?string {
    if ($date === '') return null;
    if (!empty($old) && strpos((string)$old, $date) === 0) return (string)$old;
    return $date . ' ' . (!empty($old) && strlen((string)$old) >= 19 && strpos((string)$old, '0000') !== 0 ? substr((string)$old, 11, 8) : date('H:i:s'));
}

function aieQty(string $v) {
    $v = str_replace([',', ' '], '', trim($v));
    if ($v === '') return null;
    return is_numeric($v) ? round((float)$v, 3) : false;
}

/**
 * @return array{ok: bool, message: string, redirect?: string}
 */
function aieSaveField(PDO $db, array $s, string $field, array $post, array $files, int $actorId): array {
    $id = (int)$s['id'];
    $p = fn($k) => trim((string)($post[$k] ?? ''));
    $err = fn($m) => ['ok' => false, 'message' => $m];
    $cols = array_flip(array_column($db->query('SHOW COLUMNS FROM surveys')->fetchAll(PDO::FETCH_ASSOC), 'Field'));
    $set = [];
    $msg = (AIE_FIELDS[$field] ?? 'Field') . ' updated.';
    $redirect = null;
    $extra = null; // callable run inside the transaction
    if (!in_array($field, aieAllowedFields((string)$s['status']), true)) {
        return $err('This field cannot be edited at the ' . $s['status'] . ' stage.');
    }

    switch ($field) {
        case 'vessel_name':
        case 'agent_name':
            $v = $p('value');
            if ($v === '') return $err(AIE_FIELDS[$field] . ' is required.');
            if (mb_strlen($v) > 255) return $err(AIE_FIELDS[$field] . ' is too long.');
            $set[$field] = $v;
            break;
        case 'report_number':
            $v = $p('value');
            if (mb_strlen($v) > 50) return $err('Report No is too long (50 characters max).');
            if ($v !== '') {
                $d = $db->prepare('SELECT id FROM surveys WHERE report_number = ? AND id <> ? LIMIT 1');
                $d->execute([$v, $id]);
                if ($d->fetchColumn()) return $err('Report No "' . $v . '" is already used by another vessel.');
            }
            $set[$field] = $v !== '' ? $v : null;
            break;
        case 'remarks':
            $v = trim((string)($post['value'] ?? ''));
            if (mb_strlen($v) > 5000) return $err(AIE_FIELDS[$field] . ' is too long.');
            $set[$field] = $v !== '' ? $v : null;
            break;
        case 'client_id':
        case 'port_id':
            $v = (int)$p('value');
            $t = $field === 'client_id' ? 'clients' : 'ports';
            $c = $db->prepare("SELECT id FROM `$t` WHERE id = ?");
            $c->execute([$v]);
            if (!$c->fetchColumn()) return $err('Please choose a ' . ($field === 'client_id' ? 'client' : 'port') . '.');
            $set[$field] = $v;
            break;
        case 'survey_type_ids':
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($post['values'] ?? [])))));
            if (!$ids) return $err('Choose at least one survey type.');
            $in = implode(',', $ids);
            if ((int)$db->query("SELECT COUNT(*) FROM survey_types WHERE id IN ($in)")->fetchColumn() !== count($ids)) return $err('Unknown survey type selected.');
            sort($ids);
            $csv = implode(',', $ids);
            $set['survey_type_id'] = $ids[0];
            $set['survey_type_ids'] = $csv;
            if (isRecoveryNotApplicable($db, $csv)) {
                $set += ['recovery_amount' => null, 'vlsfo_recovery' => null, 'hsfo_recovery' => null, 'lsmgo_recovery' => null];
                $msg = 'Survey type updated · recovery is not applicable for this type.';
            }
            break;
        case 'surveyors':
            $out = !empty($post['outsourcing']);
            $ids = $out ? [] : array_values(array_unique(array_filter(array_map('intval', (array)($post['values'] ?? [])))));
            if (!$out && !$ids) return $err('Choose at least one surveyor (or Outsourcing / Unassigned).');
            if ($ids) {
                $in = implode(',', $ids);
                $n = (int)$db->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'Surveyor' AND u.id IN ($in)")->fetchColumn();
                if ($n !== count($ids)) return $err('Unknown surveyor selected.');
            }
            $set['surveyor_id'] = $ids ? $ids[0] : AIE_UNASSIGNED_SURVEYOR;
            $extra = function () use ($db, $id, $ids) {
                $db->prepare('DELETE FROM survey_surveyors WHERE survey_id = ?')->execute([$id]);
                $ins = $db->prepare('INSERT INTO survey_surveyors (survey_id, surveyor_id) VALUES (?, ?)');
                foreach ($ids as $sid) $ins->execute([$id, $sid]);
            };
            break;
        case 'appointment_date':
        case 'assign_date':
            $v = aieDate($p('value'), $field === 'assign_date');
            if ($v === null) return $err(AIE_FIELDS[$field] . ' is not a valid date.');
            $set[$field] = $v !== '' ? $v : null;
            break;
        case 'survey_completed_date':
        case 'report_uploaded_date':
            $v = aieDate($p('value'));
            if ($v === null) return $err(AIE_FIELDS[$field] . ' is not a valid date.');
            $st = (string)$s['status'];
            if ($v === '' && ($field === 'survey_completed_date' ? in_array($st, ['Pending Report', 'Completed'], true) : $st === 'Completed')) {
                return $err('A ' . $st . ' record needs a ' . AIE_FIELDS[$field] . '.');
            }
            $scd = $field === 'survey_completed_date' ? $v : substr((string)($s['survey_completed_date'] ?? ''), 0, 10);
            $rpt = $field === 'report_uploaded_date' ? $v : substr((string)($s['report_uploaded_date'] ?? ''), 0, 10);
            if ($scd && $rpt && strpos($rpt, '0000') !== 0 && strpos($scd, '0000') !== 0 && $rpt < $scd) {
                return $err('Report Completed date cannot be before the Survey Completed date.');
            }
            $set[$field] = aieWithTime($v, $s[$field] ?? null);
            break;
        case 'recovery':
            $typeCsv = (string)($s['survey_type_ids'] ?: $s['survey_type_id']);
            if (isRecoveryNotApplicable($db, $typeCsv)) return $err('Recovery is not applicable for this survey type. Change the survey type first.');
            $q = [];
            foreach (['recovery_amount' => 'Total', 'vlsfo_recovery' => 'VLSFO', 'hsfo_recovery' => 'HSFO', 'lsmgo_recovery' => 'LSMGO'] as $k => $lbl) {
                $v = aieQty($p($k));
                if ($v === false) return $err($lbl . ' must be a number.');
                $q[$k] = $v;
            }
            $excel = $files['excel_report'] ?? null;
            if ($excel && ($excel['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $ext = strtolower(pathinfo((string)$excel['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['xlsx', 'xls', 'xlsm'], true)) return $err('Calculation Sheet must be an .xlsx, .xls or .xlsm file.');
                if (($ve = upload_validate($excel, UPLOAD_MIMES_XLSX, 20 * 1024 * 1024)) !== '') return $err('Calculation Sheet: ' . $ve);
                $orig = basename((string)$excel['name']);
                $name = time() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $orig);
                $dir = dirname(__DIR__) . '/uploads/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                if (!move_uploaded_file($excel['tmp_name'], $dir . $name)) return $err('Could not store the Calculation Sheet.');
                $rec = extractRecoveryFromExcel($dir . $name);
                if (($rec['recovery'] ?? null) !== null || ($rec['vlsfo'] ?? null) !== null) {
                    $q = ['recovery_amount' => $rec['recovery'] ?? null, 'vlsfo_recovery' => $rec['vlsfo'] ?? null, 'hsfo_recovery' => $rec['hsfo'] ?? null, 'lsmgo_recovery' => $rec['lsmgo'] ?? null];
                    $msg = 'Recovery updated from the Calculation Sheet.';
                } else {
                    $msg = 'Calculation Sheet saved · no recovery figures found in it, typed values kept.';
                }
                $size = (int)($excel['size'] ?? 0);
                $label = $size >= 1048576 ? round($size / 1048576, 1) . ' MB' : max(1, (int)round($size / 1024)) . ' KB';
                $extra = function () use ($db, $id, $orig, $name, $label) {
                    $db->prepare("DELETE FROM uploads WHERE survey_id = ? AND file_type = 'Formal Report Excel'")->execute([$id]);
                    $db->prepare("INSERT INTO uploads (survey_id, file_name, file_type, file_path, file_size) VALUES (?, ?, 'Formal Report Excel', ?, ?)")
                       ->execute([$id, $orig, 'uploads/' . $name, $label]);
                };
            }
            if ($q['recovery_amount'] === null && ($q['vlsfo_recovery'] !== null || $q['hsfo_recovery'] !== null || $q['lsmgo_recovery'] !== null)) {
                $q['recovery_amount'] = round((float)$q['vlsfo_recovery'] + (float)$q['hsfo_recovery'] + (float)$q['lsmgo_recovery'], 3);
            }
            $set = $q;
            break;
        default:
            return $err('This field cannot be edited.');
    }

    $set = array_intersect_key($set, $cols);
    try {
        $db->beginTransaction();
        if ($set) {
            $db->prepare('UPDATE surveys SET ' . implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($set))) . ' WHERE id = ?')
               ->execute([...array_values($set), $id]);
        }
        if ($extra) $extra();
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('aieSaveField ' . $field . ' #' . $id . ': ' . $e->getMessage());
        return $err('Could not save. Please try again.');
    }
    error_log(sprintf('admin inline edit: user %d set %s on survey %d', $actorId, $field, $id));
    return ['ok' => true, 'message' => $msg] + ($redirect ? ['redirect' => $redirect] : []);
}

/** Rename / change type / delete one uploaded file. */
function aieFileAction(PDO $db, int $fileId, string $action, array $post): array {
    $st = $db->prepare('SELECT * FROM uploads WHERE id = ?');
    $st->execute([$fileId]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) return ['ok' => false, 'message' => 'File not found.'];
    if ($action === 'rename') {
        $name = trim(preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]/', '', (string)($post['file_name'] ?? '')));
        if ($name === '') return ['ok' => false, 'message' => 'File name is required.'];
        $oldExt = strtolower(pathinfo((string)$f['file_name'], PATHINFO_EXTENSION));
        if ($oldExt !== '' && strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== $oldExt) $name .= '.' . $oldExt; // keep the real extension
        if (mb_strlen($name) > 255) return ['ok' => false, 'message' => 'File name is too long.'];
        $db->prepare('UPDATE uploads SET file_name = ? WHERE id = ?')->execute([$name, $fileId]);
        return ['ok' => true, 'message' => 'File renamed to ' . $name . '.'];
    }
    if ($action === 'type') {
        $type = (string)($post['file_type'] ?? '');
        if (!in_array($type, aieFileTypes($db), true)) return ['ok' => false, 'message' => 'Unknown file type.'];
        $db->prepare('UPDATE uploads SET file_type = ? WHERE id = ?')->execute([$type, $fileId]);
        return ['ok' => true, 'message' => 'File type changed to ' . $type . '.'];
    }
    if ($action === 'delete') {
        if (empty($post['confirm'])) return ['ok' => false, 'message' => 'Please confirm the delete.'];
        $db->prepare('DELETE FROM uploads WHERE id = ?')->execute([$fileId]);
        // Remove the stored file only when no other record still points at it.
        $path = (string)$f['file_path'];
        $chk = $db->prepare('SELECT COUNT(*) FROM uploads WHERE file_path = ?');
        $chk->execute([$path]);
        $abs = realpath(dirname(__DIR__) . '/' . $path);
        $root = realpath(dirname(__DIR__) . '/uploads');
        if ((int)$chk->fetchColumn() === 0 && $abs && $root && strpos($abs, $root . DIRECTORY_SEPARATOR) === 0 && is_file($abs)) @unlink($abs);
        error_log(sprintf('admin inline edit: user %d deleted upload %d (%s) of survey %d', (int)($_SESSION['user_id'] ?? 0), $fileId, $f['file_name'], (int)$f['survey_id']));
        return ['ok' => true, 'message' => 'File "' . $f['file_name'] . '" deleted.'];
    }
    return ['ok' => false, 'message' => 'Unknown action.'];
}

/* ------------------------------------------------------------------ */
/* Popup + script (rendered once per page for Admin).                  */
/* ------------------------------------------------------------------ */
function aieRender(): void {
    if (!aieIsAdmin() || empty($GLOBALS['__aie'])) return;
    $data = aieData($GLOBALS['__aie']['db'], $GLOBALS['__aie']['survey']);
    ?>
<div class="aie-modal" id="aieModal" hidden role="dialog" aria-modal="true" aria-labelledby="aieTitle" data-testid="aie-modal">
    <form class="aie-box" id="aieForm" novalidate>
        <div class="aie-head"><div class="aie-ico"><i class="fa-solid fa-pen"></i></div><div class="aie-title" id="aieTitle">Edit</div>
            <button type="button" class="aie-x" data-aie-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>
        <div class="aie-body" id="aieBody"></div>
        <div class="aie-error" id="aieError" hidden data-testid="aie-error"></div>
        <div class="aie-actions">
            <button type="button" class="aie-b ghost" data-aie-close>Cancel</button>
            <button type="submit" class="aie-b primary" id="aieSave" data-testid="aie-save"><i class="fa-solid fa-check"></i> Save</button>
        </div>
    </form>
</div>
<style>
.aie-btn, .aie-file-btn { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; margin-left: 6px; padding: 0; border-radius: 7px; border: 1px solid #DDD6FE; background: #F5F3FF; color: #5B2DEE; font-size: 10px; vertical-align: middle; cursor: pointer; flex: none; line-height: 1; }
.aie-btn { align-self: center !important; height: 22px !important; min-height: 0 !important; max-height: 22px; box-sizing: border-box; }
.aie-file-btn { align-self: center; box-sizing: border-box; }
.aie-btn:hover, .aie-file-btn:hover { background: #5B2DEE; color: #fff; border-color: #5B2DEE; }
.aie-file-btn { width: 30px; height: 30px; font-size: 12px; margin-left: 4px; border-radius: 9px; }
.aie-file-btn.danger { border-color: #FECACA; background: #FEF2F2; color: #DC2626; }
.aie-file-btn.danger:hover { background: #DC2626; color: #fff; border-color: #DC2626; }
.aie-add-files { display: inline-flex; align-items: center; gap: 6px; margin-top: 8px; padding: 7px 14px; border-radius: 10px; border: 1.5px dashed #A78BFA; background: #F5F3FF; color: #5B2DEE; font-size: 12.5px; font-weight: 700; }
.aie-add-files:hover { background: #5B2DEE; color: #fff; border-style: solid; }
.aie-modal { position: fixed; inset: 0; z-index: 100000; background: rgba(15,23,42,.55); display: flex; align-items: center; justify-content: center; padding: 16px; }
.aie-modal[hidden] { display: none; }
.aie-box { background: #fff; border-radius: 18px; width: 100%; max-width: 460px; padding: 18px; box-shadow: 0 24px 60px rgba(0,0,0,.25); max-height: calc(100vh - 32px); overflow: auto; }
.aie-head { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.aie-ico { width: 36px; height: 36px; border-radius: 11px; background: linear-gradient(135deg,#1E1B6B,#4B2FD6); color: #fff; display: flex; align-items: center; justify-content: center; flex: none; font-size: 14px; }
.aie-title { font-size: 15.5px; font-weight: 800; color: #0f172a; flex: 1; min-width: 0; }
.aie-x { border: none; background: #F1F5F9; color: #475569; width: 32px; height: 32px; border-radius: 10px; }
.aie-l { display: block; font-size: 11.5px; font-weight: 700; color: #475569; margin: 8px 0 5px; }
.aie-i { width: 100%; border: 1.5px solid #e2e8f0; border-radius: 11px; padding: 9px 11px; font-size: 14px; background: #F8FAFC; color: #0f172a; min-width: 0; }
.aie-i:focus { outline: none; border-color: #A78BFA; background: #fff; }
textarea.aie-i { min-height: 96px; resize: vertical; }
select.aie-i[size] { padding: 4px; }
.aie-hint { font-size: 11px; color: #94a3b8; margin-top: 5px; }
.aie-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.aie-chip input { position: absolute; opacity: 0; pointer-events: none; }
.aie-chip span { display: inline-flex; gap: 5px; align-items: center; border: 1.5px solid #e2e8f0; border-radius: 999px; padding: 6px 11px; font-size: 12.5px; font-weight: 600; color: #475569; cursor: pointer; }
.aie-chip input:checked + span { background: #1E1B6B; border-color: #1E1B6B; color: #fff; }
.aie-chip em { font-size: 9px; font-style: normal; font-weight: 800; opacity: .7; }
.aie-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 10px; }
.aie-confirm { display: flex; gap: 8px; align-items: flex-start; background: #FFF7ED; border: 1.5px solid #FED7AA; border-radius: 12px; padding: 10px 12px; font-size: 12.5px; color: #9A3412; margin-top: 10px; }
.aie-error { margin-top: 12px; font-size: 12.5px; color: #B91C1C; background: #FEF2F2; border-radius: 10px; padding: 8px 11px; font-weight: 600; }
.aie-actions { display: flex; gap: 10px; margin-top: 16px; }
.aie-b { flex: 1; border: none; border-radius: 12px; padding: 11px 14px; font-size: 13px; font-weight: 700; }
.aie-b.primary { background: linear-gradient(120deg,#1E1B6B,#4B2FD6); color: #fff; }
.aie-b.primary.danger { background: linear-gradient(120deg,#991B1B,#DC2626); }
.aie-b.ghost { background: #F1F5F9; color: #334155; }
.aie-b:disabled { opacity: .6; }
</style>
<script>
(function () {
    var D = <?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var V = D.values, modal = document.getElementById('aieModal'), form = document.getElementById('aieForm');
    var body = document.getElementById('aieBody'), errBox = document.getElementById('aieError'), saveBtn = document.getElementById('aieSave');
    var current = null;
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    function opts(list, sel) { return list.map(function (o) { return '<option value="' + esc(o.id) + '"' + (String(o.id) === String(sel) ? ' selected' : '') + '>' + esc(o.name) + '</option>'; }).join(''); }
    function input(name, val, type, extra) { return '<input class="aie-i" name="' + name + '" type="' + (type || 'text') + '" value="' + esc(val) + '" ' + (extra || '') + ' data-testid="aie-input-' + name + '">'; }
    function err(m) { errBox.textContent = m || ''; errBox.hidden = !m; }
    function open(title, html, opt) {
        current = opt; document.getElementById('aieTitle').textContent = title; body.innerHTML = html; err('');
        saveBtn.classList.toggle('danger', !!opt.danger);
        saveBtn.innerHTML = opt.danger ? '<i class="fa-solid fa-trash"></i> Delete' : opt.addFiles ? '<i class="fa-solid fa-cloud-arrow-up"></i> Upload' : '<i class="fa-solid fa-check"></i> Save';
        modal.hidden = false;
        var f = body.querySelector('input:not([type=checkbox]):not([type=hidden]),select,textarea'); if (f) setTimeout(function () { f.focus(); }, 30);
        if (opt.after) opt.after();
    }
    function close() { modal.hidden = true; current = null; }
    modal.querySelectorAll('[data-aie-close]').forEach(function (b) { b.addEventListener('click', close); });
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });

    function filterable(name, list, sel) {
        return '<input class="aie-i" type="search" placeholder="Search…" data-aie-filter style="margin-bottom:6px" data-testid="aie-filter">' +
               '<select class="aie-i" name="' + name + '" size="7" data-testid="aie-input-' + name + '">' + opts(list, sel) + '</select>';
    }
    function bindFilter() {
        var f = body.querySelector('[data-aie-filter]'), s = body.querySelector('select[size]');
        if (!f || !s) return;
        f.addEventListener('input', function () { var q = f.value.toLowerCase(); Array.prototype.forEach.call(s.options, function (o) { o.hidden = q && o.text.toLowerCase().indexOf(q) < 0; }); });
        var o = s.options[s.selectedIndex]; if (o) o.scrollIntoView({ block: 'center' });
    }

    function build(field) {
        var L = D.labels[field] || field;
        switch (field) {
            case 'vessel_name': case 'agent_name': case 'report_number':
                return open('Edit ' + L, '<label class="aie-l">' + esc(L) + '</label>' + input('value', V[field], 'text', 'maxlength="' + (field === 'report_number' ? 50 : 255) + '"'), { field: field });
            case 'remarks':
                return open('Edit ' + L, '<label class="aie-l">' + esc(L) + '</label><textarea class="aie-i" name="value" maxlength="5000" data-testid="aie-input-value">' + esc(V[field]) + '</textarea>', { field: field });
            case 'client_id':
                return open('Edit Client', '<label class="aie-l">Client</label>' + filterable('value', D.clients, V.client_id), { field: field, after: bindFilter });
            case 'port_id':
                return open('Edit Port', '<label class="aie-l">Port</label>' + filterable('value', D.ports, V.port_id), { field: field, after: bindFilter });
            case 'survey_type_ids':
                return open('Edit Survey Type(s)', '<div class="aie-chips">' + D.types.map(function (t) {
                    return '<label class="aie-chip"><input type="checkbox" name="values[]" value="' + t.id + '"' + (V.survey_type_ids.indexOf(t.id) >= 0 ? ' checked' : '') + '><span>' + esc(t.type_name) + (t.na ? ' <em>N/A REC</em>' : '') + '</span></label>';
                }).join('') + '</div><div class="aie-hint">Types marked N/A REC have no recovery.</div>', { field: field });
            case 'surveyors':
                return open('Edit Surveyor(s)', '<div class="aie-chips" data-testid="aie-surveyor-chips"><label class="aie-chip"><input type="checkbox" name="outsourcing" value="1" data-aie-out' + (V.outsourcing ? ' checked' : '') + '><span>Outsourcing / Unassigned</span></label>' +
                    D.surveyors.map(function (s) { return '<label class="aie-chip"><input type="checkbox" name="values[]" value="' + s.id + '" data-aie-sv' + (V.surveyor_ids.indexOf(Number(s.id)) >= 0 ? ' checked' : '') + '><span>' + esc(s.name) + (s.status !== 'Active' ? ' (inactive)' : '') + '</span></label>'; }).join('') +
                    '</div><div class="aie-hint">The first selected surveyor is the primary one.</div>', { field: field, after: function () {
                        var out = body.querySelector('[data-aie-out]');
                        out.addEventListener('change', function () { if (out.checked) body.querySelectorAll('[data-aie-sv]').forEach(function (c) { c.checked = false; }); });
                        body.querySelectorAll('[data-aie-sv]').forEach(function (c) { c.addEventListener('change', function () { if (c.checked) out.checked = false; }); });
                    } });
            case 'appointment_date': case 'survey_completed_date': case 'report_uploaded_date':
                return open('Edit ' + L, '<label class="aie-l">' + esc(L) + '</label>' + input('value', V[field], 'date'), { field: field });
            case 'assign_date':
                return open('Edit ' + L, '<label class="aie-l">' + esc(L) + '</label>' + input('value', V.assign_date, 'datetime-local'), { field: field });
            case 'recovery':
                return open('Edit Recovery (MT)', '<div class="aie-grid">' +
                    '<div><label class="aie-l">VLSFO</label>' + input('vlsfo_recovery', V.vlsfo_recovery, 'text', 'inputmode="decimal" data-aie-part') + '</div>' +
                    '<div><label class="aie-l">HSFO</label>' + input('hsfo_recovery', V.hsfo_recovery, 'text', 'inputmode="decimal" data-aie-part') + '</div>' +
                    '<div><label class="aie-l">LSMGO</label>' + input('lsmgo_recovery', V.lsmgo_recovery, 'text', 'inputmode="decimal" data-aie-part') + '</div>' +
                    '<div><label class="aie-l">Total</label>' + input('recovery_amount', V.recovery_amount, 'text', 'inputmode="decimal" style="font-weight:800"') + '</div></div>' +
                    '<div class="aie-hint">Leave a box empty for "not recorded". A blank Total is filled from VLSFO + HSFO + LSMGO.</div>' +
                    '<label class="aie-l">Or replace the Calculation Sheet (Excel)</label><input class="aie-i" type="file" name="excel_report" accept=".xlsx,.xls,.xlsm" data-testid="aie-input-excel_report">' +
                    '<div class="aie-hint">Recovery is then read from the new sheet (TABLES 54B).</div>', { field: field });
        }
    }

    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-aie-add-files]')) {
            e.preventDefault();
            return open('Add Pre-Uploaded Files', '<label class="aie-l">Files</label><input class="aie-i" type="file" name="files[]" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.xlsm,.jpg,.jpeg,.png,.zip" data-testid="aie-input-files">' +
                '<div class="aie-hint">PDF, Word, Excel, JPG/PNG or ZIP · up to 30 MB each.</div>' +
                '<label class="aie-l">File type</label><select class="aie-i" name="file_type" data-testid="aie-input-add_file_type">' + D.fileTypes.map(function (t) { return '<option' + (t === 'Field Report' ? ' selected' : '') + '>' + esc(t) + '</option>'; }).join('') + '</select>' +
                '<label class="aie-confirm" style="background:#EFF6FF;border-color:#BFDBFE;color:#1E40AF"><input type="checkbox" name="read_recovery" value="1" checked data-testid="aie-read-recovery"><span>If an Excel Calculation Sheet is added, read the recovery from it (TABLES 54B).</span></label>', { addFiles: true });
        }
        var b = e.target.closest('.aie-btn');
        if (b) { e.preventDefault(); e.stopPropagation(); build(b.getAttribute('data-aie')); return; }
        var fb = e.target.closest('.aie-file-btn');
        if (!fb) return;
        e.preventDefault(); e.stopPropagation();
        var id = fb.getAttribute('data-file-id'), name = fb.getAttribute('data-file-name'), type = fb.getAttribute('data-file-type'), act = fb.getAttribute('data-aie-file');
        if (act === 'rename') open('Rename file', '<label class="aie-l">File name</label>' + input('file_name', name, 'text', 'maxlength="255"') + '<div class="aie-hint">The file extension is kept.</div>', { file: id, action: 'rename' });
        else if (act === 'type') open('Change file type', '<label class="aie-l">' + esc(name) + '</label><select class="aie-i" name="file_type" data-testid="aie-input-file_type">' + D.fileTypes.map(function (t) { return '<option' + (t === type ? ' selected' : '') + '>' + esc(t) + '</option>'; }).join('') + '</select>', { file: id, action: 'type' });
        else open('Delete file', '<div style="font-size:13.5px;color:#334155">Delete <b>' + esc(name) + '</b> (' + esc(type) + ')? This cannot be undone.</div><input type="hidden" name="confirm" value="1">', { file: id, action: 'delete', danger: true });
    });

    form.addEventListener('input', function (e) {
        if (!e.target.hasAttribute('data-aie-part')) return;
        var tot = form.querySelector('[name=recovery_amount]'), any = false, sum = 0;
        form.querySelectorAll('[data-aie-part]').forEach(function (x) { if (x.value.trim() !== '') { any = true; sum += parseFloat(x.value) || 0; } });
        if (tot && !tot.dataset.touched) tot.value = any ? Math.round(sum * 1000) / 1000 : '';
    });
    form.addEventListener('input', function (e) { if (e.target.name === 'recovery_amount') e.target.dataset.touched = e.target.value.trim() !== '' ? '1' : ''; });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!current) return;
        var fd = new FormData(form);
        var url;
        if (current.addFiles) {
            var fi = form.querySelector('input[name="files[]"]'), tot = 0;
            if (!fi.files.length) { err('Please choose at least one file.'); return; }
            for (var k = 0; k < fi.files.length; k++) tot += fi.files[k].size;
            if (tot > 31 * 1024 * 1024) { err('The selected files add up to more than 31 MB — please add them in smaller batches.'); return; }
            url = 'ajax/admin_inline_edit.php?add_files=1';
        }
        else if (current.file) { url = 'ajax/admin_inline_edit.php?file=1'; fd.append('file_id', current.file); fd.append('action', current.action); }
        else { url = 'ajax/admin_inline_edit.php'; fd.append('field', current.field); }
        fd.append('survey_id', D.id);
        saveBtn.disabled = true; err('');
        fetch(url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': D.csrf } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.success) { err(j.message || 'Could not save.'); saveBtn.disabled = false; return; }
                window.location.href = j.redirect || window.location.href.split('#')[0];
            })
            .catch(function () { err('Network error — please try again.'); saveBtn.disabled = false; });
    });
})();
</script>
    <?php
}

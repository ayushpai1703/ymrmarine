<?php
/**
 * Survey Reports (survey_reports.php / survey_report.php / ajax/survey_reports.php): the bunker
 * survey report of a vessel entered on the website and filled into the company's Excel report
 * template (includes/report_templates.php).
 *
 * Who: Admin — every vessel, can edit any report. Surveyor — the vessels assigned to them, can
 * edit while the vessel is Pending Vessel / Pending Report. Super Admin — view and download.
 * Making a report never changes the vessel's status or its files.
 */
require_once __DIR__ . '/report_templates.php';
require_once __DIR__ . '/gemini_json.php';

function srRole(): string { return (string)($_SESSION['role'] ?? ''); }
function srCanUse(): bool { return in_array(srRole(), ['Admin', 'Super Admin', 'Surveyor'], true); }

/** Report + its vessel, or null when missing / not visible to this user. */
function srReport(PDO $db, int $id): ?array {
    rtEnsureTables($db);
    $q = $db->prepare('SELECT r.*, u.full_name AS updated_by_name FROM survey_reports r LEFT JOIN users u ON u.id = r.updated_by WHERE r.id = ?');
    $q->execute([$id]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $s = sfSurvey($db, (int)$r['survey_id']);
    if (!$s || !sfCanAccess($db, $s)) return null;
    $r['survey'] = $s;
    $r['data'] = json_decode((string)$r['data'], true) ?: ['fields' => [], 'tanks' => []];
    $r['id'] = (int)$r['id']; $r['version'] = (int)$r['version']; $r['template_id'] = (int)$r['template_id'];
    return $r;
}

function srCanEdit(array $survey): bool {
    if (srRole() === 'Admin') return true;
    return srRole() === 'Surveyor' && in_array((string)$survey['status'], SF_EDIT_STATUSES, true);
}

/** Starting values of a new report: the template's standard texts and what the vessel record knows. */
function srDefaults(array $eff, array $survey): array {
    $d = ['fields' => [], 'tanks' => array_fill_keys(array_keys($eff['sections']), [])];
    foreach ($eff['fields'] as $k => $f) {
        $d['fields'][$k] = in_array($f['group'], ['offhire', 'remarks'], true) ? (string)($eff['sample']['fields'][$k] ?? '') : '';
    }
    foreach (sfPrefill($survey) as $k => $v) if (isset($d['fields'][$k])) $d['fields'][$k] = (string)$v;
    if (isset($d['fields']['place']) && !empty($survey['port_name'])) $d['fields']['place'] = strtoupper((string)$survey['port_name']);
    $d['grades'] = []; $d['step'] = 'vessel';
    $d['extra'] = array_fill_keys(array_keys(srExtraFieldDefs()), '');
    $d['extra']['port_name'] = strtoupper(trim((string)($survey['port_name'] ?? '')));
    foreach (array_keys(srGradeList($eff)) as $g) $d['tanks'][$g] = $d['tanks'][$g] ?? [];
    return $d;
}

/** What the editor needs to draw the form for a template version. */
function srEditorConfig(array $tpl): array {
    $eff = rtEffective($tpl);
    $fields = [];
    foreach ($eff['fields'] as $k => $f) $fields[] = ['key' => $k, 'label' => $f['label'], 'type' => $f['type'], 'group' => $f['group'], 'cell' => $f['sheet'] . '!' . $f['cell']];
    $sections = [];
    foreach ($eff['sections'] as $k => $s) $sections[] = ['key' => $k, 'label' => trim($s['label']), 'short' => $eff['fuel_names'][$k] ?? strtoupper($k), 'rows' => $s['last'] - $s['first'] + 1];
    // what the offline Excel builder needs: where things go in the workbook
    $offline = ['tables_sheet' => $eff['tables_sheet'], 'pattern' => $eff['pattern'],
        'sections' => array_map(fn($s) => ['first' => $s['first'], 'last' => $s['last'], 'gsv_total' => $s['gsv_total'], 'mt_total' => $s['mt_total'], 'cols' => $s['cols'], 'calc_cols' => $s['calc_cols']], $eff['sections']),
        'fields' => array_map(fn($f) => ['sheet' => $f['sheet'], 'cell' => $f['cell'], 'type' => $f['type']], $eff['fields'])];
    $tanksCols = [];
    foreach (RT_TANK_COLS as $k => $d) $tanksCols[] = ['key' => $k, 'label' => $d[0], 'type' => $d[3]];
    return ['template' => ['id' => $tpl['id'], 'kind' => $tpl['kind'], 'version' => $tpl['version'], 'name' => $tpl['original_name']],
        'fields' => $fields, 'groups' => RT_GROUPS, 'sections' => $sections, 'tank_cols' => $tanksCols, 'calc' => rtCalcConfig($eff), 'offline' => $offline,
        'flags' => array_values(array_filter($eff['flags'], fn($f) => $f['level'] === 'error')),
        'grades' => array_map(fn($g, $d) => ['key' => $g] + $d, array_keys(srGradeList($eff)), srGradeList($eff)),
        'extra_fields' => array_map(fn($k, $d) => ['key' => $k, 'label' => $d[0], 'type' => $d[1]], array_keys(srExtraFieldDefs()), srExtraFieldDefs()),
        'vessel_keys' => array_values(array_filter(SR_VESSEL_KEYS, fn($k) => isset($eff['fields'][$k]))),
        'survey_required' => SR_SURVEY_REQUIRED];
}

/** File name in the company's style: "MV. PROTECTOR - OFFHIRE - FIELD REPORT". */
function srFileBase(array $report, array $tpl): string {
    $v = trim((string)($report['data']['fields']['vessel_name'] ?? '')) ?: (string)$report['survey']['vessel_name'];
    $base = strtoupper($v) . ' - ' . strtoupper(RT_KINDS[$tpl['kind']] ?? $tpl['kind']) . ' - FIELD REPORT';
    return trim(preg_replace('/[^A-Za-z0-9 .,()_-]+/', '_', $base)) ?: 'SURVEY REPORT';
}

/**
 * Make the Excel (and PDF) from the saved data with the report's template version. Each run
 * creates new files; earlier ones are kept. → ['ok', 'files' => [...], 'results'] or errors.
 */
function srGenerate(PDO $db, array $report, bool $pdf, int $uid): array {
    $tpl = rtTemplate($db, $report['template_id']);
    if (!$tpl || !rtAbs($tpl['file_path'])) return ['ok' => false, 'error' => 'The report template is missing.'];
    $eff = rtEffective($tpl);
    $issues = srValidate($eff, $report['data']);
    if ($issues) return ['ok' => false, 'error' => 'Please complete the marked values first.', 'issues' => $issues];
    [$book, $eff, $print, $where] = srBuildBook($tpl, $report['data']);
    $results = srResults($book, $eff, $report['data'], $where);
    $dir = rtReportDir($report['id']);
    rtMkdir($dir);
    $stamp = date('Ymd-His') . '-' . bin2hex(random_bytes(2));
    $base = srFileBase($report, $tpl);
    $rel = 'uploads/survey_reports/' . $report['id'] . '/' . $stamp . '.xlsx';
    rtSave($book, dirname(__DIR__) . '/' . $rel, rtAbs($tpl['file_path']));
    $files = [];
    $ins = $db->prepare('INSERT INTO survey_report_files (report_id, template_id, type, file_name, file_path, file_size, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $now = date('Y-m-d H:i:s');
    $ins->execute([$report['id'], $tpl['id'], 'xlsx', $base . '.xlsx', $rel, filesize(dirname(__DIR__) . '/' . $rel), $uid ?: null, $now]);
    $files['xlsx'] = (int)$db->lastInsertId();
    $pdfError = '';
    if ($pdf) {
        $prel = 'uploads/survey_reports/' . $report['id'] . '/' . $stamp . '.pdf';
        $p = rtPdf(dirname(__DIR__) . '/' . $rel, $print, dirname(__DIR__) . '/' . $prel);
        if ($p['ok']) { $ins->execute([$report['id'], $tpl['id'], 'pdf', $base . '.pdf', $prel, filesize(dirname(__DIR__) . '/' . $prel), $uid ?: null, $now]); $files['pdf'] = (int)$db->lastInsertId(); }
        else $pdfError = $p['error'];
    }
    return ['ok' => true, 'file_ids' => $files, 'results' => $results, 'pdf_error' => $pdfError];
}

/** Server check of the summary: the same workbook, calculated, nothing stored. */
function srVerify(array $tpl, array $data): array {
    $eff = rtEffective($tpl);
    $issues = srValidate($eff, $data);
    $grades = srChosenGrades($eff, $data);
    $ok = $grades && !array_filter($issues, fn($i) => strpos($i['step'], 'g:') === 0);
    if (!$ok) return ['issues' => $issues, 'results' => null];
    [$book, $eff, , $where] = srBuildBook($tpl, $data);
    return ['issues' => $issues, 'results' => srResults($book, $eff, $data, $where)];
}

function srFiles(PDO $db, int $reportId): array {
    $q = $db->prepare('SELECT f.id, f.type, f.file_name, f.file_size, f.created_at, f.template_id, t.version AS template_version, u.full_name AS created_by_name
        FROM survey_report_files f LEFT JOIN report_templates t ON t.id = f.template_id LEFT JOIN users u ON u.id = f.created_by WHERE f.report_id = ? ORDER BY f.id DESC');
    $q->execute([$reportId]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** Vessel particulars the "Vessel lookup" button may fill (field key => what the prompt asks for). */
const SR_LOOKUP_FIELDS = [
    'vessel_name' => 'current ship name',
    'flag' => 'flag state (country)',
    'port_of_registry' => 'port of registry',
    'grt' => 'gross tonnage (whole number)',
    'nrt' => 'net tonnage (whole number)',
    'owners' => 'registered owner company',
    'operator' => 'ship manager / operator company',
    'builder' => 'shipbuilder (yard name)',
    'keel_month' => 'keel laid month (month name, e.g. MAY)',
    'keel_year' => 'keel laid year (4 digits; if only the build year is known, null)',
    'callsign' => 'call sign',
    'loa' => 'length overall in metres',
    'breadth' => 'breadth moulded in metres',
    'depth' => 'depth moulded in metres',
    'summer_draft' => 'summer draft in metres',
    'summer_dwt' => 'summer deadweight in tonnes (whole number)',
    'class' => 'classification society abbreviation (e.g. LR, DNV, ABS, BV, NK, KR, CCS, RINA, IRS)',
    'holds' => 'number of cargo holds (whole number)',
];

/**
 * Look up a vessel's particulars by IMO number with Gemini + Google Search. Only values the web
 * sources state for this IMO are returned (each checked against the field's type); nothing is
 * saved here — the page puts them into the form for the surveyor to check.
 * → ['ok', 'fields' => [key => value], 'sources' => [[title, uri]]] | ['ok' => false, 'error']
 */
function srVesselLookup(string $imoRaw, array $types): array {
    [$imo, $err] = sfValidate('imo', $imoRaw);
    if ($imo === '' || $err) return ['ok' => false, 'error' => $err ?: 'Enter the IMO number first.'];
    if (geminiApiKey() === '') return ['ok' => false, 'error' => 'Vessel lookup is not set up on this server (Gemini API key missing). Fill the fields by hand.'];
    $want = [];
    foreach (SR_LOOKUP_FIELDS as $k => $d) if (isset($types[$k])) $want[$k] = $d;
    $prompt = "VESSEL LOOKUP BY IMO NUMBER.\nSearch the web for the merchant ship with IMO number $imo (e.g. on Equasis-like registries, MarineTraffic, VesselFinder, BalticShipping, classification society registers, shipbuilder pages).\n"
        . "Return ONE JSON object only, no other text, with these keys:\n"
        . "  \"found\": true/false, \"imo\": the IMO number of the ship the values belong to,\n";
    foreach ($want as $k => $d) $prompt .= "  \"$k\": $d,\n";
    $prompt .= "Rules: use only values a source states for THIS IMO number. If a value is not found or sources disagree, use null. Never guess or estimate. Numbers without units or thousands separators. Text in UPPER CASE.";
    $res = geminiJson([['text' => $prompt]], 90, 'vessel lookup', ['search' => true]);
    if (!$res['ok']) return ['ok' => false, 'error' => $res['message'] ?? 'Vessel lookup failed. Please try again.'];
    $d = $res['data'];
    if (empty($d['found']) || preg_replace('/\D/', '', (string)($d['imo'] ?? '')) !== $imo) {
        return ['ok' => false, 'error' => 'No vessel particulars were found for IMO ' . $imo . '. Fill the fields by hand.'];
    }
    $out = [];
    foreach ($want as $k => $_) {
        $v = $d[$k] ?? null;
        if ($v === null || is_array($v) || is_bool($v)) continue;
        $v = (string)$v;
        if ($types[$k] === 'int' || strpos($types[$k], 'num:') === 0) {
            $v = str_replace(',', '', $v);
            if (strpos($types[$k], 'num:') === 0 && is_numeric($v)) $v = rtrim(rtrim(number_format((float)$v, (int)substr($types[$k], 4), '.', ''), '0'), '.');
            if ($types[$k] === 'int' && is_numeric($v)) $v = (string)(int)round((float)$v);
        } else {
            $v = mb_strtoupper($v);
        }
        [$clean, $e] = sfValidate($types[$k], $v);
        if ($clean !== '' && !$e) $out[$k] = $clean;
    }
    if (!$out) return ['ok' => false, 'error' => 'No vessel particulars were found for IMO ' . $imo . '. Fill the fields by hand.'];
    return ['ok' => true, 'imo' => $imo, 'fields' => $out, 'sources' => array_slice($res['sources'] ?? [], 0, 8)];
}

// ================================================================ wizard: grades, checks, workbook

/** Fuel grades the surveyor can choose (order of the wizard steps). */
const SR_GRADES = [
    'vlsfo' => ['VLSFO', 'Very Low Sulphur Fuel Oil'],
    'ulsfo' => ['ULSFO', 'Ultra Low Sulphur Fuel Oil'],
    'hsfo'  => ['HSFO', 'High Sulphur Fuel Oil'],
    'lsmgo' => ['LSMGO', 'Low Sulphur Marine Gas Oil'],
    'hsmgo' => ['HSMGO', 'High Sulphur Marine Gas Oil'],
];
/**
 * Grades the template has no section for go on a copy of TABLES 54B added to the generated
 * workbook, in the section of this template grade (same layout and 54B formulas).
 */
const SR_EXTRA_SLOT = ['ulsfo' => 'vlsfo', 'hsmgo' => 'lsmgo'];
const SR_EXTRA_SHEET = 'TABLES 54B (ULSFO-HSMGO)';
const SR_SUMMARY_SHEET = 'ROB SUMMARY';
/** The 18 vessel particulars of step 1 (with the IMO): what Vessel lookup fills. */
const SR_VESSEL_KEYS = ['vessel_name', 'flag', 'port_of_registry', 'grt', 'nrt', 'owners', 'holds', 'builder', 'keel_month', 'keel_year',
    'operator', 'callsign', 'loa', 'breadth', 'depth', 'summer_draft', 'summer_dwt', 'class'];
/** Survey particulars that must be filled (ROB of each chosen grade is added). */
const SR_SURVEY_REQUIRED = ['fwe_date', 'fwe_time', 'started_date', 'started_time', 'completed_date', 'completed_time', 'surveyor_name',
    'chief_engineer', 'master', 'port_name', 'place', 'draft_fwd', 'draft_aft'];
/** Values the template has no cell for (kept in data.extra; printed on the ROB SUMMARY sheet). */
function srExtraFieldDefs(): array {
    return [
        'port_name' => ['Port Name', 'text'],
        'brob_fwe_ulsfo' => ['FWE ROB — ULSFO (MT)', 'num:3'], 'brob_fwe_hsmgo' => ['FWE ROB — HSMGO (MT)', 'num:3'],
        'brob_started_ulsfo' => ['Survey started ROB — ULSFO (MT)', 'num:3'], 'brob_started_hsmgo' => ['Survey started ROB — HSMGO (MT)', 'num:3'],
    ];
}
const SR_ROW_META = ['mode', 'ullage', 'done', 'id'];

/** Grades offered for a template: its own sections plus the ones a copied section can take. */
function srGradeList(array $eff): array {
    $out = [];
    foreach (SR_GRADES as $g => [$short, $name]) {
        if (isset($eff['sections'][$g])) $out[$g] = ['short' => $short, 'name' => $name, 'template' => true];
        elseif (isset(SR_EXTRA_SLOT[$g], $eff['sections'][SR_EXTRA_SLOT[$g]])) $out[$g] = ['short' => $short, 'name' => $name, 'template' => false];
    }
    return $out;
}
function srChosenGrades(array $eff, array $data): array {
    $list = srGradeList($eff);
    return array_values(array_filter(array_keys($list), fn($g) => in_array($g, (array)($data['grades'] ?? []), true)));
}
/** A value of the form: template field or extra value. */
function srVal(array $data, string $k): string {
    return trim((string)($data['fields'][$k] ?? $data['extra'][$k] ?? ''));
}

/** Clean what the page sends besides the template fields: grades, step, extra values, tank rows of every grade. */
function srCleanExtra(array $eff, array $in, array &$store, array &$errors): void {
    $list = srGradeList($eff);
    $store['grades'] = array_values(array_filter(array_keys($list), fn($g) => in_array($g, (array)($in['grades'] ?? []), true)));
    $store['step'] = mb_substr(preg_replace('/[^a-z0-9:_]/', '', (string)($in['step'] ?? '')), 0, 20);
    $store['extra'] = [];
    foreach (srExtraFieldDefs() as $k => [$label, $type]) {
        $raw = (string)($in['extra'][$k] ?? '');
        [$v, $err] = sfValidate($type, $raw);
        if ($err === '' && $v !== '' && strpos($k, 'brob_') === 0) $err = sfRangeError('brob', $v);
        if ($err !== '') { $errors['f:' . $k] = $err; $v = mb_substr(trim($raw), 0, 200); }
        $store['extra'][$k] = $v;
    }
    foreach (array_keys($list) as $g) { // rows as typed, with their own keys (row positions stay as the user sees them)
        $store['tanks'][$g] = array_values(array_map(function ($row) {
            $o = [];
            foreach (array_merge(array_keys(RT_TANK_COLS), SR_ROW_META) as $c) $o[$c] = is_scalar($row[$c] ?? '') ? mb_substr(trim((string)($row[$c] ?? '')), 0, 60) : '';
            $o['mode'] = $o['mode'] === 'ullage' ? 'ullage' : 'sounding';
            $o['done'] = $o['done'] ? '1' : '';
            $o['id'] = preg_replace('/[^A-Za-z0-9_-]/', '', $o['id']);
            return $o;
        }, array_slice(array_values((array)($in['tanks'][$g] ?? [])), 0, RT_MAX_TANKS)));
    }
}

/** Sounding of a tank row: typed, or Reference height − Ullage. */
function srSounding(array $row): string {
    if (($row['mode'] ?? '') !== 'ullage') return trim((string)($row['sounding'] ?? ''));
    $ref = str_replace(',', '', trim((string)($row['ref_height'] ?? ''))); $ull = str_replace(',', '', trim((string)($row['ullage'] ?? '')));
    if (!is_numeric($ref) || !is_numeric($ull)) return '';
    return number_format(round((float)$ref - (float)$ull, 3), 3, '.', '');
}

/**
 * Everything that stops the export, step by step:
 * [['step' => 'vessel'|'survey'|'g:<grade>'|'grades', 'key' => 'f:<field>'|'t:<grade>:<i>:<col>'|'', 'msg']].
 */
function srValidate(array $eff, array $data): array {
    $issues = [];
    $label = fn($k) => $eff['fields'][$k]['label'] ?? (srExtraFieldDefs()[$k][0] ?? $k);
    [, $typeErrors] = rtCleanData($eff, ['fields' => $data['fields'] ?? [], 'tanks' => []]);
    foreach (array_merge(['imo'], SR_VESSEL_KEYS) as $k) {
        if (!isset($eff['fields'][$k])) continue;
        if (srVal($data, $k) === '') $issues[] = ['step' => 'vessel', 'key' => 'f:' . $k, 'msg' => $label($k) . ' is missing.'];
        elseif (isset($typeErrors['f:' . $k])) $issues[] = ['step' => 'vessel', 'key' => 'f:' . $k, 'msg' => $label($k) . ': ' . $typeErrors['f:' . $k]];
    }
    $grades = srChosenGrades($eff, $data);
    if (!$grades) $issues[] = ['step' => 'survey', 'key' => 'grades', 'msg' => 'Choose the fuel grades on board.'];
    $req = SR_SURVEY_REQUIRED;
    foreach ($grades as $g) { $req[] = 'brob_fwe_' . $g; $req[] = 'brob_started_' . $g; }
    foreach ($req as $k) {
        if (!isset($eff['fields'][$k]) && !isset(srExtraFieldDefs()[$k])) continue;
        if (srVal($data, $k) === '') $issues[] = ['step' => 'survey', 'key' => 'f:' . $k, 'msg' => $label($k) . ' is missing.'];
    }
    foreach (['voyage_no', 'charterers', 'alongside', 'list', ...$req] as $k) {
        if (isset($typeErrors['f:' . $k]) && srVal($data, $k) !== '') $issues[] = ['step' => 'survey', 'key' => 'f:' . $k, 'msg' => $label($k) . ': ' . $typeErrors['f:' . $k]];
    }
    $s = srVal($data, 'started_date') . ' ' . srVal($data, 'started_time'); $c = srVal($data, 'completed_date') . ' ' . srVal($data, 'completed_time');
    if (strlen($s) === 16 && strlen($c) === 16 && $c < $s) $issues[] = ['step' => 'survey', 'key' => 'f:completed_time', 'msg' => 'Survey Completed is before Survey Started.'];
    $names = srGradeList($eff);
    foreach ($grades as $g) {
        $rows = array_values((array)($data['tanks'][$g] ?? []));
        $short = $names[$g]['short'];
        if (!$rows) { $issues[] = ['step' => 'g:' . $g, 'key' => '', 'msg' => $short . ': add at least one tank.']; continue; }
        foreach ($rows as $i => $row) {
            $tn = $short . ' tank ' . ($i + 1) . (trim((string)($row['name'] ?? '')) !== '' ? ' (' . trim((string)$row['name']) . ')' : '');
            foreach (srTankErrors($row) as $col => $msg) $issues[] = ['step' => 'g:' . $g, 'key' => "t:$g:$i:$col", 'msg' => $tn . ': ' . $msg];
        }
    }
    return $issues;
}

/** Missing / wrong values of one tank → [column => message]. */
function srTankErrors(array $row): array {
    $err = [];
    $ull = ($row['mode'] ?? '') === 'ullage';
    $need = ['name' => 'Tank name', 'ref_height' => 'Reference height', $ull ? 'ullage' : 'sounding' => $ull ? 'Ullage' : 'Sounding', 'volume' => 'Volume', 'density' => 'Density @15°C', 'temp' => 'Temperature'];
    foreach ($need as $c => $lab) {
        $v = trim((string)($row[$c] ?? ''));
        if ($v === '') { $err[$c] = $lab . ' is missing.'; continue; }
        $type = $c === 'ullage' ? 'num:3' : RT_TANK_COLS[$c][3];
        [$clean, $e] = sfValidate($type, $v);
        if ($e === '') $e = sfRangeError($c, $clean);
        if ($e !== '') $err[$c] = $lab . ': ' . $e;
    }
    if ($ull && !isset($err['ref_height']) && !isset($err['ullage'])) {
        if (!is_numeric(str_replace(',', '', (string)$row['ref_height']))) $err['ref_height'] = 'Reference height must be a number to work out the sounding from the ullage.';
        elseif ((float)str_replace(',', '', (string)$row['ullage']) > (float)str_replace(',', '', (string)$row['ref_height'])) $err['ullage'] = 'Ullage is more than the reference height.';
    }
    return $err;
}

/** Tank rows ready for the workbook (sounding worked out from ullage). */
function srTankRows(array $data, string $g): array {
    $out = [];
    foreach ((array)($data['tanks'][$g] ?? []) as $row) {
        $r = [];
        foreach (array_keys(RT_TANK_COLS) as $c) $r[$c] = trim((string)($row[$c] ?? ''));
        $r['sounding'] = srSounding($row);
        if (implode('', $r) !== '') $out[] = $r;
    }
    return $out;
}

/** [sheet => [col idx max, [coord => formula]]]: formula cells inside each sheet's print-area columns. */
function srFormulaCells(\PhpOffice\PhpSpreadsheet\Spreadsheet $book, array $sheets): array {
    $out = [];
    foreach ($sheets as $title) {
        $ws = $book->getSheetByName($title);
        if (!$ws) continue;
        $maxCol = 'Z';
        $pa = $ws->getPageSetup()->getPrintArea();
        if ($pa && preg_match('/:\$?([A-Z]{1,3})\$?\d+$/', explode(',', $pa)[0], $m)) $maxCol = $m[1];
        $maxIdx = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($maxCol);
        $cells = [];
        foreach ($ws->getCoordinates() as $c) {
            [$cc] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::coordinateFromString($c);
            if (\PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($cc) > $maxIdx) continue;
            $v = $ws->getCell($c)->getValue();
            if (is_string($v) && $v !== '' && $v[0] === '=') $cells[$c] = $v;
        }
        $out[$title] = $cells;
    }
    return $out;
}

/**
 * Hide a grade that is not on board: its section on the tables sheet, every printed row that
 * shows its heading or total (certificate, totals list), and rows that repeat such a row's
 * cells (e.g. the certificate's consumption lines "=D18").
 */
function srHideGrade(\PhpOffice\PhpSpreadsheet\Spreadsheet $book, string $tablesTitle, array $sec, string $mtC, array $sheets): void {
    $t = $book->getSheetByName($tablesTitle);
    srHideRows($t, $sec['heading'], $sec['mt_total']);
    $hidden = [$tablesTitle => range($sec['heading'], $sec['mt_total'])];
    $targets = ['B' . $sec['heading'], $mtC . $sec['mt_total']];
    $formulas = srFormulaCells($book, $sheets);
    foreach ($formulas as $title => $cells) {
        foreach ($cells as $coord => $f) {
            foreach ($targets as $cell) {
                [$col, $row] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::coordinateFromString($cell);
                $local = '/(?<![A-Z$!\'])\$?' . $col . '\$?' . $row . '(?!\d)/';
                $remote = '/\'?' . preg_quote($tablesTitle, '/') . '\'?!\$?' . $col . '\$?' . $row . '(?!\d)/';
                if (($title === $tablesTitle && preg_match($local, $f)) || preg_match($remote, $f)) { $hidden[$title][] = (int)preg_replace('/\D/', '', $coord); break; }
            }
        }
    }
    for ($pass = 0; $pass < 3; $pass++) { // rows that repeat a hidden row's cells on the same sheet
        $more = false;
        foreach ($formulas as $title => $cells) {
            $h = array_flip($hidden[$title] ?? []);
            if (!$h) continue;
            foreach ($cells as $coord => $f) {
                $r = (int)preg_replace('/\D/', '', $coord);
                if (isset($h[$r]) || !preg_match_all('/(?<![A-Z$!\'])\$?[A-Z]{1,3}\$?(\d+)(?!\d)/', preg_replace('/"[^"]*"|\'[^\']*\'![$A-Z0-9:]+/', '', $f), $m)) continue;
                foreach ($m[1] as $ref) if (isset($h[(int)$ref])) { $hidden[$title][] = $r; $more = true; break; }
            }
        }
        if (!$more) break;
    }
    foreach ($hidden as $title => $rows) {
        $ws = $book->getSheetByName($title);
        foreach (array_unique($rows) as $r) srHideRows($ws, $r, $r);
    }
}

/**
 * The BROB block in the report header (labels VLSFO / HSFO / LSMGO over the log-book ROB at FWE and
 * survey start): the column of template grade $slot shows grade $as instead, or is left empty.
 */
function srHeaderGrade(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws, array $eff, string $slot, ?string $as, array $data): void {
    $top = min(array_column($eff['sections'], 'heading')) - 1;
    $short = $eff['fuel_names'][$slot] ?? strtoupper($slot);
    $refs = [];
    foreach (['fwe', 'started'] as $k) {
        $f = $eff['fields']['brob_' . $k . '_' . $slot] ?? null;
        if ($f) $refs[$k] = "'" . $f['sheet'] . "'!" . $f['cell'];
    }
    foreach ($ws->getRowIterator(1, $top) as $row) {
        foreach ($row->getCellIterator('A', 'L') as $cell) {
            $v = $cell->getValue();
            if (!is_string($v) || $v === '') continue;
            if (strtoupper(trim($v)) === strtoupper(trim($short))) { $as ? $cell->setValue(SR_GRADES[$as][0]) : $cell->setValueExplicit(null, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NULL); continue; }
            foreach ($refs as $k => $ref) {
                if (str_replace('$', '', $v) !== '=' . $ref && str_replace('$', '', $v) !== "='" . trim(explode('!', $ref)[0], "'") . "'!" . explode('!', $ref)[1]) continue;
                $val = $as ? srVal($data, 'brob_' . $k . '_' . $as) : '';
                if ($val !== '' && is_numeric($val)) $cell->setValue((float)$val); else $cell->setValueExplicit(null, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NULL);
            }
        }
    }
}

function srHideRows(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws, int $from, int $to): void {
    for ($r = $from; $r <= $to; $r++) $ws->getRowDimension($r)->setVisible(false);
}

/**
 * The report workbook: the template filled with the chosen grades (the others hidden), a copy of
 * TABLES 54B for ULSFO / HSMGO when chosen, and the ROB SUMMARY sheet.
 * → [book, eff, print sheets, where each grade's section is (sheet, rows)].
 */
function srBuildBook(array $tpl, array $data): array {
    $eff = rtEffective($tpl);
    $grades = srChosenGrades($eff, $data);
    $list = srGradeList($eff);
    $tplGrades = array_values(array_filter($grades, fn($g) => $list[$g]['template']));
    $extraGrades = array_values(array_filter($grades, fn($g) => !$list[$g]['template']));
    [$clean] = rtCleanData($eff, ['fields' => $data['fields'] ?? [], 'tanks' => []]);
    $fill = ['fields' => $clean['fields'], 'tanks' => []];
    foreach (array_keys($eff['sections']) as $s) $fill['tanks'][$s] = in_array($s, $tplGrades, true) ? srTankRows($data, $s) : [];
    $book = rtBuild($tpl, $fill);
    $tn = $eff['tables_sheet'];
    $t = $book->getSheetByName($tn);
    $pos = rtSectionRows($eff, array_map('count', $fill['tanks']));
    $mtC = $eff['sections']['vlsfo']['calc_cols']['mt'];
    $printBase = $eff['print_sheets'];
    $where = [];
    foreach ($tplGrades as $g) $where[$g] = ['sheet' => $tn, 'sec' => $pos[$g]];

    // ULSFO / HSMGO: a copy of the filled TABLES 54B, its sections re-used
    $extraTitle = null;
    if ($extraGrades) {
        $copy = $t->copy();
        $copy->setTitle(SR_EXTRA_SHEET);
        $book->addSheet($copy, $book->getIndex($t) + 1);
        $rowsFor = [];
        foreach (array_keys($eff['sections']) as $s) $rowsFor[$s] = [];
        foreach ($extraGrades as $g) $rowsFor[SR_EXTRA_SLOT[$g]] = srTankRows($data, $g);
        $order = $pos; uasort($order, fn($a, $b) => $b['first'] <=> $a['first']);
        foreach ($order as $s => $sec) rtFillSection($book, $copy, $eff, $sec, $rowsFor[$s]);
        $cpos = rtSectionRows(['sections' => $pos], array_map('count', $rowsFor));
        $used = [];
        foreach ($extraGrades as $g) {
            $slot = SR_EXTRA_SLOT[$g]; $used[] = $slot;
            $where[$g] = ['sheet' => SR_EXTRA_SHEET, 'sec' => $cpos[$slot]];
            $copy->getCell('B' . $cpos[$slot]['heading'])->setValue(SR_GRADES[$g][1] . ' ');
            $mtCell = $mtC . $cpos[$slot]['mt_total'];
            foreach (srFormulaCells($book, [SR_EXTRA_SHEET])[SR_EXTRA_SHEET] as $coord => $f) { // the totals list under the tables ("VLSFO  =J36  MT")
                [$mc, $mr] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::coordinateFromString($mtCell);
                if (!preg_match('/(?<![A-Z$!\'])\$?' . $mc . '\$?' . $mr . '(?!\d)/', $f)) continue;
                $b = $copy->getCell('B' . preg_replace('/\D/', '', $coord));
                if (is_string($b->getValue()) && stripos($b->getValue(), $list[$slot]['short']) !== false) $b->setValue(SR_GRADES[$g][0]);
            }
        }
        foreach ($cpos as $s => $sec) if (!in_array($s, $used, true)) srHideGrade($book, SR_EXTRA_SHEET, $sec, $mtC, [SR_EXTRA_SHEET]);
        foreach (array_keys($eff['sections']) as $s) srHeaderGrade($copy, $eff, $s, array_search($s, array_intersect_key(SR_EXTRA_SLOT, array_flip($extraGrades)), true) ?: null, $data);
        $copy->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
        $extraTitle = SR_EXTRA_SHEET;
    }
    // grades not on board: their rows are hidden on the report and the certificate
    foreach ($pos as $s => $sec) {
        if (in_array($s, $tplGrades, true)) continue;
        srHideGrade($book, $tn, $sec, $mtC, $printBase);
        srHeaderGrade($t, $eff, $s, null, $data);
    }
    $summary = srSummarySheet($book, $eff, $data, $grades, $where);
    // printed: the report sheets in workbook order
    $print = $printBase;
    if (!$tplGrades) $print = array_values(array_diff($print, [$tn]));
    if ($extraTitle) $print[] = $extraTitle;
    $print[] = $summary;
    $order = [];
    foreach ($book->getWorksheetIterator() as $ws) if (in_array($ws->getTitle(), $print, true)) $order[] = $ws->getTitle();
    return [$book, $eff, $order, $where];
}

/** ROB SUMMARY sheet (after the tank tables): particulars, completed vs log book ROB per grade, total difference, signatures. */
function srSummarySheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $book, array $eff, array $data, array $grades, array $where): string {
    $ws = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($book, SR_SUMMARY_SHEET);
    $after = $book->getSheetByName(SR_EXTRA_SHEET) ?: $book->getSheetByName($eff['tables_sheet']);
    $book->addSheet($ws, $book->getIndex($after) + 1);
    $blue = '0F2B5B'; $brand = '00A3DF';
    $ws->getDefaultRowDimension()->setRowHeight(18);
    foreach (['A' => 26, 'B' => 22, 'C' => 22, 'D' => 24] as $c => $w) $ws->getColumnDimension($c)->setWidth($w);
    $ws->getStyle('A1:D80')->getFont()->setName('Arial')->setSize(10);
    $ws->getStyle('A1:D80')->getAlignment()->setVertical('center');
    $banner = dirname(__DIR__) . '/assets/images/invoice-header.png'; // the company letterhead banner (as on the invoices)
    if (is_file($banner)) {
        $d = new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();
        $d->setPath($banner); $d->setWidth(640); $d->setCoordinates('A1'); $d->setOffsetX(2); $d->setOffsetY(2); $d->setWorksheet($ws);
        foreach ([1, 2, 3, 4] as $i) $ws->getRowDimension($i)->setRowHeight(18);
    }
    $ws->mergeCells('A5:D5');
    $ws->setCellValue('A5', 'BUNKER SURVEY — ROB SUMMARY');
    $ws->getStyle('A5')->getFont()->setBold(true)->setSize(13)->getColor()->setRGB($blue);
    $ws->getStyle('A5')->getAlignment()->setHorizontal('center');
    $ws->getRowDimension(5)->setRowHeight(26);
    $ws->getStyle('A5:D5')->getBorders()->getBottom()->setBorderStyle('medium')->getColor()->setRGB($brand);

    $num = fn($k) => is_numeric(srVal($data, $k)) ? (float)srVal($data, $k) : null;
    $dt = fn($p) => trim((srVal($data, $p . '_date') !== '' ? sfDateText(srVal($data, $p . '_date')) : '') . '   ' . srVal($data, $p . '_time') . (srVal($data, $p . '_time') !== '' ? ' Hrs LT' : ''));
    $fa = $num('draft_aft'); $ff = $num('draft_fwd');
    $r = 7;
    $text = function (string $label, string $v) use ($ws, &$r) {
        $ws->setCellValue('A' . $r, $label); $ws->getStyle('A' . $r)->getFont()->setBold(true)->getColor()->setRGB('475569');
        $ws->mergeCells("B$r:D$r");
        $ws->setCellValueExplicit('B' . $r, $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $ws->getStyle('B' . $r)->getAlignment()->setWrapText(true);
        if (mb_strlen($v) > 60) $ws->getRowDimension($r)->setRowHeight(30);
        $r++;
    };
    $text('Vessel', srVal($data, 'vessel_name') . (srVal($data, 'imo') !== '' ? '   (IMO No. ' . srVal($data, 'imo') . ')' : ''));
    $text('Flag', srVal($data, 'flag'));
    $text('Port Name', srVal($data, 'port_name'));
    $text('Place of Survey', srVal($data, 'place'));
    $text('Voyage No.', srVal($data, 'voyage_no'));
    $text('FWE', $dt('fwe'));
    $text('Survey Started', $dt('started'));
    $text('Survey Completed', $dt('completed'));
    // drafts: two per row, Mean draft and Trim (Aft − Fwd, as the template's Trim) as formulas
    $pair = function (string $l1, $v1, string $l2, $v2) use ($ws, &$r) {
        foreach ([['A', $l1], ['C', $l2]] as [$c, $l]) { $ws->setCellValue($c . $r, $l); $ws->getStyle($c . $r)->getFont()->setBold(true)->getColor()->setRGB('475569'); }
        foreach ([['B', $v1], ['D', $v2]] as [$c, $v]) {
            if ($v === null || $v === '') continue;
            $ws->setCellValue($c . $r, $v);
            $ws->getStyle($c . $r)->getNumberFormat()->setFormatCode('0.000');
            $ws->getStyle($c . $r)->getAlignment()->setHorizontal('left');
        }
        $r++;
    };
    $dr = $r;
    $pair('Draft Fwd (m)', $ff, 'Draft Aft (m)', $fa);
    $both = $fa !== null && $ff !== null;
    $pair('Mean Draft (m)', $both ? "=ROUND((B$dr+D$dr)/2,3)" : null, 'Trim (m)', $both ? "=ROUND(D$dr-B$dr,3)" : null);
    $pair('List (degrees)', $num('list'), '', null);
    if ($num('list') !== null) $ws->getStyle('B' . ($r - 1))->getNumberFormat()->setFormatCode('0.00');
    // ROB comparison
    $r++;
    $head = $r;
    foreach (['A' => 'Fuel Grade', 'B' => 'Survey Completed ROB (MT)', 'C' => 'Log Book ROB (MT)', 'D' => 'Difference (MT)'] as $c => $h) $ws->setCellValue($c . $r, $h);
    $ws->getStyle("A$r:D$r")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $ws->getStyle("A$r:D$r")->getFill()->setFillType('solid')->getStartColor()->setRGB($blue);
    $ws->getStyle("A$r:D$r")->getAlignment()->setWrapText(true)->setHorizontal('center')->setVertical('center');
    $ws->getRowDimension($r)->setRowHeight(30);
    $mtC = $eff['sections']['vlsfo']['calc_cols']['mt'];
    $first = $r + 1;
    foreach ($grades as $g) {
        if (!isset($where[$g])) continue;
        $r++;
        $ws->setCellValue('A' . $r, SR_GRADES[$g][0]);
        $ws->setCellValue('B' . $r, "='" . $where[$g]['sheet'] . "'!" . $mtC . $where[$g]['sec']['mt_total']);
        $lk = 'brob_started_' . $g;
        if (isset($eff['fields'][$lk])) $ws->setCellValue('C' . $r, "='" . $eff['fields'][$lk]['sheet'] . "'!" . $eff['fields'][$lk]['cell']);
        elseif (is_numeric(srVal($data, $lk))) $ws->setCellValue('C' . $r, (float)srVal($data, $lk));
        $ws->setCellValue('D' . $r, "=ROUND(B$r-C$r,3)");
    }
    $last = $r;
    $r++;
    $ws->setCellValue('A' . $r, 'TOTAL DIFFERENCE');
    $ws->mergeCells("A$r:C$r");
    $ws->setCellValue('D' . $r, $last >= $first ? "=ROUND(SUM(D$first:D$last),3)" : 0);
    $ws->getStyle("A$r:D$r")->getFont()->setBold(true)->setSize(11);
    $ws->getStyle("A$r:D$r")->getFill()->setFillType('solid')->getStartColor()->setRGB('E6F6FC');
    $ws->getStyle("A$r")->getAlignment()->setHorizontal('right');
    $ws->getStyle("B$first:D$r")->getNumberFormat()->setFormatCode('0.000');
    $ws->getStyle("D$first:D$r")->getNumberFormat()->setFormatCode('+0.000;-0.000;0.000');
    $ws->getStyle("B$first:D$r")->getAlignment()->setHorizontal('right');
    $ws->getStyle("A$head:D$r")->getBorders()->getAllBorders()->setBorderStyle('thin')->getColor()->setRGB('94A3B8');
    $r += 2;
    $ws->mergeCells("A$r:D$r");
    $ws->setCellValue('A' . $r, 'Survey Completed ROB = sum of the metric tons (in air) of the tanks of each grade (TABLES 54B). Log Book ROB = ROB at the survey start as per the engine-room log book. Difference = Survey Completed − Log Book.');
    $ws->getStyle('A' . $r)->getAlignment()->setWrapText(true)->setVertical('top');
    $ws->getStyle('A' . $r)->getFont()->setSize(8.5)->setItalic(true)->getColor()->setRGB('64748B');
    $ws->getRowDimension($r)->setRowHeight(36);
    // signatures
    $r += 4;
    $sig = [['A', 'B', 'The Master', 'Capt. ' . srVal($data, 'master'), ''], ['C', 'D', 'For YMR MARINE SOLUTIONS LLP', 'Mr. ' . srVal($data, 'surveyor_name'), 'Independent Marine Surveyor']];
    foreach ([[$sig[0], $sig[1]], [['A', 'B', 'The Chief Engineer', 'Mr. ' . srVal($data, 'chief_engineer'), '']]] as $line) {
        foreach ($line as [$c1, $c2, $role, $name, $sub]) {
            $ws->getStyle("$c1$r:$c2$r")->getBorders()->getTop()->setBorderStyle('thin');
            foreach ([[$r, $role], [$r + 1, $name], [$r + 2, $sub]] as [$rr, $txt]) {
                if ($txt === '') continue;
                $ws->mergeCells("$c1$rr:$c2$rr");
                $ws->setCellValueExplicit($c1 . $rr, $txt, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            $ws->getStyle($c1 . $r)->getFont()->setBold(true);
            if ($sub !== '') $ws->getStyle($c1 . ($r + 2))->getFont()->setSize(9)->getColor()->setRGB('64748B');
        }
        $r += 6;
    }
    $r -= 4;
    $ps = $ws->getPageSetup();
    $ps->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)->setOrientation('portrait')->setFitToWidth(1)->setFitToHeight(0);
    $ps->setPrintArea('A1:D' . ($r + 2));
    $ws->getPageMargins()->setTop(0.6)->setBottom(0.6)->setLeft(0.6)->setRight(0.6);
    $ws->setShowGridlines(false);
    return SR_SUMMARY_SHEET;
}

/** Results calculated on the server from the workbook's own formulas: per tank and per grade, ROB comparison. */
function srResults(\PhpOffice\PhpSpreadsheet\Spreadsheet $book, array $eff, array $data, array $where): array {
    $cc = $eff['sections']['vlsfo']['calc_cols']; $wcfCol = $eff['calc']['wcf_col'] ?? null;
    $out = ['tanks' => [], 'grades' => [], 'total_difference' => 0.0];
    foreach ($where as $g => $w) {
        $ws = $book->getSheetByName($w['sheet']); $sec = $w['sec'];
        $n = count(srTankRows($data, $g));
        $out['tanks'][$g] = [];
        for ($i = 0; $i < $n; $i++) {
            $r = $sec['first'] + $i;
            $v = fn($c) => $c ? sfNum($ws->getCell($c . $r)->getCalculatedValue()) : null;
            $out['tanks'][$g][] = ['vcf' => $v($cc['vcf']), 'wcf' => $v($wcfCol), 'gsv' => $v($cc['gsv']), 'mt' => $v($cc['mt'])];
        }
        $done = sfNum($ws->getCell($cc['mt'] . $sec['mt_total'])->getCalculatedValue()) ?? 0.0;
        $gsv = sfNum($ws->getCell($cc['gsv'] . $sec['gsv_total'])->getCalculatedValue()) ?? 0.0;
        $log = is_numeric(srVal($data, 'brob_started_' . $g)) ? (float)srVal($data, 'brob_started_' . $g) : null;
        $diff = $log === null ? null : round($done - $log, 3);
        $out['grades'][$g] = ['gsv' => round($gsv, 3), 'completed' => round($done, 3), 'logbook' => $log, 'difference' => $diff];
        if ($diff !== null) $out['total_difference'] += $diff;
    }
    $out['total_difference'] = round($out['total_difference'], 3);
    return $out;
}

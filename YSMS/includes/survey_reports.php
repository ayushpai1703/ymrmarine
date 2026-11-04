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
        'flags' => array_values(array_filter($eff['flags'], fn($f) => $f['level'] === 'error'))];
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
    [$data, $errors] = rtCleanData($eff, $report['data']);
    if ($errors) return ['ok' => false, 'error' => 'Please correct the marked values first.', 'errors' => $errors];
    $book = rtBuild($tpl, $data);
    $results = rtResults($book, $eff, $data);
    $dir = rtReportDir($report['id']);
    rtMkdir($dir);
    $stamp = date('Ymd-His');
    $base = srFileBase($report, $tpl);
    $rel = 'uploads/survey_reports/' . $report['id'] . '/' . $stamp . '.xlsx';
    rtSave($book, dirname(__DIR__) . '/' . $rel, rtAbs($tpl['file_path']));
    $files = [];
    $ins = $db->prepare('INSERT INTO survey_report_files (report_id, template_id, type, file_name, file_path, file_size, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $now = date('Y-m-d H:i:s');
    $ins->execute([$report['id'], $tpl['id'], 'xlsx', $base . '.xlsx', $rel, filesize(dirname(__DIR__) . '/' . $rel), $uid ?: null, $now]);
    $files[] = (int)$db->lastInsertId();
    $pdfError = '';
    if ($pdf) {
        $prel = 'uploads/survey_reports/' . $report['id'] . '/' . $stamp . '.pdf';
        $p = rtPdf(dirname(__DIR__) . '/' . $rel, $eff['print_sheets'], dirname(__DIR__) . '/' . $prel);
        if ($p['ok']) { $ins->execute([$report['id'], $tpl['id'], 'pdf', $base . '.pdf', $prel, filesize(dirname(__DIR__) . '/' . $prel), $uid ?: null, $now]); $files[] = (int)$db->lastInsertId(); }
        else $pdfError = $p['error'];
    }
    return ['ok' => true, 'file_ids' => $files, 'results' => $results, 'pdf_error' => $pdfError];
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

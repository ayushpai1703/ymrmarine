<?php
/**
 * Image → Bunker Survey Form (survey_form.php).
 *
 * A surveyor photographs the ship's papers / sounding sheets, AI reads what is legible
 * (includes/survey_form_ai.php), the surveyor checks and completes every field, and the
 * app fills the approved Excel calculation format from formats/ (OFFHIRE, ONHIRE,
 * 3 PARTY OFFHIRE, ROB/221B).
 *
 * The numbers are never computed by the AI: every derived value (V.C.F., G.S.V., metric
 * tons, totals, results, difference, trim) comes from the template's own TABLES 54B
 * formulas, copied from its first tank row onto every tank row and evaluated with
 * PhpSpreadsheet. The generated file keeps those formulas, so Excel recalculates the same
 * numbers on open.
 *
 * Drafts live in survey_form_drafts (one row per form, optimistic `version` lock so two
 * tabs/devices can't silently overwrite each other). Original photos are stored unchanged
 * in uploads/survey_forms/<draft id>/. Nothing here changes the survey's status: making
 * the form or the Excel never completes a survey.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php'; // PhpSpreadsheet

const SF_FORMATS = [
    'offhire' => ['label' => 'Offhire',         'match' => '/(?<!PARTY )OFFHIRE FORMAT\.xlsx$/i'],
    'onhire'  => ['label' => 'Onhire',          'match' => '/ONHIRE FORMAT\.xlsx$/i'],
    '3party'  => ['label' => '3 Party Offhire', 'match' => '/3 PARTY OFFHIRE FORMAT\.xlsx$/i'],
    'rob'     => ['label' => 'ROB / 221B',      'match' => '/ROB OR 221B FORMAT\.xlsx$/i'],
];

// Ship details: key => [label, type, labels in the SHIP DETAILS sheet's column B, column, group]
// Types: text, int, num:<decimals>, imo, date, time, month. A field is offered only when the
// chosen template has its label.
const SF_FIELDS = [
    'vessel_name'      => ['Vessel name', 'text', ['Vessel Name'], 'C', 'vessel'],
    'imo'              => ['IMO number', 'imo', ['IMO', 'IMO No.'], 'C', 'vessel'],
    'flag'             => ['Flag', 'text', ['Flag'], 'C', 'vessel'],
    'port_of_registry' => ['Port of registry', 'text', ['Port of Registry'], 'C', 'vessel'],
    'grt'              => ['GRT', 'int', ['GRT'], 'C', 'vessel'],
    'nrt'              => ['NRT', 'int', ['NRT'], 'C', 'vessel'],
    'voyage_no'        => ['Voyage no.', 'text', ['Voyage No.'], 'C', 'vessel'],
    'owners'           => ['Owners', 'text', ['Owners'], 'C', 'parties'],
    'charterers'       => ['Charterers', 'text', ['Charterers Name'], 'C', 'parties'],
    'next_charterer'   => ['Next charterer', 'text', ['Next Charterer Name'], 'C', 'parties'],
    'operator'         => ['Manager / operator', 'text', ['Manager/Operator Name'], 'C', 'parties'],
    'fwe_date'         => ['FWE date', 'date', ['FWE'], 'C', 'times'],
    'fwe_time'         => ['FWE time (LT)', 'time', ['FWE'], 'D', 'times'],
    'started_date'     => ['Survey started date', 'date', ['Survey Started'], 'C', 'times'],
    'started_time'     => ['Survey started time (LT)', 'time', ['Survey Started'], 'D', 'times'],
    'completed_date'   => ['Survey completed date', 'date', ['Survey Completed'], 'C', 'times'],
    'completed_time'   => ['Survey completed time (LT)', 'time', ['Survey Completed'], 'D', 'times'],
    'place'            => ['Place of survey', 'text', ['Place of Survey'], 'C', 'times'],
    'brob_fwe_vlsfo'   => ['BROB at FWE — VLSFO (MT)', 'num:3', ['FWE'], 'G', 'brob'],
    'brob_fwe_hsfo'    => ['BROB at FWE — HSFO (MT)', 'num:3', ['FWE'], 'H', 'brob'],
    'brob_fwe_lsmgo'   => ['BROB at FWE — LSMGO (MT)', 'num:3', ['FWE'], 'I', 'brob'],
    'brob_started_vlsfo' => ['BROB at survey start — VLSFO (MT)', 'num:3', ['Survey Started'], 'G', 'brob'],
    'brob_started_hsfo'  => ['BROB at survey start — HSFO (MT)', 'num:3', ['Survey Started'], 'H', 'brob'],
    'brob_started_lsmgo' => ['BROB at survey start — LSMGO (MT)', 'num:3', ['Survey Started'], 'I', 'brob'],
    'draft_aft'        => ['Draft aft (m)', 'num:3', ['Draft Aft'], 'C', 'drafts'],
    'draft_fwd'        => ['Draft forward (m)', 'num:3', ['Draft Fwd'], 'C', 'drafts'],
    'list'             => ['List (degrees)', 'num:2', ['List'], 'C', 'drafts'],
    'master'           => ['Master name', 'text', ['Master Name'], 'C', 'crew'],
    'chief_engineer'   => ['Chief engineer name', 'text', ['Chief Engineer Name'], 'C', 'crew'],
    'surveyor_name'    => ['Surveyor name', 'text', ['Surveyor Name'], 'C', 'crew'],
    'holds'            => ['No. of holds', 'int', ['No. Of Holds'], 'C', 'particulars'],
    'builder'          => ['Builder', 'text', ['Builder'], 'C', 'particulars'],
    'keel_month'       => ['Keel laid month', 'month', ['Keel laid Month'], 'C', 'particulars'],
    'keel_year'        => ['Keel laid year', 'int', ['Keel laid Year'], 'C', 'particulars'],
    'callsign'         => ['Call sign', 'text', ['Callsign'], 'C', 'particulars'],
    'loa'              => ['LOA (m)', 'num:3', ['LOA'], 'C', 'particulars'],
    'breadth'          => ['Breadth moulded (m)', 'num:3', ['Breadth Moulded'], 'C', 'particulars'],
    'depth'            => ['Depth moulded (m)', 'num:3', ['Depth Moulded'], 'C', 'particulars'],
    'summer_draft'     => ['Summer draft (m)', 'num:3', ['Summer Draft'], 'C', 'particulars'],
    'summer_dwt'       => ['Summer deadweight', 'int', ['Summer Deadweight'], 'C', 'particulars'],
    'class'            => ['Class', 'text', ['Class'], 'C', 'particulars'],
];
const SF_GROUPS = [
    'vessel' => 'Vessel', 'parties' => 'Owners & charterers', 'times' => 'Survey dates & place',
    'brob' => 'Bunkers ROB as per log book', 'drafts' => 'Drafts & list', 'crew' => 'Master, chief engineer & surveyor',
    'particulars' => 'Ship particulars',
];
// Fields the final report can't do without (shown as "Missing" on the review step).
const SF_REQUIRED = ['vessel_name', 'imo', 'started_date', 'started_time', 'completed_date', 'completed_time', 'place', 'draft_aft', 'draft_fwd'];

// Tank sections of TABLES 54B, found by their heading in column B.
const SF_SECTIONS = [
    'vlsfo' => ['label' => 'VLSFO', 'heading' => '/very\s+low\s+sul/i'],
    'hsfo'  => ['label' => 'HSFO',  'heading' => '/high\s+sul/i'],
    'lsmgo' => ['label' => 'LSMGO', 'heading' => '/gas\s+oil/i'],
];
// Tank row inputs: key => [label, column, type]. "height" values may also be text such as GAUGE.
const SF_TANK_COLS = [
    'name'       => ['Tank name', 'B', 'text'],
    'ref_height' => ['Ref. height (m)', 'C', 'height'],
    'sounding'   => ['Sounding corr. (m)', 'D', 'height'],
    'volume'     => ['Volume (m³)', 'E', 'num:3'],
    'density'    => ['Density @15°C', 'F', 'num:4'],
    'temp'       => ['Temp (°C)', 'G', 'num:1'],
];
// Derived columns of a tank row: their formulas are copied from the template's first tank row.
const SF_FORMULA_COLS = ['H', 'I', 'J', 'T', 'U', 'W', 'X'];
const SF_MAX_TANKS_PER_SECTION = 40;
const SF_IMAGE_EXT = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
    'heic' => 'image/heic', 'heif' => 'image/heif', 'pdf' => 'application/pdf'];
const SF_IMAGE_MAX_BYTES = 15 * 1024 * 1024;
const SF_EDIT_STATUSES = ['Pending Vessel', 'Pending Report'];

function sfUploadDir(): string { return dirname(__DIR__) . '/uploads/survey_forms'; }

// ---------------------------------------------------------------- templates

/** Newest file in formats/ for this format, or ''. */
function sfTemplatePath(string $key): string {
    if (!isset(SF_FORMATS[$key])) return '';
    $best = '';
    foreach (glob(dirname(__DIR__) . '/formats/*.xlsx') ?: [] as $f) {
        if (preg_match(SF_FORMATS[$key]['match'], basename($f)) && strcmp(basename($f), basename($best)) > 0) $best = $f;
    }
    return $best;
}

function sfAvailableFormats(): array {
    $out = [];
    foreach (SF_FORMATS as $k => $f) if (sfTemplatePath($k) !== '') $out[$k] = $f['label'];
    return $out;
}

/** Best format for the survey's types (Onhire / 3 party / ROB / Offhire), or ''. */
function sfSuggestFormat(string $typeNames): string {
    if (preg_match('/3\s*party|three\s*party/i', $typeNames)) return '3party';
    if (preg_match('/(?<![a-z])on[\s\-]*hire/i', $typeNames)) return 'onhire';
    if (preg_match('/off[\s\-]*hire/i', $typeNames)) return 'offhire';
    if (preg_match('/\bROB\b|221\s*B/i', $typeNames)) return 'rob';
    return '';
}

function sfLoadSpreadsheet(string $path): \PhpOffice\PhpSpreadsheet\Spreadsheet {
    $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
    // Some approved formats carry a cell with a broken reference (#REF!) on the printable
    // sheet; Excel just shows #REF! there — don't let it stop the whole calculation.
    \PhpOffice\PhpSpreadsheet\Calculation\Calculation::getInstance($book)->setSuppressFormulaErrors(true);
    return $book;
}

/**
 * Where things are in this template: SHIP DETAILS row per label, and each TABLES 54B
 * section's first/last tank row and total rows. Returns null when the workbook isn't a
 * bunker calculation format.
 */
function sfAnalyze(\PhpOffice\PhpSpreadsheet\Spreadsheet $book): ?array {
    $sd = $book->getSheetByName('SHIP DETAILS');
    $t = $book->getSheetByName('TABLES 54B');
    if (!$sd || !$t) return null;
    $labelRow = [];
    for ($r = 1; $r <= 60; $r++) {
        $lab = strtolower(trim((string)$sd->getCell('B' . $r)->getValue()));
        if ($lab !== '' && !isset($labelRow[$lab])) $labelRow[$lab] = $r;
    }
    $fields = [];
    foreach (SF_FIELDS as $key => [$label, $type, $labels, $col]) {
        foreach ($labels as $l) {
            if (isset($labelRow[strtolower($l)])) { $fields[$key] = $col . $labelRow[strtolower($l)]; break; }
        }
    }
    // Fuel column headings over the BROB cells (ROB/221B calls the gas oil "MDO")
    $fuelNames = ['vlsfo' => 'VLSFO', 'hsfo' => 'HSFO', 'lsmgo' => 'LSMGO'];
    $hdrRow = isset($labelRow['fwe']) ? $labelRow['fwe'] - 1 : 10;
    foreach (['G' => 'vlsfo', 'H' => 'hsfo', 'I' => 'lsmgo'] as $c => $k) {
        $h = trim((string)$sd->getCell($c . $hdrRow)->getValue());
        if ($h !== '') $fuelNames[$k] = $h;
    }

    $sections = [];
    $maxRow = min($t->getHighestRow(), 300);
    foreach (SF_SECTIONS as $key => $def) {
        for ($r = 15; $r <= $maxRow; $r++) {
            if (!preg_match($def['heading'], (string)$t->getCell('B' . $r)->getValue())) continue;
            for ($tot = $r + 1; $tot <= $maxRow; $tot++) {
                if (stripos((string)$t->getCell('F' . $tot)->getValue(), 'TOTAL Cubic') !== false) break;
            }
            if ($tot > $maxRow) return null;
            $sections[$key] = ['heading' => $r, 'first' => $r + 3, 'last' => $tot - 1, 'gsv_total' => $tot, 'mt_total' => $tot + 1];
            break;
        }
        if (!isset($sections[$key])) return null;
    }
    // Formula pattern of a tank row, taken from the template itself (first tank row that has it)
    $pattern = [];
    foreach ($sections as $s) {
        for ($r = $s['first']; $r <= $s['last'] && !$pattern; $r++) {
            $h = (string)$t->getCell('H' . $r)->getValue();
            if (strpos($h, 'EXP(') === false) continue;
            foreach (SF_FORMULA_COLS as $c) {
                $f = (string)$t->getCell($c . $r)->getValue();
                if ($f === '' || $f[0] !== '=') { $pattern = []; break; }
                $pattern[$c] = preg_replace('/(?<![$A-Z])([A-Z]{1,2})' . $r . '(?!\d)/', '$1{r}', $f);
            }
        }
    }
    if (count($pattern) !== count(SF_FORMULA_COLS)) return null;
    // The two Table 54B constants the alpha formula points at ($U$21, $U$22) — for the
    // on-screen preview only; the Excel file always uses its own cells.
    $constants = [];
    if (preg_match_all('/\$([A-Z]{1,2})\$(\d+)/', $pattern['U'], $m, PREG_SET_ORDER)) {
        foreach ($m as $ref) $constants[] = (float)$t->getCell($ref[1] . $ref[2])->getValue();
    }
    return ['fields' => $fields, 'sections' => $sections, 'pattern' => $pattern, 'fuel_names' => $fuelNames, 'constants' => array_slice($constants, 0, 2)];
}

/** Template layout for a format key, cached per request. */
function sfTemplateInfo(string $key): ?array {
    static $cache = [];
    if (!array_key_exists($key, $cache)) {
        $path = sfTemplatePath($key);
        $cache[$key] = $path !== '' ? sfAnalyze(sfLoadSpreadsheet($path)) : null;
        if ($cache[$key]) $cache[$key]['path'] = $path;
    }
    return $cache[$key];
}

// ---------------------------------------------------------------- values & validation

/** Empty form for a format: every offered field, and the template's own tank names as rows. */
function sfEmptyData(string $key): array {
    $info = sfTemplateInfo($key);
    $data = ['fields' => [], 'tanks' => []];
    foreach (array_keys($info['fields'] ?? []) as $f) $data['fields'][$f] = ['v' => ''];
    foreach (array_keys(SF_SECTIONS) as $s) $data['tanks'][$s] = [];
    return $data;
}

/**
 * Validate one value. Returns [normalised value, error message or ''].
 * Never rounds a number: more decimals than the template shows is an error, because the
 * formats use Excel's "precision as displayed".
 */
function sfValidate(string $type, $raw): array {
    $v = trim(preg_replace('/\s+/u', ' ', (string)$raw));
    if ($v === '') return ['', ''];
    if ($type === 'text') return [mb_substr($v, 0, 200), mb_strlen($v) > 200 ? 'Too long (200 characters max).' : ''];
    if ($type === 'height') {
        if (preg_match('/^-?\d+(\.\d+)?$/', $v)) return sfValidate('num:3', $v);
        return [mb_substr($v, 0, 30), mb_strlen($v) > 30 ? 'Too long (30 characters max).' : ''];
    }
    if ($type === 'month') {
        $months = ['JANUARY','FEBRUARY','MARCH','APRIL','MAY','JUNE','JULY','AUGUST','SEPTEMBER','OCTOBER','NOVEMBER','DECEMBER'];
        $u = strtoupper($v);
        foreach ($months as $m) if ($u === $m || $u === substr($m, 0, 3)) return [$m, ''];
        return [$v, 'Enter a month name, e.g. MAY.'];
    }
    if ($type === 'int') {
        $n = str_replace(',', '', $v);
        return preg_match('/^\d{1,9}$/', $n) ? [$n, ''] : [$v, 'Whole number only.'];
    }
    if ($type === 'imo') {
        $n = preg_replace('/^IMO\s*(NO\.?)?\s*/i', '', $v);
        if (!preg_match('/^\d{7}$/', $n)) return [$v, 'IMO number has 7 digits.'];
        $sum = 0;
        for ($i = 0; $i < 6; $i++) $sum += (int)$n[$i] * (7 - $i);
        return $sum % 10 === (int)$n[6] ? [$n, ''] : [$n, 'IMO check digit does not match — please re-check the number.'];
    }
    if ($type === 'date') {
        $d = DateTime::createFromFormat('!Y-m-d', $v);
        return $d && $d->format('Y-m-d') === $v && $v >= '1990-01-01' && $v <= '2100-12-31' ? [$v, ''] : [$v, 'Enter a valid date.'];
    }
    if ($type === 'time') {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? [$v, ''] : [$v, 'Enter the time as HH:MM (24 h).'];
    }
    if (strpos($type, 'num:') === 0) {
        $dec = (int)substr($type, 4);
        $n = str_replace(',', '', $v);
        if (!preg_match('/^-?\d+(\.(\d+))?$/', $n, $m)) return [$v, 'Numbers only.'];
        if (isset($m[2]) && strlen($m[2]) > $dec) return [$n, 'Use at most ' . $dec . ' decimal' . ($dec === 1 ? '' : 's') . ' (as the Excel format shows).'];
        return [$n, ''];
    }
    return [$v, ''];
}

/** Range checks on top of the format check, for values a misread digit typically breaks. */
function sfRangeError(string $what, string $v): string {
    if ($v === '' || !is_numeric($v)) return '';
    $n = (float)$v;
    $ranges = [
        'density' => [0.6, 1.1, 'Density @15°C is normally between 0.6000 and 1.1000.'],
        'temp' => [-10, 150, 'Temperature looks wrong (−10 to 150 °C).'],
        'volume' => [0, 100000, 'Volume must be 0 or more.'],
        'draft_aft' => [0, 30, 'Draft must be between 0 and 30 m.'],
        'draft_fwd' => [0, 30, 'Draft must be between 0 and 30 m.'],
        'list' => [-45, 45, 'List must be between −45 and 45 degrees.'],
        'brob' => [0, 100000, 'BROB must be 0 or more.'],
    ];
    if (!isset($ranges[$what])) return '';
    [$lo, $hi, $msg] = $ranges[$what];
    return ($n < $lo || $n > $hi) ? $msg : '';
}

/**
 * Clean posted form data against the format: unknown keys dropped, values normalised.
 * Returns [data, errors] — errors keyed "f:<field>" / "t:<section>:<row>:<col>".
 */
function sfCleanData(string $key, array $in): array {
    $info = sfTemplateInfo($key);
    $out = ['fields' => [], 'tanks' => []];
    $errors = [];
    foreach (array_keys($info['fields']) as $f) {
        $cell = is_array($in['fields'][$f] ?? null) ? $in['fields'][$f] : ['v' => $in['fields'][$f] ?? ''];
        [$v, $err] = sfValidate(SF_FIELDS[$f][1], $cell['v'] ?? '');
        if ($err === '') $err = sfRangeError(strpos($f, 'brob_') === 0 ? 'brob' : $f, $v);
        if ($err !== '') $errors['f:' . $f] = $err;
        $out['fields'][$f] = ['v' => $v] + sfCleanMeta($cell);
    }
    foreach (array_keys(SF_SECTIONS) as $s) {
        $out['tanks'][$s] = [];
        foreach (array_slice(array_values((array)($in['tanks'][$s] ?? [])), 0, SF_MAX_TANKS_PER_SECTION) as $i => $row) {
            $clean = [];
            foreach (SF_TANK_COLS as $c => [$label, $col, $type]) {
                $cell = is_array($row[$c] ?? null) ? $row[$c] : ['v' => $row[$c] ?? ''];
                [$v, $err] = sfValidate($type, $cell['v'] ?? '');
                if ($err === '') $err = sfRangeError($c, $v);
                if ($err !== '') $errors["t:$s:$i:$c"] = $err;
                $clean[$c] = ['v' => $v] + sfCleanMeta($cell);
            }
            // The V.C.F. formula only runs when a temperature is given; then it also needs volume and density.
            if ($clean['temp']['v'] !== '') {
                foreach (['volume', 'density'] as $need) {
                    if ($clean[$need]['v'] === '' && !isset($errors["t:$s:$i:$need"])) $errors["t:$s:$i:$need"] = 'Needed when a temperature is entered.';
                }
            }
            $out['tanks'][$s][] = $clean;
        }
    }
    return [$out, $errors];
}

/** Keep only the provenance markers we know: src ai|edited|manual, conf 0..1, suggestion text. */
function sfCleanMeta(array $cell): array {
    $m = [];
    if (in_array($cell['src'] ?? '', ['ai', 'edited', 'manual'], true)) $m['src'] = $cell['src'];
    if (isset($cell['conf']) && is_numeric($cell['conf'])) $m['conf'] = max(0, min(1, round((float)$cell['conf'], 2)));
    if (isset($cell['suggest']) && is_string($cell['suggest']) && $cell['suggest'] !== '') $m['suggest'] = mb_substr($cell['suggest'], 0, 200);
    return $m;
}

// ---------------------------------------------------------------- workbook

/** "2026-01-28" → "28th JANUARY 2026" (the formats' own date style). */
function sfDateText(string $ymd): string {
    $d = DateTime::createFromFormat('!Y-m-d', $ymd);
    if (!$d) return $ymd;
    $day = (int)$d->format('j');
    $suffix = ($day % 100 >= 11 && $day % 100 <= 13) ? 'th' : (['th','st','nd','rd','th','th','th','th','th','th'][$day % 10]);
    return $day . $suffix . ' ' . strtoupper($d->format('F')) . ' ' . $d->format('Y');
}

/**
 * Fill the approved template with the form. Template sample values are cleared, so only
 * what the user has is in the file. Extra tank rows are inserted inside their section,
 * every tank row gets the template's formulas and the section totals cover exactly its
 * tank rows (which also corrects the HSFO total that overlapped the LSMGO rows).
 */
function sfBuildWorkbook(string $key, array $data): \PhpOffice\PhpSpreadsheet\Spreadsheet {
    $info = sfTemplateInfo($key);
    if (!$info) throw new RuntimeException('Template not available for this format.');
    $book = sfLoadSpreadsheet($info['path']);
    $sd = $book->getSheetByName('SHIP DETAILS');
    $t = $book->getSheetByName('TABLES 54B');

    foreach ($info['fields'] as $f => $coord) {
        $v = (string)($data['fields'][$f]['v'] ?? '');
        $type = SF_FIELDS[$f][1];
        $cell = $sd->getCell($coord);
        if ($v === '') { $cell->setValueExplicit(null, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NULL); continue; }
        if ($type === 'date') {
            $cell->setValueExplicit(sfDateText($v), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        } elseif ($type === 'time') {
            [$h, $m] = array_map('intval', explode(':', $v));
            $cell->setValue(round(($h * 60 + $m) / 1440, 10));
            $sd->getStyle($coord)->getNumberFormat()->setFormatCode('h:mm');
        } elseif ($type === 'int' || $type === 'imo' || strpos($type, 'num:') === 0) {
            $cell->setValue($type === 'int' || $type === 'imo' ? (int)$v : (float)$v);
        } else {
            $cell->setValueExplicit($v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
    }

    // Bottom section first, so inserting rows never moves a section still to be filled.
    $sections = $info['sections'];
    uasort($sections, fn($a, $b) => $b['first'] <=> $a['first']);
    foreach ($sections as $s => $sec) {
        $rows = array_values($data['tanks'][$s] ?? []);
        $first = $sec['first'];
        $cap = $sec['last'] - $first + 1;
        if (count($rows) > $cap) {
            $t->insertNewRowBefore($sec['last'] + 1, count($rows) - $cap);
            sfShiftSheetRefs($book, $t->getTitle(), $sec['last'] + 1, count($rows) - $cap);
        }
        $last = $first + max($cap, count($rows)) - 1;
        for ($r = $first; $r <= $last; $r++) {
            $row = $rows[$r - $first] ?? [];
            foreach (SF_TANK_COLS as $c => [$label, $col, $type]) {
                $v = (string)($row[$c]['v'] ?? '');
                $cell = $t->getCell($col . $r);
                if ($v === '') $cell->setValueExplicit(null, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NULL);
                elseif ($type === 'text' || !is_numeric($v)) $cell->setValueExplicit($v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                else $cell->setValue((float)$v);
            }
            foreach ($info['pattern'] as $col => $f) $t->getCell($col . $r)->setValue(str_replace('{r}', (string)$r, $f));
        }
        $gsvRow = $last + 1;
        $t->getCell('I' . $gsvRow)->setValue("=SUM(I{$first}:I{$last})");
        $t->getCell('J' . ($gsvRow + 1))->setValue("=SUM(J{$first}:J{$last})");
    }
    return $book;
}

/**
 * PhpSpreadsheet moves references inside the sheet where rows are inserted, but not the
 * ones other sheets make to it (the printable certificate and REPORT sheets point at the
 * TABLES 54B totals). Shift those: every row number >= $before moves down by $n.
 */
function sfShiftSheetRefs(\PhpOffice\PhpSpreadsheet\Spreadsheet $book, string $sheetName, int $before, int $n): void {
    $quoted = "'" . str_replace("'", "''", $sheetName) . "'!";
    $pattern = '/' . preg_quote($quoted, '/') . '(\$?[A-Z]{1,3}\$?)(\d+)(?::(\$?[A-Z]{1,3}\$?)(\d+))?/';
    $shift = fn($row) => (int)$row >= $before ? (string)((int)$row + $n) : $row;
    foreach ($book->getWorksheetIterator() as $ws) {
        if ($ws->getTitle() === $sheetName) continue;
        foreach ($ws->getCoordinates() as $coord) {
            $cell = $ws->getCell($coord);
            $f = $cell->getValue();
            if (!is_string($f) || $f === '' || $f[0] !== '=' || strpos($f, $quoted) === false) continue;
            $cell->setValue(preg_replace_callback($pattern, fn($m) => $quoted . $m[1] . $shift($m[2])
                . (isset($m[3]) && $m[3] !== '' ? ':' . $m[3] . $shift($m[4]) : ''), $f));
        }
    }
}

/** Number or null from a calculated cell. */
function sfNum($v): ?float {
    return (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) ? round((float)$v, 4) : null;
}

/** Calculated results of a filled workbook (the template's own formulas, evaluated). */
function sfResults(\PhpOffice\PhpSpreadsheet\Spreadsheet $book, string $key, array $data): array {
    $info = sfAnalyze($book); // positions after any inserted rows
    $t = $book->getSheetByName('TABLES 54B');
    $calc = fn($c) => sfNum($t->getCell($c)->getCalculatedValue());
    $out = ['tanks' => [], 'totals' => [], 'fuel_names' => $info['fuel_names']];
    foreach ($info['sections'] as $s => $sec) {
        $n = count($data['tanks'][$s] ?? []);
        $out['tanks'][$s] = [];
        for ($i = 0; $i < $n; $i++) {
            $r = $sec['first'] + $i;
            $out['tanks'][$s][] = ['vcf' => $calc('H' . $r), 'gsv' => $calc('I' . $r), 'mt' => $calc('J' . $r)];
        }
        $out['totals'][$s] = ['gsv' => $calc('I' . $sec['gsv_total']), 'mt' => $calc('J' . $sec['mt_total'])];
    }
    $out['results'] = [];
    foreach (['vlsfo' => 'M', 'hsfo' => 'N', 'lsmgo' => 'O'] as $s => $c) {
        $out['results'][$s] = ['actual' => $calc($c . '18'), 'log' => $calc($c . '19'), 'difference' => $calc($c . '20')];
    }
    $out['difference_total'] = $calc('O21');
    $out['trim'] = $calc('I17');
    return $out;
}

/**
 * Save the filled workbook. PhpSpreadsheet stores the calculated values (so the app's own
 * readers see them); the workbook's "precision as displayed" setting is restored and Excel
 * is told to recalculate on open, with its own engine.
 */
function sfSaveWorkbook(\PhpOffice\PhpSpreadsheet\Spreadsheet $book, string $dest): void {
    $book->setActiveSheetIndex(0);
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book);
    $writer->save($dest);
    $zip = new ZipArchive();
    if ($zip->open($dest) === true) {
        $wb = (string)$zip->getFromName('xl/workbook.xml');
        $wb = preg_replace('/<calcPr\b[^>]*\/>/', '<calcPr calcId="191028" fullPrecision="0" fullCalcOnLoad="1"/>', $wb, 1);
        $zip->addFromString('xl/workbook.xml', $wb);
        $zip->close();
    }
}

// ---------------------------------------------------------------- drafts

function sfEnsureTable(PDO $db): void {
    static $done = false;
    if ($done) return;
    $db->exec("CREATE TABLE IF NOT EXISTS `survey_form_drafts` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `survey_id` INT NOT NULL,
        `format_key` VARCHAR(20) NOT NULL,
        `data` MEDIUMTEXT NOT NULL,
        `images` MEDIUMTEXT NOT NULL,
        `generated` MEDIUMTEXT NOT NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
        `version` INT NOT NULL DEFAULT 1,
        `created_by` INT NULL,
        `updated_by` INT NULL,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NOT NULL,
        KEY `idx_survey` (`survey_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** Survey row (with type names) or null. */
function sfSurvey(PDO $db, int $surveyId): ?array {
    $st = $db->prepare("SELECT s.*, t.type_name, c.company_name, p.port_name FROM surveys s
        LEFT JOIN survey_types t ON t.id = s.survey_type_id LEFT JOIN clients c ON c.id = s.client_id
        LEFT JOIN ports p ON p.id = s.port_id WHERE s.id = ?");
    $st->execute([$surveyId]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) return null;
    $s['types'] = function_exists('getCombinedSurveyTypeNames') ? getCombinedSurveyTypeNames($db, $s['survey_type_ids'] ?? '', $s['type_name'] ?? '') : (string)$s['type_name'];
    return $s;
}

/** Admin / Super Admin: any survey; Surveyor: surveys assigned to them; nobody else. */
function sfCanAccess(PDO $db, array $survey): bool {
    $role = (string)($_SESSION['role'] ?? '');
    if (in_array($role, ['Admin', 'Super Admin'], true)) return true;
    return $role === 'Surveyor' && isSurveyorAssignedToSurvey($db, (int)$survey['id'], (int)($_SESSION['user_id'] ?? 0));
}

/** Drafts can be changed while the survey is Pending Vessel / Pending Report. */
function sfCanEdit(array $survey): bool {
    return in_array((string)$survey['status'], SF_EDIT_STATUSES, true);
}

function sfDraft(PDO $db, int $id): ?array {
    sfEnsureTable($db);
    $st = $db->prepare('SELECT d.*, u.full_name AS updated_by_name FROM survey_form_drafts d LEFT JOIN users u ON u.id = d.updated_by WHERE d.id = ?');
    $st->execute([$id]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    if (!$d) return null;
    foreach (['data', 'images', 'generated'] as $k) $d[$k] = json_decode((string)$d[$k], true) ?: [];
    return $d;
}

function sfDraftsForSurvey(PDO $db, int $surveyId): array {
    sfEnsureTable($db);
    $st = $db->prepare('SELECT d.id, d.format_key, d.status, d.updated_at, d.images, d.generated, u.full_name AS updated_by_name
        FROM survey_form_drafts d LEFT JOIN users u ON u.id = d.updated_by WHERE d.survey_id = ? ORDER BY d.updated_at DESC, d.id DESC');
    $st->execute([$surveyId]);
    return array_map(function ($d) {
        $d['image_count'] = count(json_decode((string)$d['images'], true) ?: []);
        $d['generated_count'] = count(json_decode((string)$d['generated'], true) ?: []);
        unset($d['images'], $d['generated']);
        return $d;
    }, $st->fetchAll(PDO::FETCH_ASSOC));
}

function sfCreateDraft(PDO $db, int $surveyId, string $key, array $prefill, int $userId): int {
    sfEnsureTable($db);
    $data = sfEmptyData($key);
    foreach ($prefill as $f => $v) {
        if (isset($data['fields'][$f]) && (string)$v !== '') $data['fields'][$f] = ['v' => (string)$v, 'src' => 'manual'];
    }
    $st = $db->prepare("INSERT INTO survey_form_drafts (survey_id, format_key, data, images, generated, status, version, created_by, updated_by, created_at, updated_at)
        VALUES (?, ?, ?, '[]', '[]', 'draft', 1, ?, ?, NOW(), NOW())");
    $st->execute([$surveyId, $key, json_encode($data, JSON_UNESCAPED_UNICODE), $userId, $userId]);
    return (int)$db->lastInsertId();
}

/**
 * Save the form only if nobody saved it since this copy was loaded ($version).
 * Returns the new version, or 0 when the draft changed elsewhere (nothing written).
 */
function sfSaveDraftData(PDO $db, int $id, int $version, array $data, int $userId): int {
    $st = $db->prepare('UPDATE survey_form_drafts SET data = ?, version = version + 1, updated_by = ?, updated_at = NOW() WHERE id = ? AND version = ?');
    $st->execute([json_encode($data, JSON_UNESCAPED_UNICODE), $userId, $id, $version]);
    return $st->rowCount() === 1 ? $version + 1 : 0;
}

/** Add/replace images or generated files list atomically (independent of the form version). */
function sfUpdateList(PDO $db, int $id, string $col, callable $change, int $userId): array {
    if (!in_array($col, ['images', 'generated'], true)) throw new InvalidArgumentException('bad column');
    $db->beginTransaction();
    try {
        $st = $db->prepare("SELECT `$col` FROM survey_form_drafts WHERE id = ? FOR UPDATE");
        $st->execute([$id]);
        $list = json_decode((string)$st->fetchColumn(), true) ?: [];
        $list = $change($list);
        $db->prepare("UPDATE survey_form_drafts SET `$col` = ?, updated_by = ?, updated_at = NOW()" . ($col === 'generated' ? ", status = 'generated'" : '') . " WHERE id = ?")
           ->execute([json_encode(array_values($list), JSON_UNESCAPED_UNICODE), $userId, $id]);
        $db->commit();
        return $list;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/** Values the app already knows about the vessel, used to pre-fill a new form. */
function sfPrefill(array $survey): array {
    $vn = trim((string)($survey['vessel_name'] ?? ''));
    $p = ['vessel_name' => $vn];
    if (!empty($survey['imo_number']) && preg_match('/^\d{7}$/', (string)$survey['imo_number'])) $p['imo'] = (string)$survey['imo_number'];
    if (!empty($_SESSION['full_name']) && ($_SESSION['role'] ?? '') === 'Surveyor') $p['surveyor_name'] = strtoupper((string)$_SESSION['full_name']);
    return $p;
}

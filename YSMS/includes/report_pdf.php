<?php
/**
 * Report Generator — PDF input source (report_generator.php, "Upload PDF").
 *
 * A PDF adapter next to the existing Excel one: it produces values keyed by the same
 * REPORT_FIELDS ids the Excel "REPORT" sheet fills, so the page's existing form, checks and
 * Word generation are used unchanged.
 *
 *  1. Text layer per page (smalot/pdfparser) — tells text pages from scanned ones and is
 *     used to VERIFY what the AI read: a value on a text page that isn't printed there is
 *     flagged for review instead of being trusted.
 *  2. Gemini reads the PDF itself (text, tables, scans, handwriting) into those field ids,
 *     with the page number; unreadable values stay null.
 *  3. Tank readings never become Word fields (the template has none); they are only used to
 *     calculate the fuel totals with the approved TABLES 54B formulas (includes/survey_form.php)
 *     and to cross-check totals printed in the documents.
 *
 * The uploaded file is processed from a temporary file that is deleted at once; nothing is
 * stored and document contents are never logged.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/gemini_json.php';
require_once __DIR__ . '/survey_form.php';

const RP_MAX_BYTES = 14 * 1024 * 1024;   // sent inline to Gemini (20 MB request limit after base64)
const RP_MAX_PAGES = 60;
const RP_CONFIDENT = 0.8;
const RP_FIELD_TYPES = ['text', 'date', 'time', 'number', 'select'];
// Report Generator tab → approved Excel calculation format used for the tank totals
const RP_SECTION_FORMAT = ['offhire' => 'offhire', 'offhire-condition' => 'offhire', 'onhire' => 'onhire', 'onhire-condition' => 'onhire',
    '3party-rrr' => '3party', '3party-rrd' => '3party'];

/** Field definitions posted by the page (its REPORT_FIELDS), cleaned. */
function rpCleanFieldDefs($raw): array {
    $out = [];
    foreach (array_slice(is_array($raw) ? $raw : [], 0, 120) as $f) {
        $id = (string)($f['id'] ?? '');
        $type = (string)($f['type'] ?? 'text');
        if (!preg_match('/^[A-Z][A-Z0-9_]{1,40}$/', $id) || !in_array($type, RP_FIELD_TYPES, true)) continue;
        $out[$id] = [
            'label' => mb_substr(trim((string)($f['label'] ?? $id)), 0, 80),
            'type' => $type,
            'precision' => isset($f['precision']) ? max(0, min(4, (int)$f['precision'])) : null,
            'options' => $type === 'select' ? array_slice(array_map(fn($o) => mb_substr((string)$o, 0, 80), (array)($f['options'] ?? [])), 0, 30) : [],
        ];
    }
    return $out;
}

/** Text of each page (1-based), or [] when the PDF has no readable text layer. Throws on unreadable / protected PDFs. */
function rpPageTexts(string $path): array {
    $parser = new \Smalot\PdfParser\Parser();
    $pdf = $parser->parseFile($path);
    $texts = [];
    foreach ($pdf->getPages() as $i => $page) {
        if ($i >= RP_MAX_PAGES) break;
        try { $texts[$i + 1] = (string)$page->getText(); } catch (Throwable $e) { $texts[$i + 1] = ''; }
    }
    return $texts;
}

function rpNorm(string $s): string {
    return trim(preg_replace('/\s+/u', ' ', mb_strtolower(str_replace(["\xC2\xA0", "\t"], ' ', $s))));
}

/** Every number printed on a page, as floats. */
function rpNumbers(string $text): array {
    preg_match_all('/-?\d[\d,]*(?:\.\d+)?|-?\.\d+/', $text, $m);
    return array_map(fn($n) => (float)str_replace(',', '', $n), $m[0]);
}

/** Is $value printed in $text (allowing for spacing, case, number and date formatting)? */
function rpFoundIn(string $value, string $type, string $text): bool {
    if (trim($text) === '') return false;
    $t = rpNorm($text);
    if ($type === 'number' || ($type === 'text' && preg_match('/^-?\d+(\.\d+)?$/', $value))) {
        $n = (float)$value;
        foreach (rpNumbers($text) as $x) if (abs($x - $n) < 1e-9) return true;
        return false;
    }
    if ($type === 'date' && ($d = DateTime::createFromFormat('!Y-m-d', $value))) {
        $day = (int)$d->format('j');
        $ord = $day . (($day % 100 >= 11 && $day % 100 <= 13) ? 'th' : ['th','st','nd','rd','th','th','th','th','th','th'][$day % 10]);
        foreach ([$d->format('d/m/Y'), $d->format('j/n/Y'), $d->format('d.m.Y'), $d->format('d-m-Y'), $d->format('Y-m-d'), $d->format('d/m/y'),
                  $d->format('d M Y'), $d->format('j M Y'), $d->format('d F Y'), $d->format('j F Y'), $ord . ' ' . $d->format('F Y'), $ord . ' ' . $d->format('M Y'),
                  $d->format('d-M-Y'), $d->format('d-M-y'), $d->format('M d, Y'), $d->format('F j, Y')] as $form) {
            if (strpos($t, rpNorm($form)) !== false) return true;
        }
        return false;
    }
    if ($type === 'time' && preg_match('/^(\d{2}):(\d{2})$/', $value, $m)) {
        foreach (["$m[1]:$m[2]", "$m[1].$m[2]", "$m[1]$m[2]", ((int)$m[1]) . ":$m[2]"] as $form) if (strpos($t, $form) !== false) return true;
        return false;
    }
    $v = rpNorm($value);
    if ($v === '') return false;
    if (strpos($t, $v) !== false) return true;
    // ignore punctuation differences ("DAVA PTE. LTD" vs "DAVA PTE LTD")
    $strip = fn($s) => preg_replace('/[^\p{L}\p{N}]+/u', '', $s);
    return $strip($v) !== '' && strpos($strip($t), $strip($v)) !== false;
}

function rpPrompt(array $defs, string $section): string {
    $lines = [];
    foreach ($defs as $id => $d) {
        $fmt = ['date' => 'YYYY-MM-DD', 'time' => 'HH:MM 24h', 'number' => 'number as printed'][$d['type']] ?? 'text';
        if ($d['type'] === 'select') $fmt = 'one of: ' . implode(' | ', $d['options']);
        $lines[] = "  \"$id\": {$d['label']} ($fmt)";
    }
    $onhire = strpos($section, 'onhire') !== false;
    return "You read documents for a ship's bunker survey report (ship particulars, bunker delivery notes, sounding / ullage sheets, survey reports, log extracts).\n"
        . "This is an " . ($onhire ? 'ONHIRE (delivery)' : 'OFFHIRE (redelivery)') . " report.\n"
        . "Extract ONLY values that are clearly printed or written in this PDF. Copy them exactly — do not round, convert units, correct, guess or calculate.\n"
        . "If a value is not in the document, is cut off or illegible, use null. Never fill a value from general knowledge. Many fields will be null.\n"
        . "For every value give the 1-based PDF page number it is on, and a confidence between 0 and 1.\n\n"
        . "Fields (id: meaning (format)):\n" . implode("\n", $lines) . "\n\n"
        . "Fuel quantities (*_VLSFO / *_HSFO / *_LSMGO, metric tons): only a total that is printed for that fuel — never add up tanks yourself. MGO/MDO/LSMGO all count as LSMGO.\n"
        . "Tank readings at the time of survey, per fuel (vlsfo / hsfo / lsmgo), if the document has a sounding table: name, ref_height (m), sounding (corrected sounding/ullage in m, or text such as GAUGE), volume (m³ as printed), density (@15°C), temp (°C). Do not return V.C.F., G.S.V. or metric tons per tank.\n"
        . "printed_totals: the TOTAL metric tons per fuel printed under a sounding table, else null.\n\n"
        . "Reply with JSON only:\n"
        . "{\"document_type\": string, \"fields\": {\"<id>\": {\"value\": string|null, \"confidence\": number, \"page\": number|null}},\n"
        . " \"tanks\": {\"vlsfo\": [{\"name\": {\"value\": ..., \"confidence\": ..., \"page\": ...}, \"ref_height\": {...}, \"sounding\": {...}, \"volume\": {...}, \"density\": {...}, \"temp\": {...}}], \"hsfo\": [], \"lsmgo\": []},\n"
        . " \"printed_totals\": {\"vlsfo\": string|null, \"hsfo\": string|null, \"lsmgo\": string|null}, \"notes\": string}";
}

/** Normalise one field value by type. Returns [value, problem] — value '' when it can't be used. */
function rpNormalise(array $def, string $v): array {
    $v = trim(preg_replace('/\s+/u', ' ', $v));
    switch ($def['type']) {
        case 'date':
            $d = DateTime::createFromFormat('!Y-m-d', $v);
            return $d && $d->format('Y-m-d') === $v ? [$v, ''] : ['', "read as \"$v\" — not a clear date"];
        case 'time':
            if (preg_match('/^(\d{1,2})[:.]?(\d{2})$/', $v, $m) && (int)$m[1] < 24 && (int)$m[2] < 60) return [sprintf('%02d:%s', $m[1], $m[2]), ''];
            return ['', "read as \"$v\" — not a clear time"];
        case 'number':
            $n = str_replace([',', ' '], '', $v);
            return preg_match('/^-?\d+(\.\d+)?$/', $n) ? [$n, ''] : ['', "read as \"$v\" — not a number"];
        case 'select':
            foreach ($def['options'] as $o) if (rpNorm($o) === rpNorm($v)) return [$o, ''];
            return ['', "the PDF says \"$v\" — choose the matching option"];
    }
    return [mb_substr($v, 0, 200), ''];
}

/**
 * Read one PDF. Returns ['ok' => true, 'doc' => …] or ['ok' => false, 'message' => …, 'retry' => bool].
 * doc.fields[id] = {v, conf, page, status: ok|review, reason?, raw?}
 */
function rpExtract(string $path, array $defs, string $section): array {
    try {
        $texts = rpPageTexts($path);
    } catch (Throwable $e) {
        $msg = stripos($e->getMessage(), 'encrypt') !== false || stripos($e->getMessage(), 'password') !== false
            ? 'This PDF is password-protected. Save an unprotected copy and upload that.' : '';
        if ($msg !== '') return ['ok' => false, 'message' => $msg, 'retry' => false];
        $texts = []; // the text layer couldn't be read — the AI still reads the PDF itself
    }
    $pages = count($texts);
    $textPages = array_keys(array_filter($texts, fn($t) => mb_strlen(trim($t)) >= 40));
    $kind = !$pages || !$textPages ? 'scanned' : (count($textPages) === $pages ? 'text' : 'mixed');

    $res = geminiJson([['text' => rpPrompt($defs, $section)], ['inline_data' => ['mime_type' => 'application/pdf', 'data' => base64_encode((string)file_get_contents($path))]]], 180, 'report pdf');
    if (!$res['ok']) return ['ok' => false, 'message' => $res['message'], 'retry' => $res['retry'] ?? true];
    $raw = $res['data'];

    $pageOf = function ($p) use ($pages) { $p = is_numeric($p) ? (int)$p : 0; return ($p >= 1 && (!$pages || $p <= $pages)) ? $p : null; };
    $verify = function (string $v, string $type, ?int $page) use ($texts, $textPages): array {
        // [verified true|false|null (scanned page: can't check), page found on]
        if ($page !== null && in_array($page, $textPages, true)) {
            if (rpFoundIn($v, $type, $texts[$page])) return [true, $page];
        }
        foreach ($textPages as $p) if (rpFoundIn($v, $type, $texts[$p])) return [true, $p];
        $onScan = $page !== null ? !in_array($page, $textPages, true) : !$textPages;
        return [$onScan ? null : false, $page];
    };

    $doc = ['document_type' => mb_substr(trim((string)($raw['document_type'] ?? '')), 0, 60), 'pages' => $pages, 'kind' => $kind,
        'fields' => [], 'tanks' => [], 'printed_totals' => [], 'notes' => mb_substr(trim((string)($raw['notes'] ?? '')), 0, 400)];
    foreach ($defs as $id => $def) {
        $cell = $raw['fields'][$id] ?? null;
        if (!is_array($cell)) $cell = ['value' => $cell];
        $val = $cell['value'] ?? null;
        if ($val === null || is_array($val) || is_bool($val) || trim((string)$val) === '' || preg_match('/^(null|n\/?a|-+|unknown|illegible|not (stated|given|available))$/i', trim((string)$val))) continue;
        $conf = is_numeric($cell['confidence'] ?? null) ? max(0, min(1, (float)$cell['confidence'])) : 0.5;
        $page = $pageOf($cell['page'] ?? null);
        [$v, $problem] = rpNormalise($def, (string)$val);
        $out = ['v' => $v, 'conf' => round($conf, 2), 'page' => $page];
        if ($v === '') {
            $out += ['status' => 'review', 'reason' => $problem, 'raw' => mb_substr((string)$val, 0, 120)];
        } else {
            [$verified, $foundPage] = $verify($v, $def['type'], $page);
            $out['page'] = $foundPage;
            $out['verified'] = $verified;
            if ($verified === false) { $out['status'] = 'review'; $out['reason'] = 'not found in the PDF text — check'; }
            elseif ($conf < RP_CONFIDENT) { $out['status'] = 'review'; $out['reason'] = 'AI was not sure (' . round($conf * 100) . '%)'; }
            elseif ($verified === null && $conf < 0.9) { $out['status'] = 'review'; $out['reason'] = 'read from a scanned page — check'; }
            else $out['status'] = 'ok';
            if ($id === 'IMO' && ($e = sfValidate('imo', $v)[1]) !== '') { $out['status'] = 'review'; $out['reason'] = $e; }
            // More decimals than the report shows: never rounded here — the user decides.
            if ($def['precision'] !== null && preg_match('/^-?\d+\.(\d+)$/', $v, $dm) && strlen($dm[1]) > $def['precision']) {
                $out['status'] = 'review'; $out['reason'] = 'has more decimals than the report shows (' . $def['precision'] . ') — check';
            }
        }
        $doc['fields'][$id] = $out;
    }
    foreach (array_keys(SF_SECTIONS) as $s) {
        $doc['tanks'][$s] = [];
        foreach (array_slice((array)($raw['tanks'][$s] ?? []), 0, SF_MAX_TANKS_PER_SECTION) as $row) {
            if (!is_array($row)) continue;
            $r = [];
            foreach (SF_TANK_COLS as $c => [, , $type]) {
                $cell = $row[$c] ?? null;
                if (!is_array($cell)) $cell = ['value' => $cell];
                $val = $cell['value'] ?? null;
                if ($val === null || is_array($val) || is_bool($val) || trim((string)$val) === '') continue;
                $v = trim((string)$val);
                if ($type !== 'text' && $type !== 'height') $v = str_replace([' ', ','], '', $v);
                $page = $pageOf($cell['page'] ?? null);
                $r[$c] = ['v' => $v, 'conf' => is_numeric($cell['confidence'] ?? null) ? round(max(0, min(1, (float)$cell['confidence'])), 2) : 0.5, 'page' => $page];
            }
            if ($r) $doc['tanks'][$s][] = $r;
        }
        $pt = $raw['printed_totals'][$s] ?? null;
        $pt = is_scalar($pt) ? str_replace([',', ' '], '', (string)$pt) : '';
        $doc['printed_totals'][$s] = is_numeric($pt) ? $pt : null;
    }
    return ['ok' => true, 'doc' => $doc];
}

/**
 * Fuel totals from tank readings with the approved Excel format's own formulas.
 * $tanks: {vlsfo: [{name, ref_height, sounding, volume, density, temp} values as strings]}.
 * Returns ['ok' => true, 'totals' => {fuel: mt|null}, 'counts' => …] or ['ok' => false, 'errors' => …].
 */
function rpCalcTotals(string $section, array $tanks): array {
    $format = RP_SECTION_FORMAT[$section] ?? '';
    if ($format === '' || !sfTemplateInfo($format)) return ['ok' => false, 'errors' => ['format' => 'No approved Excel format available to calculate these tanks.']];
    $data = sfEmptyData($format);
    foreach (array_keys(SF_SECTIONS) as $s) {
        $data['tanks'][$s] = [];
        foreach (array_slice((array)($tanks[$s] ?? []), 0, SF_MAX_TANKS_PER_SECTION) as $row) {
            $r = [];
            foreach (array_keys(SF_TANK_COLS) as $c) $r[$c] = ['v' => is_scalar($row[$c] ?? null) ? (string)$row[$c] : ''];
            $data['tanks'][$s][] = $r;
        }
    }
    [$clean, $errors] = sfCleanData($format, $data);
    // A reading that is missing or doesn't fit the format is never guessed: no total for that fuel.
    $bad = [];
    foreach ($errors as $k => $msg) if (strpos($k, 't:') === 0) $bad[explode(':', $k)[1]][] = $k . ' ' . $msg;
    $book = sfBuildWorkbook($format, $clean);
    $res = sfResults($book, $format, $clean);
    $book->disconnectWorksheets();
    $totals = [];
    $counts = [];
    foreach (array_keys(SF_SECTIONS) as $s) {
        $rows = $clean['tanks'][$s];
        $counts[$s] = count($rows);
        $incomplete = array_filter($rows, fn($r) => $r['temp']['v'] === '' || $r['volume']['v'] === '' || $r['density']['v'] === '');
        $totals[$s] = (!$rows || isset($bad[$s]) || $incomplete) ? null : $res['totals'][$s]['mt'];
        if ($rows && $incomplete && !isset($bad[$s])) $bad[$s][] = count($incomplete) . ' tank(s) without volume, density or temperature';
    }
    return ['ok' => true, 'totals' => $totals, 'counts' => $counts, 'problems' => $bad, 'format' => SF_FORMATS[$format]['label']];
}

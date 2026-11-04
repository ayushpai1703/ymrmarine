<?php
/**
 * Survey report templates (the company's own Excel report, e.g. "MV. PROTECTOR - OFFHIRE - FIELD
 * REPORT.xlsx") and the engine that fills them from the website form.
 *
 * - Templates are uploaded by an Admin (admin_report_templates.php). Every upload is a new
 *   version kept as its own file under uploads/report_templates/; a file is never overwritten.
 *   One version per kind is active; reports keep the version they were made with.
 * - rtAnalyze() reads the workbook itself: where the SHIP DETAILS / OFFHIRE / REPORT values go
 *   (by their labels), the three TABLES 54B tank sections (by their headings), the formula
 *   pattern of a tank row (copied onto every tank row), the Table 54B constants, the values
 *   already in the file (used as test data) and anything that needs review (#REF!, missing
 *   totals, typed numbers where a formula is expected ...). An Admin can override any cell.
 * - rtBuild() fills a copy of the template: values into their cells, tank rows into their
 *   section (extra rows inserted inside the section, keeping the row formatting), the
 *   template's own formulas on every tank row and section totals that cover exactly the
 *   section's tank rows. Everything else in the workbook stays as it is.
 * - rtPdf() prints the filled workbook with LibreOffice (the real layout, A4).
 * Calculation methodology is never changed: V.C.F., G.S.V. and metric tons come from the
 * template's formulas. assets/js/report-calc.js repeats them for the on-screen preview and the
 * offline file only when rtAnalyze() recognised them exactly.
 */
require_once __DIR__ . '/survey_form.php'; // PhpSpreadsheet, SF_FIELDS, sfValidate, sfDateText, sfShiftSheetRefs

const RT_KINDS = ['offhire' => 'Offhire'];
const RT_MAX_UPLOAD = 20 * 1024 * 1024;
const RT_MAX_TANKS = 60;
const RT_REQUIRED_SHEETS = ['SHIP DETAILS', 'TABLES 54B'];

/**
 * Form fields: key => [label, type, labels in the sheet, value column, group, label column].
 * SHIP DETAILS fields as in the Survey Form, plus the Offhire certificate's redelivery fields.
 * A field is offered only when the template has its label.
 */
function rtFieldDefs(): array {
    static $defs = null;
    if ($defs !== null) return $defs;
    $defs = [];
    foreach (SF_FIELDS as $k => $d) $defs[$k] = [$d[0], $d[1], $d[2], $d[3], $d[4], 'B'];
    $defs['alongside'] = ['Alongside', 'text', ['Alongside'], 'C', 'times', 'B'];
    $defs['redelivery_place'] = ['Place of redelivery', 'text', ['Place of Redelivery'], 'G', 'offhire', 'F'];
    $defs['redelivery_date'] = ['Date of redelivery', 'text', ['Date of Redelivery'], 'G', 'offhire', 'F'];
    $defs['redelivery_time'] = ['Time of redelivery', 'text', ['Time of Redelivery'], 'G', 'offhire', 'F'];
    return $defs;
}
const RT_GROUPS = [
    'vessel' => 'Vessel', 'parties' => 'Owners & charterers', 'times' => 'Survey dates & place', 'offhire' => 'Offhire (redelivery)',
    'brob' => 'Bunkers ROB as per log book', 'drafts' => 'Drafts & list', 'crew' => 'Master, chief engineer & surveyor',
    'particulars' => 'Ship particulars', 'results' => 'Results (typed in the template)', 'remarks' => 'Remarks', 'report' => 'REPORT sheet data',
];
const RT_TANK_COLS = [ // key => [label, header words, default column, type]
    'name' => ['Tank name', ['tank'], 'B', 'text'],
    'ref_height' => ['Ref. height (m)', ['ref'], 'C', 'height'],
    'sounding' => ['Sounding corr. (m)', ['sdg', 'sound'], 'D', 'height'],
    'volume' => ['Volume (m³)', ['volume'], 'E', 'num:3'],
    'density' => ['Density @15°C', ['density'], 'F', 'num:4'],
    'temp' => ['Temp (°C)', ['temp'], 'G', 'num:1'],
];

// ---------------------------------------------------------------- storage

function rtDir(): string { return dirname(__DIR__) . '/uploads/report_templates'; }

/** Create a storage folder under uploads/ with a deny-all .htaccess: files are only served by the
 *  ajax endpoints, after their access checks (same as uploads/invoices/). */
function rtMkdir(string $dir): void {
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $top = preg_replace('#^(.*/uploads/[^/]+).*$#', '$1', $dir);
    if (is_dir($top) && !is_file($top . '/.htaccess')) @file_put_contents($top . '/.htaccess', "Require all denied\nOptions -Indexes\n");
}
function rtReportDir(int $reportId): string { return dirname(__DIR__) . '/uploads/survey_reports/' . $reportId; }

function rtEnsureTables(PDO $db): void {
    static $done = false;
    if ($done) return;
    $db->exec("CREATE TABLE IF NOT EXISTS `report_templates` (
        `id` int(11) NOT NULL AUTO_INCREMENT, `kind` varchar(30) NOT NULL, `version` int(11) NOT NULL,
        `original_name` varchar(255) NOT NULL, `file_path` varchar(255) NOT NULL, `sha256` char(64) NOT NULL, `file_size` int(11) NOT NULL,
        `notes` varchar(500) DEFAULT NULL, `is_active` tinyint(1) NOT NULL DEFAULT 0, `analysis` mediumtext NOT NULL, `mapping` text DEFAULT NULL,
        `uploaded_by` int(11) DEFAULT NULL, `uploaded_at` datetime NOT NULL, `activated_at` datetime DEFAULT NULL, `activated_by` int(11) DEFAULT NULL,
        PRIMARY KEY (`id`), UNIQUE KEY `uq_rt_kind_version` (`kind`, `version`), KEY `idx_rt_kind_active` (`kind`, `is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS `survey_reports` (
        `id` int(11) NOT NULL AUTO_INCREMENT, `survey_id` int(11) NOT NULL, `template_id` int(11) NOT NULL, `kind` varchar(30) NOT NULL,
        `data` mediumtext NOT NULL, `version` int(11) NOT NULL DEFAULT 1,
        `created_by` int(11) DEFAULT NULL, `created_at` datetime NOT NULL, `updated_by` int(11) DEFAULT NULL, `updated_at` datetime NOT NULL,
        PRIMARY KEY (`id`), KEY `idx_sr_survey` (`survey_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS `survey_report_files` (
        `id` int(11) NOT NULL AUTO_INCREMENT, `report_id` int(11) NOT NULL, `template_id` int(11) NOT NULL, `type` varchar(10) NOT NULL,
        `file_name` varchar(255) NOT NULL, `file_path` varchar(255) NOT NULL, `file_size` int(11) NOT NULL DEFAULT 0,
        `created_by` int(11) DEFAULT NULL, `created_at` datetime NOT NULL,
        PRIMARY KEY (`id`), KEY `idx_srf_report` (`report_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** Absolute path of a stored file (relative to YSMS/), only inside uploads/. */
function rtAbs(?string $rel): ?string {
    if (!$rel) return null;
    $abs = realpath(dirname(__DIR__) . '/' . ltrim($rel, '/'));
    $root = realpath(dirname(__DIR__) . '/uploads');
    return ($abs && $root && strpos($abs, $root . DIRECTORY_SEPARATOR) === 0 && is_file($abs)) ? $abs : null;
}

function rtTemplate(PDO $db, int $id): ?array {
    rtEnsureTables($db);
    $q = $db->prepare('SELECT t.*, u.full_name AS uploaded_by_name FROM report_templates t LEFT JOIN users u ON u.id = t.uploaded_by WHERE t.id = ?');
    $q->execute([$id]);
    $r = $q->fetch(PDO::FETCH_ASSOC);
    return $r ? rtDecode($r) : null;
}
function rtActive(PDO $db, string $kind): ?array {
    rtEnsureTables($db);
    $q = $db->prepare('SELECT id FROM report_templates WHERE kind = ? AND is_active = 1 ORDER BY version DESC LIMIT 1');
    $q->execute([$kind]);
    $id = (int)$q->fetchColumn();
    return $id ? rtTemplate($db, $id) : null;
}
function rtTemplates(PDO $db, string $kind): array {
    rtEnsureTables($db);
    $q = $db->prepare('SELECT t.*, u.full_name AS uploaded_by_name, a.full_name AS activated_by_name FROM report_templates t
        LEFT JOIN users u ON u.id = t.uploaded_by LEFT JOIN users a ON a.id = t.activated_by WHERE t.kind = ? ORDER BY t.version DESC');
    $q->execute([$kind]);
    return array_map('rtDecode', $q->fetchAll(PDO::FETCH_ASSOC));
}
function rtDecode(array $r): array {
    $r['analysis'] = json_decode((string)$r['analysis'], true) ?: [];
    $r['mapping'] = json_decode((string)($r['mapping'] ?? ''), true) ?: [];
    $r['id'] = (int)$r['id']; $r['version'] = (int)$r['version']; $r['is_active'] = (int)$r['is_active'];
    return $r;
}

/**
 * Store a new template version. The file is kept byte for byte as uploaded (never changed or
 * overwritten); the same file twice is refused. → ['ok', 'id'|'error']
 */
function rtUpload(PDO $db, string $kind, array $file, string $notes, int $uid): array {
    rtEnsureTables($db);
    if (!isset(RT_KINDS[$kind])) return ['ok' => false, 'error' => 'Unknown template kind.'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return ['ok' => false, 'error' => 'Choose the Excel template (.xlsx).'];
    $name = mb_substr(basename((string)$file['name']), 0, 200);
    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'xlsx') return ['ok' => false, 'error' => 'The template must be an .xlsx workbook.'];
    if ((int)$file['size'] <= 0 || (int)$file['size'] > RT_MAX_UPLOAD) return ['ok' => false, 'error' => 'The file must be smaller than 20 MB.'];
    $zip = new ZipArchive();
    if ($zip->open((string)$file['tmp_name']) !== true || $zip->locateName('xl/workbook.xml') === false) return ['ok' => false, 'error' => 'This is not a valid Excel workbook.'];
    $zip->close();
    $sha = hash_file('sha256', (string)$file['tmp_name']);
    $dup = $db->prepare('SELECT version FROM report_templates WHERE kind = ? AND sha256 = ?');
    $dup->execute([$kind, $sha]);
    if ($v = $dup->fetchColumn()) return ['ok' => false, 'error' => 'This exact file is already stored as version ' . $v . '.'];
    try {
        $analysis = rtAnalyze(sfLoadSpreadsheet((string)$file['tmp_name']));
    } catch (Throwable $e) {
        error_log('rtUpload analyze: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'The workbook could not be read.'];
    }
    if (!$analysis['ok']) return ['ok' => false, 'error' => $analysis['error']];
    rtMkdir(rtDir());
    $ver = 1 + (int)$db->query('SELECT COALESCE(MAX(version), 0) FROM report_templates WHERE kind = ' . $db->quote($kind))->fetchColumn();
    $rel = 'uploads/report_templates/' . $kind . '-v' . $ver . '-' . substr($sha, 0, 12) . '.xlsx';
    $dest = dirname(__DIR__) . '/' . $rel;
    if (file_exists($dest) || !move_uploaded_file((string)$file['tmp_name'], $dest)) return ['ok' => false, 'error' => 'The template could not be stored.'];
    @chmod($dest, 0444); // the original upload is read-only from now on
    $first = !(int)$db->query('SELECT COUNT(*) FROM report_templates WHERE kind = ' . $db->quote($kind) . ' AND is_active = 1')->fetchColumn();
    $now = date('Y-m-d H:i:s');
    $db->prepare('INSERT INTO report_templates (kind, version, original_name, file_path, sha256, file_size, notes, is_active, analysis, mapping, uploaded_by, uploaded_at, activated_at, activated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?)')
       ->execute([$kind, $ver, $name, $rel, $sha, (int)filesize($dest), mb_substr(trim($notes), 0, 500) ?: null, $first ? 1 : 0, json_encode($analysis), $uid ?: null, $now, $first ? $now : null, $first ? ($uid ?: null) : null]);
    return ['ok' => true, 'id' => (int)$db->lastInsertId(), 'version' => $ver, 'active' => $first];
}

function rtActivate(PDO $db, int $id, int $uid): bool {
    $t = rtTemplate($db, $id);
    if (!$t || !$t['analysis']['ok']) return false;
    $db->beginTransaction();
    $db->prepare('UPDATE report_templates SET is_active = 0 WHERE kind = ?')->execute([$t['kind']]);
    $db->prepare('UPDATE report_templates SET is_active = 1, activated_at = ?, activated_by = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $uid ?: null, $id]);
    $db->commit();
    return true;
}

// ---------------------------------------------------------------- analysis

function rtCellText($v): string {
    if ($v instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) return $v->getPlainText();
    return is_scalar($v) ? (string)$v : '';
}
function rtIsFormula($v): bool { return is_string($v) && $v !== '' && $v[0] === '='; }
function rtSheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $book, string $name): ?\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet {
    if ($ws = $book->getSheetByName($name)) return $ws;
    foreach ($book->getWorksheetIterator() as $ws) if (strcasecmp(trim($ws->getTitle()), trim($name)) === 0) return $ws;
    return null;
}

/** The template value of a field as the form holds it (Y-m-d, HH:MM, text, number). */
function rtSampleValue(\PhpOffice\PhpSpreadsheet\Cell\Cell $cell, string $type): string {
    $v = $cell->getValue();
    if (rtIsFormula($v)) return '';
    if ($v === null || $v === '') return '';
    if ($type === 'time') {
        if (is_numeric($v)) { $m = (int)round(fmod((float)$v, 1) * 1440); return sprintf('%02d:%02d', intdiv($m, 60) % 24, $m % 60); }
        return preg_match('/^(\d{1,2}):(\d{2})/', (string)$v, $mm) ? sprintf('%02d:%02d', $mm[1], $mm[2]) : '';
    }
    if ($type === 'date' || $type === 'xdate') {
        if (is_numeric($v) && (float)$v > 20000) return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$v)->format('Y-m-d');
        $t = strtoupper(trim(rtCellText($v)));
        if (preg_match('/^(\d{1,2})(ST|ND|RD|TH)?\s+([A-Z]+)\s+(\d{4})$/', $t, $m)) {
            $d = DateTime::createFromFormat('!j F Y', $m[1] . ' ' . ucfirst(strtolower($m[3])) . ' ' . $m[4]);
            if ($d) return $d->format('Y-m-d');
        }
        return '';
    }
    if ($type === 'imo') return preg_replace('/\D/', '', rtCellText($v));
    $s = trim(rtCellText($v));
    if (is_float($v) && strpos($type, 'num:') === 0) $s = rtrim(rtrim(number_format($v, (int)substr($type, 4), '.', ''), '0'), '.');
    return $s;
}

/**
 * Understand a template. Returns ['ok' => true, ...layout, 'flags' => [...], 'sample' => test data]
 * or ['ok' => false, 'error' => ...].
 */
function rtAnalyze(\PhpOffice\PhpSpreadsheet\Spreadsheet $book): array {
    $sheets = array_map(fn($ws) => $ws->getTitle(), iterator_to_array($book->getWorksheetIterator()));
    foreach (RT_REQUIRED_SHEETS as $need) if (!rtSheet($book, $need)) return ['ok' => false, 'error' => 'The workbook has no "' . $need . '" sheet — this does not look like the bunker survey report format.'];
    $sd = rtSheet($book, 'SHIP DETAILS');
    $t = rtSheet($book, 'TABLES 54B');
    $tn = $t->getTitle();
    $flags = [];
    $flag = function (string $level, string $where, string $text) use (&$flags) { $flags[] = ['level' => $level, 'where' => $where, 'text' => $text]; };

    // ---- SHIP DETAILS fields (by label)
    $labels = [];
    foreach (['B', 'F'] as $lc) for ($r = 1; $r <= 80; $r++) {
        $l = strtolower(trim(rtCellText($sd->getCell($lc . $r)->getValue())));
        if ($l !== '' && !isset($labels[$lc][$l])) $labels[$lc][$l] = $r;
    }
    $fields = [];
    foreach (rtFieldDefs() as $key => [$label, $type, $lbls, $col, $group, $lc]) {
        foreach ($lbls as $l) {
            if (isset($labels[$lc][strtolower($l)])) { $fields[$key] = ['sheet' => $sd->getTitle(), 'cell' => $col . $labels[$lc][strtolower($l)], 'label' => $label, 'type' => $type, 'group' => $group]; break; }
        }
    }
    // fuel names over the BROB columns
    $fuelNames = ['vlsfo' => 'VLSFO', 'hsfo' => 'HSFO', 'lsmgo' => 'LSMGO'];
    $hdr = isset($labels['B']['fwe']) ? $labels['B']['fwe'] - 1 : 10;
    foreach (['G' => 'vlsfo', 'H' => 'hsfo', 'I' => 'lsmgo'] as $c => $k) { $h = trim(rtCellText($sd->getCell($c . $hdr)->getValue())); if ($h !== '') $fuelNames[$k] = $h; }

    // ---- TABLES 54B sections
    $maxRow = min($t->getHighestRow(), 400);
    $sections = [];
    foreach (SF_SECTIONS as $key => $def) {
        for ($r = 10; $r <= $maxRow; $r++) {
            if (!preg_match($def['heading'], rtCellText($t->getCell('B' . $r)->getValue()))) continue;
            $first = $r + 3;
            for ($x = $r + 1; $x <= $r + 4; $x++) if (stripos(rtCellText($t->getCell('B' . $x)->getValue()), 'name') === 0) { $first = $x + 1; break; }
            // header columns of this section
            $cols = []; $calcCols = [];
            foreach (range('B', 'L') as $c) {
                $h = strtolower(rtCellText($t->getCell($c . ($r + 1))->getValue()));
                foreach (RT_TANK_COLS as $k => $d) foreach ($d[1] as $w) if ($h !== '' && !isset($cols[$k]) && strpos($h, $w) !== false) $cols[$k] = $c;
                if (strpos($h, 'v.c.f') !== false) $calcCols['vcf'] = $c;
                if (strpos($h, 'g.s.v') !== false) $calcCols['gsv'] = $c;
                if (strpos($h, 'metric') !== false) $calcCols['mt'] = $c;
            }
            $gsvTot = $mtTot = null;
            for ($x = $first; $x <= $maxRow; $x++) {
                foreach (['E', 'F', 'G', 'H'] as $c) {
                    $txt = strtolower(rtCellText($t->getCell($c . $x)->getValue()));
                    if ($gsvTot === null && strpos($txt, 'total cubic') !== false) $gsvTot = $x;
                    if ($mtTot === null && strpos($txt, 'total metric') !== false) $mtTot = $x;
                }
                if ($gsvTot && $mtTot) break;
            }
            if (!$gsvTot || !$mtTot) return ['ok' => false, 'error' => 'TABLES 54B: the ' . $def['label'] . ' section has no "TOTAL Cubic Metres" / "TOTAL Metric Tons" rows.'];
            $sections[$key] = ['label' => trim(rtCellText($t->getCell('B' . $r)->getValue())), 'heading' => $r, 'first' => $first, 'last' => $gsvTot - 1,
                'gsv_total' => $gsvTot, 'mt_total' => $mtTot, 'cols' => $cols + array_map(fn($d) => $d[2], RT_TANK_COLS), 'calc_cols' => $calcCols + ['vcf' => 'H', 'gsv' => 'I', 'mt' => 'J']];
            foreach (RT_TANK_COLS as $k => $d) if (!isset($cols[$k])) $flag('info', $tn . '!' . $d[2] . ($r + 1), $sections[$key]['label'] . ': column "' . $d[0] . '" not found by its heading — column ' . $d[2] . ' is used.');
            break;
        }
        if (!isset($sections[$key])) return ['ok' => false, 'error' => 'TABLES 54B: the ' . $def['label'] . ' section heading was not found.'];
    }
    $gsvC = $sections['vlsfo']['calc_cols']['gsv']; $mtC = $sections['vlsfo']['calc_cols']['mt'];
    foreach ($sections as $k => $s) {
        $tot = [$gsvC . $s['gsv_total'], $mtC . $s['mt_total']];
        $miss = array_filter($tot, fn($c) => !rtIsFormula($t->getCell($c)->getValue()));
        if ($miss) $flag('warn', $tn . '!' . implode(', ', $miss), $s['label'] . ': the section total has no formula (' . implode(', ', $miss) . ' is empty) — totals that point at it show nothing. The generated report fills it with =SUM(...) over the section\'s tank rows (as for the other sections). Approved: HSFO totals.');
    }

    // ---- tank row formula pattern (first tank row whose V.C.F. cell has the formula)
    $pattern = []; $patRow = 0;
    foreach ($sections as $s) {
        for ($r = $s['first']; $r <= $s['last'] && !$pattern; $r++) {
            if (strpos((string)$t->getCell($s['calc_cols']['vcf'] . $r)->getValue(), 'EXP(') === false) continue;
            $patRow = $r;
            foreach ($t->getRowIterator($r, $r)->current()->getCellIterator('H', 'AF') as $cell) {
                $f = $cell->getValue();
                if (rtIsFormula($f)) $pattern[$cell->getColumn()] = preg_replace('/(?<![$A-Z])([A-Z]{1,3})' . $r . '(?!\d)/', '$1{r}', $f);
            }
        }
    }
    if (!$pattern) return ['ok' => false, 'error' => 'TABLES 54B: no tank row with the V.C.F. formula was found.'];
    $calc = rtParseCalc($t, $pattern, $sections['vlsfo']['cols'], $sections['vlsfo']['calc_cols']);
    if (!$calc['ok']) $flag('warn', $tn . '!H' . $patRow, 'The tank formulas are not the recognised ASTM Table 54B pattern (' . $calc['why'] . '). The Excel file still uses them; the on-screen preview and the offline file cannot recalculate them — make the Excel on the server.');
    else {
        $flag('info', $tn . '!' . $calc['k0_cell'] . ', ' . $calc['k1_cell'], 'All three sections use one pair of Table 54B constants (K0 = ' . $calc['k0'] . ', K1 = ' . $calc['k1'] . ' — the ASTM 54B fuel-oil band, 839–1075 kg/m³). Gas oil lighter than 838.5 kg/m³ falls in the ASTM jet/kerosene band (K0 = 594.5418, K1 = 0). Kept exactly as in the template — review if needed.');
        $flag('info', $tn . '!' . $calc['wcf_col'] . $patRow, 'Metric tons = G.S.V. × (density − ' . $calc['wcf'] . ') — weight in air, as in the template.');
    }
    // other sections: rows whose formulas differ from the pattern are rewritten from it
    foreach ($sections as $s) for ($r = $s['first']; $r <= $s['last']; $r++) {
        $h = (string)$t->getCell($s['calc_cols']['vcf'] . $r)->getValue();
        if ($h !== '' && rtIsFormula($h) && $h !== str_replace('{r}', (string)$r, $pattern[$s['calc_cols']['vcf']] ?? '')) {
            $flag('warn', $tn . '!' . $s['calc_cols']['vcf'] . $r, $s['label'] . ' row ' . $r . ': its V.C.F. formula differs from the first tank row. The generated report uses the first tank row\'s formulas on every tank row.');
        }
    }

    // ---- results block (Actual / Log Book / Difference), typed numbers become fields
    $res = ['row_actual' => 0, 'row_log' => 0, 'row_diff' => 0, 'cols' => []];
    for ($r = 10; $r <= 40; $r++) {
        $l = strtolower(trim(rtCellText($t->getCell('M' . $r)->getValue())));
        if ($l === 'actual') $res['row_actual'] = $r; elseif ($l === 'log book') $res['row_log'] = $r; elseif ($l === 'difference') $res['row_diff'] = $r;
    }
    if ($res['row_actual']) {
        $hdrRow = $res['row_actual'] - 1;
        foreach (['N', 'O', 'P'] as $c) {
            $f = (string)$t->getCell($c . $hdrRow)->getValue();
            if (!rtIsFormula($f)) continue;
            try { $name = strtoupper(trim((string)$t->getCell($c . $hdrRow)->getCalculatedValue())); } catch (Throwable $e) { $name = ''; }
            $fuel = array_search($name, array_map('strtoupper', $fuelNames), true);
            if ($fuel === false) continue;
            $res['cols'][$fuel] = $c;
            foreach (['actual' => 'Actual (ROB as per bunker survey)', 'log' => 'Log book'] as $which => $lab) {
                $cell = $c . $res['row_' . $which];
                $v = $t->getCell($cell)->getValue();
                if ($v !== null && $v !== '' && !rtIsFormula($v)) {
                    $fields['result_' . $which . '_' . $fuel] = ['sheet' => $tn, 'cell' => $cell, 'label' => 'Results — ' . $fuelNames[$fuel] . ' ' . strtolower($lab), 'type' => 'num:3', 'group' => 'results'];
                    $flag('warn', $tn . '!' . $cell, 'RESULTS: ' . $fuelNames[$fuel] . ' "' . $lab . '" is a typed number (' . rtCellText($v) . ') where the other fuels use a formula' . ($which === 'actual' ? ' (the survey total is in ' . $mtC . $sections[$fuel]['mt_total'] . ')' : '') . '. It is a form field now; it is outside the print area.');
                } elseif ($which === 'actual' && rtIsFormula($v) && stripos($v, $mtC . $sections[$fuel]['mt_total']) === false) {
                    $flag('warn', $tn . '!' . $cell, 'RESULTS: ' . $fuelNames[$fuel] . ' "Actual" is ' . $v . ' — not the section total ' . $mtC . $sections[$fuel]['mt_total'] . '. Kept as in the template (outside the print area).');
                }
            }
        }
        for ($r = $res['row_diff']; $r <= $res['row_diff'] + 2 && $res['row_diff']; $r++) {
            foreach (['N', 'O', 'P', 'Q'] as $c) {
                $f = (string)$t->getCell($c . $r)->getValue();
                if (rtIsFormula($f) && stripos($f, 'SUM(') !== false) {
                    $missing = array_filter($res['cols'], fn($col) => !preg_match('/' . $col . $res['row_diff'] . '(?!\d)|' . 'N' . $res['row_diff'] . ':P' . $res['row_diff'] . '/', $f));
                    if ($missing) $flag('warn', $tn . '!' . $c . $r, 'RESULTS: the total difference ' . $f . ' leaves out ' . implode(', ', array_map(fn($k) => $fuelNames[$k], array_keys($missing))) . '. Kept as in the template (outside the print area).');
                }
            }
        }
    }

    // ---- remarks (TABLES 54B paragraph after the last section, OFFHIRE "REMARK:")
    $lastTot = max(array_column($sections, 'mt_total'));
    for ($r = $lastTot + 1; $r <= min($maxRow, $lastTot + 12); $r++) {
        $v = $t->getCell('B' . $r)->getValue();
        if (!rtIsFormula($v) && mb_strlen(rtCellText($v)) > 60) { $fields['remarks'] = ['sheet' => $tn, 'cell' => 'B' . $r, 'label' => 'Survey remarks (report)', 'type' => 'long', 'group' => 'remarks']; break; }
    }
    $off = null;
    foreach ($book->getWorksheetIterator() as $ws) if (stripos($ws->getTitle(), 'OFFHIRE') !== false) { $off = $ws; break; }
    if ($off) {
        for ($r = 1; $r <= 80; $r++) {
            if (stripos(rtCellText($off->getCell('A' . $r)->getValue()), 'REMARK') === 0) {
                $fields['offhire_remark'] = ['sheet' => $off->getTitle(), 'cell' => 'B' . $r, 'label' => 'Offhire certificate remark', 'type' => 'long', 'group' => 'remarks'];
                break;
            }
        }
    }
    // ---- REPORT sheet: KEY in column A, value in B; typed values (not formulas) are fields
    $rep = rtSheet($book, 'REPORT');
    if ($rep) {
        $holdsSd = isset($fields['holds']) ? trim(rtCellText($sd->getCell($fields['holds']['cell'])->getValue())) : '';
        for ($r = 1; $r <= 120; $r++) {
            $k = strtoupper(trim(rtCellText($rep->getCell('A' . $r)->getValue())));
            if (!preg_match('/^[A-Z][A-Z0-9_]{1,40}$/', $k)) continue;
            $v = $rep->getCell('B' . $r)->getValue();
            if (rtIsFormula($v)) continue;
            $fmt = $rep->getStyle('B' . $r)->getNumberFormat()->getFormatCode();
            $type = preg_match('/_(VLSFO|LSMGO|HSFO)$/', $k) ? 'num:3' : ($k === 'HOLDS' ? 'int' : (strpos($k, 'DATE') !== false && \PhpOffice\PhpSpreadsheet\Shared\Date::isDateTimeFormatCode($fmt) ? 'xdate' : 'text'));
            $fields['rep_' . strtolower($k)] = ['sheet' => $rep->getTitle(), 'cell' => 'B' . $r, 'label' => $k, 'type' => $type, 'group' => 'report'];
            if ($k === 'HOLDS' && $holdsSd !== '' && $v !== null && (string)$v !== $holdsSd) $flag('warn', $rep->getTitle() . '!B' . $r, 'REPORT HOLDS is ' . $v . ' while SHIP DETAILS "No. Of Holds" is ' . $holdsSd . ' — check which is right.');
        }
    }

    // ---- broken references, empty targets, print setup
    foreach ($book->getWorksheetIterator() as $ws) {
        foreach ($ws->getCoordinates() as $c) {
            $v = $ws->getCell($c)->getValue();
            if (!rtIsFormula($v)) continue;
            if (stripos($v, '#REF!') !== false) $flag('error', $ws->getTitle() . '!' . $c, 'Broken reference: ' . mb_substr($v, 0, 120) . ' — Excel shows #REF! here. Fix it in the template (it is kept as it is).');
            if ($ws->getTitle() !== $tn && preg_match_all("/'" . preg_quote($tn, '/') . "'!\\$?([A-Z]{1,3})\\$?(\\d+)/", $v, $m, PREG_SET_ORDER)) {
                foreach ($m as $ref) {
                    $target = $t->getCell($ref[1] . $ref[2])->getValue();
                    $isTotal = false;
                    foreach ($sections as $s) if ((int)$ref[2] === $s['gsv_total'] || (int)$ref[2] === $s['mt_total']) $isTotal = true;
                    if (($target === null || $target === '') && !$isTotal) $flag('warn', $ws->getTitle() . '!' . $c, 'Points at ' . $tn . '!' . $ref[1] . $ref[2] . ', which is empty in the template.');
                }
            }
        }
    }
    foreach ($book->getDefinedNames() as $dn) {
        if (stripos((string)$dn->getValue(), '#REF!') !== false) $flag('info', 'Name ' . $dn->getName(), 'Defined name ' . $dn->getName() . ' points at #REF! (an old link). Not used by any cell; kept as it is.');
    }
    $printSheets = [];
    foreach ($book->getWorksheetIterator() as $ws) {
        $title = $ws->getTitle();
        if (in_array(strtoupper(trim($title)), ['SHIP DETAILS', 'INTERP', 'REPORT'], true)) continue;
        $hasContent = false;
        foreach ($ws->getCoordinates() as $c) if ((string)$ws->getCell($c)->getValue() !== '') { $hasContent = true; break; }
        if (!$hasContent && !count($ws->getDrawingCollection())) { $flag('info', $title, 'The "' . trim($title) . '" sheet is empty (no text and no image) — kept as it is and left out of the PDF.'); continue; }
        $printSheets[] = $title;
        if (!$ws->getPageSetup()->getPaperSize() || $ws->getPageSetup()->getPaperSize() !== \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4) $flag('info', $title, 'No A4 paper size set on "' . trim($title) . '" — the PDF prints it on A4.');
    }

    // ---- test data: the values already in the template
    $sample = ['fields' => [], 'tanks' => []];
    foreach ($fields as $k => $f) {
        $ws = $book->getSheetByName($f['sheet']);
        $sample['fields'][$k] = $ws ? rtSampleValue($ws->getCell($f['cell']), $f['type']) : '';
    }
    foreach ($sections as $k => $s) {
        $sample['tanks'][$k] = [];
        for ($r = $s['first']; $r <= $s['last']; $r++) {
            if (trim(rtCellText($t->getCell($s['cols']['name'] . $r)->getValue())) === '') continue;
            $row = [];
            foreach (RT_TANK_COLS as $c => $d) $row[$c] = rtSampleValue($t->getCell($s['cols'][$c] . $r), $d[3] === 'height' ? 'text' : $d[3]);
            $sample['tanks'][$k][] = $row;
        }
    }
    // expected results (the template's own calculated values) for the acceptance comparison
    $expected = [];
    foreach ($sections as $k => $s) {
        $rows = [];
        for ($r = $s['first']; $r <= $s['last']; $r++) {
            if (trim(rtCellText($t->getCell($s['cols']['name'] . $r)->getValue())) === '') continue;
            $g = fn($c) => sfNum($t->getCell($c . $r)->getOldCalculatedValue() ?? null);
            $rows[] = ['vcf' => $g($s['calc_cols']['vcf']), 'gsv' => $g($s['calc_cols']['gsv']), 'mt' => $g($s['calc_cols']['mt'])];
        }
        $expected[$k] = ['tanks' => $rows, 'gsv' => sfNum($t->getCell($gsvC . $s['gsv_total'])->getOldCalculatedValue()), 'mt' => sfNum($t->getCell($mtC . $s['mt_total'])->getOldCalculatedValue())];
    }

    usort($flags, fn($a, $b) => ['error' => 0, 'warn' => 1, 'info' => 2][$a['level']] <=> ['error' => 0, 'warn' => 1, 'info' => 2][$b['level']]);
    return ['ok' => true, 'sheets' => $sheets, 'tables_sheet' => $tn, 'fields' => $fields, 'fuel_names' => $fuelNames, 'sections' => $sections,
        'pattern' => $pattern, 'pattern_row' => $patRow, 'calc' => $calc, 'results' => $res, 'print_sheets' => $printSheets,
        'flags' => $flags, 'sample' => $sample, 'expected' => $expected];
}

/**
 * Recognise the tank-row formulas: V.C.F. = EXP(−α·Δt·(1 + 0.8·α·Δt)) with α = K0/ρ² + K1/ρ
 * (ρ = density in kg/m³ rounded to an even number), G.S.V. = volume × V.C.F., metric tons =
 * G.S.V. × (density − WCF). Returns the constants and rounding, or ok = false with the reason.
 */
function rtParseCalc(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $t, array $p, array $cols, array $cc): array {
    $no = fn($why) => ['ok' => false, 'why' => $why];
    $F = $cols['density']; $G = $cols['temp']; $E = $cols['volume'];
    $norm = fn($f) => preg_replace('/(?<=[=(])\+/', '', str_replace(' ', '', $f)); // "=+F23" → "=F23", keeps "1+0.8"

    $rho = $alpha = $wcf = $mtIn = null; $k = [];
    foreach ($p as $col => $f) {
        $n = $norm($f);
        if (preg_match('/^=ROUND\(' . $F . '\{r\}\/2\*1000,0\)\*2$/', $n)) $rho = $col;
        elseif (preg_match('/^=' . $F . '\{r\}-([0-9.]+)$/', $n, $m)) { $wcf = $col; $k['wcf'] = (float)$m[1]; }
    }
    if (!$rho) return $no('density rounding column not found');
    foreach ($p as $col => $f) {
        if (preg_match('/^=ROUND\(\$([A-Z]+)\$(\d+)\/\(' . $rho . '\{r\}\*' . $rho . '\{r\}\)\+\(\$([A-Z]+)\$(\d+)\/' . $rho . '\{r\}\),(\d+)\)$/', $norm($f), $m)) {
            $alpha = $col; $k['k0_cell'] = $m[1] . $m[2]; $k['k1_cell'] = $m[3] . $m[4]; $k['alpha_dp'] = (int)$m[5];
        }
    }
    if (!$alpha || $wcf === null) return $no(!$alpha ? 'α (K0/ρ² + K1/ρ) formula not found' : 'density − WCF formula not found');
    $vcf = $norm($p[$cc['vcf']] ?? '');
    if (!preg_match('/^=IF\(' . $G . '\{r\}="","",ROUND\(EXP\(-' . $alpha . '\{r\}\*\(' . $G . '\{r\}-(\d+(?:\.\d+)?)\)\*\(1\+0\.8\*' . $alpha . '\{r\}\*\(' . $G . '\{r\}-\1\)\)\),(\d)\)\)$/', $vcf, $m)) return $no('V.C.F. formula differs');
    $k['base'] = (float)$m[1]; $k['vcf_dp'] = (int)$m[2];
    if (!preg_match('/^=IF\(' . $G . '\{r\}="","",ROUND\(' . $E . '\{r\}\*' . $cc['vcf'] . '\{r\},(\d)\)\)$/', $norm($p[$cc['gsv']] ?? ''), $m)) return $no('G.S.V. formula differs');
    $k['gsv_dp'] = (int)$m[1];
    $mt = $norm($p[$cc['mt']] ?? '');
    if (preg_match('/^=([A-Z]+)\{r\}$/', $mt, $m) && isset($p[$m[1]])) $mt = $norm($p[$m[1]]); // J = Y
    if (!preg_match('/^=IF\(' . $G . '\{r\}="","",ROUND\(\(?' . $cc['gsv'] . '\{r\}\*' . $wcf . '\{r\}\)?,(\d)\)\)$/', $mt, $m)) return $no('metric tons formula differs');
    $k['mt_dp'] = (int)$m[1];
    $k0 = $t->getCell($k['k0_cell'])->getValue(); $k1 = $t->getCell($k['k1_cell'])->getValue();
    if (!is_numeric($k0) || !is_numeric($k1)) return $no('Table 54B constants are not numbers');
    return ['ok' => true, 'k0' => (float)$k0, 'k1' => (float)$k1, 'wcf_col' => $wcf] + $k;
}

/** Analysis with the Admin's cell overrides applied. */
function rtEffective(array $tpl): array {
    $a = $tpl['analysis'];
    $m = $tpl['mapping'] ?: [];
    foreach ((array)($m['fields'] ?? []) as $k => $cell) {
        if (!isset($a['fields'][$k])) continue;
        if ($cell === '') { unset($a['fields'][$k]); continue; }
        if (preg_match('/^(.+)!\$?([A-Z]{1,3})\$?(\d+)$/', (string)$cell, $mm)) { $a['fields'][$k]['sheet'] = trim($mm[1], "'"); $a['fields'][$k]['cell'] = $mm[2] . $mm[3]; }
    }
    if (!empty($m['print_sheets']) && is_array($m['print_sheets'])) $a['print_sheets'] = array_values(array_intersect($m['print_sheets'], $a['sheets']));
    return $a;
}

// ---------------------------------------------------------------- form data

/** Clean posted form data → [data, errors]; errors keyed "f:<field>" / "t:<section>:<row>:<col>". */
function rtCleanData(array $eff, array $in): array {
    $out = ['fields' => [], 'tanks' => []];
    $errors = [];
    foreach ($eff['fields'] as $k => $f) {
        $raw = is_array($in['fields'][$k] ?? null) ? ($in['fields'][$k]['v'] ?? '') : ($in['fields'][$k] ?? '');
        $type = $f['type'] === 'xdate' ? 'date' : $f['type'];
        if ($type === 'long') { $v = mb_substr(trim(str_replace(["\r\n", "\r"], "\n", (string)$raw)), 0, 3000); $err = ''; }
        else [$v, $err] = sfValidate($type === 'text' && $f['group'] === 'report' ? 'text' : $type, $raw);
        if ($err === '' && strpos($type, 'num:') === 0 && $v !== '') $err = sfRangeError(strpos($k, 'brob_') === 0 ? 'brob' : $k, $v);
        if ($err !== '') $errors['f:' . $k] = $err;
        $out['fields'][$k] = $v;
    }
    foreach ($eff['sections'] as $s => $sec) {
        $out['tanks'][$s] = [];
        foreach (array_slice(array_values((array)($in['tanks'][$s] ?? [])), 0, RT_MAX_TANKS) as $i => $row) {
            $clean = [];
            foreach (RT_TANK_COLS as $c => $d) {
                $raw = is_array($row[$c] ?? null) ? ($row[$c]['v'] ?? '') : ($row[$c] ?? '');
                [$v, $err] = sfValidate($d[3], $raw);
                if ($err === '') $err = sfRangeError($c, $v);
                if ($err !== '') $errors["t:$s:$i:$c"] = $err;
                $clean[$c] = $v;
            }
            if (implode('', $clean) === '') continue; // empty row
            if ($clean['temp'] !== '') foreach (['volume', 'density'] as $need) if ($clean[$need] === '' && !isset($errors["t:$s:$i:$need"])) $errors["t:$s:$i:$need"] = 'Needed when a temperature is entered.';
            $out['tanks'][$s][] = $clean;
        }
    }
    return [$out, $errors];
}

// ---------------------------------------------------------------- Excel

/** Fill a copy of the template. */
function rtBuild(array $tpl, array $data): \PhpOffice\PhpSpreadsheet\Spreadsheet {
    $abs = rtAbs($tpl['file_path']);
    if (!$abs) throw new RuntimeException('Template file missing.');
    $eff = rtEffective($tpl);
    $book = sfLoadSpreadsheet($abs);
    $t = $book->getSheetByName($eff['tables_sheet']);
    $DT = \PhpOffice\PhpSpreadsheet\Cell\DataType::class;

    // tank sections, bottom one first so inserting rows never moves a section still to be filled
    $sections = $eff['sections'];
    uasort($sections, fn($a, $b) => $b['first'] <=> $a['first']);
    $gsvC = $eff['sections']['vlsfo']['calc_cols']['gsv']; $mtC = $eff['sections']['vlsfo']['calc_cols']['mt'];
    $shifted = []; // rows inserted before a given template row (for field cells on TABLES 54B below)
    foreach ($sections as $s => $sec) {
        $rows = array_values($data['tanks'][$s] ?? []);
        $first = $sec['first'];
        $cap = $sec['last'] - $first + 1;
        $add = max(0, count($rows) - $cap);
        if ($add) {
            $t->insertNewRowBefore($sec['last'] + 1, $add);
            sfShiftSheetRefs($book, $t->getTitle(), $sec['last'] + 1, $add);
            $shifted[] = [$sec['last'] + 1, $add];
        }
        $last = $first + max($cap, count($rows)) - 1;
        for ($r = $first; $r <= $last; $r++) {
            $row = $rows[$r - $first] ?? [];
            foreach (RT_TANK_COLS as $c => $d) {
                $v = (string)($row[$c] ?? '');
                $cell = $t->getCell($sec['cols'][$c] . $r);
                if ($v === '') $cell->setValueExplicit(null, $DT::TYPE_NULL);
                elseif ($d[3] === 'text' || !is_numeric($v)) $cell->setValueExplicit($v, $DT::TYPE_STRING);
                else $cell->setValue((float)$v);
            }
            foreach ($eff['pattern'] as $col => $f) $t->getCell($col . $r)->setValue(str_replace('{r}', (string)$r, $f));
        }
        $t->getCell($gsvC . ($last + 1 + ($sec['gsv_total'] - $sec['last'] - 1)))->setValue("=SUM({$gsvC}{$first}:{$gsvC}{$last})");
        $t->getCell($mtC . ($last + 1 + ($sec['mt_total'] - $sec['last'] - 1)))->setValue("=SUM({$mtC}{$first}:{$mtC}{$last})");
    }
    // field values
    foreach ($eff['fields'] as $k => $f) {
        $ws = $book->getSheetByName($f['sheet']);
        if (!$ws) continue;
        $coord = $f['cell'];
        if ($ws === $t) { // follow rows inserted above it
            [$col, $row] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::coordinateFromString($coord);
            $row = (int)$row; $orig = $row;
            foreach ($shifted as [$before, $n]) if ($orig >= $before) $row += $n;
            $coord = $col . $row;
        }
        $v = (string)($data['fields'][$k] ?? '');
        $cell = $ws->getCell($coord);
        if ($v === '') { $cell->setValueExplicit(null, $DT::TYPE_NULL); continue; }
        switch ($f['type']) {
            case 'date': $cell->setValueExplicit(sfDateText($v), $DT::TYPE_STRING); break;
            case 'xdate': $cell->setValue(\PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new DateTime($v))); break;
            case 'time':
                [$h, $m] = array_map('intval', explode(':', $v));
                $cell->setValue(($h * 60 + $m) / 1440); // full precision: rounding shows 17:00 as 16:59
                if (!\PhpOffice\PhpSpreadsheet\Shared\Date::isDateTimeFormatCode($ws->getStyle($coord)->getNumberFormat()->getFormatCode())) $ws->getStyle($coord)->getNumberFormat()->setFormatCode('h:mm');
                break;
            case 'int': case 'imo': $cell->setValue((int)$v); break;
            default:
                if (strpos($f['type'], 'num:') === 0) $cell->setValue((float)$v);
                else $cell->setValueExplicit($v, $DT::TYPE_STRING);
        }
    }
    return $book;
}

/** Calculated results of a filled workbook (the template's formulas, evaluated by PhpSpreadsheet). */
function rtResults(\PhpOffice\PhpSpreadsheet\Spreadsheet $book, array $eff, array $data): array {
    $t = $book->getSheetByName($eff['tables_sheet']);
    $out = ['tanks' => [], 'totals' => []];
    // section positions after inserted rows: walk sections top to bottom adding the extra rows
    $secs = $eff['sections'];
    uasort($secs, fn($a, $b) => $a['first'] <=> $b['first']);
    $offset = 0;
    foreach ($secs as $s => $sec) {
        $n = count($data['tanks'][$s] ?? []);
        $cap = $sec['last'] - $sec['first'] + 1;
        $first = $sec['first'] + $offset;
        $out['tanks'][$s] = [];
        for ($i = 0; $i < $n; $i++) {
            $r = $first + $i;
            $g = fn($c) => sfNum($t->getCell($c . $r)->getCalculatedValue());
            $out['tanks'][$s][] = ['vcf' => $g($sec['calc_cols']['vcf']), 'gsv' => $g($sec['calc_cols']['gsv']), 'mt' => $g($sec['calc_cols']['mt'])];
        }
        $extra = max(0, $n - $cap);
        $out['totals'][$s] = ['gsv' => sfNum($t->getCell($sec['calc_cols']['gsv'] . ($sec['gsv_total'] + $offset + $extra))->getCalculatedValue()),
            'mt' => sfNum($t->getCell($sec['calc_cols']['mt'] . ($sec['mt_total'] + $offset + $extra))->getCalculatedValue())];
        $offset += $extra;
    }
    return $out;
}

/**
 * Save: calculated values are stored (other programs and the PDF read them) and Excel is told
 * to recalculate everything on open with its own engine. Calculation settings stay as in the
 * template otherwise.
 */
function rtSave(\PhpOffice\PhpSpreadsheet\Spreadsheet $book, string $dest, ?string $templatePath = null): void {
    $book->setActiveSheetIndex(0);
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($dest);
    $noPaper = $templatePath ? rtSheetsWithoutPaper($templatePath) : [];
    $zip = new ZipArchive();
    if ($zip->open($dest) === true) {
        $parts = rtSheetParts($zip); // before workbook.xml is replaced (a pending entry can't be read back)
        $wb = (string)$zip->getFromName('xl/workbook.xml');
        $wb = preg_match('/<calcPr\b[^>]*fullCalcOnLoad/', $wb) ? $wb : preg_replace('/<calcPr\b([^>]*?)\s*\/>/', '<calcPr$1 fullCalcOnLoad="1"/>', $wb, 1);
        $zip->addFromString('xl/workbook.xml', $wb);
        // PhpSpreadsheet writes paperSize="1" (Letter) where the template sets none — keep "none"
        foreach ($parts as $name => $part) {
            if (!in_array($name, $noPaper, true)) continue;
            $xml = (string)$zip->getFromName($part);
            $zip->addFromString($part, preg_replace('/(<pageSetup\b[^>]*?)\s+paperSize="1"/', '$1', $xml, 1));
        }
        $zip->close();
    }
}

/** Sheet name => worksheet part inside an open xlsx. */
function rtSheetParts(ZipArchive $zip): array {
    $wb = (string)$zip->getFromName('xl/workbook.xml');
    $rels = (string)$zip->getFromName('xl/_rels/workbook.xml.rels');
    $target = [];
    if (preg_match_all('/<Relationship\b[^>]*>/', $rels, $m)) foreach ($m[0] as $rel) {
        if (preg_match('/Id="([^"]+)"/', $rel, $i) && preg_match('/Target="([^"]+)"/', $rel, $t)) $target[$i[1]] = 'xl/' . ltrim(preg_replace('#^/?xl/#', '', $t[1]), '/');
    }
    $out = [];
    if (preg_match_all('/<sheet\b[^>]*>/', $wb, $m)) foreach ($m[0] as $sh) {
        if (preg_match('/name="([^"]*)"/', $sh, $n) && preg_match('/r:id="([^"]+)"/', $sh, $i) && isset($target[$i[1]])) $out[html_entity_decode($n[1], ENT_QUOTES | ENT_XML1)] = $target[$i[1]];
    }
    return $out;
}

/** Names of the template's sheets whose page setup has no paper size. */
function rtSheetsWithoutPaper(string $xlsx): array {
    $zip = new ZipArchive();
    if ($zip->open($xlsx) !== true) return [];
    $out = [];
    foreach (rtSheetParts($zip) as $name => $part) {
        $xml = (string)$zip->getFromName($part);
        if (!preg_match('/<pageSetup\b[^>]*paperSize=/', $xml)) $out[] = $name;
    }
    $zip->close();
    return $out;
}

/** LibreOffice binary, or '' when not installed. */
function rtSoffice(): string {
    foreach ([getenv('YSMS_SOFFICE') ?: '', '/usr/bin/soffice', '/usr/bin/libreoffice', '/usr/lib/libreoffice/program/soffice'] as $p) if ($p !== '' && is_executable($p)) return $p;
    return '';
}

/**
 * PDF of the filled workbook as Excel would print it: only the report sheets (the others are
 * hidden in a temporary copy), their own print areas, page setup and images; A4 where the
 * template sets no paper size. → ['ok', 'pdf' => path] or ['ok' => false, 'error'].
 */
function rtPdf(string $xlsx, array $printSheets, string $destPdf): array {
    $bin = rtSoffice();
    if ($bin === '') return ['ok' => false, 'error' => 'PDF needs LibreOffice on the server (it is installed by the Docker image).'];
    $work = sys_get_temp_dir() . '/rtpdf_' . bin2hex(random_bytes(6));
    @mkdir($work, 0700, true);
    try {
        $book = sfLoadSpreadsheet($xlsx);
        $first = null;
        foreach ($book->getWorksheetIterator() as $ws) {
            if (in_array($ws->getTitle(), $printSheets, true)) {
                $first = $first ?? $book->getIndex($ws);
                $ps = $ws->getPageSetup();
                if ($ps->getPaperSize() !== \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4) $ps->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
                $ws->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_VISIBLE);
                if (!$ps->isPrintAreaSet()) { // as Excel prints it: used cells in visible columns only (LibreOffice adds pages for hidden ones)
                    $maxC = 1; $maxR = 1;
                    foreach ($ws->getCoordinates() as $c) {
                        [$col, $row] = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::coordinateFromString($c);
                        if ((string)$ws->getCell($c)->getValue() === '' || !$ws->getColumnDimension($col)->getVisible()) continue;
                        $maxC = max($maxC, \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($col)); $maxR = max($maxR, (int)$row);
                    }
                    $ps->setPrintArea('A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($maxC) . $maxR);
                }
            } else {
                $ws->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN);
            }
        }
        if ($first === null) return ['ok' => false, 'error' => 'No sheet is set to be printed.'];
        $book->setActiveSheetIndex($first);
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book);
        $writer->setPreCalculateFormulas(false); // keep the values already calculated
        $writer->save($work . '/report.xlsx');
        $cmd = 'HOME=' . escapeshellarg($work) . ' timeout 120 ' . escapeshellarg($bin) . ' -env:UserInstallation=file://' . escapeshellarg($work . '/profile') . ' --headless --norestore --convert-to pdf --outdir ' . escapeshellarg($work) . ' ' . escapeshellarg($work . '/report.xlsx') . ' 2>&1';
        $out = []; $code = 0;
        exec($cmd, $out, $code);
        if (!is_file($work . '/report.pdf')) { error_log('rtPdf: ' . implode(' | ', array_slice($out, -3))); return ['ok' => false, 'error' => 'The PDF could not be made.']; }
        if (!@rename($work . '/report.pdf', $destPdf) && !@copy($work . '/report.pdf', $destPdf)) return ['ok' => false, 'error' => 'The PDF could not be stored.'];
        return ['ok' => true, 'pdf' => $destPdf];
    } finally {
        exec('rm -rf ' . escapeshellarg($work));
    }
}

/** Calculation settings for assets/js/report-calc.js (null when the template's formulas were not recognised). */
function rtCalcConfig(array $eff): ?array {
    $c = $eff['calc'] ?? [];
    if (empty($c['ok'])) return null;
    return ['k0' => $c['k0'], 'k1' => $c['k1'], 'wcf' => $c['wcf'], 'base' => $c['base'], 'alpha_dp' => $c['alpha_dp'], 'vcf_dp' => $c['vcf_dp'], 'gsv_dp' => $c['gsv_dp'], 'mt_dp' => $c['mt_dp']];
}

// ---------------------------------------------------------------- Admin: mapping, test generation

/**
 * Save the Admin's overrides: field => "Sheet!A1" (empty = use the detected cell, "-" = leave the
 * field out) and the sheets printed to PDF. Cells are checked against the template.
 */
function rtSaveMapping(PDO $db, array $tpl, array $fields, array $printSheets, int $uid): array {
    $abs = rtAbs($tpl['file_path']);
    if (!$abs) return ['ok' => false, 'error' => 'Template file missing.'];
    $book = sfLoadSpreadsheet($abs);
    $clean = ['fields' => [], 'print_sheets' => []];
    foreach ($fields as $k => $v) {
        if (!isset($tpl['analysis']['fields'][$k])) continue;
        $v = trim((string)$v);
        if ($v === '') continue;
        if ($v === '-') { $clean['fields'][$k] = ''; continue; }
        if (!preg_match("/^'?(.+?)'?!\\$?([A-Z]{1,3})\\$?(\\d{1,5})$/i", $v, $m) || !rtSheet($book, $m[1])) return ['ok' => false, 'error' => $tpl['analysis']['fields'][$k]['label'] . ': "' . $v . '" is not a cell of this workbook (write it as SHEET!C12).'];
        $ws = rtSheet($book, $m[1]);
        if (rtIsFormula($ws->getCell(strtoupper($m[2]) . $m[3])->getValue())) return ['ok' => false, 'error' => $tpl['analysis']['fields'][$k]['label'] . ': ' . $v . ' holds a formula — a form value would replace it. Choose an input cell.'];
        $cell = $ws->getTitle() . '!' . strtoupper($m[2]) . $m[3];
        $det = $tpl['analysis']['fields'][$k]['sheet'] . '!' . $tpl['analysis']['fields'][$k]['cell'];
        if ($cell !== $det) $clean['fields'][$k] = $cell;
    }
    foreach ($printSheets as $p) if (in_array($p, $tpl['analysis']['sheets'], true)) $clean['print_sheets'][] = $p;
    if (!$clean['print_sheets']) return ['ok' => false, 'error' => 'Choose at least one sheet for the PDF.'];
    if ($clean['print_sheets'] === $tpl['analysis']['print_sheets']) unset($clean['print_sheets']);
    $clean['updated_by'] = $uid; $clean['updated_at'] = date('Y-m-d H:i:s');
    $db->prepare('UPDATE report_templates SET mapping = ? WHERE id = ?')->execute([json_encode($clean), $tpl['id']]);
    return ['ok' => true];
}

/**
 * Generate the template's own test report (the values already in the workbook), then compare every
 * calculated tank row and total with what Excel had calculated in the uploaded file.
 */
function rtTestGenerate(array $tpl, bool $pdf = true): array {
    $eff = rtEffective($tpl);
    [$data, $errors] = rtCleanData($eff, $eff['sample']);
    $book = rtBuild($tpl, $data);
    $res = rtResults($book, $eff, $data);
    $dir = rtDir() . '/tests';
    rtMkdir($dir);
    $base = 'test-' . $tpl['kind'] . '-v' . $tpl['version'] . '-' . date('Ymd-His');
    $xlsx = $dir . '/' . $base . '.xlsx';
    rtSave($book, $xlsx, rtAbs($tpl['file_path']));
    $cmp = []; $ok = true;
    foreach ($eff['expected'] as $s => $exp) {
        foreach ($exp['tanks'] as $i => $e) foreach (['vcf', 'gsv', 'mt'] as $k) {
            $got = $res['tanks'][$s][$i][$k] ?? null;
            $same = $e[$k] === null ? $got === null : ($got !== null && abs($got - $e[$k]) < 0.0005);
            if (!$same) { $ok = false; $cmp[] = ['where' => $eff['sections'][$s]['label'] . ' tank ' . ($i + 1) . ' ' . strtoupper($k), 'expected' => $e[$k], 'got' => $got]; }
        }
        foreach (['gsv', 'mt'] as $k) {
            $e = $exp[$k]; $got = $res['totals'][$s][$k] ?? null;
            $same = $e === null ? true : ($got !== null && abs($got - $e) < 0.0005); // an empty total in the template (no formula) can't be compared
            if (!$same) { $ok = false; $cmp[] = ['where' => $eff['sections'][$s]['label'] . ' total ' . strtoupper($k), 'expected' => $e, 'got' => $got]; }
        }
    }
    $out = ['ok' => true, 'match' => $ok, 'differences' => $cmp, 'errors' => $errors, 'totals' => $res['totals'],
        'tank_count' => array_sum(array_map('count', $data['tanks'])), 'xlsx' => 'uploads/report_templates/tests/' . $base . '.xlsx'];
    if ($pdf) {
        $p = rtPdf($xlsx, $eff['print_sheets'], $dir . '/' . $base . '.pdf');
        $out['pdf'] = $p['ok'] ? 'uploads/report_templates/tests/' . $base . '.pdf' : null;
        $out['pdf_error'] = $p['ok'] ? '' : $p['error'];
    }
    return $out;
}

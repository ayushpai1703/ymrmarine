<?php
/**
 * Reads the recovery figures from an uploaded calculation sheet's TABLES 54B
 * tab: O21 = total recovery, M20 = VLSFO, N20 = HSFO, O20 = LSMGO.
 *
 * Formula cells use the value Excel saved in the file (exactly what the
 * surveyor sees in Excel), falling back to recalculating only when no saved
 * value exists. Each cell is read independently, so one unreadable cell
 * never zeroes the others.
 */

const RECOVERY_EXCEL_CELLS = ['recovery' => 'O21', 'vlsfo' => 'M20', 'hsfo' => 'N20', 'lsmgo' => 'O20'];

/** 3 = the TABLES 54B tab itself, 2 = any "…54B…" tab, 1 = any other "TABLES 54…" tab, 0 = unrelated. */
function recoverySheetRank(string $name): int {
    $n = preg_replace('/[\s_.\-]+/', '', strtoupper($name));
    if ($n === 'TABLES54B' || $n === 'TABLE54B') return 3;
    if (strpos($n, '54B') !== false) return 2;
    if (strpos($n, 'TABLES54') === 0 || strpos($n, 'TABLE54') === 0) return 1;
    return 0;
}

/** Positive number from a cell value, or null for blanks, text and Excel errors (#VALUE!, #DIV/0!, …). */
function recoveryNumber($v): ?float {
    if ($v === null || is_bool($v) || is_array($v) || is_object($v)) return null;
    if (is_int($v) || is_float($v)) return is_finite((float)$v) ? abs((float)$v) : null;
    $s = trim((string)$v);
    if ($s === '' || $s[0] === '#') return null;
    $s = trim(str_replace([',', ' ', "\u{00A0}"], '', $s), '()');
    if (!preg_match('/^[-+]?(\d+\.?\d*|\.\d+)(e[-+]?\d+)?$/i', $s)) return null;
    return abs((float)$s);
}

/** @return array{recovery: ?float, vlsfo: ?float, hsfo: ?float, lsmgo: ?float} */
function extractRecoveryFromExcel(string $path): array {
    // Fast path: the saved cell values of the recovery tab, read straight from the .xlsx
    // (milliseconds). PhpSpreadsheet loads the whole workbook — seconds on big sheets, which
    // made the Pending Vessel upload slow — so it only runs when this can't give all values.
    $xmlRank = 0;
    $out = recoveryReadCellsFromXlsxXml($path, $xmlRank);
    if ($xmlRank > 0 && !in_array(null, $out, true)) return $out;
    $out = array_fill_keys(array_keys(RECOVERY_EXCEL_CELLS), null);

    if (class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
        try {
            $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
            $sheet = null;
            $best = 0;
            foreach ($book->getSheetNames() as $name) {
                $rank = recoverySheetRank($name);
                if ($rank > $best) { $best = $rank; $sheet = $book->getSheetByName($name); }
            }
            $sheet = $sheet ?: $book->getActiveSheet();
            foreach (RECOVERY_EXCEL_CELLS as $key => $ref) {
                try {
                    $cell = $sheet->getCell($ref);
                    if ($cell->isFormula()) {
                        $val = recoveryNumber($cell->getOldCalculatedValue());
                        if ($val === null) {
                            try { $val = recoveryNumber($cell->getCalculatedValue()); } catch (Throwable $e) { $val = null; }
                        }
                    } else {
                        $val = recoveryNumber($cell->getValue());
                    }
                    $out[$key] = $val;
                } catch (Throwable $e) {
                    error_log("extractRecoveryFromExcel $ref: " . $e->getMessage());
                }
            }
            $book->disconnectWorksheets();
        } catch (Throwable $e) {
            error_log('extractRecoveryFromExcel load: ' . $e->getMessage());
        }
    }

    if (in_array(null, $out, true)) {
        foreach (recoveryReadCellsFromXlsxXml($path) as $key => $val) {
            if ($out[$key] === null && $val !== null) $out[$key] = $val;
        }
    }
    return $out;
}

/** Fallback for .xlsx/.xlsm without PhpSpreadsheet: reads the saved cell values of the TABLES 54B tab straight from the file. */
function recoveryReadCellsFromXlsxXml(string $path, int &$sheetRank = 0): array {
    $out = array_fill_keys(array_keys(RECOVERY_EXCEL_CELLS), null);
    if (!class_exists('ZipArchive')) return $out;
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) return $out;
    try {
        $workbook = (string)$zip->getFromName('xl/workbook.xml');
        $rels = (string)$zip->getFromName('xl/_rels/workbook.xml.rels');
        $targets = [];
        if (preg_match_all('/<Relationship\b[^>]*>/', $rels, $rm)) {
            foreach ($rm[0] as $tag) {
                if (preg_match('/\bId="([^"]+)"/', $tag, $id) && preg_match('/\bTarget="([^"]+)"/', $tag, $tg)) {
                    $targets[$id[1]] = $tg[1];
                }
            }
        }
        $sheetFile = null;
        $best = -1;
        if (preg_match_all('/<sheet\b[^>]*>/', $workbook, $sm)) {
            foreach ($sm[0] as $tag) {
                if (!preg_match('/\bname="([^"]*)"/', $tag, $nm) || !preg_match('/\br:id="([^"]+)"/', $tag, $rid)) continue;
                $rank = recoverySheetRank(html_entity_decode($nm[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                if ($rank > $best && isset($targets[$rid[1]])) {
                    $best = $rank;
                    $t = ltrim($targets[$rid[1]], '/');
                    $sheetFile = strpos($t, 'xl/') === 0 ? $t : 'xl/' . $t;
                }
            }
        }
        $sheetRank = max(0, $best);
        $xml = $sheetFile !== null ? (string)$zip->getFromName($sheetFile) : '';
        if ($xml === '') return $out;

        $shared = null;
        foreach (RECOVERY_EXCEL_CELLS as $key => $ref) {
            if (!preg_match('/<c\b[^>]*\br="' . $ref . '"[^>]*?(?:\/>|>(.*?)<\/c>)/s', $xml, $cm)) continue;
            $type = preg_match('/<c\b[^>]*\bt="([^"]+)"/', $cm[0], $tm) ? $tm[1] : 'n';
            $inner = $cm[1] ?? '';
            $raw = preg_match('/<v>([^<]*)<\/v>/', $inner, $vm) ? html_entity_decode($vm[1], ENT_QUOTES | ENT_XML1, 'UTF-8') : null;
            if ($type === 'e' || ($raw === null && $type !== 'inlineStr')) continue;
            if ($type === 's') {
                if ($shared === null) {
                    $shared = [];
                    $ss = (string)$zip->getFromName('xl/sharedStrings.xml');
                    if (preg_match_all('/<si>(.*?)<\/si>/s', $ss, $si)) {
                        foreach ($si[1] as $s) $shared[] = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_XML1, 'UTF-8');
                    }
                }
                $raw = $shared[(int)$raw] ?? null;
            } elseif ($type === 'inlineStr') {
                $raw = html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
            $out[$key] = recoveryNumber($raw);
        }
    } finally {
        $zip->close();
    }
    return $out;
}

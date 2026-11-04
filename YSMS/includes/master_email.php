<?php
/**
 * "Send Email to Master for Redelivery" helper (report_detail.php → ajax/master_email.php):
 * looks through a survey's uploaded attachments for the Ship's Particulars and reads the
 * Master's email address from it, so the user can copy it into the redelivery email.
 *
 * Read-only: files are only read (never changed, never sent anywhere except — for a scanned
 * or photographed Ship's Particulars only — to the configured Gemini model to read it).
 * Document contents are never logged.
 *
 * Ship's Particulars = a file (or a file inside a ZIP) whose name says so ("particular",
 * "ship's particulars", "Q88"), or a PDF / Word / Excel / text page whose text has a heading
 * like "SHIP'S PARTICULARS" / "VESSEL PARTICULARS".
 */
require_once __DIR__ . '/gemini_json.php';

const ME_MAX_FILE = 25 * 1024 * 1024;   // skip bigger files
const ME_MAX_ENTRY = 15 * 1024 * 1024;  // skip bigger ZIP entries
const ME_MAX_ENTRIES = 400;              // ZIP entries looked at
const ME_MAX_PDF_PAGES = 60;
const ME_AI_MAX = 12 * 1024 * 1024;      // biggest scan sent to the AI
const ME_OWN_DOMAINS = ['ymrmarine.com'];

/** Name looks like a Ship's Particulars document. */
function meNameIsParticulars(string $name): bool {
    $n = strtolower(preg_replace('/[_\-.]+/', ' ', basename($name)));
    return (bool)preg_match("/particular|ship\s*'?s?\s*part\b|vsl\s*part|\bq\s*88\b/", $n);
}

/** Text has a Ship's / Vessel Particulars heading. */
function meTextIsParticulars(string $t): bool {
    return (bool)preg_match("/\b(ship|ships|ship'?s|ship’s|vessel|vessel'?s|vsl)\s*(particulars|particular|details\s+and\s+particulars)\b|\bq\s?88\b/i", $t);
}

/** Plain text of a document (by extension); '' when it has none we can read. */
function meText(string $ext, string $bytes): string {
    $ext = strtolower($ext);
    try {
        if ($ext === 'pdf') {
            if (!class_exists(\Smalot\PdfParser\Parser::class)) return '';
            $pdf = (new \Smalot\PdfParser\Parser())->parseContent($bytes);
            $out = [];
            foreach ($pdf->getPages() as $i => $p) {
                if ($i >= ME_MAX_PDF_PAGES) break;
                try { $out[] = (string)$p->getText(); } catch (Throwable $e) { $out[] = ''; }
            }
            return implode("\n\f\n", $out); // \f marks page breaks
        }
        if (in_array($ext, ['docx', 'xlsx'], true)) {
            $tmp = tempnam(sys_get_temp_dir(), 'me');
            file_put_contents($tmp, $bytes);
            $z = new ZipArchive();
            $txt = '';
            if ($z->open($tmp) === true) {
                $names = $ext === 'docx' ? ['word/document.xml'] : ['xl/sharedStrings.xml'];
                if ($ext === 'xlsx') for ($i = 0; $i < min($z->numFiles, 60); $i++) { $n = $z->getNameIndex($i); if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $n)) $names[] = $n; }
                foreach ($names as $n) {
                    $x = $z->getFromName($n);
                    if ($x === false) continue;
                    $x = preg_replace(['#</w:p>#', '#<w:tab/>#', '#</(si|c|row)>#'], ["\n", "\t", "\n"], $x);
                    $txt .= html_entity_decode(strip_tags($x), ENT_QUOTES | ENT_XML1, 'UTF-8') . "\n";
                }
                $z->close();
            }
            @unlink($tmp);
            return $txt;
        }
        if ($ext === 'xls') {
            if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) return '';
            $tmp = tempnam(sys_get_temp_dir(), 'me') . '.xls';
            file_put_contents($tmp, $bytes);
            $txt = '';
            try {
                $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
                foreach ($book->getAllSheets() as $sh) foreach ($sh->toArray(null, false, false, false) as $row) $txt .= implode("\t", array_map('strval', $row)) . "\n";
            } finally { @unlink($tmp); }
            return $txt;
        }
        if (in_array($ext, ['txt', 'csv', 'eml', 'htm', 'html'], true)) {
            return $ext === 'htm' || $ext === 'html' ? html_entity_decode(strip_tags($bytes), ENT_QUOTES, 'UTF-8') : $bytes;
        }
    } catch (Throwable $e) {
        return '';
    }
    return '';
}

/**
 * Emails in the text, best Master candidate first.
 * Returns [['email' => …, 'master' => bool], …] (own-company addresses left out).
 */
function meEmails(string $text): array {
    if (!preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $m, PREG_OFFSET_CAPTURE)) return [];
    $out = [];
    foreach ($m[0] as [$email, $pos]) {
        // PDF text sometimes glues the label to the value ("Emailce@x.com"): a capitalised
        // label word at the very start is not part of the address
        if (preg_match('/^(E-?mail(?:\s?ID)?|EMAIL|E-MAIL|Mail(?:\s?ID)?|Email[-_]?Id)(?=[a-z0-9])/', $email, $lm) && strpos($email, '@') > strlen($lm[1])) {
            $pos += strlen($lm[1]);
            $email = substr($email, strlen($lm[1]));
        }
        $email = strtolower(rtrim($email, '.'));
        $domain = substr(strrchr($email, '@'), 1);
        if (in_array($domain, ME_OWN_DOMAINS, true)) continue;
        // the label of this value: text before it on the same line, or the previous line(s)
        // when the line holds only the address (PDF tables often put label and value on
        // separate lines)
        $lineStart = strrpos(substr($text, 0, $pos), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $before = strtolower(substr($text, $lineStart, $pos - $lineStart));
        if (($k = strrpos($before, '@')) !== false) $before = substr($before, $k + 1);
        if (trim(preg_replace('/[:\-–\s\t|]+/u', '', $before)) === '' || preg_match('/^\s*(e-?mail|email\s*id|mail)\s*[:\-]?\s*$/', $before)) {
            $prev = substr($text, 0, $lineStart);
            $lines = preg_split('/\R/', rtrim($prev));
            $before .= ' ' . strtolower(implode(' ', array_slice($lines, -2)));
        }
        $local = strstr($email, '@', true);
        $score = 0;
        if (preg_match("/\b(master|captain|capt\.?)\b|master'?s|master’s/u", $before)) $score += 2;
        if (preg_match('/master|capt/', $local)) $score += 3;
        if (!isset($out[$email]) || $out[$email] < $score) $out[$email] = $score;
    }
    arsort($out);
    $res = [];
    foreach ($out as $email => $score) $res[] = ['email' => $email, 'master' => $score > 0];
    return $res;
}

/** The Ship's Particulars part of a text: the pages that carry the heading (all text when none do). */
function meParticularsPart(string $text): string {
    $pages = explode("\f", $text);
    $keep = [];
    foreach ($pages as $i => $p) if (meTextIsParticulars($p)) { $keep[] = $p; if (isset($pages[$i + 1])) $keep[] = $pages[$i + 1]; }
    return $keep ? implode("\n", $keep) : $text;
}

/** Asks the AI to read a scanned / photographed Ship's Particulars. */
function meAiRead(string $mime, string $bytes): array {
    if (geminiApiKey() === '' || strlen($bytes) > ME_AI_MAX) return ['ok' => false];
    $prompt = "This is a document from a ship. If it is (or contains) the SHIP'S PARTICULARS / VESSEL PARTICULARS, "
        . "return the Master's (Captain's) email address written on it. Answer only JSON: "
        . '{"is_ships_particulars": true|false, "master_email": "<email or empty>", "other_emails": ["<emails on the particulars>"]}';
    $r = geminiJson([['text' => $prompt], ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]]], 90, 'master_email');
    if (!$r['ok']) return ['ok' => false];
    $d = $r['data'];
    $valid = fn($e) => is_string($e) && filter_var(trim($e), FILTER_VALIDATE_EMAIL) ? strtolower(trim($e)) : null;
    $emails = [];
    if ($e = $valid($d['master_email'] ?? '')) $emails[] = ['email' => $e, 'master' => true];
    foreach ((array)($d['other_emails'] ?? []) as $o) if (($e = $valid($o)) && !in_array($e, array_column($emails, 'email'), true)) $emails[] = ['email' => $e, 'master' => false];
    $emails = array_values(array_filter($emails, fn($x) => !in_array(substr(strrchr($x['email'], '@'), 1), ME_OWN_DOMAINS, true)));
    return ['ok' => true, 'particulars' => !empty($d['is_ships_particulars']), 'emails' => $emails];
}

/**
 * Looks through the survey's uploads. $files: [['name' => shown name, 'path' => absolute path], …]
 * Returns ['found' => bool, 'sources' => [names], 'master' => email|null, 'emails' => [...], 'note' => string]
 */
function meFindMasterEmail(array $files, bool $assume = false): array {
    $docs = []; // ['name', 'ext', 'bytes'] — files and ZIP entries
    foreach ($files as $f) {
        if (!is_file($f['path']) || filesize($f['path']) > ME_MAX_FILE) continue;
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION) ?: pathinfo($f['path'], PATHINFO_EXTENSION));
        if ($ext === 'zip') {
            $z = new ZipArchive();
            if ($z->open($f['path']) !== true) continue;
            for ($i = 0; $i < min($z->numFiles, ME_MAX_ENTRIES); $i++) {
                $st = $z->statIndex($i);
                if (!$st || substr($st['name'], -1) === '/' || $st['size'] > ME_MAX_ENTRY || strpos($st['name'], '__MACOSX') !== false) continue;
                $eExt = strtolower(pathinfo($st['name'], PATHINFO_EXTENSION));
                if (!in_array($eExt, ['pdf', 'docx', 'xlsx', 'xls', 'txt', 'csv', 'eml', 'htm', 'html', 'jpg', 'jpeg', 'png', 'webp'], true)) continue;
                $bytes = $z->getFromIndex($i);
                if ($bytes !== false) $docs[] = ['name' => basename($f['name']) . ' › ' . $st['name'], 'ext' => $eExt, 'bytes' => $bytes, 'named' => meNameIsParticulars($st['name'])];
            }
            $z->close();
            continue;
        }
        $docs[] = ['name' => $f['name'], 'ext' => $ext, 'bytes' => (string)file_get_contents($f['path']), 'named' => meNameIsParticulars($f['name'])];
    }
    // files named as particulars first
    usort($docs, fn($a, $b) => (int)$b['named'] <=> (int)$a['named']);

    // $assume: the user uploaded this file as the Ship's Particulars — read every document
    // in it (inside a ZIP, only the ones recognised as particulars when there are any)
    $texts = [];
    foreach ($docs as $i => $d) $texts[$i] = in_array($d['ext'], ['jpg', 'jpeg', 'png', 'webp'], true) ? '' : meText($d['ext'], $d['bytes']);
    $recognised = [];
    foreach ($docs as $i => $d) if ($d['named'] || ($texts[$i] !== '' && meTextIsParticulars($texts[$i]))) $recognised[$i] = true;
    if ($assume && !$recognised) $recognised = array_fill_keys(array_keys($docs), true);

    $sources = []; $emails = []; $scans = [];
    foreach ($docs as $i => $d) {
        $text = $texts[$i];
        if (empty($recognised[$i])) continue;
        if ($assume && trim($text) !== '' && !meTextIsParticulars($text)) { // whole file is the particulars
            $sources[] = $d['name'];
            foreach (meEmails($text) as $e) $emails[] = $e + ['source' => $d['name']];
            continue;
        }
        if (trim($text) === '') { $scans[] = $d; $sources[] = $d['name']; continue; } // scan / photo: AI below
        $sources[] = $d['name'];
        foreach (meEmails(meParticularsPart($text)) as $e) $emails[] = $e + ['source' => $d['name']];
    }
    $master = null;
    foreach ($emails as $e) if ($e['master']) { $master = $e['email']; break; }
    $note = '';
    if ($master === null && $scans) {
        foreach ($scans as $d) {
            $mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$d['ext']] ?? null;
            if (!$mime) continue;
            $r = meAiRead($mime, $d['bytes']);
            if (!$r['ok']) { $note = geminiApiKey() === '' ? 'The Ship\'s Particulars is a scan / photo and AI reading is not set up on this server.' : 'The scanned Ship\'s Particulars could not be read.'; continue; }
            if ($assume && !$r['particulars'] && !$r['emails']) { $note = 'This file does not look like a Ship\'s Particulars.'; continue; }
            foreach ($r['emails'] as $e) $emails[] = $e + ['source' => $d['name']];
            if ($master === null) foreach ($r['emails'] as $e) if ($e['master']) { $master = $e['email']; break; }
            if ($master) break;
        }
    }
    // one entry per email (Master first)
    $seen = []; $list = [];
    foreach ($emails as $e) { if (isset($seen[$e['email']])) continue; $seen[$e['email']] = true; $list[] = ['email' => $e['email'], 'master' => $e['email'] === $master, 'source' => $e['source']]; }
    usort($list, fn($a, $b) => (int)$b['master'] <=> (int)$a['master']);
    return ['found' => (bool)$sources, 'sources' => array_values(array_unique($sources)), 'master' => $master, 'emails' => array_slice($list, 0, 10), 'note' => $note];
}

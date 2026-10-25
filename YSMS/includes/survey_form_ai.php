<?php
/**
 * AI reading of survey-form photos (survey_form.php): sends the stored original images to
 * Gemini (same key / model settings as ajax/gemini_describe.php) and returns only what is
 * legible, per field, with a confidence. Never asks for, or accepts, calculated values:
 * V.C.F., G.S.V., metric tons, totals and trim always come from the Excel format's own
 * formulas (includes/survey_form.php). Totals printed on the paper are read only to warn
 * when they disagree with that calculation.
 *
 * Merging (sfAiMerge): AI values go only into EMPTY fields. A field the user already has
 * keeps its value; a different reading is attached as a suggestion the user can accept.
 */

require_once __DIR__ . '/survey_form.php';
require_once __DIR__ . '/gemini_json.php';

const SF_AI_MAX_IMAGES = 8;
const SF_AI_MAX_BYTES = 18 * 1024 * 1024; // Gemini inline request limit is 20 MB
const SF_AI_CONFIDENT = 0.8;              // below this the field is marked "check"

function sfAiPrompt(string $key): string {
    $info = sfTemplateInfo($key);
    $fields = [];
    foreach (array_keys($info['fields']) as $f) {
        [$label, $type] = SF_FIELDS[$f];
        $fmt = ['date' => 'YYYY-MM-DD', 'time' => 'HH:MM 24h', 'imo' => '7 digits', 'int' => 'whole number', 'month' => 'month name'][$type] ?? (strpos($type, 'num:') === 0 ? 'number' : 'text');
        $fields[] = "  \"$f\": $label ($fmt)";
    }
    $fuel = $info['fuel_names'];
    return "You read bunker survey documents of a ship (sounding sheets, ullage reports, engine log extracts, ship particulars, notes).\n"
        . "Extract ONLY values that are clearly written in the images. Copy them exactly as written — do not round, convert units, correct, guess or calculate anything.\n"
        . "If a value is missing, cut off, blurred or you are not sure what it says, use null. Never fill a value from general knowledge.\n"
        . "Give each value a confidence between 0 and 1 for how sure you are you read it correctly.\n\n"
        . "Fields (key: meaning (format)):\n" . implode("\n", $fields) . "\n\n"
        . "Tank measurements, per fuel: \"vlsfo\" = {$fuel['vlsfo']}, \"hsfo\" = {$fuel['hsfo']}, \"lsmgo\" = {$fuel['lsmgo']} (MDO/MGO/LSMGO all go to \"lsmgo\").\n"
        . "For each tank row give: name (tank name as written), ref_height (m), sounding (corrected sounding/ullage in m, or text such as GAUGE), volume (cubic metres as written), density (density at 15°C), temp (temperature °C).\n"
        . "Do NOT return V.C.F., G.S.V., metric tons or any value you would have to compute.\n"
        . "If a TOTAL metric tons per fuel is printed on the document, copy it into printed_totals (else null).\n\n"
        . "Reply with JSON only, exactly this shape:\n"
        . "{\"fields\": {\"<key>\": {\"value\": string|null, \"confidence\": number}},\n"
        . " \"tanks\": {\"vlsfo\": [{\"name\": {\"value\": ..., \"confidence\": ...}, \"ref_height\": {...}, \"sounding\": {...}, \"volume\": {...}, \"density\": {...}, \"temp\": {...}}], \"hsfo\": [], \"lsmgo\": []},\n"
        . " \"printed_totals\": {\"vlsfo\": string|null, \"hsfo\": string|null, \"lsmgo\": string|null},\n"
        . " \"notes\": string}";
}

/**
 * Ask Gemini about the given stored images. Returns ['ok' => true, 'raw' => array] or
 * ['ok' => false, 'message' => ...]. $images: list of ['path' => ..., 'mime' => ...].
 */
function sfAiExtract(string $key, array $images): array {
    if (geminiApiKey() === '') return ['ok' => false, 'message' => 'AI reading is not set up on this server (Gemini API key missing). Fill the form by hand.'];
    $parts = [['text' => sfAiPrompt($key)]];
    $bytes = 0;
    $used = 0;
    foreach ($images as $img) {
        if ($used >= SF_AI_MAX_IMAGES) break;
        $size = @filesize($img['path']);
        if (!$size || $bytes + $size > SF_AI_MAX_BYTES) continue;
        $parts[] = ['inline_data' => ['mime_type' => $img['mime'], 'data' => base64_encode((string)file_get_contents($img['path']))]];
        $bytes += $size;
        $used++;
    }
    if ($used === 0) return ['ok' => false, 'message' => 'No photo small enough to send to the AI (18 MB total limit).'];
    $res = geminiJson($parts, 120, 'survey form');
    if (!$res['ok']) return ['ok' => false, 'message' => $res['message']];
    $raw = $res['data'];
    return ['ok' => true, 'raw' => $raw, 'images_used' => $used];
}

/** One AI cell → ['v' => normalised value, 'conf' => 0..1] or null when empty/unknown. */
function sfAiCell($cell, string $type): ?array {
    if (!is_array($cell)) $cell = ['value' => $cell, 'confidence' => null];
    $val = $cell['value'] ?? null;
    if ($val === null || is_array($val) || is_bool($val)) return null;
    $val = trim((string)$val);
    if ($val === '' || preg_match('/^(null|n\/?a|-+|unknown|illegible)$/i', $val)) return null;
    $conf = is_numeric($cell['confidence'] ?? null) ? max(0, min(1, (float)$cell['confidence'])) : 0.5;
    if ($type !== 'text' && $type !== 'height') $val = str_replace([' ', ','], '', $val);
    [$v, $err] = sfValidate($type, $val);
    // A value that doesn't fit the field (e.g. too many decimals, bad IMO check digit) is kept
    // exactly as read but marked for checking; the form shows the validation message.
    if ($err !== '') $conf = min($conf, 0.4);
    return ['v' => $v, 'conf' => round($conf, 2)];
}

/** Clean the AI reply against the format: known keys only, typed values, no derived numbers. */
function sfAiSanitize(string $key, array $raw): array {
    $info = sfTemplateInfo($key);
    $out = ['fields' => [], 'tanks' => [], 'printed_totals' => [], 'notes' => mb_substr(trim((string)($raw['notes'] ?? '')), 0, 500)];
    foreach (array_keys($info['fields']) as $f) {
        $c = sfAiCell($raw['fields'][$f] ?? null, SF_FIELDS[$f][1]);
        if ($c) $out['fields'][$f] = $c;
    }
    foreach (array_keys(SF_SECTIONS) as $s) {
        $out['tanks'][$s] = [];
        foreach (array_slice((array)($raw['tanks'][$s] ?? []), 0, SF_MAX_TANKS_PER_SECTION) as $row) {
            if (!is_array($row)) continue;
            $r = [];
            foreach (SF_TANK_COLS as $c => [, , $type]) {
                $cell = sfAiCell($row[$c] ?? null, $type);
                if ($cell) $r[$c] = $cell;
            }
            if ($r) $out['tanks'][$s][] = $r;
        }
        $pt = $raw['printed_totals'][$s] ?? null;
        $pt = is_scalar($pt) ? str_replace([',', ' '], '', (string)$pt) : '';
        $out['printed_totals'][$s] = is_numeric($pt) ? (float)$pt : null;
    }
    return $out;
}

/**
 * Put AI values into the draft without overwriting the user's work.
 * Returns [data, stats] — stats counts filled / suggested / new rows.
 */
function sfAiMerge(array $data, array $ai): array {
    $stats = ['filled' => 0, 'suggested' => 0, 'rows' => 0];
    $apply = function (array $cur, array $new) use (&$stats): array {
        if (($cur['v'] ?? '') === '') {
            $stats['filled']++;
            return ['v' => $new['v'], 'src' => 'ai', 'conf' => $new['conf']];
        }
        if ((string)$cur['v'] !== (string)$new['v']) {
            $stats['suggested']++;
            $cur['suggest'] = $new['v'];
        }
        return $cur;
    };
    foreach ($ai['fields'] as $f => $new) {
        if (isset($data['fields'][$f])) $data['fields'][$f] = $apply($data['fields'][$f], $new);
    }
    foreach ($ai['tanks'] as $s => $rows) {
        foreach ($rows as $newRow) {
            $name = strtoupper(trim((string)($newRow['name']['v'] ?? '')));
            $idx = null;
            if ($name !== '') {
                foreach ($data['tanks'][$s] ?? [] as $i => $row) {
                    if (strtoupper(trim((string)($row['name']['v'] ?? ''))) === $name) { $idx = $i; break; }
                }
            }
            if ($idx === null) {
                if (count($data['tanks'][$s] ?? []) >= SF_MAX_TANKS_PER_SECTION) continue;
                $blank = [];
                foreach (array_keys(SF_TANK_COLS) as $c) $blank[$c] = ['v' => ''];
                $data['tanks'][$s][] = $blank;
                $idx = count($data['tanks'][$s]) - 1;
                $stats['rows']++;
            }
            foreach ($newRow as $c => $new) $data['tanks'][$s][$idx][$c] = $apply($data['tanks'][$s][$idx][$c] ?? ['v' => ''], $new);
        }
    }
    return [$data, $stats];
}

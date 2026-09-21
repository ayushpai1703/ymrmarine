<?php
/**
 * No-AI, regex/label-based field extraction from a vessel/job email that
 * the Admin pastes into the Assign Vessel form (subject + body text,
 * pasted as-is from the email client). Populates only fields it actually
 * finds — never invents a value. Pure PHP, no external API required.
 */

/** Every recognized "Label: Value" synonym, mapped to our canonical field name. Longer/more specific synonyms first so they win over generic ones. */
const YSMS_EMAIL_FIELD_LABELS = [
    'vessel_name' => ['vessel name', 'name of vessel', "ship's name", 'ship name', 'vessel', 'ship', 'm/v', 'mv'],
    'imo_number'  => ['imo number', 'imo no.', 'imo no', 'imo'],
    'call_sign'   => ['call sign', 'callsign'],
    'port'        => ['port of survey', 'survey port', 'port name', 'port of call', 'port'],
    'agent'       => ["agent's name", 'agent name', 'appointed agent', 'local agent', 'agents', 'agent'],
    'eta'         => ['expected time of arrival', 'estimated time of arrival', 'eta'],
    'etb'         => ['expected time of berthing', 'estimated time of berthing', 'etb'],
    'survey_type' => ['type of survey', 'nature of survey', 'survey type', 'job type', 'purpose of survey', 'purpose', 'survey'],
    'cargo'       => ['cargo description', 'cargo type', 'commodity', 'cargo'],
    'client'      => ["client's name", 'client name', 'appointed by', 'principal', 'charterers', 'owners', 'company', 'client'],
    'master_name' => ['master/captain', 'master name', 'ship master', 'captain', 'master'],
    'loa'         => ['length overall', 'loa'],
    'draft'       => ['draught', 'draft'],
    'dwt'         => ['deadweight', 'dwt'],
    'gt'          => ['gross tonnage', 'grt', 'gt'],
    'survey_date' => ['date of survey', 'date of inspection', 'inspection date', 'survey date', 'date'],
];

/** Longest-synonym-first lookup: synonym string => field name. Built once. */
function ysmsEmailLabelLookup(): array {
    static $lookup = null;
    if ($lookup !== null) return $lookup;
    $lookup = [];
    foreach (YSMS_EMAIL_FIELD_LABELS as $field => $synonyms) {
        foreach ($synonyms as $syn) {
            $lookup[$syn] = $field;
        }
    }
    // Longest first so "type of survey" matches before a hypothetical shorter overlapping synonym would.
    uksort($lookup, static function ($a, $b) { return strlen($b) <=> strlen($a); });
    return $lookup;
}

/** Strips common trailing/leading noise from an extracted value: quotes, trailing punctuation, "(TBC)" left as-is but stray separators trimmed. */
function ysmsCleanExtractedValue(string $value): string {
    $value = trim($value, " \t\"'-–—:");
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return trim($value);
}

/**
 * Extracts every recognized field from $subject + $text (plain text, as
 * pasted by the Admin from an email client). Returns an assoc array with
 * all YSMS_EMAIL_FIELD_LABELS keys (null when not found) plus
 * 'additional_instructions' and 'confidence_score'.
 */
function extractVesselFieldsFromText(string $subject, string $text): array {
    $result = array_fill_keys(array_keys(YSMS_EMAIL_FIELD_LABELS), null);
    $lookup = ysmsEmailLabelLookup();

    $combined = trim($subject) . "\n" . $text;
    $rawLines = preg_split('/\r\n|\r|\n/', $combined) ?: [];
    $lines = array_values(array_filter(array_map('trim', $rawLines), static function ($l) { return $l !== ''; }));

    for ($i = 0; $i < count($lines); $i++) {
        $line = $lines[$i];

        // "Label: Value" or "Label - Value" (colon/dash separator).
        if (preg_match('/^([A-Za-z][A-Za-z0-9\/\.\'\(\) ]{1,48}?)\s*[:\-]\s*(.*)$/', $line, $m)) {
            $label = strtolower(trim($m[1]));
            $value = ysmsCleanExtractedValue($m[2]);
            $field = $lookup[$label] ?? null;
            if ($field !== null && $result[$field] === null) {
                if ($value !== '') {
                    $result[$field] = $value;
                    continue;
                }
                // "Label:" with nothing after it on the same line — the value is
                // often on the very next non-empty line (common in pasted emails).
                if (isset($lines[$i + 1]) && !preg_match('/^[A-Za-z][A-Za-z0-9\/\.\'\(\) ]{1,48}?\s*[:\-]/', $lines[$i + 1])) {
                    $result[$field] = ysmsCleanExtractedValue($lines[$i + 1]);
                    continue;
                }
            }
        }

        // "Label      Value" — 2+ spaces or a tab, no punctuation separator
        // (common when pasting from a table or a fixed-width PDF export).
        if (preg_match('/^([A-Za-z][A-Za-z0-9\/\.\'\(\) ]{1,40}?)(?:\t| {2,})(\S.*)$/', $line, $m)) {
            $label = strtolower(trim($m[1]));
            $field = $lookup[$label] ?? null;
            if ($field !== null && $result[$field] === null) {
                $value = ysmsCleanExtractedValue($m[2]);
                if ($value !== '') {
                    $result[$field] = $value;
                }
            }
        }
    }

    // IMO number anywhere in the text, even without a label — 7-digit number,
    // optionally preceded by "IMO".
    if ($result['imo_number'] === null && preg_match('/\bIMO\s*(?:No\.?|Number)?\s*[:\-]?\s*(\d{7})\b/i', $combined, $m)) {
        $result['imo_number'] = $m[1];
    } elseif ($result['imo_number'] === null && preg_match('/IMO/i', $combined) && preg_match('/\b(\d{7})\b/', $combined, $m)) {
        $result['imo_number'] = $m[1];
    }
    if ($result['imo_number'] !== null && !preg_match('/^\d{7}$/', (string)$result['imo_number'])) {
        $result['imo_number'] = null;
    }

    // Vessel name fallback — "MV XXXX" / "M.V. XXXX" pattern anywhere in subject or body.
    if ($result['vessel_name'] === null && preg_match('/\bM\.?\s*V\.?\s+([A-Z][A-Za-z0-9 .\-]{2,60})/', $subject, $m)) {
        $result['vessel_name'] = ysmsCleanExtractedValue($m[1]);
    }
    if ($result['vessel_name'] === null && preg_match('/\bM\.?\s*V\.?\s+([A-Z][A-Za-z0-9 .\-]{2,60})/', $text, $m)) {
        $result['vessel_name'] = ysmsCleanExtractedValue($m[1]);
    }

    // "Additional instructions" — a labeled free-text block if present, else nothing invented.
    foreach (['additional instructions', 'special instructions', 'remarks', 'instructions', 'notes'] as $syn) {
        if (preg_match('/^' . preg_quote($syn, '/') . '\s*[:\-]\s*(.+)$/im', $text, $m)) {
            $result['additional_instructions'] = ysmsCleanExtractedValue($m[1]);
            break;
        }
    }

    $totalFields = count(YSMS_EMAIL_FIELD_LABELS);
    $foundFields = count(array_filter($result, static function ($v) { return $v !== null; }));
    $result['confidence_score'] = $totalFields > 0 ? round($foundFields / $totalFields, 3) : 0.0;

    return $result;
}

/**
 * Normalizes a vessel name for duplicate comparison only (not for storage):
 * upper-case, strip MV/M.V. prefix, collapse whitespace/punctuation. Two
 * names that normalize the same are treated as "the same vessel" for
 * duplicate detection — this does NOT need to match normalizeVesselName()'s
 * storage format exactly, since it's only ever compared against itself.
 */
function normalizeVesselNameForMatch(string $name): string {
    $name = trim($name);
    if ($name === '') return '';
    $name = preg_replace('/^m\.?\s*v\.?\s*/i', '', $name) ?? $name;
    $name = mb_strtoupper($name, 'UTF-8');
    $name = preg_replace('/[^A-Z0-9]+/', ' ', $name) ?? $name;
    return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
}

/** Simple ISO 6346-style IMO number check-digit validation (informational only, never blocks extraction). */
function isValidImoNumber(string $imo): bool {
    if (!preg_match('/^\d{7}$/', $imo)) return false;
    $digits = str_split(substr($imo, 0, 6));
    $sum = 0;
    foreach ($digits as $i => $d) {
        $sum += (int)$d * (7 - $i);
    }
    return ($sum % 10) === (int)$imo[6];
}

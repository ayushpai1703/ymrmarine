<?php
/**
 * No-AI, regex/label-based field extraction from a vessel/job email
 * (subject + plain text body + any PDF attachment text already extracted
 * by pdf_extractor.php). Populates only fields it actually finds — never
 * invents a value. Pure PHP, no external API required.
 */

/** Every recognized "Label: Value" synonym, mapped to our canonical field name. */
const YSMS_EMAIL_FIELD_LABELS = [
    'vessel_name' => ['vessel name', 'vessel', 'ship name', 'ship', 'm/v', 'mv'],
    'imo_number'  => ['imo number', 'imo no', 'imo no.', 'imo'],
    'call_sign'   => ['call sign', 'callsign'],
    'port'        => ['port', 'port name', 'port of call'],
    'agent'       => ['agent', 'agents', 'agent name', 'local agent'],
    'eta'         => ['eta', 'estimated time of arrival'],
    'etb'         => ['etb', 'estimated time of berthing'],
    'survey_type' => ['survey type', 'type of survey', 'survey'],
    'cargo'       => ['cargo', 'cargo type', 'commodity'],
    'client'      => ['client', 'client name', 'principal', 'owners', 'charterers'],
    'master_name' => ['master', 'captain', 'master/captain', 'master name'],
    'loa'         => ['loa', 'length overall'],
    'draft'       => ['draft', 'draught'],
    'dwt'         => ['dwt', 'deadweight'],
    'gt'          => ['gt', 'grt', 'gross tonnage'],
    'survey_date' => ['survey date', 'date of survey', 'date'],
];

/**
 * Extracts every recognized field from $subject + $text (plain text body,
 * with PDF-extracted text appended if any). Returns an assoc array with all
 * YSMS_EMAIL_FIELD_LABELS keys (null when not found) plus
 * 'additional_instructions' and 'confidence_score'.
 */
function extractVesselFieldsFromText(string $subject, string $text): array {
    $result = array_fill_keys(array_keys(YSMS_EMAIL_FIELD_LABELS), null);

    $combined = $subject . "\n" . $text;
    $lines = preg_split('/\r\n|\r|\n/', $combined) ?: [];

    // Pass 1: "Label: Value" / "Label - Value" lines, anywhere in subject+body.
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || !preg_match('/^([A-Za-z\/\. ]{2,40}?)\s*[:\-]\s*(.+)$/', $line, $m)) {
            continue;
        }
        $label = strtolower(trim($m[1]));
        $value = trim($m[2]);
        if ($value === '') continue;
        foreach (YSMS_EMAIL_FIELD_LABELS as $field => $synonyms) {
            if ($result[$field] !== null) continue; // first match wins
            foreach ($synonyms as $syn) {
                if ($label === $syn) {
                    $result[$field] = $value;
                    break 2;
                }
            }
        }
    }

    // Pass 2: IMO number anywhere in the text, even without a "IMO:" label —
    // 7-digit number, optionally preceded by "IMO".
    if ($result['imo_number'] === null && preg_match('/\bIMO\s*(?:No\.?|Number)?\s*[:\-]?\s*(\d{7})\b/i', $combined, $m)) {
        $result['imo_number'] = $m[1];
    } elseif ($result['imo_number'] === null && preg_match('/\b(\d{7})\b/', $combined, $m) && preg_match('/IMO/i', $combined)) {
        $result['imo_number'] = $m[1];
    }
    // Validate: IMO numbers are exactly 7 digits with a check-digit — reject obvious non-matches.
    if ($result['imo_number'] !== null && !preg_match('/^\d{7}$/', (string)$result['imo_number'])) {
        $result['imo_number'] = null;
    }

    // Pass 3: Vessel name fallback — "MV XXXX" / "M.V. XXXX" pattern in subject or first lines.
    if ($result['vessel_name'] === null && preg_match('/\bM\.?\s*V\.?\s+([A-Z][A-Za-z0-9 .\-]{2,60})/i', $subject, $m)) {
        $result['vessel_name'] = trim($m[1]);
    }
    if ($result['vessel_name'] === null && preg_match('/\bM\.?\s*V\.?\s+([A-Z][A-Za-z0-9 .\-]{2,60})/', $text, $m)) {
        $result['vessel_name'] = trim($m[1]);
    }

    // "Additional instructions" — a labeled free-text block if present, else nothing invented.
    foreach (['additional instructions', 'remarks', 'instructions', 'notes'] as $syn) {
        if (preg_match('/^' . preg_quote($syn, '/') . '\s*[:\-]\s*(.+)$/im', $text, $m)) {
            $result['additional_instructions'] = trim($m[1]);
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

<?php
/**
 * AI Email -> Vessel Assignment extraction — shared helpers.
 *
 * Used by ajax/email_vessel_extract.php (assign_vessel.php's "Paste Email"
 * tab). Reuses the exact Gemini call/error-handling pattern already
 * established in ajax/gemini_describe.php (same env vars, same curl
 * options, same "retry once on unparseable JSON" convention) — duplicated
 * here as its own text-only variant rather than shared, matching this
 * codebase's existing per-endpoint convention (gemini_describe.php itself
 * keeps all of its Gemini-calling code local rather than in a shared file).
 *
 * Nothing in this file ever throws out of the request — every public
 * function either returns a safe default or an ['error'=>true,...] shape
 * so a tracking/audit failure can never block the admin from using the
 * Paste Email feature or falling back to the Manual Form.
 */

/** SAFETY NET — audit table for every Paste Email extraction attempt (not
 *  only ones that end in an actual vessel assignment). Mirrors the
 *  established ensureXTable(PDO $db) lazy-create pattern used throughout
 *  this app (see config/config.php's ensureSurveyAttachmentsTable /
 *  includes/login_activity_helpers.php's ensureLoginActivityTable). No
 *  equivalent audit/source table exists in this project today — the prior
 *  "Recent Vessel Emails" (IMAP) feature's vessel_emails table was removed
 *  along with that feature — so this is a new, purely additive table. */
function ensureEmailExtractionLogTable(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `email_extraction_log` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `admin_user_id` int DEFAULT NULL,
            `source_email_subject` varchar(500) DEFAULT NULL,
            `source_email_date` varchar(100) DEFAULT NULL,
            `original_email_body` mediumtext,
            `cleaned_email_text` mediumtext,
            `extracted_json` mediumtext,
            `extraction_status` varchar(30) NOT NULL DEFAULT 'SUCCESS',
            `vessel_count` int NOT NULL DEFAULT 0,
            `extraction_confidence` int DEFAULT NULL,
            `survey_id` int DEFAULT NULL,
            `ip_address` varchar(45) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_email_log_admin` (`admin_user_id`),
            KEY `idx_email_log_survey` (`survey_id`),
            KEY `idx_email_log_status` (`extraction_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('ensureEmailExtractionLogTable: ' . $e->getMessage());
    }
}

/**
 * Normalizes a pasted client email (plain text, or HTML copied straight out
 * of Gmail/Outlook) into clean plain text for the AI prompt: converts
 * block-level HTML boundaries to newlines before stripping tags (so
 * paragraphs/table rows/list items don't get smashed together), decodes
 * entities, and collapses excess blank lines. Caps the result at $maxChars
 * so one pasted email can't blow up the Gemini request (or the eventual
 * audit-log row) — a forwarded chain this long has far more history than
 * the AI needs anyway, and the prompt explicitly asks it to prioritize the
 * most recent message.
 *
 * @return array{text:string, was_html:bool, truncated:bool}
 */
function cleanPastedEmailText(string $raw, int $maxChars = 20000): array
{
    $raw = trim($raw);
    $wasHtml = (bool)preg_match('/<\s*(br|p|div|table|tr|td|li|html|body)\b/i', $raw);

    $text = $raw;
    if ($wasHtml) {
        // Block-level boundaries -> newline, BEFORE stripping tags, so the
        // text doesn't end up as one unreadable run-on line.
        $text = preg_replace('/<\s*(br)\s*\/?\s*>/i', "\n", $text);
        $text = preg_replace('/<\s*\/\s*(p|div|tr|li|h[1-6]|table)\s*>/i', "\n", $text);
        $text = preg_replace('/<\s*(style|script)\b[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // Collapse runs of blank lines / trailing whitespace per line, but keep
    // paragraph breaks (the AI benefits from seeing the email's structure).
    $text = preg_replace('/[ \t]+$/m', '', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    $text = trim($text);

    $truncated = false;
    if (mb_strlen($text, 'UTF-8') > $maxChars) {
        $text = mb_substr($text, 0, $maxChars, 'UTF-8');
        $truncated = true;
    }

    return ['text' => $text, 'was_html' => $wasHtml, 'truncated' => $truncated];
}

/** One Gemini generateContent call, text-only (no inline_data image part).
 *  Same return shape / same error-handling as gemini_describe.php's
 *  gemini_call() — see that file for the retry_after parsing rationale. */
function geminiCallText(string $prompt, string $apiKey, string $model): array
{
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
        . rawurlencode($model)
        . ':generateContent?key=' . rawurlencode($apiKey);

    $payload = [
        'contents' => [
            ['parts' => [['text' => $prompt]]],
        ],
        'generationConfig' => [
            'temperature'     => 0.1,
            'maxOutputTokens' => 2048,
            'thinkingConfig'  => ['thinkingBudget' => 0],
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false || $response === '') {
        return [
            'error'   => true,
            'message' => 'Could not reach Gemini API' . ($curlErr ? (': ' . $curlErr) : '.'),
            'http'    => 502,
        ];
    }

    $json = json_decode($response, true);
    if ($httpCode >= 400 || !is_array($json)) {
        $msg = 'Gemini API error (HTTP ' . $httpCode . ').';
        if (is_array($json) && isset($json['error']['message'])) {
            $msg = $json['error']['message'];
        }
        $retryAfter = null;
        if (preg_match('/retry in\s*([0-9]+(?:\.[0-9]+)?)\s*s/i', $msg, $rm)) {
            $retryAfter = (int) ceil((float) $rm[1]) + 1;
        }
        return [
            'error'       => true,
            'message'     => $msg,
            'http'        => ($httpCode >= 400 ? $httpCode : 502),
            'retry_after' => $retryAfter,
        ];
    }

    $raw = '';
    if (!empty($json['candidates'][0]['content']['parts'])) {
        foreach ($json['candidates'][0]['content']['parts'] as $part) {
            if (isset($part['text'])) {
                $raw .= $part['text'];
            }
        }
    }

    return [
        'error'        => false,
        'raw'          => $raw,
        'finishReason' => $json['candidates'][0]['finishReason'] ?? 'unknown',
    ];
}

/** Strips markdown code fences the model sometimes wraps JSON in despite
 *  being told not to (same cleanup gemini_describe.php applies), then
 *  json_decode. Returns null if still not valid JSON. */
function geminiExtractJson(string $raw): ?array
{
    $cleaned = trim($raw);
    $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
    $cleaned = preg_replace('/```\s*$/', '', $cleaned);
    $decoded = json_decode(trim($cleaned), true);
    return is_array($decoded) ? $decoded : null;
}

/** Normalizes a name for fuzzy matching: lowercase, strip anything that
 *  isn't a letter/digit/space, collapse whitespace. "M/s. ABC Shipping Pvt
 *  Ltd." and "ABC Shipping" both reduce close enough for substring/
 *  similarity matching to find each other. */
function emailAiNormalizeForMatch(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = preg_replace('/[^a-z0-9\s]/u', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

/**
 * Best-effort fuzzy match of an AI-extracted name against a real list of DB
 * rows (id => label). Never invents a match below $minScore — the caller
 * must treat a null return as "no confident match, leave blank for the
 * admin to pick manually" (per the "never let AI silently guess" rule).
 *
 * @param array<int,string> $candidates id => label
 * @return array{id:int, label:string, score:int}|null score is 0-100
 */
function emailAiBestMatch(string $needle, array $candidates, int $minScore = 60): ?array
{
    $needle = trim($needle);
    if ($needle === '' || !$candidates) {
        return null;
    }
    $normNeedle = emailAiNormalizeForMatch($needle);
    if ($normNeedle === '') {
        return null;
    }

    $best = null;
    $bestScore = 0;
    foreach ($candidates as $id => $label) {
        $normLabel = emailAiNormalizeForMatch((string) $label);
        if ($normLabel === '') continue;

        if ($normLabel === $normNeedle) {
            $score = 100;
        } elseif (str_contains($normLabel, $normNeedle) || str_contains($normNeedle, $normLabel)) {
            // Substring either direction — e.g. needle "ABC" inside label
            // "ABC Shipping", or needle "ABC Shipping Pvt Ltd" containing
            // label "ABC Shipping". Scaled by how much of the longer
            // string the shorter one covers, floored so it still clears
            // $minScore for a clean containment match.
            $longer = max(mb_strlen($normLabel, 'UTF-8'), mb_strlen($normNeedle, 'UTF-8'));
            $shorter = min(mb_strlen($normLabel, 'UTF-8'), mb_strlen($normNeedle, 'UTF-8'));
            $score = (int) max(75, round(($shorter / max(1, $longer)) * 100));
        } else {
            similar_text($normLabel, $normNeedle, $percent);
            $score = (int) round($percent);
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $best = ['id' => (int) $id, 'label' => (string) $label, 'score' => $score];
        }
    }

    return ($best !== null && $bestScore >= $minScore) ? $best : null;
}

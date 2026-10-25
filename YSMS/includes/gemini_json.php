<?php
/**
 * One Gemini generateContent call that must answer with JSON (survey form photos,
 * Report Generator PDFs). Key / model / endpoint from the environment, the key sent in a
 * header (never in the URL). Never logs document contents — only status codes.
 *
 * geminiJson($parts, $timeout) → ['ok' => true, 'data' => array] | ['ok' => false, 'message' => …]
 * $parts: Gemini content parts, e.g. [['text' => …], ['inline_data' => ['mime_type' => …, 'data' => base64]]]
 */

function geminiApiKey(): string {
    if (defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '') return (string)GEMINI_API_KEY;
    return (string)(getenv('YSMS_GEMINI_API_KEY') ?: getenv('GEMINI_API_KEY') ?: '');
}

function geminiJson(array $parts, int $timeout = 120, string $logTag = 'gemini'): array {
    $apiKey = geminiApiKey();
    if ($apiKey === '') return ['ok' => false, 'message' => 'AI reading is not set up on this server (Gemini API key missing). Fill the form by hand.', 'retry' => false];
    $model = getenv('YSMS_GEMINI_MODEL') ?: (defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-3.6-flash');
    $base = rtrim(getenv('YSMS_GEMINI_BASE_URL') ?: 'https://generativelanguage.googleapis.com', '/');
    $url = $base . '/v1beta/models/' . rawurlencode($model) . ':generateContent';
    $payload = json_encode([
        'contents' => [['role' => 'user', 'parts' => $parts]],
        'generationConfig' => ['temperature' => 0, 'responseMimeType' => 'application/json', 'maxOutputTokens' => 16384],
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey], CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 15]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) {
        error_log("$logTag AI: HTTP $code" . ($err ? " ($err)" : ''));
        return ['ok' => false, 'message' => 'The AI service did not answer (' . ($code ?: 'no connection') . '). Try again, or fill the form by hand.', 'retry' => true];
    }
    $resp = json_decode((string)$body, true);
    $text = '';
    foreach ((array)($resp['candidates'][0]['content']['parts'] ?? []) as $p) $text .= (string)($p['text'] ?? '');
    $text = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($text)));
    $data = json_decode($text, true);
    if (!is_array($data)) {
        error_log("$logTag AI: reply was not JSON (" . strlen($text) . ' chars)');
        return ['ok' => false, 'message' => 'The AI reply could not be read. Try again, or fill the form by hand.', 'retry' => true];
    }
    return ['ok' => true, 'data' => $data];
}

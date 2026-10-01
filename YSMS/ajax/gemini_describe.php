<?php
/**
 * Gemini Vision – marine survey image description proxy.
 * API key stays on the server; never exposed to the browser.
 *
 * POST JSON:
 *   { "image": "<base64>", "mime": "image/jpeg", "survey_id": 123,
 *     "context": { "survey_type": "...", "vessel_name": "...", "port_name": "...", "category": "..." },
 *     "options": ["Forward Section", "Hull › Port Side › At Bow Section", ...] }
 *   optional: "mime_type" (alias of mime)
 * POST multipart: field "image" (file), optional "survey_id", "context[...]", "options[...]"
 *
 * When "options" is a non-empty list, the endpoint runs in classification
 * mode: instead of writing free text, Gemini picks the single best-matching
 * item from that fixed list (the report's own existing structure/description
 * dropdown options) and only its 1-based list position is returned — faster
 * (a few output tokens instead of a full sentence) and it reuses the report's
 * own standard phrasing instead of inventing new wording. The model always
 * commits to one pick (majority-of-frame object + its side), never an
 * "unable to determine" — confidence/evidence come back for logging and
 * quality tracking only, not to withhold an answer. A reply that genuinely
 * can't be parsed even after one server-side retry is surfaced as an error
 * (success: false) so the client's normal retry/backoff handles it.
 *   { "success": true, "index": 7, "confidence": 92, "evidence": "..." }
 *
 * Without "options" (or an empty list), falls back to free-text mode:
 *   { "success": true, "description": "..." }
 *   { "success": false, "message": "..." }
 */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// CORS not required when same-origin; keep strict.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST only.']);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';
checkAuth();

// Same role set as the AI widget itself — Client logins never reach this.
$__role = $_SESSION['role'] ?? '';
if (!in_array($__role, ['Admin', 'Super Admin', 'Surveyor'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit;
}

if (!AI_PHOTO_DESCRIPTION_ENABLED) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'AI photo description is disabled.']);
    exit;
}

// Prefer env / define; never hard-code a real key in the repo.
$apiKey = '';
if (defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '') {
    $apiKey = (string) GEMINI_API_KEY;
} elseif (getenv('YSMS_GEMINI_API_KEY')) {
    $apiKey = (string) getenv('YSMS_GEMINI_API_KEY');
} elseif (getenv('GEMINI_API_KEY')) {
    $apiKey = (string) getenv('GEMINI_API_KEY');
}

if ($apiKey === '') {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'Gemini API key is not configured on the server. Set YSMS_GEMINI_API_KEY or GEMINI_API_KEY.',
    ]);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    // Fallback: multipart form (field "image" file)
    if (!empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
        $bin = file_get_contents($_FILES['image']['tmp_name']);
        $mime = $_FILES['image']['type'] ?: 'image/jpeg';
        $b64 = base64_encode($bin);
        $surveyId = isset($_POST['survey_id']) ? (int) $_POST['survey_id'] : 0;
        $context = isset($_POST['context']) && is_array($_POST['context']) ? $_POST['context'] : [];
        $options = isset($_POST['options']) && is_array($_POST['options']) ? $_POST['options'] : [];
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON body.']);
        exit;
    }
} else {
    $b64 = isset($data['image']) ? (string) $data['image'] : '';
    // Strip data-URL prefix if the client sent one
    if (preg_match('#^data:([^;]+);base64,#i', $b64, $m)) {
        $mime = $m[1];
        $b64 = substr($b64, strpos($b64, ',') + 1);
    } else {
        $mime = (string) ($data['mime'] ?? $data['mime_type'] ?? 'image/jpeg');
    }
    $surveyId = isset($data['survey_id']) ? (int) $data['survey_id'] : 0;
    $context = isset($data['context']) && is_array($data['context']) ? $data['context'] : [];
    $options = isset($data['options']) && is_array($data['options']) ? $data['options'] : [];
}

// Authorization: a Surveyor may only describe photos for a survey they're
// actually assigned to. Admin/Super Admin bypass (same as report_detail.php).
// survey_id = 0 means a standalone draft (not tied to any survey) — allowed
// for any of the three roles above, same as opening the page itself with no ?id=.
if ($surveyId > 0 && $__role === 'Surveyor') {
    $db = getDB();
    if (!isSurveyorAssignedToSurvey($db, $surveyId, (int) $_SESSION['user_id'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not authorized for this survey.']);
        exit;
    }
}

$b64 = preg_replace('/\s+/', '', $b64);
if ($b64 === '' || strlen($b64) < 100) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Image data is missing or too small.']);
    exit;
}

// Cap ~4 MB base64 (~3 MB binary) to protect server memory
if (strlen($b64) > 4 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['success' => false, 'message' => 'Image too large. Please use a smaller image.']);
    exit;
}

$allowedMimes = [
    'image/jpeg' => true,
    'image/jpg'  => true,
    'image/png'  => true,
    'image/webp' => true,
    'image/gif'  => true,
    'image/heic' => true,
    'image/heif' => true,
];
$mime = strtolower(trim($mime));
if ($mime === 'image/jpg') {
    $mime = 'image/jpeg';
}
if (!isset($allowedMimes[$mime])) {
    $mime = 'image/jpeg';
}

// Optional context (survey type / vessel / port / photo category) — for
// reference only. The model is explicitly told not to assume anything from
// it that isn't also visible in the photo, so a wrong/stale dropdown value
// on the page can never override what the image actually shows.
$contextPrefix = '';
if (!empty($context)) {
    $labels = [
        'survey_type' => 'Survey Type',
        'vessel_name' => 'Vessel',
        'port_name'   => 'Location',
        'category'    => 'Photo Category',
    ];
    $lines = [];
    foreach ($labels as $key => $label) {
        if (!empty($context[$key]) && is_string($context[$key])) {
            $val = trim($context[$key]);
            if ($val !== '') {
                $lines[] = $label . ': ' . $val;
            }
        }
    }
    if ($lines) {
        $contextPrefix = "Context, for reference only — do NOT mention or assume anything below that is not also clearly visible in the photograph itself:\n"
            . implode("\n", $lines) . "\n\n";
    }
}

// Classification mode — pick one item from the report's own existing
// structure/description dropdown instead of writing free text. Much less
// for Gemini to generate (a few tokens vs a full sentence), so it's both
// faster and guarantees the report's standard phrasing is reused as-is.
$cleanOptions = [];
foreach ($options as $o) {
    if (is_string($o) && trim($o) !== '') {
        $cleanOptions[] = trim($o);
    }
}
$cleanOptions = array_slice($cleanOptions, 0, 200);

/** One Gemini generateContent call. Returns ['error'=>false,'raw'=>...,
 *  'finishReason'=>...] or ['error'=>true,'message'=>...,'http'=>...,
 *  'retry_after'=>...|null] — shared by the classification pass(es) below
 *  so the curl/error-handling code isn't duplicated per call. */
function gemini_call($payload, $apiKey, $model)
{
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
        . rawurlencode($model)
        . ':generateContent?key=' . rawurlencode($apiKey);

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

/** Pulls {index, confidence, evidence} out of a classification response's
 *  raw text. 'parsed_ok' is false only when nothing usable came back at
 *  all (empty / safety block / thinking ate the budget) — that's the
 *  signal to retry once, per the "invalid JSON -> retry once" rule. */
function gemini_parse_classification($raw, $optionCount)
{
    $index = null;
    $hasExplicitNull = false;
    if (preg_match('/"?index"?\s*:\s*(\d+)/i', $raw, $im)) {
        $index = (int) $im[1];
    } elseif (preg_match('/"?index"?\s*:\s*null/i', $raw)) {
        $hasExplicitNull = true;
    } elseif (preg_match('/\b(\d+)\b/', $raw, $nm)) {
        // Fallback: model replied with a bare number, no JSON wrapper
        $index = (int) $nm[1];
    }
    if ($index !== null && ($index < 1 || $index > $optionCount)) {
        // Never trust a number the model invented outside the list we sent.
        $index = null;
    }

    $confidence = null;
    if (preg_match('/"?confidence"?\s*:\s*(\d+)/i', $raw, $cm)) {
        $confidence = max(0, min(100, (int) $cm[1]));
    }

    $evidence = '';
    if (preg_match('/"evidence"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/i', $raw, $em)) {
        $evidence = stripcslashes($em[1]);
    }

    $parsedOk = $index !== null || $hasExplicitNull || $confidence !== null;
    return ['index' => $index, 'confidence' => $confidence, 'evidence' => $evidence, 'parsed_ok' => $parsedOk];
}

if (count($cleanOptions) > 0) {
    $t0 = microtime(true);
    $listText = '';
    foreach ($cleanOptions as $i => $label) {
        $listText .= ($i + 1) . '. ' . $label . "\n";
    }

    // Targets the two failure modes actually seen in production: Gemini
    // defaulting almost every Bulkhead photo to "Forward" out of habit
    // rather than evidence, and confusing a close-up Ladder shot with the
    // Bulkhead it's mounted on (or vice versa). Always commits to a single
    // pick — the report's dropdown has no "unsure" option, so the model's
    // job is to make its best professional call, not to abstain.
    $domainGuidance = 'Special guidance:
- Always choose the option whose object occupies the majority of the photograph\'s frame — that is the subject the photo is documenting. Do not default to "Forward" (or the first-listed option) out of habit; independently look at whatever side/orientation evidence is visible (surrounding hold/ship structure, access arrangements, ladder position, longitudinal/transverse arrangement) and make your best judgment even if that evidence is only partial — never leave a photo unclassified.
- Do not classify a Bulkhead photo as a Ladder just because a ladder is attached to or near it, and do not classify a Ladder close-up as a Bulkhead. Classify by what the photograph PRIMARILY documents: if a full/major portion of a bulkhead is visible, classify the bulkhead\'s orientation; if only a close-up of a ladder is the main subject and the bulkhead cannot be reliably identified, classify the ladder type instead.
- If classifying a ladder, distinguish by shape: a straight ladder with parallel rails roughly vertical is "Vertical"; a curved or spiral ladder is "Australian"; a straight ladder installed at a clear angle/slope is the inclined one.
';

    $classifyPromptBase = $contextPrefix . 'You are a professional Marine Cargo, Hull, and Bunker Surveyor with over 20 years of experience.
Analyze ONLY what is clearly visible in the uploaded photograph.

Below is a fixed numbered list of standard ship/hold structure locations used to organize photographs in this survey report. Your task is to pick the ONE item from this list that best matches what is actually, visibly shown in the photograph — specifically, the object/structure that takes up the majority portion of the frame, and its side where applicable.

Numbered list:
' . $listText . '
' . $domainGuidance . '
Rules:
1. Base your choice only on what is visible in the photograph — never on assumptions or on the context above.
2. Always choose exactly one item\'s number from the list, regardless of the object\'s visible condition or damage. The list is not exhaustive of every possible structure, so pick whichever listed item is the closest reasonable match to the majority-portion object and its side — do not leave this unanswered.
3. confidence is an integer 0-100 reflecting how strong the visual evidence is for that specific pick (used only for logging/quality tracking, not to withhold an answer).
4. evidence is a very short phrase (under 12 words) naming the visual cue that justified the choice.
5. Respond with ONLY one JSON object and nothing else — no markdown, no explanation, no extra text:
{"index": <number>, "confidence": <0-100>, "evidence": "<short phrase>"}';

    function gemini_classify_payload($prompt, $mime, $b64)
    {
        return [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                        ['inline_data' => ['mime_type' => $mime, 'data' => $b64]],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature'     => 0,
                'maxOutputTokens' => 150,
                // 2.5+/3.x Flash models think by default and those thinking
                // tokens are billed against maxOutputTokens — with a small
                // cap a model can spend the whole budget "reasoning" and
                // never actually emit the answer text, coming back empty.
                // This is a short pick-and-score task; no reasoning needed.
                'thinkingConfig'  => ['thinkingBudget' => 0],
            ],
        ];
    }

    $model = getenv('YSMS_GEMINI_MODEL') ?: (defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-3.6-flash');

    $r1 = gemini_call(gemini_classify_payload($classifyPromptBase, $mime, $b64), $apiKey, $model);
    if ($r1['error']) {
        http_response_code($r1['http']);
        $out = ['success' => false, 'message' => $r1['message']];
        if (!empty($r1['retry_after'])) {
            $out['retry_after'] = $r1['retry_after'];
        }
        echo json_encode($out);
        exit;
    }
    $p1 = gemini_parse_classification($r1['raw'], count($cleanOptions));

    // "IF JSON is invalid -> retry the API request once" — only for a
    // genuinely unparseable reply.
    if (!$p1['parsed_ok'] || $p1['index'] === null) {
        error_log('gemini_describe.php classify: unparseable/empty first attempt (finishReason=' . $r1['finishReason'] . '), retrying once');
        $r1b = gemini_call(gemini_classify_payload($classifyPromptBase, $mime, $b64), $apiKey, $model);
        if (!$r1b['error']) {
            $p1b = gemini_parse_classification($r1b['raw'], count($cleanOptions));
            if ($p1b['parsed_ok'] && $p1b['index'] !== null) {
                $p1 = $p1b;
            }
        }
    }

    // Still nothing usable after the retry — this is a genuine technical
    // failure (not a legitimate "no match", which the prompt no longer
    // allows), so surface it as an error. The client's existing retry/
    // backoff handles it the same as any other transient API hiccup.
    if (!$p1['parsed_ok'] || $p1['index'] === null) {
        error_log('gemini_describe.php classify: still unparseable/empty after retry');
        http_response_code(502);
        echo json_encode(['success' => false, 'message' => 'AI could not produce a classification for this photo. Please try again.']);
        exit;
    }

    error_log(json_encode([
        'evt'         => 'ai_classify',
        'survey_id'   => $surveyId,
        'index'       => $p1['index'],
        'confidence'  => $p1['confidence'],
        'elapsed_ms'  => (int) round((microtime(true) - $t0) * 1000),
    ]));

    echo json_encode([
        'success'    => true,
        'index'      => $p1['index'],
        'confidence' => $p1['confidence'],
        'evidence'   => $p1['evidence'],
    ]);
    exit;
}

$prompt = $contextPrefix . 'You are a professional Marine Cargo, Hull, and Bunker Surveyor with over 20 years of experience.
Analyze ONLY what is clearly visible in the uploaded image.
Your task is to identify the MAIN visible object or structure and generate a professional marine survey photo description.

Rules:
1. Identify the primary object only (Bulkhead, Bulk Frame, Hopper, Tank Top, Coaming, Hatch Cover, Side Shell, Cargo Hold, Pipe, Ladder, Manhole, Bilge Well, etc.).
2. Identify its visible position whenever possible (Port Side, Starboard Side, Forward, Aft, Upper, Lower, Center, Fore End, Aft End, etc.).
3. Mention only visible conditions.
4. If damage is visible, mention the damage type such as: Corrosion, Surface Rust, Heavy Rust Scale, Coating Breakdown, Coating Peeling, Crack, Dent, Deformation, Pitting, Indentation, Buckling, Cargo Residue, Water Stain, Temporary Repair.
5. Never guess information that is not clearly visible.
6. If no damage is visible, write \"No visible damage observed.\"
7. Keep the description professional and concise.
8. Return ONLY one complete sentence.
9. Do not include introductions, explanations, reasoning steps, confidence scores, markdown, bullets, asterisks, or quotation marks.
10. Never start with phrases like \"Let\'s\", \"I can see\", \"Looking at\", \"The image shows\", or any thinking text.

Output Format (strict):
View of [Object Name] at [Position], showing [Visible Condition].

Examples:
View of Port Side Bulkhead, showing moderate surface corrosion.
View of Starboard Lower Hopper, showing coating breakdown and localized rust.
View of Tank Top at Centre, showing clean surface with no visible damage observed.
View of Forward Hatch Coaming, showing minor paint peeling.
View of Bulk Frame, showing heavy rust scale and coating deterioration.
View of Bilge Well, showing clean condition with no visible damage observed.

If the object or position cannot be confidently determined, use:
View of marine structure, showing [Visible Condition].

Return only that one sentence and nothing else.';

// Latest stable multimodal Flash model (vision). Change via env if needed.
$model = getenv('YSMS_GEMINI_MODEL') ?: (defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-3.6-flash');
$url = 'https://generativelanguage.googleapis.com/v1beta/models/'
    . rawurlencode($model)
    . ':generateContent?key=' . rawurlencode($apiKey);

$payload = [
    'contents' => [
        [
            'parts' => [
                ['text' => $prompt],
                [
                    'inline_data' => [
                        'mime_type' => $mime,
                        'data'      => $b64,
                    ],
                ],
            ],
        ],
    ],
    'generationConfig' => [
        'temperature'     => 0.2,
        'maxOutputTokens' => 180,
        // See the classification-mode payload above for why this matters —
        // same thinking-tokens-eat-the-budget risk applies here too.
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
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => 'Could not reach Gemini API' . ($curlErr ? (': ' . $curlErr) : '.'),
    ]);
    exit;
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
    http_response_code($httpCode >= 400 ? $httpCode : 502);
    $out = ['success' => false, 'message' => $msg];
    if ($retryAfter !== null) {
        $out['retry_after'] = $retryAfter;
    }
    echo json_encode($out);
    exit;
}

$description = '';
if (!empty($json['candidates'][0]['content']['parts'])) {
    foreach ($json['candidates'][0]['content']['parts'] as $part) {
        if (isset($part['text'])) {
            $description .= $part['text'];
        }
    }
}
$description = trim(preg_replace('/\s+/u', ' ', $description));

// Strip accidental quotes / markdown wrappers and model reasoning leakage
$description = trim($description, " \t\n\r\0\x0B\"'`");
// Drop leading junk like ". * Let's identify..." or markdown bullets
$description = preg_replace('/^[\\.\\*\\-\\s]+/', '', $description);
$description = preg_replace('/^(?:Let\'s|I can see|Looking at|The image shows|Here is|Based on)[^.]*?[.!?]?\s*/i', '', $description);
$description = trim($description);
// Keep only the first sentence that starts with "View of" if mixed garbage present
if (preg_match('/View of\s+.+?(?:\.|$)/i', $description, $vm)) {
    $description = trim($vm[0]);
}
// Ensure it ends with a period
if ($description !== '' && !preg_match('/[.!?]$/', $description)) {
    $description .= '.';
}

if ($description === '') {
    $blockReason = $json['candidates'][0]['finishReason'] ?? ($json['promptFeedback']['blockReason'] ?? '');
    echo json_encode([
        'success' => false,
        'message' => 'No description returned' . ($blockReason ? (' (' . $blockReason . ')') : '.') ,
    ]);
    exit;
}

echo json_encode([
    'success'     => true,
    'description' => $description,
]);

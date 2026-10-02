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
 * item from that fixed list and only its 1-based list position is returned —
 * faster (a few output tokens instead of a full sentence) and it reuses
 * whatever fixed phrasing the caller supplied instead of inventing new
 * wording. The model always commits to one pick (majority-of-frame object +
 * its side), never an "unable to determine" — confidence/evidence come back
 * for logging and quality tracking only, not to withhold an answer. A reply
 * that genuinely can't be parsed even after one server-side retry is
 * surfaced as an error (success: false) so the client's normal retry/
 * backoff handles it.
 *   { "success": true, "index": 7, "confidence": 92, "evidence": "..." }
 * NOTE: photo_report_generator.php currently always calls this in free-text/
 * structured mode (below). Classification mode above is kept as-is since
 * it's a documented, independently usable mode of this shared endpoint —
 * not dead code, just not the current caller's choice.
 *
 * Without "options" (or an empty list), falls back to free-text/structured
 * mode — ONE call returns the primary physical object (explicitly kept
 * separate from the general location/vessel-area, so a photo taken inside
 * a Cargo Hold that mainly shows a Bulkhead returns primary_object:
 * "Bulkhead", never "Cargo Hold"), a confidently-detected side if any, a
 * basic photo-quality read, and the final description sentence, all from
 * one Gemini call (no second request for quality or structure):
 *   { "success": true,
 *     "primary_object": "Bulkhead", "direction": "Forward" | "",
 *     "location_context": "Cargo Hold", "photo_quality": "Good" | "Fair" | "Poor",
 *     "confidence": 90, "description": "View of Forward Bulkhead, showing ..." }
 *   { "success": false, "message": "..." }
 * The surveyor can still correct the side with the one-click Forward/Aft/
 * Port Side/Starboard Side quick-select, and correct the object via the
 * report's structure dropdown (itself a separate, fully manual pick that
 * never calls this endpoint) — see photo_report_generator.php.
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
- Identify the primary subject of the photograph based on the largest visually significant and contextually relevant object. Do not select a secondary object merely because it is clearly visible. If multiple objects are present, determine which object represents the main focus of the photograph.
- Do not hallucinate objects that are not clearly visible.
- If confidence between two objects is close, select the object that occupies the larger visually relevant area.
- Always map the result to one of the existing numbered list options — never invent a new one.
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

// Free-text / structured mode — ONE call returns primary_object (the actual
// physical component), direction, location_context (kept strictly separate
// from primary_object so the model can never substitute one for the other —
// see CRITICAL PRINCIPLE below), photo_quality, confidence, and the final
// description sentence, all in a single JSON object. No second API call.
$describeDomainGuidance = 'CRITICAL PRINCIPLE — object vs. location:
Identify the actual physical object that is the primary subject of the photograph. Do not confuse the surrounding location or vessel area with the object being photographed. A photograph taken inside a Cargo Hold does not mean the subject is "Cargo Hold". If the photograph primarily shows a Bulkhead, the primary object is Bulkhead. If it primarily shows a Bulk Frame, the primary object is Bulk Frame. If it primarily shows a Ladder, the primary object is Ladder. If it primarily shows Coaming, the primary object is Coaming. If it primarily shows a Drain Channel, the primary object is Drain Channel. Determine the primary subject using visual dominance, framing, area occupied, camera focus, and photographic intent. Only use the general location/context (Cargo Hold, Tank, Engine Room, Deck, Accommodation, etc.) as supporting metadata in "location_context" — NEVER as "primary_object".
';

$prompt = $contextPrefix . 'You are a professional Marine Cargo, Hull, and Bunker Surveyor with over 20 years of experience.
Analyze ONLY what is clearly visible in the uploaded image.

' . $describeDomainGuidance . '
Your task is to return ONE JSON object describing the photograph:
{"primary_object": "...", "direction": "...", "location_context": "...", "photo_quality": "...", "confidence": 0, "description": "..."}

Field rules:
1. primary_object: the specific physical component actually shown (e.g. Bulkhead, Bulk Frame, Hopper, Tank Top, Coaming, Hatch Cover, Side Shell, Pipe, Ladder, Manhole, Bilge Well, Drain Channel, Handrail, Door, Hatch, etc.) — NEVER a general location such as Cargo Hold, Tank, Engine Room, Deck, or Accommodation. Do not hallucinate an object that is not clearly visible.
2. direction: "Forward", "Aft", "Port Side", or "Starboard Side" ONLY if confidently visible from surrounding structure/orientation evidence; otherwise an empty string "" — never guess.
3. location_context: the general vessel area/location shown (Cargo Hold, Tank, Engine Room, Deck, Accommodation, etc.) — supporting metadata only, never used as primary_object.
4. photo_quality: "Good", "Fair", or "Poor" based on sharpness, focus, exposure, blur, obstruction, and whether the primary object is clearly visible. Use "Poor" only for genuinely unusable photos (severe blur, extreme darkness/brightness, subject not identifiable) — most normal survey photos are "Good".
5. confidence: integer 0-100 reflecting confidence in the primary_object identification.
6. description: one complete, professional, concise sentence. Mention only visible conditions. If damage is visible, mention the damage type such as: Corrosion, Surface Rust, Heavy Rust Scale, Coating Breakdown, Coating Peeling, Crack, Dent, Deformation, Pitting, Indentation, Buckling, Cargo Residue, Water Stain, Temporary Repair. If no damage is visible, write "No visible damage observed." Never guess information that is not clearly visible.

Description format (strict):
If direction is non-empty: "View of [Direction] [Primary Object], [Visible Condition]."
If direction is empty: "View of [Primary Object], [Visible Condition]."

Examples (each a complete JSON object):
{"primary_object":"Bulkhead","direction":"Forward","location_context":"Cargo Hold","photo_quality":"Good","confidence":90,"description":"View of Forward Bulkhead, showing moderate surface corrosion."}
{"primary_object":"Bulkhead","direction":"","location_context":"Cargo Hold","photo_quality":"Good","confidence":75,"description":"View of Bulkhead, showing minor surface rust."}
{"primary_object":"Bulk Frame","direction":"Port Side","location_context":"Cargo Hold","photo_quality":"Good","confidence":85,"description":"View of Port Side Bulk Frame, showing heavy rust scale and coating deterioration."}
{"primary_object":"Ladder","direction":"","location_context":"Cargo Hold","photo_quality":"Fair","confidence":70,"description":"View of Ladder, showing clean condition with no visible damage observed."}
{"primary_object":"Coaming","direction":"Aft","location_context":"Cargo Hold","photo_quality":"Good","confidence":88,"description":"View of Aft Coaming, showing minor paint peeling."}
{"primary_object":"Drain Channel","direction":"","location_context":"Cargo Hold","photo_quality":"Good","confidence":80,"description":"View of Drain Channel, showing cargo residue accumulation."}

Do not include introductions, explanations, reasoning steps, markdown, bullets, or any text outside the single JSON object. Respond with ONLY that one JSON object and nothing else.';

/** Pulls the structured describe-mode fields out of a free-text response's
 *  raw text. Tries a clean json_decode first (stripping markdown fences the
 *  model sometimes wraps JSON in despite instructions not to); falls back
 *  to field-by-field regex extraction — same robustness pattern as
 *  gemini_parse_classification() above — for a reply that isn't clean JSON.
 *  'parsed_ok' is false only when nothing usable came back at all. */
function gemini_parse_describe($raw)
{
    $primaryObject = '';
    $direction = '';
    $locationContext = '';
    $photoQuality = '';
    $confidence = null;
    $description = '';

    $cleaned = trim($raw);
    $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
    $cleaned = preg_replace('/```\s*$/', '', $cleaned);
    $decoded = json_decode(trim($cleaned), true);

    if (is_array($decoded)) {
        $primaryObject   = isset($decoded['primary_object']) ? trim((string) $decoded['primary_object']) : '';
        $direction       = isset($decoded['direction']) ? trim((string) $decoded['direction']) : '';
        $locationContext = isset($decoded['location_context']) ? trim((string) $decoded['location_context']) : '';
        $photoQuality    = isset($decoded['photo_quality']) ? trim((string) $decoded['photo_quality']) : '';
        $confidence      = (isset($decoded['confidence']) && is_numeric($decoded['confidence'])) ? max(0, min(100, (int) $decoded['confidence'])) : null;
        $description     = isset($decoded['description']) ? trim((string) $decoded['description']) : '';
    } else {
        if (preg_match('/"primary_object"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/i', $raw, $m)) $primaryObject = stripcslashes($m[1]);
        if (preg_match('/"direction"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/i', $raw, $m)) $direction = stripcslashes($m[1]);
        if (preg_match('/"location_context"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/i', $raw, $m)) $locationContext = stripcslashes($m[1]);
        if (preg_match('/"photo_quality"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/i', $raw, $m)) $photoQuality = stripcslashes($m[1]);
        if (preg_match('/"confidence"\s*:\s*(\d+)/i', $raw, $m)) $confidence = max(0, min(100, (int) $m[1]));
        if (preg_match('/"description"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/i', $raw, $m)) $description = stripcslashes($m[1]);
    }

    if (!in_array($photoQuality, ['Good', 'Fair', 'Poor'], true)) {
        $photoQuality = '';
    }
    if ($direction !== '' && !in_array($direction, ['Forward', 'Aft', 'Port Side', 'Starboard Side'], true)) {
        $norm = ucwords(strtolower(trim($direction)));
        $direction = in_array($norm, ['Forward', 'Aft', 'Port Side', 'Starboard Side'], true) ? $norm : '';
    }

    // Same cleanup safety-net the old plain-sentence response always applied
    // — still worth running even on a JSON-decoded description, in case the
    // model embedded stray quoting/markdown inside the field itself.
    if ($description !== '') {
        $description = trim(preg_replace('/\s+/u', ' ', $description));
        $description = trim($description, " \t\n\r\0\x0B\"'`");
        $description = preg_replace('/^[\\.\\*\\-\\s]+/', '', $description);
        $description = preg_replace('/^(?:Let\'s|I can see|Looking at|The image shows|Here is|Based on)[^.]*?[.!?]?\s*/i', '', $description);
        $description = trim($description);
        if ($description !== '' && !preg_match('/[.!?]$/', $description)) {
            $description .= '.';
        }
    }

    // If the description field came back empty/unusable but we do have a
    // primary_object, build the same strict-format sentence ourselves
    // rather than failing outright — avoids a wasted retry for partial data.
    if ($description === '' && $primaryObject !== '') {
        $description = 'View of ' . ($direction !== '' ? ($direction . ' ') : '') . $primaryObject . '.';
    }

    $parsedOk = $primaryObject !== '' || $description !== '';
    return [
        'primary_object'   => $primaryObject,
        'direction'        => $direction,
        'location_context' => $locationContext,
        'photo_quality'    => $photoQuality,
        'confidence'       => $confidence,
        'description'      => $description,
        'parsed_ok'        => $parsedOk,
    ];
}

function gemini_describe_payload($prompt, $mime, $b64)
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
            'temperature'     => 0.2,
            'maxOutputTokens' => 320,
            // See the classification-mode payload above for why this matters —
            // same thinking-tokens-eat-the-budget risk applies here too.
            'thinkingConfig'  => ['thinkingBudget' => 0],
        ],
    ];
}

$model = getenv('YSMS_GEMINI_MODEL') ?: (defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-3.6-flash');
$td0 = microtime(true);

$d1 = gemini_call(gemini_describe_payload($prompt, $mime, $b64), $apiKey, $model);
if ($d1['error']) {
    http_response_code($d1['http']);
    $out = ['success' => false, 'message' => $d1['message']];
    if (!empty($d1['retry_after'])) {
        $out['retry_after'] = $d1['retry_after'];
    }
    echo json_encode($out);
    exit;
}
$dp1 = gemini_parse_describe($d1['raw']);

// "IF JSON is invalid -> retry the API request once" — same convention as
// the classification branch — only for a genuinely unparseable reply.
if (!$dp1['parsed_ok']) {
    error_log('gemini_describe.php describe: unparseable/empty first attempt (finishReason=' . $d1['finishReason'] . '), retrying once');
    $d1b = gemini_call(gemini_describe_payload($prompt, $mime, $b64), $apiKey, $model);
    if (!$d1b['error']) {
        $dp1b = gemini_parse_describe($d1b['raw']);
        if ($dp1b['parsed_ok']) {
            $dp1 = $dp1b;
        }
    }
}

if (!$dp1['parsed_ok']) {
    error_log('gemini_describe.php describe: still unparseable/empty after retry');
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'No description returned. Please try again.']);
    exit;
}

error_log(json_encode([
    'evt'               => 'ai_describe',
    'survey_id'         => $surveyId,
    'primary_object'    => $dp1['primary_object'],
    'direction'         => $dp1['direction'],
    'location_context'  => $dp1['location_context'],
    'photo_quality'     => $dp1['photo_quality'],
    'confidence'        => $dp1['confidence'],
    'elapsed_ms'        => (int) round((microtime(true) - $td0) * 1000),
]));

echo json_encode([
    'success'          => true,
    'primary_object'   => $dp1['primary_object'],
    'direction'        => $dp1['direction'],
    'location_context' => $dp1['location_context'],
    'photo_quality'    => $dp1['photo_quality'],
    'confidence'       => $dp1['confidence'],
    'description'      => $dp1['description'],
]);

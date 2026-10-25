<?php
/**
 * JSON API for survey_form.php (Image → Bunker Survey Form). POST only, logged-in user +
 * CSRF (checkAuth). Admin / Super Admin, or the Surveyor assigned to the survey; changes
 * only while the survey is Pending Vessel / Pending Report. Never changes a survey's status.
 *
 *  action=create        survey_id, format            → {draft_id}
 *  action=get           draft_id                     → draft + form layout
 *  action=save          draft_id, version, data      → {version, errors} | 409 {conflict, draft}
 *  action=upload_image  draft_id, image (file)       → {image}
 *  action=extract       draft_id, version            → AI reading merged into empty fields
 *  action=calculate     draft_id, data               → values from the template's formulas
 *  action=generate      draft_id, version            → filled Excel format (saved, never overwritten)
 */
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/config/config.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST only.']);
    exit;
}
checkAuth();
require_once dirname(__DIR__) . '/includes/survey_form.php';

function sfOut(array $body, int $code = 200): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}
function sfFail(string $msg, int $code = 400, array $extra = []): void {
    sfOut(['success' => false, 'message' => $msg] + $extra, $code);
}

$db = getDB();
$userId = (int)($_SESSION['user_id'] ?? 0);
$action = (string)($_POST['action'] ?? '');

/** Draft + survey the user may see (and, with $edit, change). */
function sfLoadForUser(PDO $db, bool $edit): array {
    $draft = sfDraft($db, (int)($_POST['draft_id'] ?? 0));
    if (!$draft) sfFail('This form no longer exists.', 404);
    $survey = sfSurvey($db, (int)$draft['survey_id']);
    if (!$survey || !sfCanAccess($db, $survey)) sfFail('You do not have access to this vessel.', 403);
    if ($edit && !sfCanEdit($survey)) sfFail('This vessel is ' . $survey['status'] . ' — the form can no longer be changed.', 409);
    if (!sfTemplateInfo($draft['format_key'])) sfFail('The Excel format for this form is not available on the server.', 500);
    return [$draft, $survey];
}

function sfPublicDraft(array $draft, array $survey): array {
    $info = sfTemplateInfo($draft['format_key']);
    $fields = [];
    foreach (array_keys($info['fields']) as $f) {
        [$label, $type, , , $group] = SF_FIELDS[$f];
        $fields[] = ['key' => $f, 'label' => $label, 'type' => $type, 'group' => $group, 'required' => in_array($f, SF_REQUIRED, true)];
    }
    $sections = [];
    foreach (SF_SECTIONS as $s => $def) {
        $sec = $info['sections'][$s];
        $sections[] = ['key' => $s, 'label' => $info['fuel_names'][$s] ?? $def['label'], 'template_rows' => $sec['last'] - $sec['first'] + 1];
    }
    $tankCols = [];
    foreach (SF_TANK_COLS as $c => [$label, , $type]) $tankCols[] = ['key' => $c, 'label' => $label, 'type' => $type];
    return [
        'id' => (int)$draft['id'], 'survey_id' => (int)$draft['survey_id'], 'format' => $draft['format_key'],
        'format_label' => SF_FORMATS[$draft['format_key']]['label'], 'version' => (int)$draft['version'],
        'data' => $draft['data'], 'updated_at' => $draft['updated_at'], 'updated_by' => $draft['updated_by_name'] ?? '',
        'images' => array_map(fn($i) => ['id' => $i['id'], 'name' => $i['name'], 'size' => $i['size'], 'mime' => $i['mime'], 'uploaded_at' => $i['uploaded_at'],
            'url' => 'survey_form_file.php?draft=' . (int)$draft['id'] . '&image=' . rawurlencode($i['id'])], $draft['images']),
        'generated' => array_map(fn($g, $n) => ['name' => $g['name'], 'at' => $g['at'], 'by' => $g['by_name'] ?? '',
            'url' => 'survey_form_file.php?draft=' . (int)$draft['id'] . '&file=' . $n], $draft['generated'], array_keys($draft['generated'])),
        'can_edit' => sfCanEdit($survey),
        'layout' => ['fields' => $fields, 'groups' => SF_GROUPS, 'sections' => $sections, 'tank_cols' => $tankCols, 'constants' => $info['constants']],
        'survey' => ['vessel_name' => $survey['vessel_name'], 'status' => $survey['status'], 'report_number' => $survey['report_number'] ?? ''],
    ];
}

/** Form data posted as JSON. */
function sfPostedData(): array {
    $d = json_decode((string)($_POST['data'] ?? ''), true);
    if (!is_array($d)) sfFail('Form data missing.');
    return $d;
}

try {
    sfEnsureTable($db);
    switch ($action) {
        case 'create': {
            $survey = sfSurvey($db, (int)($_POST['survey_id'] ?? 0));
            if (!$survey || !sfCanAccess($db, $survey)) sfFail('You do not have access to this vessel.', 403);
            if (!sfCanEdit($survey)) sfFail('Forms can only be started while the vessel is Pending Vessel or Pending Report.', 409);
            $format = (string)($_POST['format'] ?? '');
            if (!isset(sfAvailableFormats()[$format]) || !sfTemplateInfo($format)) sfFail('Choose one of the available Excel formats.');
            $id = sfCreateDraft($db, (int)$survey['id'], $format, sfPrefill($survey), $userId);
            sfOut(['success' => true, 'draft_id' => $id]);
        }
        case 'get': {
            [$draft, $survey] = sfLoadForUser($db, false);
            sfOut(['success' => true, 'draft' => sfPublicDraft($draft, $survey)]);
        }
        case 'save': {
            [$draft, $survey] = sfLoadForUser($db, true);
            [$data, $errors] = sfCleanData($draft['format_key'], sfPostedData());
            $new = sfSaveDraftData($db, (int)$draft['id'], (int)($_POST['version'] ?? 0), $data, $userId);
            if (!$new) {
                $cur = sfDraft($db, (int)$draft['id']);
                sfFail('This form was changed on another screen' . ($cur['updated_by_name'] ? ' by ' . $cur['updated_by_name'] : '') . ' at '
                    . date('d M H:i', strtotime($cur['updated_at'])) . '. Nothing was overwritten.', 409, ['conflict' => true, 'draft' => sfPublicDraft($cur, $survey)]);
            }
            sfOut(['success' => true, 'version' => $new, 'data' => $data, 'errors' => (object)$errors]);
        }
        case 'upload_image': {
            [$draft, $survey] = sfLoadForUser($db, true);
            $f = $_FILES['image'] ?? null;
            if (!$f || (int)$f['error'] === UPLOAD_ERR_NO_FILE) sfFail('Choose a photo or scan.');
            if ((int)$f['error'] === UPLOAD_ERR_INI_SIZE || (int)$f['error'] === UPLOAD_ERR_FORM_SIZE || (int)$f['size'] > SF_IMAGE_MAX_BYTES) sfFail('The file is larger than 15 MB.');
            if ((int)$f['error'] !== UPLOAD_ERR_OK) sfFail('The upload did not complete. Please try again.');
            $orig = basename((string)$f['name']);
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (!isset(SF_IMAGE_EXT[$ext])) sfFail('Use a photo (JPG, PNG, WEBP, HEIC) or a PDF scan.');
            $mime = function_exists('finfo_open') ? (string)finfo_file(finfo_open(FILEINFO_MIME_TYPE), $f['tmp_name']) : SF_IMAGE_EXT[$ext];
            $okMime = $ext === 'pdf' ? $mime === 'application/pdf' : (strpos($mime, 'image/') === 0 || ($mime === 'application/octet-stream' && in_array($ext, ['heic', 'heif'], true)));
            if (!$okMime) sfFail('That file is not a photo or PDF.');
            $dir = sfUploadDir() . '/' . (int)$draft['id'];
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) sfFail('Could not store the photo.', 500);
            $imgId = bin2hex(random_bytes(8));
            $stored = $dir . '/' . $imgId . '.' . $ext;
            if (!move_uploaded_file($f['tmp_name'], $stored)) sfFail('Could not store the photo.', 500);
            $entry = ['id' => $imgId, 'name' => mb_substr($orig, 0, 150), 'file' => basename($stored), 'size' => (int)$f['size'],
                'mime' => $ext === 'pdf' ? 'application/pdf' : (strpos($mime, 'image/') === 0 ? $mime : SF_IMAGE_EXT[$ext]),
                'uploaded_at' => date('Y-m-d H:i:s'), 'uploaded_by' => $userId];
            sfUpdateList($db, (int)$draft['id'], 'images', fn($l) => array_merge($l, [$entry]), $userId);
            $pub = sfPublicDraft(sfDraft($db, (int)$draft['id']), $survey);
            sfOut(['success' => true, 'images' => $pub['images']]);
        }
        case 'extract': {
            [$draft, $survey] = sfLoadForUser($db, true);
            require_once dirname(__DIR__) . '/includes/survey_form_ai.php';
            if (!$draft['images']) sfFail('Add a photo first.');
            if ((int)($_POST['version'] ?? 0) !== (int)$draft['version']) sfFail('This form was changed on another screen. Reload it first — nothing was overwritten.', 409, ['conflict' => true]);
            @set_time_limit(180);
            session_write_close();
            $dir = sfUploadDir() . '/' . (int)$draft['id'];
            $imgs = array_map(fn($i) => ['path' => $dir . '/' . $i['file'], 'mime' => $i['mime']], array_reverse($draft['images'])); // newest first
            $res = sfAiExtract($draft['format_key'], $imgs);
            if (!$res['ok']) sfFail($res['message'], 502);
            $ai = sfAiSanitize($draft['format_key'], $res['raw']);
            [$merged, $stats] = sfAiMerge($draft['data'], $ai);
            [$clean, $errors] = sfCleanData($draft['format_key'], $merged);
            $new = sfSaveDraftData($db, (int)$draft['id'], (int)$draft['version'], $clean, $userId);
            if (!$new) sfFail('This form was changed on another screen while the AI was reading. Reload it — nothing was overwritten.', 409, ['conflict' => true]);
            sfOut(['success' => true, 'version' => $new, 'data' => $clean, 'errors' => (object)$errors, 'stats' => $stats,
                'printed_totals' => $ai['printed_totals'], 'notes' => $ai['notes'], 'images_used' => $res['images_used'], 'images_total' => count($imgs)]);
        }
        case 'calculate': {
            [$draft, $survey] = sfLoadForUser($db, false);
            [$data, $errors] = sfCleanData($draft['format_key'], sfPostedData());
            $results = null;
            if (!$errors) {
                $book = sfBuildWorkbook($draft['format_key'], $data);
                $results = sfResults($book, $draft['format_key'], $data);
                $book->disconnectWorksheets();
            }
            sfOut(['success' => true, 'errors' => (object)$errors, 'results' => $results]);
        }
        case 'generate': {
            [$draft, $survey] = sfLoadForUser($db, false);
            if ((int)($_POST['version'] ?? 0) !== (int)$draft['version']) sfFail('Save the form first — the saved copy is different from what you see.', 409, ['conflict' => true]);
            [$data, $errors] = sfCleanData($draft['format_key'], $draft['data']);
            if ($errors) sfFail('Fix the highlighted values before making the Excel file.', 422, ['errors' => (object)$errors]);
            $book = sfBuildWorkbook($draft['format_key'], $data);
            $results = sfResults($book, $draft['format_key'], $data);
            $dir = sfUploadDir() . '/' . (int)$draft['id'] . '/generated';
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) sfFail('Could not save the Excel file.', 500);
            $vessel = preg_replace('/[^A-Z0-9 ._-]+/', '', strtoupper((string)($data['fields']['vessel_name']['v'] ?? $survey['vessel_name']))) ?: 'VESSEL';
            $name = trim($vessel) . ' - ' . strtoupper(SF_FORMATS[$draft['format_key']]['label']) . ' CALCULATION ' . date('d-m-Y H-i') . '.xlsx';
            $file = date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.xlsx'; // a new file every time — never overwrites
            sfSaveWorkbook($book, $dir . '/' . $file);
            $book->disconnectWorksheets();
            $byName = (string)($_SESSION['full_name'] ?? '');
            $list = sfUpdateList($db, (int)$draft['id'], 'generated', fn($l) => array_merge($l, [['file' => $file, 'name' => $name, 'at' => date('Y-m-d H:i:s'), 'by' => $userId, 'by_name' => $byName]]), $userId);
            sfOut(['success' => true, 'results' => $results, 'url' => 'survey_form_file.php?draft=' . (int)$draft['id'] . '&file=' . (count($list) - 1), 'name' => $name]);
        }
    }
    sfFail('Unknown action.');
} catch (Throwable $e) {
    error_log('survey_form_api ' . $action . ': ' . $e->getMessage());
    sfFail('Something went wrong on the server. Your last saved copy is safe.', 500);
}

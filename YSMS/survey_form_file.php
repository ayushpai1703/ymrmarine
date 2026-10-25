<?php
/**
 * Serves a survey-form photo (original, unchanged) or a generated Excel file
 * (survey_form.php), to Admin / Super Admin or the Surveyor assigned to the vessel.
 *   ?draft=<id>&image=<image id>   ?draft=<id>&file=<n>
 */
require_once 'config/config.php';
checkAuth();
require_once 'includes/survey_form.php';

$db = getDB();
$draft = sfDraft($db, (int)($_GET['draft'] ?? 0));
$survey = $draft ? sfSurvey($db, (int)$draft['survey_id']) : null;
if (!$draft || !$survey || !sfCanAccess($db, $survey)) {
    http_response_code(403);
    exit('Not allowed.');
}
$dir = sfUploadDir() . '/' . (int)$draft['id'];
$path = '';
$name = '';
$type = '';
if (isset($_GET['image'])) {
    foreach ($draft['images'] as $img) {
        if (hash_equals((string)$img['id'], (string)$_GET['image'])) {
            $path = $dir . '/' . basename((string)$img['file']);
            $name = (string)$img['name'];
            $type = (string)$img['mime'];
        }
    }
    $disposition = 'inline';
} elseif (isset($_GET['file'])) {
    $g = $draft['generated'][(int)$_GET['file']] ?? null;
    if ($g) {
        $path = $dir . '/generated/' . basename((string)$g['file']);
        $name = (string)$g['name'];
        $type = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }
    $disposition = 'attachment';
}
if ($path === '' || !is_file($path)) {
    http_response_code(404);
    exit('File not found.');
}
session_write_close();
header('Content-Type: ' . $type);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-store');
header("Content-Disposition: $disposition; filename=\"" . str_replace(['"', "\r", "\n"], '', $name) . "\"; filename*=UTF-8''" . rawurlencode($name));
readfile($path);

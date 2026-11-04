<?php
/**
 * report_detail.php → "Send Email to Master for Redelivery": finds the Ship's Particulars
 * among the survey's attachments and reads the Master's email from it (includes/master_email.php).
 *
 *   GET survey_id → { ok, found, sources[], master, emails[{email, master, source}], message }
 *
 * Same people who see the Redelivery button: Admin / Super Admin, or a surveyor assigned to
 * the survey (not Clients). Read-only; nothing is stored, file contents are never logged.
 */
require_once __DIR__ . '/../config/config.php';
@include_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/master_email.php';
checkAuth();

function meJson(int $code, array $d): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($d);
    exit;
}

$db = getDB();
$role = (string)($_SESSION['role'] ?? '');
$uid = (int)($_SESSION['user_id'] ?? 0);
$sid = (int)($_GET['survey_id'] ?? 0);
$st = $db->prepare('SELECT id FROM surveys WHERE id = ?');
$st->execute([$sid]);
if (!$st->fetchColumn()) meJson(404, ['ok' => false, 'message' => 'Survey not found.']);
$allowed = in_array($role, ['Admin', 'Super Admin'], true) || ($role !== 'Client' && isSurveyorAssignedToSurvey($db, $sid, $uid));
if (!$allowed) meJson(403, ['ok' => false, 'message' => 'Access denied.']);

@set_time_limit(120);
@ini_set('memory_limit', '512M');
$base = realpath(__DIR__ . '/../uploads');
$q = $db->prepare('SELECT file_name, file_path FROM uploads WHERE survey_id = ? ORDER BY id');
$q->execute([$sid]);
$files = [];
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $u) {
    $abs = realpath(__DIR__ . '/../' . ltrim((string)$u['file_path'], '/'));
    if (!$base || !$abs || strpos($abs, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($abs)) continue;
    $name = preg_replace('/^[0-9]+_/', '', (string)$u['file_name']) ?: basename($abs);
    $files[] = ['name' => $name, 'path' => $abs];
}
if (!$files) meJson(200, ['ok' => true, 'found' => false, 'sources' => [], 'master' => null, 'emails' => [], 'message' => 'There are no attachments on this vessel, so there is no Ship\'s Particulars to read.']);

try {
    $r = meFindMasterEmail($files);
} catch (Throwable $e) {
    error_log('master_email: ' . get_class($e)); // no file contents in the log
    meJson(500, ['ok' => false, 'message' => 'The attachments could not be read. Please try again.']);
}
if (!$r['found']) $msg = 'There is no Ship\'s Particulars in this vessel\'s attachments.';
elseif ($r['master']) $msg = 'Master\'s email found in the Ship\'s Particulars.';
elseif ($r['emails']) $msg = 'Ship\'s Particulars found, but no address is marked as the Master\'s. Emails on it are listed below.';
else $msg = $r['note'] !== '' ? $r['note'] : 'Ship\'s Particulars found, but it has no Master email address.';
meJson(200, ['ok' => true, 'found' => $r['found'], 'sources' => $r['sources'], 'master' => $r['master'], 'emails' => $r['emails'], 'message' => $msg]);

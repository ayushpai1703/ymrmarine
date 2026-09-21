<?php
/**
 * Assign Vessel page: "Paste Email — Auto-fill" action. Admin pastes the
 * raw email text (subject + body, copied from their mail client) into a
 * textarea; this endpoint runs the same regex extraction + duplicate check
 * used by the field-mapping logic, and returns the results for the form's
 * JS to fill in — including matching existing client/port/survey-type
 * dropdown options. Nothing is stored server-side; each paste is stateless.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/email_extractor.php';
checkAuth();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}
if (!in_array($_SESSION['role'] ?? '', ['Admin'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not authorized.']);
    exit;
}

$db = getDB();
$pastedText = (string)($_POST['email_text'] ?? '');
$pastedText = trim($pastedText);

if ($pastedText === '') {
    echo json_encode(['ok' => false, 'error' => 'Please paste the email text first.']);
    exit;
}
if (mb_strlen($pastedText) > 20000) {
    $pastedText = mb_substr($pastedText, 0, 20000);
}

// No separate subject field in the paste box — the first line often *is*
// the subject when pasted from a mail client, so treat the whole thing as
// one block; extractVesselFieldsFromText() already scans subject+body as one.
$extracted = extractVesselFieldsFromText('', $pastedText);
$confidenceScore = $extracted['confidence_score'];
unset($extracted['confidence_score']);

// Best-effort matches against existing dropdown data — never invented, only used if found.
$suggested = ['client_id' => null, 'port_id' => null, 'survey_type_id' => null];
try {
    if (!empty($extracted['client'])) {
        $c = $db->prepare("SELECT id FROM clients WHERE company_name LIKE ? LIMIT 1");
        $c->execute(['%' . $extracted['client'] . '%']);
        $suggested['client_id'] = $c->fetchColumn() ?: null;
    }
    if (!empty($extracted['port'])) {
        $p = $db->prepare("SELECT id FROM ports WHERE port_name LIKE ? LIMIT 1");
        $p->execute(['%' . $extracted['port'] . '%']);
        $suggested['port_id'] = $p->fetchColumn() ?: null;
    }
    if (!empty($extracted['survey_type'])) {
        $t = $db->prepare("SELECT id FROM survey_types WHERE type_name LIKE ? LIMIT 1");
        $t->execute(['%' . $extracted['survey_type'] . '%']);
        $suggested['survey_type_id'] = $t->fetchColumn() ?: null;
    }
} catch (Throwable $e) {
    error_log('vessel_email_paste_autofill suggestion lookup: ' . $e->getMessage());
}

// Duplicate check: IMO first (authoritative), then normalized vessel name.
// If the check itself fails, report it explicitly rather than silently
// saying "no duplicate" — never risk a silent double-assignment.
$duplicate = 'none';
$existing = null;
try {
    $incomingImo = trim((string)($extracted['imo_number'] ?? ''));
    $incomingNameNorm = !empty($extracted['vessel_name']) ? normalizeVesselName((string)$extracted['vessel_name']) : '';

    $found = null;
    if ($incomingImo !== '') {
        $q = $db->prepare("
            SELECT s.*, c.company_name, p.port_name, t.type_name
            FROM surveys s
            LEFT JOIN clients c ON s.client_id = c.id
            LEFT JOIN ports p ON s.port_id = p.id
            LEFT JOIN survey_types t ON s.survey_type_id = t.id
            WHERE s.imo_number = ? ORDER BY s.id DESC LIMIT 1
        ");
        $q->execute([$incomingImo]);
        $found = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($found) {
            $duplicate = 'exact';
        }
    }

    if (!$found && $incomingNameNorm !== '') {
        $q = $db->prepare("
            SELECT s.*, c.company_name, p.port_name, t.type_name
            FROM surveys s
            LEFT JOIN clients c ON s.client_id = c.id
            LEFT JOIN ports p ON s.port_id = p.id
            LEFT JOIN survey_types t ON s.survey_type_id = t.id
            WHERE s.vessel_name = ? ORDER BY s.id DESC LIMIT 1
        ");
        $q->execute([$incomingNameNorm]);
        $found = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($found) {
            $existingImo = trim((string)($found['imo_number'] ?? ''));
            $duplicate = ($existingImo === '' || $incomingImo === '' || $existingImo === $incomingImo) ? 'exact' : 'possible';
        }
    }

    if ($found) {
        $existing = [
            'vessel_name'   => $found['vessel_name'],
            'imo_number'    => $found['imo_number'] ?: null,
            'report_number' => $found['report_number'] ?: 'N/A',
            'port_name'     => $found['port_name'] ?: 'N/A',
            'survey_type'   => getCombinedSurveyTypeNames($db, $found['survey_type_ids'] ?? '', $found['type_name'] ?? 'N/A'),
            'surveyor'      => getCombinedSurveyorNames($db, $found['id'], 'Unassigned'),
            'status'        => $found['status'],
            'created'       => !empty($found['assign_date']) ? date('d M Y', strtotime($found['assign_date'])) : 'N/A',
            'survey_id'     => (int)$found['id'],
        ];
    }
} catch (Throwable $e) {
    error_log('vessel_email_paste_autofill duplicate check failed: ' . $e->getMessage());
    echo json_encode([
        'ok'        => true,
        'duplicate' => 'check_failed',
        'extracted' => $extracted,
        'suggested' => $suggested,
        'existing'  => null,
    ]);
    exit;
}

echo json_encode([
    'ok'         => true,
    'duplicate'  => $duplicate,
    'extracted'  => $extracted,
    'suggested'  => $suggested,
    'existing'   => $existing,
    'confidence' => $confidenceScore,
]);

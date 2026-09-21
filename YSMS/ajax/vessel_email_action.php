<?php
/**
 * "Use This Email" / "Ignore" actions on one Recent Vessel Email row.
 *
 * action=use: runs the duplicate check (IMO first, then normalized vessel
 * name) against `surveys` and returns the extracted fields for the Assign
 * Vessel form to auto-fill, plus best-effort client/port/survey-type id
 * matches. Per spec: IMO exact match -> hard "Vessel Already Exists"
 * warning; name-only match with a different/missing IMO -> "Possible
 * Duplicate"; no match -> caller fills the form straight away. If the
 * duplicate check itself throws, we deliberately report a duplicate-check
 * failure rather than silently returning "no duplicate" (never silently
 * risk a duplicate assignment).
 *
 * action=ignore: marks the email IGNORED so it stops showing in the panel.
 * Never touches the real mailbox either way.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/email_account.php';
checkAuth();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}
if (($_SESSION['role'] ?? '') !== 'Admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not authorized.']);
    exit;
}

$db = getDB();
$action = (string)($_POST['action'] ?? '');
$emailId = (int)($_POST['id'] ?? 0);

if ($emailId <= 0 || !in_array($action, ['use', 'ignore'], true)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
    exit;
}

$stmt = $db->prepare("SELECT * FROM emails WHERE id = ? LIMIT 1");
$stmt->execute([$emailId]);
$email = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$email) {
    echo json_encode(['ok' => false, 'error' => 'Email not found.']);
    exit;
}

if ($action === 'ignore') {
    $db->prepare("UPDATE emails SET processing_status = 'IGNORED' WHERE id = ?")->execute([$emailId]);
    logEmailAudit($db, (int)$email['account_id'], 'ignore_email', 'email_id=' . $emailId);
    echo json_encode(['ok' => true]);
    exit;
}

// action === 'use'
$dstmt = $db->prepare("SELECT * FROM email_extracted_data WHERE email_id = ? LIMIT 1");
$dstmt->execute([$emailId]);
$data = $dstmt->fetch(PDO::FETCH_ASSOC) ?: [];

$extracted = [
    'vessel_name'             => $data['vessel_name'] ?? null,
    'imo_number'               => $data['imo_number'] ?? null,
    'call_sign'                => $data['call_sign'] ?? null,
    'port'                      => $data['port'] ?? null,
    'agent'                     => $data['agent'] ?? null,
    'eta'                       => $data['eta'] ?? null,
    'etb'                       => $data['etb'] ?? null,
    'survey_type'               => $data['survey_type'] ?? null,
    'cargo'                     => $data['cargo'] ?? null,
    'client'                    => $data['client'] ?? null,
    'master_name'               => $data['master_name'] ?? null,
    'loa'                       => $data['loa'] ?? null,
    'draft'                     => $data['draft'] ?? null,
    'dwt'                       => $data['dwt'] ?? null,
    'gt'                        => $data['gt'] ?? null,
    'survey_date'               => $data['survey_date'] ?? null,
    'additional_instructions'   => $data['additional_instructions'] ?? null,
];

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
    error_log('vessel_email_action suggestion lookup: ' . $e->getMessage());
}

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
            $duplicate = 'exact'; // IMO is authoritative — hard "already exists" warning
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
            // Same name + same (or either side missing) IMO -> treat as the same vessel (exact);
            // same name + genuinely conflicting IMO -> only "possible" (let the user decide).
            $duplicate = ($existingImo === '' || $incomingImo === '' || $existingImo === $incomingImo) ? 'exact' : 'possible';
        }
    }

    if ($found) {
        $existing = [
            'vessel_name'    => $found['vessel_name'],
            'imo_number'     => $found['imo_number'] ?: null,
            'report_number'  => $found['report_number'] ?: 'N/A',
            'port_name'      => $found['port_name'] ?: 'N/A',
            'survey_type'    => getCombinedSurveyTypeNames($db, $found['survey_type_ids'] ?? '', $found['type_name'] ?? 'N/A'),
            'surveyor'       => getCombinedSurveyorNames($db, $found['id'], 'Unassigned'),
            'status'         => $found['status'],
            'created'        => !empty($found['assign_date']) ? date('d M Y', strtotime($found['assign_date'])) : 'N/A',
            'survey_id'      => (int)$found['id'],
        ];
    }
} catch (Throwable $e) {
    error_log('vessel_email_action duplicate check failed: ' . $e->getMessage());
    // Spec: if the duplicate check itself fails, never silently create a new
    // assignment — surface a hard stop instead of "no duplicate found".
    echo json_encode([
        'ok' => true,
        'duplicate' => 'check_failed',
        'extracted' => $extracted,
        'suggested' => $suggested,
        'existing' => null,
    ]);
    exit;
}

$db->prepare("UPDATE emails SET matched_survey_id = ?, used_by_user_id = ? WHERE id = ?")
   ->execute([$existing['survey_id'] ?? null, (int)($_SESSION['user_id'] ?? 0), $emailId]);
logEmailAudit($db, (int)$email['account_id'], 'use_email', 'email_id=' . $emailId . ' duplicate=' . $duplicate);

echo json_encode([
    'ok'        => true,
    'duplicate' => $duplicate,
    'extracted' => $extracted,
    'suggested' => $suggested,
    'existing'  => $existing,
]);

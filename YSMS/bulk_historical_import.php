<?php
/**
 * Bulk Historical Import
 *
 * Admin / Super Admin only.
 * Upload Excel -> validate / preview -> import.
 *
 * Phase 1 imports data only. No dossier/archive files are attached here.
 *
 * Expected columns:
 * Vessel Name      (required)
 * Client           (required)
 * Survey Assigned  (required)
 * Survey Completed (optional)
 * Report Completed (optional)
 * Report No        (optional; NOT used as the duplicate identity)
 * Survey Type      (required; multiple values may be separated by comma or +)
 * Port             (required)
 * Agent            (optional)
 * Surveyor         (optional)
 * VLSFO            (required; numeric, 0 allowed)
 * HSFO             (required; numeric, 0 allowed)
 * LSMGO            (required; numeric, 0 allowed)
 */

require_once 'config/config.php';
require_once __DIR__ . '/../includes/upload_validation.php';
require_once 'includes/completed_import_helpers.php';

checkAuth();

if (!in_array($_SESSION['role'] ?? '', ['Admin', 'Super Admin'], true)) {
    header('Location: index.php');
    exit;
}

@set_time_limit(300);
@ini_set('memory_limit', '256M');

$db = getDB();
$page_title = 'Bulk Historical Import';

$actorId = (int)($_SESSION['user_id'] ?? 0);
$actorName = (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin');

$step = 'upload';
$error = '';
$preview = null;
$result = null;

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

function bhiLoadLibrary(): bool
{
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }
    return class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory');
}

function bhiNormHeader($value): string
{
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
    return trim($value);
}

function bhiAliases(): array
{
    return [
        'vessel_name'       => ['vessel name', 'vessel', 'ship name', 'ship'],
        'client'            => ['client', 'client name', 'company', 'company name'],
        'survey_assigned'   => ['survey assigned', 'assigned date', 'survey assigned date', 'appointment date', 'assign date'],
        'survey_completed'  => ['survey completed', 'survey completed date', 'completed date'],
        'report_completed'  => ['report completed', 'report completed date', 'report uploaded date', 'report date'],
        'report_number'     => ['report no', 'report number', 'report num'],
        'survey_type'       => ['survey type', 'survey types', 'type of survey'],
        'port'              => ['port', 'port name'],
        'agent'             => ['agent', 'agent name'],
        'surveyor'          => ['surveyor', 'surveyor name'],
        'vlsfo'             => ['vlsfo', 'vlsfo mt', 'vlsfo recovery'],
        'hsfo'              => ['hsfo', 'hsfo mt', 'hsfo recovery'],
        'lsmgo'             => ['lsmgo', 'lsmgo mt', 'lsmgo recovery'],
    ];
}

function bhiFieldFor($header): string
{
    $n = bhiNormHeader($header);
    if ($n === '') {
        return '';
    }

    foreach (bhiAliases() as $field => $aliases) {
        if (in_array($n, $aliases, true)) {
            return $field;
        }
    }
    return '';
}

function bhiRequiredFields(): array
{
    return [
        'vessel_name'      => 'Vessel Name',
        'client'           => 'Client',
        'survey_assigned'  => 'Survey Assigned',
        'survey_type'      => 'Survey Type',
        'port'             => 'Port',
        'vlsfo'            => 'VLSFO',
        'hsfo'             => 'HSFO',
        'lsmgo'            => 'LSMGO',
    ];
}

/**
 * Read every sheet. A row containing "Vessel Name" is treated as a header.
 */
function bhiReadRows($spreadsheet): array
{
    $rowsOut = [];
    $headerFound = false;
    $seenFields = [];

    foreach ($spreadsheet->getAllSheets() as $sheet) {
        $map = [];
        $sheetName = $sheet->getTitle();
        $rows = $sheet->toArray(null, true, false, false);

        foreach ($rows as $i => $cells) {
            $nonEmpty = 0;
            foreach ($cells as $c) {
                if (trim((string)$c) !== '') {
                    $nonEmpty++;
                }
            }

            if ($nonEmpty === 0) {
                continue;
            }

            $isHeader = false;
            foreach ($cells as $c) {
                if (bhiFieldFor($c) === 'vessel_name') {
                    $isHeader = true;
                    break;
                }
            }

            if ($isHeader) {
                $headerFound = true;
                $map = [];

                foreach ($cells as $idx => $c) {
                    $field = bhiFieldFor($c);
                    if ($field === '') {
                        continue;
                    }
                    $map[$field] = $idx;
                    $seenFields[$field] = true;
                }
                continue;
            }

            if (!$map) {
                continue;
            }

            $row = [
                '_sheet' => $sheetName,
                '_row'   => $i + 1,
            ];

            foreach ($map as $field => $idx) {
                $value = $cells[$idx] ?? '';
                $row[$field] = is_string($value) ? trim($value) : $value;
            }

            if (trim((string)($row['vessel_name'] ?? '')) === '') {
                continue;
            }

            $rowsOut[] = $row;
        }
    }

    $missing = [];
    if ($headerFound) {
        foreach (bhiRequiredFields() as $field => $label) {
            if (empty($seenFields[$field])) {
                $missing[] = $label;
            }
        }
    }

    return [
        'rows' => $rowsOut,
        'missing' => $missing,
        'header_found' => $headerFound,
    ];
}

function bhiParseRecovery($raw): array
{
    if (is_int($raw) || is_float($raw)) {
        $n = (float)$raw;
        return $n >= 0 ? [true, round($n, 3)] : [false, null];
    }

    $s = trim((string)$raw);
    if ($s === '') {
        return [false, null];
    }

    $s = preg_replace('/\s*(mt|m\.t\.?)$/i', '', $s);
    $s = str_replace(',', '', $s);

    if (!is_numeric($s)) {
        return [false, null];
    }

    $n = (float)$s;
    return $n >= 0 ? [true, round($n, 3)] : [false, null];
}

function bhiDate($raw): ?string
{
    if (trim((string)$raw) === '') {
        return null;
    }
    return bulkAssignParseDate($raw);
}

function bhiSplitTypes(string $raw): array
{
    $parts = preg_split('/\s*(?:\+|,|;)\s*/', trim($raw)) ?: [];
    return array_values(array_filter(array_map('trim', $parts), fn($x) => $x !== ''));
}

function bhiVesselKey(string $value): string
{
    return completedImportVesselKey($value);
}

function bhiTypeSet(array $ids): string
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    sort($ids, SORT_NUMERIC);
    return implode(',', $ids);
}

function bhiFindClient(PDO $db, string $raw): ?array
{
    $raw = trim($raw);

    if ($raw === '') {
        return null;
    }

    // Try the existing YSMS matcher first.
    $match = completedImportFindClient($db, $raw);

    if ($match && empty($match['error'])) {
        return [
            'id' => (int)$match['id'],
            'name' => (string)$match['name'],
            'needs_create' => false,
        ];
    }

    // Exact case-insensitive check as a second safeguard.
    $stmt = $db->prepare("
        SELECT id, company_name
        FROM clients
        WHERE LOWER(TRIM(company_name)) = LOWER(TRIM(?))
        LIMIT 1
    ");
    $stmt->execute([$raw]);

    if ($existing = $stmt->fetch(PDO::FETCH_ASSOC)) {
        return [
            'id' => (int)$existing['id'],
            'name' => (string)$existing['company_name'],
            'needs_create' => false,
        ];
    }

    // Do NOT create anything during preview.
    return [
        'id' => 0,
        'name' => $raw,
        'needs_create' => true,
    ];
}

function bhiGetOrCreateClient(PDO $db, string $raw, int $actorId): array
{
    $raw = trim($raw);

    // Check again immediately before inserting.
    $stmt = $db->prepare("
        SELECT id, company_name
        FROM clients
        WHERE LOWER(TRIM(company_name)) = LOWER(TRIM(?))
        LIMIT 1
    ");
    $stmt->execute([$raw]);

    if ($existing = $stmt->fetch(PDO::FETCH_ASSOC)) {
        return [
            'id' => (int)$existing['id'],
            'name' => (string)$existing['company_name'],
            'created' => false,
        ];
    }

    $stmt = $db->prepare("
        INSERT INTO clients (
            company_name,
            created_by
        )
        VALUES (?, ?)
    ");

    $stmt->execute([
        $raw,
        $actorId > 0 ? $actorId : null
    ]);

    return [
        'id' => (int)$db->lastInsertId(),
        'name' => $raw,
        'created' => true,
    ];
}

function bhiFindPort(PDO $db, string $raw): array
{
    $match = completedImportResolvePort($db, $raw, '');

    if (!empty($match['error'])) {
        return ['error' => $match['error']];
    }

    // Historical import must not silently add master-data records.
    if (!empty($match['create'])) {
        return ['error' => 'Port "' . $raw . '" is not in YSMS. Add/map the port first, then import again.'];
    }

    return $match;
}

function bhiFindTypes(PDO $db, string $raw): array
{
    $ids = [];
    $names = [];
    $errors = [];

    // Split combinations such as:
    // Offhire + Condition Survey
    // Offhire, Condition Survey
    // Offhire; Condition Survey
    $parts = bhiSplitTypes($raw);

    foreach ($parts as $name) {

        $name = trim($name);

        if ($name === '') {
            continue;
        }

        // First try the existing helper.
        $match = completedImportFindType($db, $name);

        // If helper did not match, try a normalized comparison.
        if (!$match || !empty($match['error'])) {

            $needle = strtolower($name);
            $needle = preg_replace('/[^a-z0-9]+/', '', $needle);

            $stmt = $db->query("
                SELECT id, type_name
                FROM survey_types
                ORDER BY type_name
            ");

            $match = null;

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {

                $candidate = strtolower(trim((string)$row['type_name']));
                $candidate = preg_replace('/[^a-z0-9]+/', '', $candidate);

                // Treat these as equivalent:
                // Offhire
                // Off Hire
                // Off-Hire
                if ($candidate === $needle) {
                    $match = [
                        'id' => (int)$row['id'],
                        'name' => (string)$row['type_name'],
                    ];
                    break;
                }

                // Also allow the word "survey" to be omitted in Excel.
                $candidateNoSurvey = preg_replace('/survey$/', '', $candidate);
                $needleNoSurvey = preg_replace('/survey$/', '', $needle);

                if (
                    $candidateNoSurvey !== '' &&
                    $candidateNoSurvey === $needleNoSurvey
                ) {
                    $match = [
                        'id' => (int)$row['id'],
                        'name' => (string)$row['type_name'],
                    ];
                    break;
                }
            }
        }

        if (!$match || !empty($match['error'])) {
            $errors[] = 'Survey type "' . $name . '" was not found.';
            continue;
        }

        $id = (int)$match['id'];

        if (!in_array($id, $ids, true)) {
            $ids[] = $id;
            $names[] = (string)$match['name'];
        }
    }

    if (!$ids && !$errors) {
        $errors[] = 'Survey Type is empty.';
    }

    return [
        'ids' => $ids,
        'names' => $names,
        'errors' => $errors,
    ];
}
/**
 * Blank Surveyor is allowed by the spreadsheet, but surveys.surveyor_id is
 * non-null in the current schema. Use one inactive internal placeholder.
 */
function bhiEnsurePlaceholderSurveyor(PDO $db): int
{
    $name = 'Not Recorded';

    $stmt = $db->prepare("
        SELECT u.id
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE r.name = 'Surveyor'
          AND LOWER(TRIM(u.full_name)) = LOWER(?)
        LIMIT 1
    ");
    $stmt->execute([$name]);
    $id = (int)($stmt->fetchColumn() ?: 0);

    if ($id > 0) {
        return $id;
    }

    $roleId = (int)$db->query("SELECT id FROM roles WHERE name = 'Surveyor' LIMIT 1")->fetchColumn();
    if ($roleId <= 0) {
        throw new RuntimeException('Surveyor role is missing.');
    }

    $username = 'historical.notrecorded.' . bin2hex(random_bytes(3));
    $password = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);

    $db->prepare("
        INSERT INTO users (role_id, username, password, full_name, status)
        VALUES (?, ?, ?, ?, 'Inactive')
    ")->execute([$roleId, $username, $password, $name]);

    return (int)$db->lastInsertId();
}

function bhiResolveSurveyor(PDO $db, string $raw): array
{
    $raw = trim($raw);

    if ($raw === '') {
        return [
            'id' => 0,
            'name' => 'Not Recorded',
            'placeholder' => true,
        ];
    }

    $match = completedImportFindSurveyor($db, $raw);

    if (!empty($match['error'])) {
        return ['error' => $match['error']];
    }

    if (!$match || empty($match['id'])) {
        return ['error' => 'Surveyor "' . $raw . '" is not in YSMS. Use the full existing surveyor name, or leave the cell blank.'];
    }

    return [
        'id' => (int)$match['id'],
        'name' => (string)$match['name'],
        'placeholder' => false,
    ];
}

function bhiReportNumberExists(PDO $db, string $reportNo): ?array
{
    $reportNo = trim($reportNo);
    if ($reportNo === '') {
        return null;
    }

    $stmt = $db->prepare("
        SELECT id, vessel_name, report_number
        FROM surveys
        WHERE LOWER(TRIM(report_number)) = LOWER(TRIM(?))
        LIMIT 1
    ");
    $stmt->execute([$reportNo]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Duplicate identity intentionally does NOT depend on Report No.
 *
 * Exact duplicate:
 *   same client + assigned date + normalized vessel + port + survey-type set
 */
function bhiFindDuplicate(PDO $db, array $v): ?array
{
    $stmt = $db->prepare("
        SELECT id, vessel_name, report_number, port_id, survey_type_id, survey_type_ids,
               appointment_date, status
        FROM surveys
        WHERE client_id = ?
          AND DATE(COALESCE(appointment_date, assign_date)) = DATE(?)
    ");
    $stmt->execute([(int)$v['client_id'], $v['survey_assigned']]);

    $vk = bhiVesselKey($v['vessel_name']);
    $wantTypes = bhiTypeSet($v['survey_type_ids']);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (bhiVesselKey((string)$row['vessel_name']) !== $vk) {
            continue;
        }

        $rowTypesRaw = trim((string)($row['survey_type_ids'] ?? ''));
        $rowIds = [];

        if ($rowTypesRaw !== '') {
            foreach (explode(',', $rowTypesRaw) as $id) {
                if ((int)$id > 0) {
                    $rowIds[] = (int)$id;
                }
            }
        } elseif ((int)($row['survey_type_id'] ?? 0) > 0) {
            $rowIds[] = (int)$row['survey_type_id'];
        }

        if ((int)$row['port_id'] === (int)$v['port_id'] && bhiTypeSet($rowIds) === $wantTypes) {
            return $row;
        }
    }

    return null;
}

function bhiPossibleDuplicate(PDO $db, array $v): ?array
{
    $stmt = $db->prepare("
        SELECT id, vessel_name, report_number, appointment_date, assign_date, status
        FROM surveys
        WHERE client_id = ?
          AND DATE(COALESCE(appointment_date, assign_date))
              BETWEEN DATE_SUB(DATE(?), INTERVAL 2 DAY)
                  AND DATE_ADD(DATE(?), INTERVAL 2 DAY)
        ORDER BY ABS(DATEDIFF(DATE(COALESCE(appointment_date, assign_date)), DATE(?))), id
    ");
    $stmt->execute([
        (int)$v['client_id'],
        $v['survey_assigned'],
        $v['survey_assigned'],
        $v['survey_assigned'],
    ]);

    $vk = bhiVesselKey($v['vessel_name']);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (bhiVesselKey((string)$row['vessel_name']) === $vk) {
            return $row;
        }
    }

    return null;
}

function bhiValidateRow(PDO $db, array $r, array &$seen): array
{
    $v = [
        'row' => (int)($r['_row'] ?? 0),
        'sheet' => (string)($r['_sheet'] ?? ''),
        'status' => 'ready',
        'errors' => [],
        'warnings' => [],
        'possible_duplicate' => false,

        'vessel_name' => trim((string)($r['vessel_name'] ?? '')),
        'client_raw' => trim((string)($r['client'] ?? '')),
        'client_id' => 0,
        'client_name' => '',

        'survey_assigned' => null,
        'survey_completed' => null,
        'report_completed' => null,

        'report_number' => trim((string)($r['report_number'] ?? '')),

        'survey_type_raw' => trim((string)($r['survey_type'] ?? '')),
        'survey_type_ids' => [],
        'survey_type_names' => [],

        'port_raw' => trim((string)($r['port'] ?? '')),
        'port_id' => 0,
        'port_name' => '',

        'agent_name' => trim((string)($r['agent'] ?? '')),

        'surveyor_raw' => trim((string)($r['surveyor'] ?? '')),
        'surveyor_id' => 0,
        'surveyor_name' => '',

        'vlsfo' => null,
        'hsfo' => null,
        'lsmgo' => null,
        'recovery_amount' => null,

        'derived_status' => '',
        'existing_id' => 0,
    ];

    $err = function (string $msg) use (&$v): void {
        $v['errors'][] = $msg;
    };

    if ($v['vessel_name'] === '') {
        $err('Vessel Name is required.');
    }

    if ($v['client_raw'] === '') {
        $err('Client is required.');
    } else {
        $client = bhiFindClient($db, $v['client_raw']);

        if (!$client) {

            $err('Client name is invalid.');

        } else {

            $v['client_id'] = (int)$client['id'];
            $v['client_name'] = (string)$client['name'];

            if (!empty($client['needs_create'])) {
                $v['warnings'][] =
                    'New client "' .
                    $client['name'] .
                    '" will be created when you click Import.';
            }
        }
    }

    $v['survey_assigned'] = bhiDate($r['survey_assigned'] ?? '');
    $v['survey_completed'] = bhiDate($r['survey_completed'] ?? '');
    $v['report_completed'] = bhiDate($r['report_completed'] ?? '');

    if ($v['survey_assigned'] === null) {
        $err('Survey Assigned is required and must be a valid date.');
    }

    if (trim((string)($r['survey_completed'] ?? '')) !== '' && $v['survey_completed'] === null) {
        $err('Survey Completed is not a valid date.');
    }

    if (trim((string)($r['report_completed'] ?? '')) !== '' && $v['report_completed'] === null) {
        $err('Report Completed is not a valid date.');
    }

    if ($v['report_completed'] !== null && $v['survey_completed'] === null) {
        $err('Report Completed cannot be present when Survey Completed is blank.');
    }

    if ($v['survey_assigned'] !== null && $v['survey_completed'] !== null &&
        strtotime($v['survey_completed']) < strtotime($v['survey_assigned'])) {
        $err('Survey Completed is earlier than Survey Assigned.');
    }

    if ($v['survey_completed'] !== null && $v['report_completed'] !== null &&
        strtotime($v['report_completed']) < strtotime($v['survey_completed'])) {
        $err('Report Completed is earlier than Survey Completed.');
    }

    if ($v['survey_completed'] === null) {
        $v['derived_status'] = 'Pending Vessel';
    } elseif ($v['report_completed'] === null) {
        $v['derived_status'] = 'Pending Report';
    } else {
        $v['derived_status'] = 'Completed';
    }

    if ($v['survey_type_raw'] === '') {
        $err('Survey Type is required.');
    } else {
        $types = bhiFindTypes($db, $v['survey_type_raw']);
        foreach ($types['errors'] as $e) {
            $err($e);
        }
        $v['survey_type_ids'] = $types['ids'];
        $v['survey_type_names'] = $types['names'];
    }

    if ($v['port_raw'] === '') {
        $err('Port is required.');
    } else {
        $port = bhiFindPort($db, $v['port_raw']);
        if (!empty($port['error'])) {
            $err($port['error']);
        } else {
            $v['port_id'] = (int)$port['id'];
            $v['port_name'] = (string)$port['name'];
        }
    }

    $surveyor = bhiResolveSurveyor($db, $v['surveyor_raw']);
    if (!empty($surveyor['error'])) {
        $err($surveyor['error']);
    } else {
        $v['surveyor_id'] = (int)$surveyor['id'];
        $v['surveyor_name'] = (string)$surveyor['name'];
        if (!empty($surveyor['placeholder'])) {
            $v['warnings'][] = 'Surveyor is blank — saved internally as "Not Recorded".';
        }
    }

    foreach (['vlsfo' => 'VLSFO', 'hsfo' => 'HSFO', 'lsmgo' => 'LSMGO'] as $key => $label) {
        [$ok, $value] = bhiParseRecovery($r[$key] ?? null);
        if (!$ok) {
            $err($label . ' is required and must be a number (0 is allowed).');
        } else {
            $v[$key] = $value;
        }
    }

    if ($v['vlsfo'] !== null && $v['hsfo'] !== null && $v['lsmgo'] !== null) {
        $v['recovery_amount'] = round($v['vlsfo'] + $v['hsfo'] + $v['lsmgo'], 3);
    }

    // Report No is optional, but if supplied it still has to respect the DB unique constraint.
    if ($v['report_number'] !== '') {
        $rn = bhiReportNumberExists($db, $v['report_number']);
        if ($rn) {
            $err('Report No "' . $v['report_number'] . '" is already used by ' . $rn['vessel_name'] . ' (survey #' . $rn['id'] . ').');
        }
    }

    if ($v['errors']) {
        $v['status'] = 'error';
        return $v;
    }

    // Same-file duplicate check; deliberately excludes Report No.
    $key = implode('|', [
        bhiVesselKey($v['vessel_name']),
        (string)$v['client_id'],
        substr((string)$v['survey_assigned'], 0, 10),
        (string)$v['port_id'],
        bhiTypeSet($v['survey_type_ids']),
    ]);

    if (isset($seen[$key])) {
        $v['status'] = 'duplicate';
        $v['warnings'][] = 'Same vessel/client/assigned date/port/survey type as row ' . $seen[$key] . ' in this file.';
        return $v;
    }
    $seen[$key] = $v['row'];

    $existing = bhiFindDuplicate($db, $v);
    if ($existing) {
        $v['status'] = 'duplicate';
        $v['existing_id'] = (int)$existing['id'];
        $v['warnings'][] = 'Already in YSMS as survey #' . $existing['id']
            . (!empty($existing['report_number']) ? ' (' . $existing['report_number'] . ')' : '')
            . '.';
        return $v;
    }

    $possible = bhiPossibleDuplicate($db, $v);
    if ($possible) {
        $v['possible_duplicate'] = true;
        $v['warnings'][] = 'Possible duplicate: the same vessel/client already exists within ±2 days as survey #'
            . $possible['id']
            . (!empty($possible['report_number']) ? ' (' . $possible['report_number'] . ')' : '')
            . '. Review before importing.';
    }

    return $v;
}

function bhiValidateAll(PDO $db, array $rawRows): array
{
    // Reset caches / registries used by the shared matching helpers.
    completedImportRefs($db, true);

    $seen = [];
    $out = [];
    foreach ($rawRows as $row) {
        $out[] = bhiValidateRow($db, $row, $seen);
    }
    return $out;
}

function bhiEnsureSurveySurveyors(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS survey_surveyors (
            id INT(11) NOT NULL AUTO_INCREMENT,
            survey_id INT(11) NOT NULL,
            surveyor_id INT(11) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_survey_surveyor (survey_id, surveyor_id),
            KEY idx_survey_surveyors_survey (survey_id),
            KEY idx_survey_surveyors_surveyor (surveyor_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function bhiInsertRow(PDO $db, array $v, int $actorId, string $actorName): array
{
    try {
        // Resolve blank-surveyor placeholder before opening the transaction.
        $client = bhiGetOrCreateClient(
            $db,
            $v['client_raw'],
            $actorId
        );

        $v['client_id'] = (int)$client['id'];
        $v['client_name'] = (string)$client['name'];
        $surveyorId = (int)$v['surveyor_id'];
        if ($surveyorId <= 0) {
            $surveyorId = bhiEnsurePlaceholderSurveyor($db);
        }

        bhiEnsureSurveySurveyors($db);

        $db->beginTransaction();

        // Final duplicate re-check against live DB.
        $again = bhiFindDuplicate($db, $v);
        if ($again) {
            $db->rollBack();
            return [
                'ok' => false,
                'duplicate' => true,
                'survey_id' => (int)$again['id'],
                'error' => 'Duplicate appeared before import; skipped.',
            ];
        }

        if ($v['report_number'] !== '' && bhiReportNumberExists($db, $v['report_number'])) {
            $db->rollBack();
            return [
                'ok' => false,
                'duplicate' => false,
                'survey_id' => 0,
                'error' => 'Report No is already used.',
            ];
        }

        $assignedDate = substr((string)$v['survey_assigned'], 0, 10);

        $data = [
            'vessel_name'           => $v['vessel_name'],
            'client_id'             => (int)$v['client_id'],
            'agent_name'            => $v['agent_name'] !== '' ? $v['agent_name'] : 'N/A',
            'surveyor_id'           => $surveyorId,
            'survey_type_id'        => (int)$v['survey_type_ids'][0],
            'survey_type_ids'       => implode(',', $v['survey_type_ids']),
            'port_id'               => (int)$v['port_id'],
            'assign_date'           => $v['survey_assigned'],
            'appointment_date'      => $assignedDate,
            'survey_completed_date' => $v['survey_completed'],
            'report_uploaded_date'  => $v['report_completed'],
            'status'                => $v['derived_status'],
            'recovery_amount'       => $v['recovery_amount'],
            'vlsfo_recovery'        => $v['vlsfo'],
            'hsfo_recovery'         => $v['hsfo'],
            'lsmgo_recovery'        => $v['lsmgo'],
            'client_submitted'      => 0,
            'notes'                 => 'Historical bulk import by ' . $actorName . ' on ' . date('d-m-Y') . '.',
            'status_updated_by'     => $actorId > 0 ? $actorId : null,
            'status_updated_at'     => $v['report_completed']
                ?? $v['survey_completed']
                ?? $v['survey_assigned'],
        ];

        // Report No is intentionally optional. Store NULL when blank.
        $data['report_number'] = $v['report_number'] !== '' ? $v['report_number'] : null;

        $cols = [];
        foreach ($db->query("SHOW COLUMNS FROM surveys")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $cols[strtolower((string)$c['Field'])] = true;
        }

        $data = array_filter($data, fn($k) => isset($cols[strtolower($k)]), ARRAY_FILTER_USE_KEY);

        $names = array_keys($data);
        $sql = 'INSERT INTO surveys (`' . implode('`, `', $names) . '`) VALUES ('
             . implode(', ', array_fill(0, count($names), '?')) . ')';

        $db->prepare($sql)->execute(array_values($data));
        $surveyId = (int)$db->lastInsertId();

        $ss = $db->prepare("
            INSERT IGNORE INTO survey_surveyors (survey_id, surveyor_id)
            VALUES (?, ?)
        ");
        $ss->execute([$surveyId, $surveyorId]);

        $db->commit();

        return [
            'ok' => true,
            'duplicate' => false,
            'survey_id' => $surveyId,
            'error' => '',
        ];

    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        error_log('bulk_historical_import insert: ' . $e->getMessage());

        return [
            'ok' => false,
            'duplicate' => false,
            'survey_id' => 0,
            'error' => 'Database error while saving this row.',
        ];
    }
}

/* -------------------------------------------------------------------------
 * Request flow
 * ---------------------------------------------------------------------- */

$action = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (string)($_POST['action'] ?? '')
    : '';

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && empty($_POST)
    && empty($_FILES)
    && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
) {
    $error = 'The file is too large to upload.';

} elseif ($action === 'preview') {

    $file = $_FILES['excel_file'] ?? null;
    $name = basename((string)($file['name'] ?? ''));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $error = 'Please choose an Excel file.';
    } elseif (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
        $error = 'Only .xlsx, .xls or .csv files are allowed.';
    } elseif (($uploadError = upload_validate($file, [], 10 * 1024 * 1024)) !== '') {
        $error = $uploadError;
    } elseif (!bhiLoadLibrary()) {
        $error = 'PhpSpreadsheet is not available on this server.';
    } else {
        try {
            $spreadsheet = bulkAssignLoadSpreadsheet($file['tmp_name'], $ext);
            $read = bhiReadRows($spreadsheet);

            if (!$read['header_found']) {
                $error = 'No header row found. The sheet must contain a "Vessel Name" column.';
            } elseif ($read['missing']) {
                $error = 'Required column(s) missing: ' . implode(', ', $read['missing']) . '.';
            } elseif (!$read['rows']) {
                $error = 'No survey rows were found.';
            } elseif (count($read['rows']) > 2000) {
                $error = 'This file has more than 2000 rows. Split it into smaller files.';
            } else {
                $token = bin2hex(random_bytes(16));

                $_SESSION['bulk_historical_import'] = [
                    'token' => $token,
                    'file'  => $name,
                    'rows'  => $read['rows'],
                    'at'    => time(),
                ];

                $preview = [
                    'token' => $token,
                    'file'  => $name,
                    'rows'  => bhiValidateAll($db, $read['rows']),
                ];

                $step = 'preview';
            }
        } catch (Throwable $e) {
            error_log('bulk_historical_import preview: ' . $e->getMessage());

            $error = 'Spreadsheet error: ' .
                $e->getMessage() .
                ' — File: ' .
                basename($e->getFile()) .
                ' — Line: ' .
                $e->getLine();
        }
    }

} elseif ($action === 'import') {

    $sess = $_SESSION['bulk_historical_import'] ?? null;

    if (
        !$sess
        || !hash_equals((string)$sess['token'], (string)($_POST['token'] ?? ''))
    ) {
        $error = 'This preview expired or was already imported. Upload the file again.';
    } elseif (!bhiLoadLibrary()) {
        $error = 'PhpSpreadsheet is not available on this server.';
    } else {
        unset($_SESSION['bulk_historical_import']);

        $validated = bhiValidateAll($db, $sess['rows']);

        $result = [
            'file' => $sess['file'],
            'inserted' => [],
            'skipped' => 0,
            'failed' => [],
        ];

        foreach ($validated as $v) {
            if ($v['status'] === 'duplicate') {
                $result['skipped']++;
                continue;
            }

            if ($v['status'] !== 'ready') {
                $result['failed'][] = [
                    'row' => $v['row'],
                    'vessel' => $v['vessel_name'],
                    'error' => implode(' ', $v['errors']),
                ];
                continue;
            }

            $save = bhiInsertRow($db, $v, $actorId, $actorName);

            if ($save['ok']) {
                $result['inserted'][] = [
                    'id' => $save['survey_id'],
                    'vessel' => $v['vessel_name'],
                    'status' => $v['derived_status'],
                    'report_number' => $v['report_number'],
                ];
            } elseif (!empty($save['duplicate'])) {
                $result['skipped']++;
            } else {
                $result['failed'][] = [
                    'row' => $v['row'],
                    'vessel' => $v['vessel_name'],
                    'error' => $save['error'],
                ];
            }
        }

        $_SESSION['bulk_historical_import_result'] = $result;

        error_log(sprintf(
            'bulk_historical_import: user %d imported %d, skipped %d, failed %d from "%s"',
            $actorId,
            count($result['inserted']),
            $result['skipped'],
            count($result['failed']),
            $sess['file']
        ));

        header('Location: bulk_historical_import.php?done=1');
        exit;
    }

} elseif (isset($_GET['done']) && !empty($_SESSION['bulk_historical_import_result'])) {

    $result = $_SESSION['bulk_historical_import_result'];
    unset($_SESSION['bulk_historical_import_result']);
    $step = 'result';
}

/* -------------------------------------------------------------------------
 * Page
 * ---------------------------------------------------------------------- */

include 'includes/header.php';

function bhiEsc($value): string
{
    return sanitize((string)$value);
}

function bhiDateDisplay(?string $date): string
{
    if (!$date) {
        return '—';
    }
    $ts = strtotime($date);
    return $ts ? date('d-M-Y', $ts) : '—';
}

?>
<style>
.bhi-page{padding:18px;max-width:none}
.bhi-hero{background:linear-gradient(120deg,#0f172a,#1e1b6b 55%,#4b2fd6);border-radius:20px;padding:22px 24px;color:#fff;margin-bottom:16px;box-shadow:0 12px 30px rgba(30,27,107,.18)}
.bhi-hero h1{font-size:21px;font-weight:800;margin:0 0 4px}
.bhi-hero p{font-size:12px;color:rgba(255,255,255,.72);margin:0}
.bhi-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:18px;margin-bottom:16px;box-shadow:0 3px 14px rgba(15,23,42,.04)}
.bhi-title{font-size:15px;font-weight:800;color:#0f172a;margin-bottom:4px}
.bhi-sub{font-size:12px;color:#64748b;margin-bottom:14px}
.bhi-error{background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:12px 14px;color:#991b1b;font-size:12px;font-weight:600;margin-bottom:14px}
.bhi-drop{display:block;border:2px dashed #cbd5e1;border-radius:14px;padding:32px 18px;text-align:center;background:#f8fafc;cursor:pointer}
.bhi-drop:hover{border-color:#8b5cf6;background:#faf5ff}
.bhi-drop i{font-size:30px;color:#7c3aed;margin-bottom:9px}
.bhi-drop b{display:block;font-size:13px;color:#334155}
.bhi-drop small{display:block;color:#94a3b8;margin-top:4px}
.bhi-actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:14px}
.bhi-btn{border:0;border-radius:10px;padding:10px 16px;font-size:12.5px;font-weight:700;display:inline-flex;align-items:center;gap:7px;text-decoration:none;cursor:pointer}
.bhi-primary{background:#4f46e5;color:#fff}
.bhi-success{background:#059669;color:#fff}
.bhi-ghost{background:#f1f5f9;color:#334155}
.bhi-help{font-size:12px;color:#475569;line-height:1.7;margin:14px 0 0;padding-left:18px}
.bhi-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:14px}
.bhi-stat{border-radius:12px;padding:11px;text-align:center}
.bhi-stat b{display:block;font-size:20px}
.bhi-stat span{font-size:10px;text-transform:uppercase;font-weight:700;letter-spacing:.04em}
.bhi-stat.all{background:#f1f5f9;color:#334155}
.bhi-stat.ready{background:#ecfdf5;color:#047857}
.bhi-stat.dup{background:#fffbeb;color:#b45309}
.bhi-stat.err{background:#fef2f2;color:#dc2626}
.bhi-table-wrap{overflow:auto;border:1px solid #e2e8f0;border-radius:13px;max-height:600px}
.bhi-table{width:100%;border-collapse:collapse;font-size:12px}
.bhi-table th{position:sticky;top:0;z-index:1;background:#f8fafc;color:#64748b;text-transform:uppercase;font-size:10px;letter-spacing:.04em;text-align:left;padding:9px;border-bottom:1px solid #e2e8f0;white-space:nowrap}
.bhi-table td{padding:9px;border-bottom:1px solid #f1f5f9;vertical-align:top;color:#334155}
.bhi-table tr.possible td{background:#fffdf4}
.bhi-badge{display:inline-flex;align-items:center;border-radius:999px;padding:4px 8px;font-size:10px;font-weight:700;white-space:nowrap}
.bhi-badge.ready{background:#dcfce7;color:#166534}
.bhi-badge.duplicate{background:#fef3c7;color:#92400e}
.bhi-badge.error{background:#fee2e2;color:#991b1b}
.bhi-badge.stage{background:#eef2ff;color:#4338ca}
.bhi-msg{font-size:10.5px;line-height:1.5;margin-top:3px}
.bhi-msg.err{color:#b91c1c}
.bhi-msg.warn{color:#b45309}
.bhi-ok{color:#047857}
.bhi-result-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.bhi-result-box{padding:16px;border-radius:14px;text-align:center}
.bhi-result-box b{display:block;font-size:24px}
.bhi-result-box span{font-size:11px;font-weight:700;text-transform:uppercase}
@media(max-width:767.98px){
  .bhi-page{padding:12px}
  .bhi-stats{grid-template-columns:repeat(2,1fr)}
  .bhi-result-grid{grid-template-columns:1fr}
}
</style>

<div class="scroll-content">
    <?php
    $back_url = 'index.php';
    $page_testid = 'bulk-historical-import';
    if (is_file('includes/top_app_bar.php')) {
        include 'includes/top_app_bar.php';
    }
    ?>

    <div class="bhi-page">
        <div class="bhi-hero">
            <h1><i class="fa-solid fa-file-arrow-up"></i> Bulk Historical Import</h1>
            <p>Import missing historical survey data from Excel. Dossier/archive files are handled separately.</p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="bhi-error"><?= bhiEsc($error) ?></div>
        <?php endif; ?>

        <?php if ($step === 'upload'): ?>

            <div class="bhi-card">
                <div class="bhi-title">Upload historical survey spreadsheet</div>
                <div class="bhi-sub">Nothing is saved until you review the validation preview and confirm the import.</div>

                <form method="post" enctype="multipart/form-data" action="bulk_historical_import.php" data-no-loading>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="preview">


                    <label class="bhi-drop">
                        <i class="fa-solid fa-file-excel"></i>
                        <b data-bhi-file-name>Choose .xlsx, .xls or .csv file</b>
                        <small>Maximum file size: 10 MB</small>
                        <input type="file" name="excel_file" accept=".xlsx,.xls,.csv" required style="display:none" data-bhi-file>
                    </label>

                    <div class="bhi-actions">
                        <button class="bhi-btn bhi-primary" type="submit">
                            <i class="fa-solid fa-magnifying-glass"></i> Validate & Preview
                        </button>
                    </div>
                </form>

                <ul class="bhi-help">
                    <li><b>Required:</b> Vessel Name, Client, Survey Assigned, Survey Type, Port, VLSFO, HSFO and LSMGO.</li>
                    <li><b>Optional:</b> Survey Completed, Report Completed, Report No, Agent and Surveyor.</li>
                    <li>Recovery cells must be numeric; <b>0 is valid</b>.</li>
                    <li>Report No is stored when supplied, but <b>is not used as the duplicate identity</b>.</li>
                    <li>Status is derived automatically: Pending Vessel / Pending Report / Completed.</li>
                    <li>Multiple survey types may be separated with commas or <b>+</b>.</li>
                </ul>
            </div>

        <?php elseif ($step === 'preview' && $preview): ?>

            <?php
            $counts = ['ready' => 0, 'duplicate' => 0, 'error' => 0];
            $possible = 0;
            foreach ($preview['rows'] as $r) {
                $counts[$r['status']]++;
                if (!empty($r['possible_duplicate']) && $r['status'] === 'ready') {
                    $possible++;
                }
            }
            ?>

            <div class="bhi-card">
                <div class="bhi-title">Validation preview — <?= bhiEsc($preview['file']) ?></div>
                <div class="bhi-sub">Review all errors and possible duplicates before importing.</div>

                <div class="bhi-stats">
                    <div class="bhi-stat all"><b><?= count($preview['rows']) ?></b><span>Total rows</span></div>
                    <div class="bhi-stat ready"><b><?= $counts['ready'] ?></b><span>Ready</span></div>
                    <div class="bhi-stat dup"><b><?= $counts['duplicate'] ?></b><span>Duplicates</span></div>
                    <div class="bhi-stat err"><b><?= $counts['error'] ?></b><span>Errors</span></div>
                </div>

                <?php if ($possible): ?>
                    <div class="bhi-error" style="background:#fffbeb;border-color:#fde68a;color:#92400e">
                        <?= $possible ?> ready row(s) have a possible-duplicate warning. Review them carefully before importing.
                    </div>
                <?php endif; ?>

                <div class="bhi-table-wrap">
                    <table class="bhi-table">
                        <thead>
                        <tr>
                            <th>Row</th>
                            <th>Result</th>
                            <th>Vessel</th>
                            <th>Client</th>
                            <th>Assigned</th>
                            <th>Survey Completed</th>
                            <th>Report Completed</th>
                            <th>Report No</th>
                            <th>Survey Type</th>
                            <th>Port</th>
                            <th>Agent</th>
                            <th>Surveyor</th>
                            <th>Recovery</th>
                            <th>Status</th>
                            <th>Notes</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($preview['rows'] as $r): ?>
                            <tr class="<?= !empty($r['possible_duplicate']) ? 'possible' : '' ?>">
                                <td><?= (int)$r['row'] ?></td>
                                <td><span class="bhi-badge <?= bhiEsc($r['status']) ?>"><?= strtoupper(bhiEsc($r['status'])) ?></span></td>
                                <td><b><?= bhiEsc($r['vessel_name']) ?></b></td>
                                <td><?= bhiEsc($r['client_name'] ?: $r['client_raw']) ?></td>
                                <td><?= bhiEsc(bhiDateDisplay($r['survey_assigned'])) ?></td>
                                <td><?= bhiEsc(bhiDateDisplay($r['survey_completed'])) ?></td>
                                <td><?= bhiEsc(bhiDateDisplay($r['report_completed'])) ?></td>
                                <td><?= $r['report_number'] !== '' ? bhiEsc($r['report_number']) : '—' ?></td>
                                <td><?= bhiEsc(implode(' + ', $r['survey_type_names'])) ?></td>
                                <td><?= bhiEsc($r['port_name'] ?: $r['port_raw']) ?></td>
                                <td><?= $r['agent_name'] !== '' ? bhiEsc($r['agent_name']) : '—' ?></td>
                                <td><?= bhiEsc($r['surveyor_name']) ?></td>
                                <td>
                                    <?php if ($r['recovery_amount'] !== null): ?>
                                        <b><?= number_format((float)$r['recovery_amount'], 3) ?> MT</b><br>
                                        <span style="font-size:10px;color:#64748b">
                                            V <?= number_format((float)$r['vlsfo'], 3) ?> /
                                            H <?= number_format((float)$r['hsfo'], 3) ?> /
                                            L <?= number_format((float)$r['lsmgo'], 3) ?>
                                        </span>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td><span class="bhi-badge stage"><?= bhiEsc($r['derived_status']) ?></span></td>
                                <td>
                                    <?php foreach ($r['errors'] as $m): ?>
                                        <div class="bhi-msg err"><?= bhiEsc($m) ?></div>
                                    <?php endforeach; ?>
                                    <?php foreach ($r['warnings'] as $m): ?>
                                        <div class="bhi-msg warn"><?= bhiEsc($m) ?></div>
                                    <?php endforeach; ?>
                                    <?php if (!$r['errors'] && !$r['warnings']): ?>
                                        <span class="bhi-ok">Ready</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="bhi-actions">
                    <form method="post" action="bulk_historical_import.php" style="display:inline" data-no-loading>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="import">
                        <input type="hidden" name="token" value="<?= bhiEsc($preview['token']) ?>">
                        <button
                            class="bhi-btn bhi-success"
                            type="submit"
                            <?= $counts['ready'] === 0 ? 'disabled' : '' ?>
                            onclick="return confirm('Import <?= (int)$counts['ready'] ?> ready historical survey record(s)? Existing duplicates and invalid rows will be skipped.');"
                        >
                            <i class="fa-solid fa-database"></i>
                            Import <?= (int)$counts['ready'] ?> Ready Record<?= $counts['ready'] === 1 ? '' : 's' ?>
                        </button>
                    </form>

                    <a href="bulk_historical_import.php" class="bhi-btn bhi-ghost">
                        <i class="fa-solid fa-arrow-left"></i> Choose Another File
                    </a>
                </div>
            </div>

        <?php elseif ($step === 'result' && $result): ?>

            <div class="bhi-card">
                <div class="bhi-title">Import complete — <?= bhiEsc($result['file']) ?></div>
                <div class="bhi-sub">The database import has finished. No dossier files were uploaded.</div>

                <div class="bhi-result-grid">
                    <div class="bhi-result-box" style="background:#ecfdf5;color:#047857">
                        <b><?= count($result['inserted']) ?></b>
                        <span>Imported</span>
                    </div>
                    <div class="bhi-result-box" style="background:#fffbeb;color:#b45309">
                        <b><?= (int)$result['skipped'] ?></b>
                        <span>Skipped duplicates</span>
                    </div>
                    <div class="bhi-result-box" style="background:#fef2f2;color:#dc2626">
                        <b><?= count($result['failed']) ?></b>
                        <span>Failed</span>
                    </div>
                </div>

                <?php if ($result['inserted']): ?>
                    <div style="margin-top:18px">
                        <div class="bhi-title">Imported records</div>
                        <div class="bhi-table-wrap" style="max-height:300px">
                            <table class="bhi-table">
                                <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Vessel</th>
                                    <th>Report No</th>
                                    <th>Status</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($result['inserted'] as $r): ?>
                                    <tr>
                                        <td><?= (int)$r['id'] ?></td>
                                        <td><?= bhiEsc($r['vessel']) ?></td>
                                        <td><?= $r['report_number'] !== '' ? bhiEsc($r['report_number']) : '—' ?></td>
                                        <td><?= bhiEsc($r['status']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($result['failed']): ?>
                    <div style="margin-top:18px">
                        <div class="bhi-title">Failed rows</div>
                        <div class="bhi-table-wrap" style="max-height:300px">
                            <table class="bhi-table">
                                <thead>
                                <tr>
                                    <th>Row</th>
                                    <th>Vessel</th>
                                    <th>Error</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($result['failed'] as $r): ?>
                                    <tr>
                                        <td><?= (int)$r['row'] ?></td>
                                        <td><?= bhiEsc($r['vessel']) ?></td>
                                        <td style="color:#b91c1c"><?= bhiEsc($r['error']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="bhi-actions">
                    <a href="bulk_historical_import.php" class="bhi-btn bhi-primary">
                        <i class="fa-solid fa-file-arrow-up"></i> Import Another File
                    </a>
                    <a href="completed.php" class="bhi-btn bhi-ghost">
                        <i class="fa-solid fa-circle-check"></i> Completed Vessels
                    </a>
                    <a href="monthly_data.php" class="bhi-btn bhi-ghost">
                        <i class="fa-solid fa-calendar-days"></i> Monthly Data
                    </a>
                </div>
            </div>

        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var file = document.querySelector('[data-bhi-file]');
    var label = document.querySelector('[data-bhi-file-name]');

    if (file && label) {
        file.addEventListener('change', function () {
            label.textContent = file.files && file.files[0]
                ? file.files[0].name
                : 'Choose .xlsx, .xls or .csv file';
        });
    }
})();
</script>

<?php
include 'includes/nav.php';
include 'includes/footer.php';
?>

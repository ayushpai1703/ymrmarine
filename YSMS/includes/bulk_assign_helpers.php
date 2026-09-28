<?php
/**
 * Bulk Vessel Assignment via Excel Upload — shared helpers.
 *
 * Reuses the existing assignment building blocks (report_number.php,
 * notifications.php, mailer.php's notifySurveyorOfAssignment) so a bulk row
 * is inserted and notified in exactly the same way a single Assign Vessel
 * form submission already is. Nothing here duplicates that logic — it only
 * adds: Excel reading (PhpSpreadsheet), flexible column-name recognition,
 * per-row lookups against the existing tables, and duplicate/already-
 * assigned detection before any insert happens.
 */

// ─────────────────────────────────────────────────────────────────────────
// Storage (audit / history)
// ─────────────────────────────────────────────────────────────────────────

function ensureBulkAssignmentTables(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `bulk_assignment_batches` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `batch_token` VARCHAR(64) NOT NULL,
            `uploaded_by` INT(11) NOT NULL,
            `original_filename` VARCHAR(255) NOT NULL,
            `stored_path` VARCHAR(255) NOT NULL,
            `sheet_name` VARCHAR(255) DEFAULT NULL,
            `total_rows` INT(11) NOT NULL DEFAULT 0,
            `valid_rows` INT(11) NOT NULL DEFAULT 0,
            `duplicate_rows` INT(11) NOT NULL DEFAULT 0,
            `already_assigned_rows` INT(11) NOT NULL DEFAULT 0,
            `error_rows` INT(11) NOT NULL DEFAULT 0,
            `inserted_rows` INT(11) NOT NULL DEFAULT 0,
            `status` VARCHAR(20) NOT NULL DEFAULT 'uploaded',
            `ip_address` VARCHAR(64) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `completed_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_bulk_batch_token` (`batch_token`),
            KEY `idx_bulk_batch_user` (`uploaded_by`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS `bulk_assignment_rows` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `batch_id` INT(11) NOT NULL,
            `excel_row_number` INT(11) NOT NULL,
            `vessel_name` VARCHAR(255) DEFAULT NULL,
            `imo_number` VARCHAR(20) DEFAULT NULL,
            `surveyor_label` VARCHAR(255) DEFAULT NULL,
            `client_label` VARCHAR(255) DEFAULT NULL,
            `status` VARCHAR(40) NOT NULL,
            `message` TEXT DEFAULT NULL,
            `survey_id` INT(11) DEFAULT NULL,
            `report_number` VARCHAR(50) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_bulk_rows_batch` (`batch_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('ensureBulkAssignmentTables: ' . $e->getMessage());
    }
}

function bulkAssignCreateBatch(PDO $db, int $uploadedBy, string $token, string $originalFilename, string $storedPath, ?string $ip): int {
    ensureBulkAssignmentTables($db);
    $stmt = $db->prepare("INSERT INTO bulk_assignment_batches (batch_token, uploaded_by, original_filename, stored_path, ip_address, status) VALUES (?, ?, ?, ?, ?, 'uploaded')");
    $stmt->execute([$token, $uploadedBy, $originalFilename, $storedPath, $ip]);
    return (int)$db->lastInsertId();
}

function bulkAssignGetBatchByToken(PDO $db, string $token, int $uploadedBy): ?array {
    ensureBulkAssignmentTables($db);
    $stmt = $db->prepare("SELECT * FROM bulk_assignment_batches WHERE batch_token = ? AND uploaded_by = ? LIMIT 1");
    $stmt->execute([$token, $uploadedBy]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function bulkAssignUpdateBatch(PDO $db, int $batchId, array $fields): void {
    if (empty($fields)) return;
    $set = [];
    $vals = [];
    foreach ($fields as $k => $v) {
        $set[] = "`$k` = ?";
        $vals[] = $v;
    }
    $vals[] = $batchId;
    $db->prepare("UPDATE bulk_assignment_batches SET " . implode(', ', $set) . " WHERE id = ?")->execute($vals);
}

function bulkAssignSaveRow(PDO $db, int $batchId, array $row): void {
    $stmt = $db->prepare("INSERT INTO bulk_assignment_rows
        (batch_id, excel_row_number, vessel_name, imo_number, surveyor_label, client_label, status, message, survey_id, report_number)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $batchId,
        (int)$row['row_number'],
        $row['vessel_name'] ?? null,
        $row['imo_number'] ?? null,
        $row['surveyor_label'] ?? null,
        $row['client_label'] ?? null,
        $row['status'],
        $row['message'] ?? '',
        $row['survey_id'] ?? null,
        $row['report_number'] ?? null,
    ]);
}

function bulkAssignListBatches(PDO $db, ?int $uploadedBy = null, int $limit = 50): array {
    ensureBulkAssignmentTables($db);
    $limit = max(1, min(200, $limit));
    if ($uploadedBy !== null) {
        $stmt = $db->prepare("
            SELECT b.*, u.full_name AS uploaded_by_name
            FROM bulk_assignment_batches b
            LEFT JOIN users u ON b.uploaded_by = u.id
            WHERE b.uploaded_by = ?
            ORDER BY b.id DESC LIMIT $limit
        ");
        $stmt->execute([$uploadedBy]);
    } else {
        $stmt = $db->query("
            SELECT b.*, u.full_name AS uploaded_by_name
            FROM bulk_assignment_batches b
            LEFT JOIN users u ON b.uploaded_by = u.id
            ORDER BY b.id DESC LIMIT $limit
        ");
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function bulkAssignGetBatchRows(PDO $db, int $batchId): array {
    ensureBulkAssignmentTables($db);
    $stmt = $db->prepare("SELECT * FROM bulk_assignment_rows WHERE batch_id = ? ORDER BY excel_row_number ASC");
    $stmt->execute([$batchId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// ─────────────────────────────────────────────────────────────────────────
// Column recognition
// ─────────────────────────────────────────────────────────────────────────

/** Trim, lowercase, strip everything but letters/digits — "IMO No." and "imo no" both become "imono". */
function bulkAssignNormalizeHeader(string $h): string {
    $h = trim($h);
    $h = mb_strtolower($h, 'UTF-8');
    $h = preg_replace('/[^a-z0-9]/', '', $h);
    return (string)$h;
}

/** Canonical logical field => normalized header aliases it should be recognized from. */
function bulkAssignFieldAliases(): array {
    return [
        'vessel_name' => ['vesselname', 'vessel', 'shipname', 'vesselsname', 'nameofvessel'],
        'imo_number'  => ['imo', 'imonumber', 'imono', 'imonum', 'imocode'],
        'surveyor'    => ['surveyor', 'surveyorname', 'surveyorid', 'surveyoremail'],
        'client'      => ['client', 'clientname', 'clientid', 'clientemail', 'companyname'],
        'port'        => ['port', 'portname'],
        'assign_date' => ['assignmentdate', 'assigndate', 'date', 'assigneddate'],
        'survey_type' => ['surveytype', 'type', 'servicetype', 'surveytypes'],
        'remarks'     => ['remarks', 'remark', 'notes', 'note', 'comments', 'comment'],
        'agent_name'  => ['agentname', 'agent'],
    ];
}

/** Required logical fields — a row can't be assigned without these columns existing in the sheet. */
function bulkAssignRequiredFields(): array {
    return ['client', 'port', 'survey_type'];
}

/**
 * @param array $headerCells raw header row values (col index => text)
 * @return array{map: array<string,int>, labels: array<string,string>, unmapped: array<int,string>, missing_required: array<int,string>}
 */
function bulkAssignDetectColumnMap(array $headerCells): array {
    $aliases = bulkAssignFieldAliases();
    $map = [];      // field => col index
    $labels = [];   // field => original header text used
    $unmapped = []; // col index => header text

    foreach ($headerCells as $idx => $raw) {
        $text = trim((string)$raw);
        if ($text === '') continue;
        $norm = bulkAssignNormalizeHeader($text);
        $matchedField = null;
        foreach ($aliases as $field => $list) {
            if (isset($map[$field])) continue; // first matching column wins
            if (in_array($norm, $list, true)) {
                $matchedField = $field;
                break;
            }
        }
        if ($matchedField !== null) {
            $map[$matchedField] = $idx;
            $labels[$matchedField] = $text;
        } else {
            $unmapped[$idx] = $text;
        }
    }

    // vessel identification: either vessel_name OR imo_number column is enough
    $missingRequired = [];
    if (!isset($map['vessel_name']) && !isset($map['imo_number'])) {
        $missingRequired[] = 'Vessel Name';
    }
    foreach (bulkAssignRequiredFields() as $field) {
        if (!isset($map[$field])) {
            $missingRequired[] = ucfirst(str_replace('_', ' ', $field));
        }
    }

    return ['map' => $map, 'labels' => $labels, 'unmapped' => $unmapped, 'missing_required' => $missingRequired];
}

// ─────────────────────────────────────────────────────────────────────────
// Excel / CSV reading
// ─────────────────────────────────────────────────────────────────────────

function bulkAssignAllowedExtensions(): array {
    return ['xlsx', 'xls', 'csv'];
}

/** @return \PhpOffice\PhpSpreadsheet\Spreadsheet */
function bulkAssignLoadSpreadsheet(string $filePath, string $ext) {
    if ($ext === 'csv') {
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
        $reader->setDelimiter(bulkAssignDetectCsvDelimiter($filePath));
        $reader->setEnclosure('"');
        $reader->setSheetIndex(0);
        return $reader->load($filePath);
    }
    return \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
}

function bulkAssignDetectCsvDelimiter(string $filePath): string {
    $first = @fgets(fopen($filePath, 'r')) ?: '';
    $commas = substr_count($first, ',');
    $semis = substr_count($first, ';');
    return $semis > $commas ? ';' : ',';
}

function bulkAssignSheetNames($spreadsheet): array {
    return $spreadsheet->getSheetNames();
}

/**
 * Reads one sheet as a plain 2D array (row 0 = header), trimming fully-blank
 * trailing rows. Uses getCalculatedValue() so formula cells resolve, but the
 * assign_date column is re-read separately (bulkAssignRowDateValue) to keep
 * access to the raw Excel date serial when present.
 */
function bulkAssignReadSheetRows($spreadsheet, ?string $sheetName): array {
    $sheet = $sheetName !== null ? $spreadsheet->getSheetByName($sheetName) : $spreadsheet->getActiveSheet();
    if (!$sheet) {
        $sheet = $spreadsheet->getActiveSheet();
    }
    $rows = $sheet->toArray(null, true, true, false);
    // Drop fully-empty rows (blank Excel rows in the middle/end of real data)
    $rows = array_values(array_filter($rows, function ($r) {
        foreach ($r as $cell) {
            if (trim((string)$cell) !== '') return true;
        }
        return false;
    }));
    return $rows;
}

/** Raw cell value for a given data row + column index, from the live worksheet (for date-serial detection). */
function bulkAssignRawCell($spreadsheet, ?string $sheetName, int $sheetRowNumber, int $colIndex) {
    $sheet = $sheetName !== null ? $spreadsheet->getSheetByName($sheetName) : $spreadsheet->getActiveSheet();
    if (!$sheet) $sheet = $spreadsheet->getActiveSheet();
    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex + 1);
    $cell = $sheet->getCell($colLetter . $sheetRowNumber);
    return $cell;
}

// ─────────────────────────────────────────────────────────────────────────
// Field normalization / parsing
// ─────────────────────────────────────────────────────────────────────────

function bulkAssignNormalizeImo(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '';
    $raw = preg_replace('/imo/i', '', $raw);
    $digits = preg_replace('/[^0-9]/', '', $raw);
    return $digits;
}

/**
 * Accepts an Excel date cell (may already be a PhpSpreadsheet Cell for
 * serial detection) or a plain scalar, returns 'Y-m-d H:i:s' or null.
 */
function bulkAssignParseDate($cellOrValue): ?string {
    if ($cellOrValue instanceof \PhpOffice\PhpSpreadsheet\Cell\Cell) {
        $value = $cellOrValue->getValue();
        if (is_numeric($value) && \PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($cellOrValue)) {
            try {
                $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value);
                return $dt->format('Y-m-d H:i:s');
            } catch (Throwable $e) {
                // fall through to string parsing below
            }
        }
        $value = (string)$cellOrValue->getCalculatedValue();
    } else {
        $value = $cellOrValue;
    }

    $value = trim((string)$value);
    if ($value === '') return null;

    // Pure numeric — likely an Excel serial read outside a Cell context (CSV rarely hits this)
    if (is_numeric($value) && (float)$value > 20000 && (float)$value < 80000) {
        try {
            $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$value);
            return $dt->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            // fall through
        }
    }

    // DD-MM-YYYY / DD/MM/YYYY (bias toward day-first, matches this business's locale)
    if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{4})$#', $value, $m)) {
        $day = (int)$m[1];
        $month = (int)$m[2];
        $year = (int)$m[3];
        if ($day > 12 && $month <= 12) {
            // unambiguous day-first
        } elseif ($month > 12 && $day <= 12) {
            [$day, $month] = [$month, $day];
        }
        if (checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day);
        }
    }

    $ts = strtotime($value);
    if ($ts !== false) {
        return date('Y-m-d H:i:s', $ts);
    }
    return null;
}

// ─────────────────────────────────────────────────────────────────────────
// Lookups against existing tables
// ─────────────────────────────────────────────────────────────────────────

/** @return array{id:int,full_name:string,email:string}|null */
function bulkAssignFindSurveyor(PDO $db, string $raw): ?array {
    $raw = trim($raw);
    if ($raw === '') return null;

    if (ctype_digit($raw)) {
        $stmt = $db->prepare("SELECT id, full_name, email FROM users WHERE id = ? AND role_id = 2 AND status = 'Active' LIMIT 1");
        $stmt->execute([(int)$raw]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
    }
    if (strpos($raw, '@') !== false) {
        $stmt = $db->prepare("SELECT id, full_name, email FROM users WHERE role_id = 2 AND status = 'Active' AND LOWER(email) = LOWER(?) LIMIT 1");
        $stmt->execute([$raw]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
        return null; // an email was given but didn't match — don't fall through to a fuzzy name match
    }
    $stmt = $db->prepare("SELECT id, full_name, email FROM users WHERE role_id = 2 AND status = 'Active' AND LOWER(full_name) = LOWER(?) LIMIT 1");
    $stmt->execute([$raw]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;

    $stmt = $db->prepare("SELECT id, full_name, email FROM users WHERE role_id = 2 AND status = 'Active' AND full_name LIKE ? LIMIT 2");
    $stmt->execute(['%' . $raw . '%']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return count($rows) === 1 ? $rows[0] : null; // ambiguous partial match = no match
}

/** @return array{id:int,company_name:string}|null */
function bulkAssignFindClient(PDO $db, string $raw): ?array {
    $raw = trim($raw);
    if ($raw === '') return null;

    if (ctype_digit($raw)) {
        $stmt = $db->prepare("SELECT id, company_name FROM clients WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$raw]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
    }
    if (strpos($raw, '@') !== false) {
        try {
            $stmt = $db->prepare("SELECT id, company_name FROM clients WHERE LOWER(email) = LOWER(?) LIMIT 1");
            $stmt->execute([$raw]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) return $row;
        } catch (Throwable $e) { /* email column may not exist on very old installs */ }
        return null;
    }
    // exact short_code match (e.g. "AS")
    try {
        $stmt = $db->prepare("SELECT id, company_name FROM clients WHERE UPPER(short_code) = UPPER(?) LIMIT 1");
        $stmt->execute([$raw]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
    } catch (Throwable $e) { /* short_code may not exist yet */ }

    $stmt = $db->prepare("SELECT id, company_name FROM clients WHERE LOWER(company_name) = LOWER(?) LIMIT 1");
    $stmt->execute([$raw]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;

    $stmt = $db->prepare("SELECT id, company_name FROM clients WHERE company_name LIKE ? LIMIT 2");
    $stmt->execute(['%' . $raw . '%']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return count($rows) === 1 ? $rows[0] : null;
}

/** @return array{id:int,port_name:string}|null */
function bulkAssignFindPort(PDO $db, string $raw): ?array {
    $raw = trim($raw);
    if ($raw === '') return null;

    if (ctype_digit($raw)) {
        $stmt = $db->prepare("SELECT id, port_name FROM ports WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$raw]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
    }
    $stmt = $db->prepare("SELECT id, port_name FROM ports WHERE LOWER(port_name) = LOWER(?) LIMIT 1");
    $stmt->execute([$raw]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;

    $stmt = $db->prepare("SELECT id, port_name FROM ports WHERE port_name LIKE ? LIMIT 2");
    $stmt->execute(['%' . $raw . '%']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return count($rows) === 1 ? $rows[0] : null;
}

/** @return array{id:int,type_name:string}|null */
function bulkAssignFindSurveyType(PDO $db, string $raw): ?array {
    $raw = trim($raw);
    if ($raw === '') return null;

    if (ctype_digit($raw)) {
        $stmt = $db->prepare("SELECT id, type_name FROM survey_types WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$raw]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
    }
    $stmt = $db->prepare("SELECT id, type_name FROM survey_types WHERE LOWER(type_name) = LOWER(?) LIMIT 1");
    $stmt->execute([$raw]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;

    $stmt = $db->prepare("SELECT id, type_name FROM survey_types WHERE type_name LIKE ? LIMIT 2");
    $stmt->execute(['%' . $raw . '%']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return count($rows) === 1 ? $rows[0] : null;
}

/**
 * If vessel_name is blank but imo_number is present, try to recover the
 * vessel's name from a past survey that used the same IMO.
 */
function bulkAssignBackfillVesselNameByImo(PDO $db, string $imo): string {
    if ($imo === '') return '';
    try {
        $stmt = $db->prepare("SELECT vessel_name FROM surveys WHERE imo_number = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$imo]);
        return (string)($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Looks for an existing OPEN (not Completed) survey for the same
 * vessel+client+port+survey type — the natural key of "this job already
 * exists" for duplicate / already-assigned detection.
 *
 * @return array{survey_id:int,report_number:string,surveyor_ids:int[]}|null
 */
function bulkAssignFindOpenMatchingSurvey(PDO $db, string $vesselName, string $imo, int $clientId, int $portId, int $surveyTypeId): ?array {
    try {
        if ($imo !== '') {
            $stmt = $db->prepare("
                SELECT id, report_number, surveyor_id FROM surveys
                WHERE imo_number = ? AND client_id = ? AND port_id = ? AND survey_type_id = ?
                  AND status IN ('Pending Vessel','Pending Report')
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$imo, $clientId, $portId, $surveyTypeId]);
        } else {
            $stmt = $db->prepare("
                SELECT id, report_number, surveyor_id FROM surveys
                WHERE UPPER(vessel_name) = UPPER(?) AND client_id = ? AND port_id = ? AND survey_type_id = ?
                  AND status IN ('Pending Vessel','Pending Report')
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$vesselName, $clientId, $portId, $surveyTypeId]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $surveyId = (int)$row['id'];
        $surveyorIds = [];
        try {
            $ss = $db->prepare("SELECT surveyor_id FROM survey_surveyors WHERE survey_id = ? ORDER BY id ASC");
            $ss->execute([$surveyId]);
            $surveyorIds = array_map('intval', $ss->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) { /* junction table may not exist yet */ }
        if (empty($surveyorIds) && (int)$row['surveyor_id'] > 1) {
            $surveyorIds = [(int)$row['surveyor_id']];
        }

        return ['survey_id' => $surveyId, 'report_number' => (string)$row['report_number'], 'surveyor_ids' => $surveyorIds];
    } catch (Throwable $e) {
        error_log('bulkAssignFindOpenMatchingSurvey: ' . $e->getMessage());
        return null;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Row validation (does NOT write to the database)
// ─────────────────────────────────────────────────────────────────────────

/**
 * @param array $cells    raw row cells (col index => value) for this Excel row
 * @param array $colMap   canonical field => col index (from bulkAssignDetectColumnMap)
 * @param int   $rowNumber 1-based row number as it appears in the original Excel sheet
 */
function bulkAssignValidateRow(PDO $db, array $cells, array $colMap, int $rowNumber, $spreadsheet = null, ?string $sheetName = null, ?int $sheetRowOffset = null): array {
    $get = function (string $field) use ($cells, $colMap): string {
        if (!isset($colMap[$field])) return '';
        return trim((string)($cells[$colMap[$field]] ?? ''));
    };

    $vesselNameRaw = $get('vessel_name');
    $imoRaw = bulkAssignNormalizeImo($get('imo_number'));
    $surveyorRaw = $get('surveyor');
    $clientRaw = $get('client');
    $portRaw = $get('port');
    $surveyTypeRaw = $get('survey_type');
    $agentRaw = $get('agent_name');
    $remarksRaw = $get('remarks');

    $result = [
        'row_number'    => $rowNumber,
        'vessel_name'   => $vesselNameRaw,
        'imo_number'    => $imoRaw,
        'surveyor_label' => $surveyorRaw,
        'client_label'  => $clientRaw,
        'port_label'    => $portRaw,
        'survey_type_label' => $surveyTypeRaw,
        'assign_date_label' => $get('assign_date'),
        'remarks'       => $remarksRaw,
        'status'        => 'VALID',
        'message'       => '',
        'resolved'      => null,
    ];

    // Vessel identification
    $vesselName = $vesselNameRaw;
    if ($vesselName === '') {
        if ($imoRaw === '') {
            $result['status'] = 'MISSING DATA';
            $result['message'] = 'Required field missing: Vessel Name';
            return $result;
        }
        $vesselName = bulkAssignBackfillVesselNameByImo($db, $imoRaw);
        if ($vesselName === '') {
            $result['status'] = 'VESSEL NOT FOUND';
            $result['message'] = 'No Vessel Name given and IMO ' . $imoRaw . ' does not match any previous assignment.';
            return $result;
        }
        $result['vessel_name'] = $vesselName;
    }
    $vesselName = normalizeVesselName($vesselName);
    $result['vessel_name'] = $vesselName;

    // Client (required)
    if ($clientRaw === '') {
        $result['status'] = 'MISSING DATA';
        $result['message'] = 'Required field missing: Client';
        return $result;
    }
    $client = bulkAssignFindClient($db, $clientRaw);
    if (!$client) {
        $result['status'] = 'INVALID DATA';
        $result['message'] = 'Client "' . $clientRaw . '" was not found.';
        return $result;
    }

    // Port (required)
    if ($portRaw === '') {
        $result['status'] = 'MISSING DATA';
        $result['message'] = 'Required field missing: Port';
        return $result;
    }
    $port = bulkAssignFindPort($db, $portRaw);
    if (!$port) {
        $result['status'] = 'INVALID DATA';
        $result['message'] = 'Port "' . $portRaw . '" was not found.';
        return $result;
    }

    // Survey type (required)
    if ($surveyTypeRaw === '') {
        $result['status'] = 'MISSING DATA';
        $result['message'] = 'Required field missing: Survey Type';
        return $result;
    }
    $surveyType = bulkAssignFindSurveyType($db, $surveyTypeRaw);
    if (!$surveyType) {
        $result['status'] = 'INVALID DATA';
        $result['message'] = 'Survey Type "' . $surveyTypeRaw . '" was not found.';
        return $result;
    }

    // Surveyor (optional — a blank surveyor leaves the row unassigned, same
    // as the existing Client-submitted-vessel workflow already allows)
    $surveyorId = 0;
    $surveyorName = '';
    if ($surveyorRaw !== '') {
        $surveyor = bulkAssignFindSurveyor($db, $surveyorRaw);
        if (!$surveyor) {
            $result['status'] = 'SURVEYOR NOT FOUND';
            $result['message'] = 'Surveyor "' . $surveyorRaw . '" was not found or is not an active surveyor.';
            return $result;
        }
        $surveyorId = (int)$surveyor['id'];
        $surveyorName = (string)$surveyor['full_name'];
        $result['surveyor_label'] = $surveyorName;
    }

    // Assignment date (optional — defaults to now at insert time)
    $assignDate = null;
    $dateRaw = $get('assign_date');
    if ($dateRaw !== '') {
        $dateCell = null;
        if ($spreadsheet !== null && isset($colMap['assign_date']) && $sheetRowOffset !== null) {
            $dateCell = bulkAssignRawCell($spreadsheet, $sheetName, $sheetRowOffset, $colMap['assign_date']);
        }
        $assignDate = bulkAssignParseDate($dateCell ?? $dateRaw);
        if ($assignDate === null) {
            $result['status'] = 'INVALID DATA';
            $result['message'] = 'Assignment Date "' . $dateRaw . '" could not be understood.';
            return $result;
        }
    }

    // Duplicate / already-assigned detection against existing open surveys
    $existing = bulkAssignFindOpenMatchingSurvey($db, $vesselName, $imoRaw, (int)$client['id'], (int)$port['id'], (int)$surveyType['id']);
    if ($existing) {
        $sameSurveyor = $surveyorId > 0 && count($existing['surveyor_ids']) === 1 && $existing['surveyor_ids'][0] === $surveyorId;
        if ($sameSurveyor) {
            $result['status'] = 'DUPLICATE';
            $result['message'] = 'Already assigned to the same surveyor as report ' . $existing['report_number'] . '.';
        } elseif ($surveyorId > 0 && !empty($existing['surveyor_ids'])) {
            $result['status'] = 'ALREADY ASSIGNED TO OTHER SURVEYOR';
            $result['message'] = 'This vessel/client/port/survey-type combination is already assigned (report ' . $existing['report_number'] . ') to a different surveyor. Not overwritten — use Edit on that assignment if you need to change it.';
        } else {
            $result['status'] = 'ALREADY ASSIGNED';
            $result['message'] = 'An open assignment already exists for this vessel/client/port/survey-type (report ' . $existing['report_number'] . ').';
        }
        return $result;
    }

    $result['resolved'] = [
        'vessel_name'      => $vesselName,
        'imo_number'       => $imoRaw,
        'client_id'        => (int)$client['id'],
        'client_name'      => (string)$client['company_name'],
        'port_id'          => (int)$port['id'],
        'port_name'        => (string)$port['port_name'],
        'survey_type_id'   => (int)$surveyType['id'],
        'survey_type_name' => (string)$surveyType['type_name'],
        'surveyor_id'      => $surveyorId,
        'surveyor_name'    => $surveyorName,
        'agent_name'       => $agentRaw !== '' ? $agentRaw : (string)$client['company_name'],
        'remarks'          => $remarksRaw,
        'assign_date'      => $assignDate,
    ];
    return $result;
}

// ─────────────────────────────────────────────────────────────────────────
// Insert (mirrors assign_vessel.php's single-assign INSERT path)
// ─────────────────────────────────────────────────────────────────────────

/**
 * Inserts one validated+resolved row as a new survey, exactly the same way
 * the single Assign Vessel form does (same columns, same report-number
 * generation with retry, same survey_surveyors junction write, same
 * surveyor notification call). Returns ['ok'=>bool,'survey_id'=>int,
 * 'report_number'=>string,'error'=>string].
 */
function bulkAssignInsertRow(PDO $db, array $resolved, int $actorUserId, string $actorName, string $actorEmail): array {
    $vesselName = $resolved['vessel_name'];
    $imo = $resolved['imo_number'];
    $clientId = $resolved['client_id'];
    $agentName = mb_strtoupper($resolved['agent_name'], 'UTF-8');
    $portId = $resolved['port_id'];
    $surveyTypeId = $resolved['survey_type_id'];
    $surveyorId = $resolved['surveyor_id'];
    $remarks = $resolved['remarks'] !== '' ? mb_strtoupper($resolved['remarks'], 'UTF-8') : '';
    $assignDate = $resolved['assign_date']; // may be null -> NOW()

    $finalSurveyorId = $surveyorId > 0 ? $surveyorId : 1; // 1 = unassigned placeholder, matches single-assign form
    if ($remarks === '' && $surveyorId === 0) {
        $remarks = 'Bulk uploaded — awaiting surveyor assignment.';
    }

    $maxAttempts = 5;
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        try {
            $reportNumber = generateNextReportNumberForClient($db, $clientId);
            $hasImoColumn = true;
            try {
                $db->query("SHOW COLUMNS FROM surveys LIKE 'imo_number'")->fetch();
            } catch (Throwable $e) {
                $hasImoColumn = false;
            }

            $db->beginTransaction();

            if ($hasImoColumn && $imo !== '') {
                $stmt = $db->prepare("
                    INSERT INTO surveys
                    (vessel_name, imo_number, report_number, client_id, agent_name, surveyor_id, survey_type_id, survey_type_ids, port_id, assign_date, status, remarks, client_submitted)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending Vessel', ?, 0)
                ");
                $stmt->execute([$vesselName, $imo, $reportNumber, $clientId, $agentName, $finalSurveyorId, $surveyTypeId, (string)$surveyTypeId, $portId, $assignDate ?: date('Y-m-d H:i:s'), $remarks]);
            } else {
                $stmt = $db->prepare("
                    INSERT INTO surveys
                    (vessel_name, report_number, client_id, agent_name, surveyor_id, survey_type_id, survey_type_ids, port_id, assign_date, status, remarks, client_submitted)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending Vessel', ?, 0)
                ");
                $stmt->execute([$vesselName, $reportNumber, $clientId, $agentName, $finalSurveyorId, $surveyTypeId, (string)$surveyTypeId, $portId, $assignDate ?: date('Y-m-d H:i:s'), $remarks]);
            }

            $surveyId = (int)$db->lastInsertId();

            if ($surveyorId > 0) {
                try {
                    $db->prepare("INSERT IGNORE INTO survey_surveyors (survey_id, surveyor_id) VALUES (?, ?)")->execute([$surveyId, $surveyorId]);
                } catch (Throwable $sse) {
                    error_log('bulkAssignInsertRow survey_surveyors: ' . $sse->getMessage());
                }
            }

            $db->commit();

            // Notification — outside the transaction (best-effort, never rolls back a successful insert)
            if ($surveyorId > 0) {
                try {
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $base = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['PHP_SELF'] ?? ''))), '/');
                    $appUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base . '/vessel_detail.php?id=' . $surveyId;
                    $job = [
                        'vessel_name'       => $vesselName,
                        'report_number'     => $reportNumber,
                        'client_name'       => $resolved['client_name'],
                        'port_name'         => $resolved['port_name'],
                        'survey_types'      => $resolved['survey_type_name'],
                        'agent_name'        => $agentName,
                        'assign_date'       => date('d-m-Y', strtotime($assignDate ?: 'now')),
                        'remarks'           => $remarks,
                        'app_url'           => $appUrl,
                        'assigned_by_name'  => $actorName,
                        'assigned_by_email' => $actorEmail,
                    ];
                    notifySurveyorOfAssignment($db, $surveyorId, $job);
                    createNotification(
                        $db,
                        $surveyorId,
                        'New vessel assigned',
                        $actorName . ' assigned ' . $vesselName . ' (' . $reportNumber . ') to you via bulk upload.',
                        'assign',
                        'vessel_detail.php?id=' . $surveyId,
                        $actorUserId
                    );
                } catch (Throwable $ne) {
                    error_log('bulkAssignInsertRow notify: ' . $ne->getMessage());
                }
            }

            return ['ok' => true, 'survey_id' => $surveyId, 'report_number' => $reportNumber, 'error' => ''];
        } catch (PDOException $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            if ($e->getCode() == 23000 || strpos($e->getMessage(), 'Duplicate') !== false) {
                usleep(50000);
                continue; // retry with a fresh report number
            }
            error_log('bulkAssignInsertRow: ' . $e->getMessage());
            return ['ok' => false, 'survey_id' => 0, 'report_number' => '', 'error' => 'Database error while saving this row.'];
        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            error_log('bulkAssignInsertRow: ' . $e->getMessage());
            return ['ok' => false, 'survey_id' => 0, 'report_number' => '', 'error' => 'Unexpected error while saving this row.'];
        }
    }

    return ['ok' => false, 'survey_id' => 0, 'report_number' => '', 'error' => 'Could not generate a unique report number.'];
}

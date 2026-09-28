<?php
/**
 * Bulk Vessel Assignment — Step 1/2: upload (or re-parse on sheet change)
 * and return a full validation preview. Never writes to the surveys table.
 *
 * POST (multipart, first call):  excel_file, [sheet]
 * POST (form, sheet re-select):  batch_token, sheet
 */
ob_start();
ini_set('display_errors', '0');
error_reporting(0);
@set_time_limit(120);
@ini_set('memory_limit', '256M');

// 🌟 Same safety net export_vessels.php already uses: a PHP warning/notice
// (PhpSpreadsheet is known to trigger deprecation notices on newer PHP) or
// an uncaught fatal error would otherwise print raw text before our
// json_encode() output, corrupting the JSON — jQuery's dataType:'json' then
// fails the whole request with a generic "Upload failed" and no real reason
// shown anywhere. This guarantees the response is always valid JSON.
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        error_log('bulk_assign_preview fatal: ' . $error['message']);
        echo json_encode(['success' => false, 'message' => 'Server error while reading this file. Please try again or contact support.']);
    }
});

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../../includes/upload_validation.php';
require_once __DIR__ . '/../includes/bulk_assign_helpers.php';
checkAuth();
header('Content-Type: application/json');

if (($_SESSION['role'] ?? '') !== 'Admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit;
}

$db = getDB();
ensureBulkAssignmentTables($db);
$userId = (int)($_SESSION['user_id'] ?? 0);
$actorName = (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin');

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    echo json_encode(['success' => false, 'message' => 'Excel library is not installed on the server.']);
    exit;
}
require_once $autoload;
if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
    echo json_encode(['success' => false, 'message' => 'Excel library is not available on the server.']);
    exit;
}

$batchToken = trim((string)($_POST['batch_token'] ?? ''));
$requestedSheet = isset($_POST['sheet']) ? trim((string)$_POST['sheet']) : null;
$storedPath = '';
$originalFilename = '';
$batchId = 0;

try {
    if ($batchToken !== '') {
        // Re-select a different sheet on an already-uploaded file.
        $batch = bulkAssignGetBatchByToken($db, $batchToken, $userId);
        if (!$batch) {
            echo json_encode(['success' => false, 'message' => 'Upload session not found. Please upload the file again.']);
            exit;
        }
        $storedPath = $batch['stored_path'];
        $originalFilename = $batch['original_filename'];
        $batchId = (int)$batch['id'];
        if (!is_file($storedPath)) {
            echo json_encode(['success' => false, 'message' => 'The uploaded file is no longer available. Please upload it again.']);
            exit;
        }
    } else {
        if (empty($_FILES['excel_file']) || ($_FILES['excel_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            echo json_encode(['success' => false, 'message' => 'Please choose an Excel or CSV file to upload.']);
            exit;
        }
        $file = $_FILES['excel_file'];
        $originalFilename = basename((string)$file['name']);
        $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        if (!in_array($ext, bulkAssignAllowedExtensions(), true)) {
            echo json_encode(['success' => false, 'message' => 'Only .xlsx, .xls or .csv files are allowed.']);
            exit;
        }
        $mimeSet = $ext === 'csv' ? ['text/csv', 'text/plain', 'application/csv', 'text/x-csv'] : UPLOAD_MIMES_XLSX;
        $err = upload_validate($file, $mimeSet, 15 * 1024 * 1024);
        if ($err !== '') {
            echo json_encode(['success' => false, 'message' => $err]);
            exit;
        }

        $tmpDir = __DIR__ . '/../uploads/bulk_tmp/';
        if (!is_dir($tmpDir)) { @mkdir($tmpDir, 0755, true); }
        $batchToken = bin2hex(random_bytes(20));
        $storedPath = $tmpDir . $batchToken . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $storedPath)) {
            echo json_encode(['success' => false, 'message' => 'Could not save the uploaded file.']);
            exit;
        }
        @chmod($storedPath, 0640);

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $batchId = bulkAssignCreateBatch($db, $userId, $batchToken, $originalFilename, $storedPath, $ip);
    }

    $ext = strtolower(pathinfo($storedPath, PATHINFO_EXTENSION));
    $spreadsheet = bulkAssignLoadSpreadsheet($storedPath, $ext);
    $sheetNames = bulkAssignSheetNames($spreadsheet);
    $selectedSheet = $requestedSheet && in_array($requestedSheet, $sheetNames, true) ? $requestedSheet : $sheetNames[0];

    $allRows = bulkAssignReadSheetRows($spreadsheet, $selectedSheet);
    if (empty($allRows)) {
        echo json_encode(['success' => false, 'message' => 'The selected sheet has no data.']);
        exit;
    }

    $headerCells = $allRows[0];
    $detect = bulkAssignDetectColumnMap($headerCells);
    $dataRows = array_slice($allRows, 1);
    $rowCount = count($dataRows);

    $mappingPreview = [];
    foreach ($headerCells as $idx => $h) {
        $h = trim((string)$h);
        if ($h === '') continue;
        $field = null;
        foreach ($detect['map'] as $f => $colIdx) {
            if ($colIdx === $idx) { $field = $f; break; }
        }
        $mappingPreview[] = [
            'column'  => $h,
            'mapped'  => $field !== null,
            'field'   => $field ? ucfirst(str_replace('_', ' ', $field)) : null,
        ];
    }

    $response = [
        'success'          => true,
        'batch_token'      => $batchToken,
        'filename'         => $originalFilename,
        'sheet_names'      => $sheetNames,
        'selected_sheet'   => $selectedSheet,
        'row_count'        => $rowCount,
        'mapping'          => $mappingPreview,
        'missing_required' => $detect['missing_required'],
        'rows'             => [],
        'summary'          => ['valid' => 0, 'duplicate' => 0, 'already_assigned' => 0, 'errors' => 0],
    ];

    if (!empty($detect['missing_required'])) {
        bulkAssignUpdateBatch($db, $batchId, ['sheet_name' => $selectedSheet, 'total_rows' => $rowCount, 'status' => 'uploaded']);
        echo json_encode($response);
        exit;
    }

    // Cap preview size to keep the request fast/safe for very large files.
    $maxRows = 3000;
    $capped = $rowCount > $maxRows;
    $validated = [];
    $summary = ['valid' => 0, 'duplicate' => 0, 'already_assigned' => 0, 'errors' => 0];

    foreach ($dataRows as $i => $cells) {
        if ($i >= $maxRows) break;
        $excelRowNumber = $i + 2; // +1 header row, +1 for 1-based numbering
        $sheetRowNumber = $i + 2;
        $row = bulkAssignValidateRow($db, $cells, $detect['map'], $excelRowNumber, $spreadsheet, $selectedSheet, $sheetRowNumber);
        if ($row['status'] === 'VALID') {
            $summary['valid']++;
        } elseif ($row['status'] === 'DUPLICATE') {
            $summary['duplicate']++;
        } elseif (in_array($row['status'], ['ALREADY ASSIGNED', 'ALREADY ASSIGNED TO OTHER SURVEYOR'], true)) {
            $summary['already_assigned']++;
        } else {
            $summary['errors']++;
        }
        unset($row['resolved']); // don't leak resolved IDs to the client; confirm step re-resolves fresh
        $validated[] = $row;
    }

    $response['rows'] = $validated;
    $response['summary'] = $summary;
    $response['capped'] = $capped;

    bulkAssignUpdateBatch($db, $batchId, [
        'sheet_name'    => $selectedSheet,
        'total_rows'    => $rowCount,
        'valid_rows'    => $summary['valid'],
        'duplicate_rows' => $summary['duplicate'],
        'already_assigned_rows' => $summary['already_assigned'],
        'error_rows'    => $summary['errors'],
        'status'        => 'previewed',
    ]);

    echo json_encode($response);
} catch (Throwable $e) {
    error_log('bulk_assign_preview: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not read this file. Please check it is a valid Excel/CSV file and try again.']);
}

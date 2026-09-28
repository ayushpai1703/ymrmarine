<?php
/**
 * Bulk Vessel Assignment — Step 3: process one chunk of rows.
 * Called repeatedly by the page's JS (offset += limit) so the browser can
 * show "Processing X / Y" and never freezes on a large file. Every row is
 * re-validated fresh right before insert (data may have changed since the
 * preview was shown a few seconds/minutes earlier), and only rows still
 * VALID at that moment are inserted.
 *
 * POST: batch_token, offset, limit
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/bulk_assign_helpers.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/report_number.php';
require_once __DIR__ . '/../includes/notifications.php';
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
$actorEmail = '';
try {
    $ast = $db->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
    $ast->execute([$userId]);
    $actorEmail = trim((string)($ast->fetchColumn() ?: ''));
} catch (Throwable $e) { /* ignore */ }

$batchToken = trim((string)($_POST['batch_token'] ?? ''));
$offset = max(0, (int)($_POST['offset'] ?? 0));
$limit = max(1, min(50, (int)($_POST['limit'] ?? 15)));

try {
    $batch = bulkAssignGetBatchByToken($db, $batchToken, $userId);
    if (!$batch) {
        echo json_encode(['success' => false, 'message' => 'Upload session not found. Please upload the file again.']);
        exit;
    }
    if (!is_file($batch['stored_path'])) {
        echo json_encode(['success' => false, 'message' => 'The uploaded file is no longer available. Please upload it again.']);
        exit;
    }

    $autoload = __DIR__ . '/../vendor/autoload.php';
    require_once $autoload;

    $ext = strtolower(pathinfo($batch['stored_path'], PATHINFO_EXTENSION));
    $spreadsheet = bulkAssignLoadSpreadsheet($batch['stored_path'], $ext);
    $sheetName = $batch['sheet_name'];
    $allRows = bulkAssignReadSheetRows($spreadsheet, $sheetName);
    $headerCells = $allRows[0] ?? [];
    $detect = bulkAssignDetectColumnMap($headerCells);
    $dataRows = array_slice($allRows, 1);
    $totalRows = count($dataRows);

    if ($offset === 0) {
        bulkAssignUpdateBatch($db, (int)$batch['id'], ['status' => 'processing']);
    }

    $chunk = array_slice($dataRows, $offset, $limit, true);
    $results = [];
    $chunkInserted = 0;
    $chunkDuplicate = 0;
    $chunkAlreadyAssigned = 0;
    $chunkErrors = 0;

    foreach ($chunk as $i => $cells) {
        $excelRowNumber = $i + 2;
        $sheetRowNumber = $i + 2;
        $row = bulkAssignValidateRow($db, $cells, $detect['map'], $excelRowNumber, $spreadsheet, $sheetName, $sheetRowNumber);

        if ($row['status'] === 'VALID' && $row['resolved']) {
            $ins = bulkAssignInsertRow($db, $row['resolved'], $userId, $actorName, $actorEmail);
            if ($ins['ok']) {
                $row['status'] = 'ASSIGNED';
                $row['message'] = 'Assigned — report ' . $ins['report_number'];
                $row['survey_id'] = $ins['survey_id'];
                $row['report_number'] = $ins['report_number'];
                $chunkInserted++;
            } else {
                $row['status'] = 'ERROR';
                $row['message'] = $ins['error'] ?: 'Could not save this row.';
                $chunkErrors++;
            }
        } elseif ($row['status'] === 'DUPLICATE') {
            $chunkDuplicate++;
        } elseif (in_array($row['status'], ['ALREADY ASSIGNED', 'ALREADY ASSIGNED TO OTHER SURVEYOR'], true)) {
            $chunkAlreadyAssigned++;
        } else {
            $chunkErrors++;
        }

        unset($row['resolved']);
        bulkAssignSaveRow($db, (int)$batch['id'], $row);
        $results[] = $row;
    }

    $processedSoFar = min($offset + count($chunk), $totalRows);
    $done = $processedSoFar >= $totalRows;

    $db->prepare("UPDATE bulk_assignment_batches SET
        inserted_rows = inserted_rows + ?,
        duplicate_rows = duplicate_rows + ?,
        already_assigned_rows = already_assigned_rows + ?,
        error_rows = error_rows + ?
        WHERE id = ?")->execute([$chunkInserted, $chunkDuplicate, $chunkAlreadyAssigned, $chunkErrors, (int)$batch['id']]);

    if ($done) {
        bulkAssignUpdateBatch($db, (int)$batch['id'], ['status' => 'completed', 'completed_at' => date('Y-m-d H:i:s')]);

        // One in-app summary notification to admins (existing notifyAllAdmins
        // helper) — not per-row, so this never duplicates the per-surveyor
        // assignment emails already sent above.
        try {
            $final = $db->prepare('SELECT inserted_rows, duplicate_rows, already_assigned_rows, error_rows, original_filename FROM bulk_assignment_batches WHERE id = ?');
            $final->execute([(int)$batch['id']]);
            $fRow = $final->fetch(PDO::FETCH_ASSOC);
            if ($fRow) {
                notifyAllAdmins(
                    $db,
                    'Bulk vessel assignment completed',
                    $actorName . ' bulk-uploaded "' . $batch['original_filename'] . '" — ' . (int)$fRow['inserted_rows'] . ' assigned, ' .
                        ((int)$fRow['duplicate_rows'] + (int)$fRow['already_assigned_rows']) . ' already assigned, ' . (int)$fRow['error_rows'] . ' failed.',
                    'assign',
                    'bulk_assignment_history.php',
                    $userId
                );
            }
        } catch (Throwable $ne) {
            error_log('bulk_assign_confirm summary notify: ' . $ne->getMessage());
        }
    }

    echo json_encode([
        'success'   => true,
        'processed' => $processedSoFar,
        'total'     => $totalRows,
        'done'      => $done,
        'rows'      => $results,
    ]);
} catch (Throwable $e) {
    error_log('bulk_assign_confirm: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Something went wrong while processing this batch. Please try again.']);
}

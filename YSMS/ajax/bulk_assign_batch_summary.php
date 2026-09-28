<?php
/**
 * Small helper endpoint: returns one bulk-assignment batch's current counters
 * (used by bulk_assign_vessels.php to render the final result screen, and by
 * bulk_assignment_history.php to expand a batch's row details).
 *
 * GET: batch_token  (or) batch_id
 */
ob_start();
ini_set('display_errors', '0');
error_reporting(0);

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        error_log('bulk_assign_batch_summary fatal: ' . $error['message']);
        echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
    }
});

require_once __DIR__ . '/../config/config.php';
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

$batchToken = trim((string)($_GET['batch_token'] ?? ''));
$batchId = (int)($_GET['batch_id'] ?? 0);

if ($batchToken !== '') {
    $batch = bulkAssignGetBatchByToken($db, $batchToken, $userId);
} elseif ($batchId > 0) {
    $stmt = $db->prepare("SELECT * FROM bulk_assignment_batches WHERE id = ? AND uploaded_by = ? LIMIT 1");
    $stmt->execute([$batchId, $userId]);
    $batch = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} else {
    $batch = null;
}

if (!$batch) {
    echo json_encode(['success' => false, 'message' => 'Batch not found.']);
    exit;
}

$includeRows = isset($_GET['with_rows']) && $_GET['with_rows'] === '1';
$response = ['success' => true, 'batch' => $batch];
if ($includeRows) {
    $response['rows'] = bulkAssignGetBatchRows($db, (int)$batch['id']);
}
echo json_encode($response);

<?php
/**
 * Bulk Vessel Assignment — downloads an .xlsx containing only the
 * failed/duplicate/already-assigned rows from one batch. Never touches the
 * originally uploaded file.
 *
 * GET: batch_id
 */
ob_start();
ini_set('display_errors', '0');
error_reporting(0);
@set_time_limit(120);
@ini_set('memory_limit', '256M');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/bulk_assign_helpers.php';
require_once __DIR__ . '/../includes/excel_export_helpers.php';
checkAuth();

if (($_SESSION['role'] ?? '') !== 'Admin') {
    http_response_code(403);
    echo 'Not authorized.';
    exit;
}

$db = getDB();
ensureBulkAssignmentTables($db);
$userId = (int)($_SESSION['user_id'] ?? 0);
$batchId = (int)($_GET['batch_id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM bulk_assignment_batches WHERE id = ? AND uploaded_by = ? LIMIT 1");
$stmt->execute([$batchId, $userId]);
$batch = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$batch) {
    http_response_code(404);
    echo 'Batch not found.';
    exit;
}

$rows = bulkAssignGetBatchRows($db, $batchId);
$errorRows = array_filter($rows, function ($r) {
    return !in_array($r['status'], ['VALID', 'ASSIGNED'], true);
});

$autoload = __DIR__ . '/../vendor/autoload.php';
require_once $autoload;

$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Errors');

$headers = ['Row', 'Vessel Name', 'IMO Number', 'Surveyor', 'Client', 'Status', 'Error Message'];
$sheet->fromArray([$headers], null, 'A1');
$headerStyle = $sheet->getStyle('A1:G1');
$headerStyle->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$headerStyle->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('4B2FD6');

$r = 2;
foreach ($errorRows as $row) {
    $sheet->fromArray([[
        (int)$row['excel_row_number'],
        $row['vessel_name'] ?? '',
        $row['imo_number'] ?? '',
        $row['surveyor_label'] ?? '',
        $row['client_label'] ?? '',
        $row['status'],
        $row['message'] ?? '',
    ]], null, 'A' . $r);
    $r++;
}

foreach (['A','B','C','D','E','F','G'] as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

$baseName = exportFileName('Bulk Assignment Errors ' . $batchId . ' as on ' . date('d-m-Y'));
while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $baseName . '.xlsx"');
header('Content-Transfer-Encoding: binary');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
header('Expires: 0');
$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
$writer->save('php://output');
exit;

<?php
/**
 * Bulk Vessel Assignment — downloadable starter Excel template.
 * Not mandatory (the upload step accepts any existing Excel/CSV via flexible
 * column detection — see bulkAssignFieldAliases() in bulk_assign_helpers.php)
 * but offered as a convenience so admins who don't already have a working
 * sheet get one with the exact column names the detector recognizes,
 * pre-filled with one real example row from this system's own data.
 */
ob_start();
ini_set('display_errors', '0');
error_reporting(0);
@set_time_limit(60);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/bulk_assign_helpers.php';
checkAuth();

if (($_SESSION['role'] ?? '') !== 'Admin') {
    http_response_code(403);
    echo 'Not authorized.';
    exit;
}

$db = getDB();

// Real example values from this system, when available, so the sample row
// upload-tests cleanly instead of needing edits first.
$exampleClient = (string)($db->query("SELECT company_name FROM clients ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 'Northern Shipping');
$examplePort = (string)($db->query("SELECT port_name FROM ports ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 'Visakhapatnam Port');
$exampleType = (string)($db->query("SELECT type_name FROM survey_types ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 'Bunker Survey');
$exampleSurveyorRow = $db->query("SELECT full_name, email FROM users WHERE role_id = 2 AND status = 'Active' AND email IS NOT NULL AND email != '' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$exampleSurveyor = $exampleSurveyorRow ? (string)$exampleSurveyorRow['email'] : '';

$autoload = __DIR__ . '/../vendor/autoload.php';
require_once $autoload;

$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

// ── Sheet 1: Vessel Data ────────────────────────────────────────────────
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Vessel Data');

$headers = ['Vessel Name', 'IMO Number', 'Client Name', 'Agent Name', 'Port', 'Survey Type', 'Surveyor Email', 'Assignment Date', 'Remarks'];
$sheet->fromArray([$headers], null, 'A1');
$headerStyle = $sheet->getStyle('A1:I1');
$headerStyle->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$headerStyle->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('4B2FD6');
$headerStyle->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
$sheet->getRowDimension(1)->setRowHeight(22);

$sheet->fromArray([[
    'MV Example Vessel',
    '9876543',
    $exampleClient,
    $exampleClient,
    $examplePort,
    $exampleType,
    $exampleSurveyor,
    date('d-m-Y'),
    'Optional notes for the surveyor',
]], null, 'A2');
$sheet->getStyle('A2:I2')->getFont()->setItalic(true)->getColor()->setRGB('64748B');

foreach (['A','B','C','D','E','F','G','H','I'] as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}
$sheet->freezePane('A2');

// ── Sheet 2: Instructions ────────────────────────────────────────────────
$info = $spreadsheet->createSheet();
$info->setTitle('Instructions');
$rows = [
    ['Column', 'Required?', 'Notes'],
    ['Vessel Name', 'Yes (or IMO Number)', 'Free text — if left blank but IMO Number matches a vessel this system has seen before, the name is filled in automatically.'],
    ['IMO Number', 'No', 'Digits only; "IMO 1234567" or "1234567" both work.'],
    ['Client Name', 'Yes', 'Must match an existing client in this system (by name, short code, or email). Add new clients from Admin Controls first.'],
    ['Agent Name', 'No', 'Defaults to the Client Name if left blank.'],
    ['Port', 'Yes', 'Must match an existing port. Add new ports from Admin Controls first.'],
    ['Survey Type', 'Yes', 'Must match an existing survey type. Add new ones from Admin Controls first.'],
    ['Surveyor Email', 'No', 'Can also be the surveyor\'s exact full name or numeric ID instead of email. Leave blank to leave the vessel unassigned (an Admin can assign one later).'],
    ['Assignment Date', 'No', 'Accepts DD-MM-YYYY, YYYY-MM-DD, or a real Excel date cell. Defaults to today if left blank.'],
    ['Remarks', 'No', 'Free text.'],
];
$info->fromArray($rows, null, 'A1');
$info->getStyle('A1:C1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$info->getStyle('A1:C1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('4B2FD6');
foreach ($info->getRowIterator(2) as $rowIt) {
    foreach ($rowIt->getCellIterator() as $cell) {
        $cell->getStyle()->getAlignment()->setWrapText(true)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
    }
}
$info->getColumnDimension('A')->setWidth(18);
$info->getColumnDimension('B')->setWidth(18);
$info->getColumnDimension('C')->setWidth(80);
$info->getStyle('A1:C10')->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)->getColor()->setRGB('E2E8F0');
$info->freezePane('A2');

$note = $info->getCell('A12');
$note->setValue('Column names/order don\'t matter — headers are matched by meaning (e.g. "Vessel", "Ship Name" and "Vessel Name" all work), so you can also upload your own existing sheet as-is instead of this template.');
$info->mergeCells('A12:C13');
$info->getStyle('A12')->getAlignment()->setWrapText(true)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
$info->getStyle('A12')->getFont()->setItalic(true)->getColor()->setRGB('64748B');

$spreadsheet->setActiveSheetIndex(0);

$baseName = 'Bulk_Vessel_Assignment_Template';
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

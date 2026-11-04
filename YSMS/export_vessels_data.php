<?php
/**
 * Vessels Data → Excel. Same rows the grid shows (includes/vessels_data.php visibility):
 *   ?<filters>   the list with its search / filters      ?ids=1,2,3   the selected vessels
 *   (none)       every vessel this user can see           ?template=1  empty import template
 * Columns: every vessel field incl. Record ID, so the file can be edited and imported back
 * (Import updates existing records only; read-only columns such as Status and Recovery are ignored there).
 */
ob_start();
ini_set('display_errors', '0');
require 'vendor/autoload.php';
require_once 'config/config.php';
require_once __DIR__ . '/includes/vessels_data.php';
checkAuth();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

if (!vdCanView()) { http_response_code(403); exit('You do not have access to Vessels Data.'); }
$db = getDB();

$headers = ['Record ID', 'Vessel Name', 'IMO Number', 'Report Number', 'Client', 'Agent', 'Survey Type', 'Port', 'Country', 'Surveyor',
    'Assigned Date', 'Appointment Date', 'Survey Completed Date', 'Report Uploaded Date', 'Status', 'Due Days', 'Overdue Days',
    'Total Recovery (MT)', 'VLSFO Recovery (MT)', 'LSMGO Recovery (MT)', 'HSFO Recovery (MT)', 'Remarks', 'Notes', 'Live Status',
    'Status Updated', 'Status Updated By'];
$template = !empty($_GET['template']);
$rows = [];
if (!$template) {
    [, $where, $params] = vdFilters($db, $_GET);
    $st = $db->prepare(VD_SELECT . VD_FROM . " WHERE $where ORDER BY " . vdOrderSql($db, json_decode((string)($_GET['sort'] ?? '[]'), true)) . ' LIMIT 20000');
    $st->execute($params);
    $raw = $st->fetchAll(PDO::FETCH_ASSOC);
    $sv = vdSurveyorIds($db, array_column($raw, 'id'));
    $dmy = fn($v, bool $t = false) => $v !== '' ? date($t ? 'd-m-Y H:i' : 'd-m-Y', strtotime($v)) : '';
    foreach ($raw as $r) {
        $d = vdRow($db, $r, $sv);
        $rec = fn($v) => $d['na'] ? RECOVERY_NA_LABEL : recoverySigned($v, $d['onhire']);
        $rows[] = [$d['id'], $d['vessel'], $d['imo'], $d['report_no'], $d['client'], $d['agent'], $d['type'], $d['port'], $d['country'], $d['surveyor'],
            $dmy($d['assigned'], true), $dmy($d['appointment']), $dmy($d['completed']), $dmy($d['report_date']), $d['status'], $d['due'], $d['overdue'],
            $rec($d['recovery']), $rec($d['vlsfo']), $rec($d['lsmgo']), $rec($d['hsfo']), $d['remarks'], $d['notes'], $d['live_status'],
            $dmy($d['updated_at'], true), $d['updated_by']];
    }
}

$book = new Spreadsheet();
$sheet = $book->getActiveSheet();
$sheet->setTitle($template ? 'Import' : 'Vessels Data');
$sheet->fromArray($headers, null, 'A1');
$last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
$hs = $sheet->getStyle("A1:{$last}1");
$hs->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$hs->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0F2B5B');
$r = 2;
foreach ($rows as $row) {
    foreach ($row as $i => $v) {
        $coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1) . $r;
        if (is_int($v) || is_float($v)) $sheet->setCellValue($coord, $v);
        else $sheet->setCellValueExplicit($coord, (string)$v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    }
    $r++;
}
foreach (range(1, count($headers)) as $i) {
    $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i))->setWidth(in_array($i, [2, 5, 6, 7, 10, 22, 23], true) ? 28 : 16);
}
if ($r > 2) foreach (['R', 'S', 'T', 'U'] as $c) $sheet->getStyle($c . '2:' . $c . ($r - 1))->getNumberFormat()->setFormatCode('#,##0.000');
$sheet->freezePane('C2');
if ($rows) $sheet->setAutoFilter("A1:{$last}" . ($r - 1));

if ($template) {
    $help = $book->createSheet();
    $help->setTitle('How to import');
    $lines = [
        ['Vessels Data — import'],
        ['Import updates EXISTING vessels, matched by Record ID. It never adds new vessels (use Assign Vessel / Bulk Assign) and never deletes anything.'],
        ['Easiest: Export the vessels from Vessels Data, change the cells, and import that file.'],
        ['Keep the column names as they are. Leave a cell blank to keep the current value.'],
        ['Imported: Vessel Name, IMO Number, Report Number, Client, Agent, Survey Type, Port, Surveyor, Assigned / Appointment / Survey Completed / Report Uploaded Date, Remarks, Notes.'],
        ['Ignored: Status, Due / Overdue Days, Recovery, Country, Live Status, Status Updated (change Status in the grid; recovery comes from the Calculation Sheet).'],
        ['Client, Port, Survey Type and Surveyor must match the names in YSMS exactly. Several survey types or surveyors: separate with " + ". "' . VD_OUTSOURCING_LABEL . '" for no surveyor.'],
        ['Dates: dd-mm-yyyy (Assigned Date may add a time: dd-mm-yyyy hh:mm).'],
        ['After uploading you see a preview of every change and every row that cannot be imported; nothing is saved until you confirm.'],
    ];
    $help->fromArray($lines, null, 'A1');
    $help->getStyle('A1')->getFont()->setBold(true)->setSize(13);
    $help->getColumnDimension('A')->setWidth(150);
    $book->setActiveSheetIndex(0);
}

$name = $template ? 'Vessels Data - Import Template.xlsx' : 'Vessels Data ' . date('d-m-Y') . (!empty($_GET['ids']) ? ' (selected)' : '') . '.xlsx';
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Cache-Control: private, no-store');
(new Xlsx($book))->save('php://output');
exit;

<?php
/**
 * Invoices → Download: the list on screen (pending or completed view, same search / filters /
 * order, every page) as an Excel file. Admin only. Reads the invoices table and the survey
 * details it points to; nothing is written.
 */
ob_start();
ini_set('display_errors', '0');

require 'vendor/autoload.php';
require_once 'config/config.php';
require_once __DIR__ . '/includes/list_filters.php';
require_once __DIR__ . '/includes/invoice_helpers.php';
require_once __DIR__ . '/includes/excel_export_helpers.php';
checkAuth();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

if (!invoiceCanManage()) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Only Admin can download invoices.');
}

try {
    @set_time_limit(120);
    $db = getDB();
    invoiceEnsureTable($db);
    [$f, $whereSql, $params] = invoiceListFilters($_GET);
    $st = $db->prepare(invoiceRowSql() . " WHERE $whereSql ORDER BY " . invoiceListOrderSql());
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $title = $f['view'] === 'completed' ? 'Completed Invoices' : 'Pending Invoices';
    $book = new Spreadsheet();
    $sheet = $book->getActiveSheet();
    $sheet->setTitle($title);
    $headers = ['S.No', 'Vessel', 'Report No', 'Client Name', 'Survey Type', 'Port Name', 'Invoice Status', 'Survey Completed Date', 'Invoice No', 'Invoice Date', 'Amount', 'Invoice PDF'];
    $sheet->fromArray($headers, null, 'A1');
    $r = 2;
    foreach ($rows as $i => $row) {
        $done = !empty($row['survey_completed_date']) && strpos((string)$row['survey_completed_date'], '0000') !== 0;
        $sheet->fromArray([
            $i + 1,
            (string)($row['s_vessel_name'] ?? $row['vessel_name'] ?? ''),
            (string)($row['s_report_number'] ?? $row['report_number'] ?? ''),
            (string)($row['company_name'] ?? ''),
            !empty($row['sid']) ? getCombinedSurveyTypeNames($db, $row['survey_type_ids'] ?? '', $row['type_name'] ?? '') : '',
            (string)($row['port_name'] ?? ''),
            (string)$row['invoice_status'],
            $done ? date('d-m-Y', strtotime($row['survey_completed_date'])) : '',
            (string)($row['invoice_no'] ?? ''),
            !empty($row['invoice_date']) ? date('d-m-Y', strtotime($row['invoice_date'])) : '',
            $row['invoice_amount'] !== null ? (float)$row['invoice_amount'] : '',
            invoiceFileAbs($row['file_path'] ?? null) ? 'Uploaded' : '',
        ], null, 'A' . $r);
        $r++;
    }
    $last = 'L' . max(1, $r - 1);
    $sheet->getStyle('A1:L1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $sheet->getStyle('A1:L1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('4B2FD6');
    $sheet->getStyle('A1:' . $last)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
    $sheet->getStyle('K2:K' . max(2, $r - 1))->getNumberFormat()->setFormatCode('#,##0.00');
    foreach (range('A', 'L') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
    $sheet->freezePane('A2');

    $name = buildExportFileBaseName($title, [
        ['label' => 'status', 'value' => $f['status']],
        ['label' => 'type', 'value' => $f['type']],
        ['label' => 'in', 'value' => $f['month'] !== '' ? date('F Y', strtotime($f['month'] . '-01')) : ''],
    ]) . '.xlsx';
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    (new Xlsx($book))->save('php://output');
    exit;
} catch (Throwable $e) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); }
    error_log('export_invoices: ' . $e->getMessage());
    echo 'Something went wrong while generating this export. Please try again.';
    exit;
}

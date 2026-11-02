<?php
/**
 * Invoice Airtable → Export (Excel). Admin only; reads only.
 *   scope=all       every invoice document
 *   scope=filtered  the rows matching the page's search / filters (same parameters as the list)
 *   scope=selected  keys=<id>-<kind>,… (the ticked rows)
 *   scope=payments  payment history of the filtered rows (keys=… limits it to the ticked rows)
 * Sort follows the grid (sort=JSON). Amounts are the database values (DECIMAL).
 */
ob_start();
ini_set('display_errors', '0');

require 'vendor/autoload.php';
require_once 'config/config.php';
require_once __DIR__ . '/includes/invoice_ledger.php';
require_once __DIR__ . '/includes/excel_export_helpers.php';
checkAuth();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

if (!ledgerCanManage()) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Only Admin can export invoice payments.');
}

try {
    @set_time_limit(180);
    $db = getDB();
    ledgerEnsureTables($db);
    $scope = in_array($_GET['scope'] ?? '', ['all', 'filtered', 'selected', 'payments'], true) ? $_GET['scope'] : 'filtered';
    $base = ledgerBaseSql($db, date('Y-m-d'));
    if ($scope === 'all') {
        $where = '1=1'; $params = [];
    } else {
        [, $where, $params] = ledgerFilters($_GET);
    }
    $keys = [];
    foreach (explode(',', (string)($_GET['keys'] ?? '')) as $k) if ($p = ledgerParseKey(trim($k))) $keys[] = $p[0] . '-' . $p[1];
    $keys = array_slice(array_unique($keys), 0, 5000);
    if ($scope === 'selected' || ($scope === 'payments' && $keys)) {
        if (!$keys) throw new InvalidArgumentException('no rows');
        $where = 'x.doc_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')';
        $params = $keys;
    }
    $order = ledgerOrderSql(json_decode((string)($_GET['sort'] ?? '[]'), true));
    $book = new Spreadsheet();
    $sheet = $book->getActiveSheet();
    $d = fn($v) => $v ? date('d-m-Y', strtotime($v)) : '';

    if ($scope === 'payments') {
        $title = 'Invoice Payments';
        $st = $db->prepare("SELECT x.doc_no, x.client_name, x.vessel_name, x.amount AS inv_amount, p.* FROM $base
            JOIN invoice_payments p ON p.invoice_id = x.invoice_id AND p.kind = x.kind
            WHERE $where ORDER BY $order, p.payment_date, p.id");
        $st->execute($params);
        $headers = ['S.No', 'Invoice Number', 'Client', 'Vessel', 'Invoice Amount', 'Payment Date', 'Amount Received', 'Payment Mode', 'Payment Reference', 'Transaction ID', 'Status', 'Remarks', 'Receipt', 'Recorded At'];
        $sheet->fromArray($headers, null, 'A1');
        $r = 2;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i => $row) {
            $sheet->fromArray([$i + 1, $row['doc_no'], $row['client_name'], $row['vessel_name'], $row['inv_amount'] !== null ? (float)$row['inv_amount'] : '',
                $d($row['payment_date']), (float)$row['amount'], $row['payment_mode'], (string)$row['reference'], (string)$row['transaction_id'],
                $row['status'] === 'void' ? 'Void: ' . $row['void_reason'] : 'Success', (string)$row['remarks'], $row['receipt_name'] ? 'Yes' : '', $row['created_at']], null, 'A' . $r, true);
            $r++;
        }
        $moneyCols = ['E', 'G'];
    } else {
        $title = $scope === 'all' ? 'All Invoices' : ($scope === 'selected' ? 'Selected Invoices' : 'Filtered Invoices');
        $st = $db->prepare("SELECT x.* FROM $base WHERE $where ORDER BY $order");
        $st->execute($params);
        $headers = ['S.No', 'Invoice Number', 'Type', 'Invoice Date', 'Client Name', 'Vessel Name', 'Port', 'Survey Reference', 'Invoice Amount', 'Amount Received',
            'Outstanding Amount', 'Payment Due Date', 'Last Payment Date', 'Payment Status', 'Payments', 'Invoice Document', 'Remarks'];
        $sheet->fromArray($headers, null, 'A1');
        $r = 2;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i => $row) {
            $sheet->fromArray([$i + 1, $row['doc_no'], $row['kind'] === 'recovery' ? 'Recovery invoice' : 'Vessel invoice', $d($row['doc_date']), (string)$row['client_name'],
                (string)$row['vessel_name'], (string)$row['port_name'], (string)$row['survey_ref'],
                $row['amount'] !== null ? (float)$row['amount'] : '', (float)$row['received'], $row['outstanding'] !== null ? (float)$row['outstanding'] : '',
                $d($row['due_date']), $d($row['last_paid']), $row['pay_status'], (int)$row['payment_count'],
                invoiceFileAbs($row['doc_path']) ? (string)$row['doc_name'] : '', (string)$row['remarks']], null, 'A' . $r, true);
            $r++;
        }
        $moneyCols = ['I', 'J', 'K'];
    }
    $lastCol = chr(ord('A') + count($headers) - 1);
    $last = $lastCol . max(1, $r - 1);
    $sheet->setTitle(mb_substr($title, 0, 31));
    $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $sheet->getStyle("A1:{$lastCol}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('4B2FD6');
    $sheet->getStyle('A1:' . $last)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
    foreach ($moneyCols as $c) $sheet->getStyle($c . '2:' . $c . max(2, $r - 1))->getNumberFormat()->setFormatCode('[$$-409]#,##0.00');
    foreach (range('A', $lastCol) as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
    $sheet->freezePane('A2');

    $name = buildExportFileBaseName($title, []) . '.xlsx';
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    (new Xlsx($book))->save('php://output');
    exit;
} catch (InvalidArgumentException $e) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code(422);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Select at least one invoice to export.';
    exit;
} catch (Throwable $e) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); }
    error_log('export_invoice_ledger: ' . $e->getMessage());
    echo 'Something went wrong while generating this export. Please try again.';
    exit;
}

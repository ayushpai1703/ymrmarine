<?php
// Dashboard Month Summary → Excel of that month's vessels (Survey Completed Date),
// the same vessels and recovery the card shows. ?y=YYYY&m=MM
ob_start();
ini_set('display_errors', '0');
require 'vendor/autoload.php';
require_once 'config/config.php';
require_once __DIR__ . '/includes/excel_export_helpers.php';
require_once __DIR__ . '/includes/dashboard_month.php';
require_once __DIR__ . '/includes/recovery_list.php';
checkAuth();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$y = (int)($_GET['y'] ?? date('Y'));
$m = (int)($_GET['m'] ?? date('n'));
if ($y < 2000 || $y > 2100 || $m < 1 || $m > 12) { $y = (int)date('Y'); $m = (int)date('n'); }

$db = getDB();
$role = (string)($_SESSION['role'] ?? '');
$userId = (int)($_SESSION['user_id'] ?? 0);
$clientRowId = 0;
if ($role === 'Client') {
    $c = $db->prepare('SELECT id FROM clients WHERE user_id = ? LIMIT 1');
    $c->execute([$userId]);
    $clientRowId = (int)($c->fetchColumn() ?: 0);
}

try {
    @set_time_limit(120);
    [$whereSql, $params, $start] = dashboardMonthWhere($role, $userId, $clientRowId, $y, $m);
    $st = $db->prepare("
        SELECT s.id, s.vessel_name, s.report_number, s.status, s.survey_type_ids, t.type_name, c.company_name, u.full_name AS surveyor_name,
               p.port_name, p.country, s.survey_completed_date, s.appointment_date, s.assign_date, s.report_uploaded_date,
               " . RECOVERY_NO_SURVEYOR_SQL . " AS no_surveyor,
               s.vlsfo_recovery, s.hsfo_recovery, s.lsmgo_recovery, s.recovery_amount, s.client_id, s.notes
        FROM surveys s
        LEFT JOIN survey_types t ON s.survey_type_id = t.id
        LEFT JOIN clients c ON s.client_id = c.id
        LEFT JOIN users u ON s.surveyor_id = u.id
        LEFT JOIN ports p ON s.port_id = p.id
        WHERE $whereSql
        ORDER BY " . SURVEY_COMPLETED_ORDER_SQL . " DESC, s.id DESC
    ");
    $st->execute($params);
    $label = date('F Y', strtotime($start));

    $d = fn($v) => (!empty($v) && strpos((string)$v, '0000') !== 0) ? date('d-m-Y', strtotime((string)$v)) : '';
    $q = fn($v, bool $onhire = false) => $v !== null ? recoverySigned($v, $onhire) : ''; // Onhire cells negative; $total stays positive
    $headers = ['Vessel Name', 'Report No', 'Client', 'Port', 'Survey Type', 'Surveyor', 'Status', 'Survey Completed', 'Report Completed', 'VLSFO', 'HSFO', 'LSMGO', 'Total Recovery', 'Source', 'Check'];
    $rows = []; $ships = 0; $total = 0.0;
    $all = $st->fetchAll(PDO::FETCH_ASSOC);
    // Reconciliation aids only (no effect on the figures): where the record came from, and
    // the same vessel + client listed more than once this month (a repeat survey, or a duplicate).
    $vkey = fn($r) => preg_replace('/^(mv|mt|m v|m t)\s+/', '', trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower((string)$r['vessel_name'])))) . '|' . (int)$r['client_id'];
    $seen = array_count_values(array_map($vkey, $all));
    foreach ($all as $r) {
        $ships++;
        $na = isRecoveryNotApplicable($db, (string)($r['survey_type_ids'] ?? ''), (string)($r['type_name'] ?? ''));
        $total += $na ? 0 : (float)$r['recovery_amount'];
        $oh = isOnhireSurvey($db, (string)($r['survey_type_ids'] ?? ''), (string)($r['type_name'] ?? ''));
        $rows[] = ['country' => $r['country'] ?? 'India', 'cells' => [
            $r['vessel_name'] ?? '', $r['report_number'] ?? '', $r['company_name'] ?? '', $r['port_name'] ?? '',
            getCombinedSurveyTypeNames($db, $r['survey_type_ids'] ?? '', $r['type_name'] ?? ''),
            // Outsourced (no surveyor login) → OUTSOURCED_CREDIT_SURVEYOR, same as the dashboard / Recovery page
            !empty($r['no_surveyor']) ? outsourcedCreditSurveyor($db)[1] : getCombinedSurveyorNames($db, (int)$r['id'], $r['surveyor_name'] ?? ''),
            $r['status'] === 'Completed' ? 'Completed' : 'Report Pending',
            surveyCompletedDateText($r), $d($r['report_uploaded_date']),
            $na ? '' : $q($r['vlsfo_recovery'], $oh), $na ? '' : $q($r['hsfo_recovery'], $oh), $na ? '' : $q($r['lsmgo_recovery'], $oh),
            $na ? RECOVERY_NA_LABEL : $q($r['recovery_amount'], $oh),
            strpos((string)($r['notes'] ?? ''), 'Imported from Excel') === 0 ? 'Imported from Excel' : 'Entered in YSMS',
            ($n = $seen[$vkey($r)]) > 1 ? 'Same vessel + client listed ' . $n . ' times this month' : '',
        ]];
    }

    $ss = new Spreadsheet();
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle(substr('Month Summary ' . date('M Y', strtotime($start)), 0, 31));
    if ($rows) {
        writeCountryGroupedSheet($sheet, $headers, $rows);
        $last = $sheet->getHighestRow() + 1;
    } else {
        $sheet->fromArray([$headers], null, 'A1');
        $sheet->setCellValue('A2', 'No vessels with a Survey Completed Date in ' . $label . '.');
        $last = 4;
    }
    $sheet->setCellValue('A' . $last, $label . ' — Ships surveyed: ' . $ships);
    $sheet->setCellValue('L' . $last, 'Total');
    $sheet->setCellValue('M' . $last, round($total, 3));
    $sheet->getStyle('A' . $last . ':M' . $last)->getFont()->setBold(true);
    $sheet->getStyle('J2:M' . $last)->getNumberFormat()->setFormatCode('0.000');

    while (ob_get_level() > 0) { ob_end_clean(); }
    $fileName = exportFileName('Month Summary ' . date('F Y', strtotime($start))) . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    (new Xlsx($ss))->save('php://output');
    exit;
} catch (Throwable $e) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); }
    error_log('export_month_summary: ' . $e->getMessage());
    echo 'Something went wrong while generating this export. Please try again.';
    exit;
}

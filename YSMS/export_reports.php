<?php
ob_start();
ini_set('display_errors', '0');
error_reporting(0);

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo "Export Fatal Error: " . $error['message'];
    }
});

require 'vendor/autoload.php';
require_once 'config/config.php';
require_once __DIR__ . '/includes/excel_export_helpers.php';
require_once __DIR__ . '/includes/list_filters.php';
require_once __DIR__ . '/includes/report_table.php';
checkAuth();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

try {
    @set_time_limit(120);
    @ini_set('memory_limit', '256M');

    $db = getDB();
    $role = $_SESSION['role'] ?? '';
    $user_id = (int)($_SESSION['user_id'] ?? 0);
    // Admin and Super Admin see every report; Client sees only their own
    // company's; Surveyor sees only their own (previously: everyone who
    // isn't Admin got filtered by surveyor_id, silently emptying the export
    // for both Super Admin and Client).
    $is_full_access = in_array($role, ['Admin', 'Super Admin'], true);
    $client_row_id = 0;
    if ($role === 'Client') {
        try {
            $ccheck = $db->prepare("SELECT id FROM clients WHERE user_id = ? LIMIT 1");
            $ccheck->execute([$user_id]);
            $client_row_id = (int)($ccheck->fetchColumn() ?: 0);
        } catch (Throwable $e) { error_log('export_reports.php client lookup: ' . $e->getMessage()); }
    }

    // Same filters and order as the page on screen (includes/report_table.php), so the
    // download is exactly the filtered table (every page of it).
    $rtState = rtState($_GET, 'survey', $is_full_access);
    [$rtScopeWhere, $rtScopeParams] = rtScope('Pending Report', $role, $user_id, $client_row_id, $is_full_access);
    [$whereSql, $params] = rtWhere($rtState, $rtScopeWhere, $rtScopeParams);
    $filter_place = implode(', ', $rtState['place']);
    $filter_client = implode(', ', array_merge($rtState['client'], $rtState['agent']));
    $filter_surveyor = implode(', ', $rtState['surveyor']);
    $filter_month = $rtState['ym'];

    $baseSql = "SELECT s.id, s.vessel_name, s.agent_name, c.company_name, st.type_name, s.survey_type_ids, u.full_name as surveyor_name, s.survey_completed_date, s.appointment_date, s.report_uploaded_date,
                       p.port_name, p.country
                        " . rtFromSql(true) . "
                        WHERE $whereSql
                        ORDER BY " . rtOrderSql($rtState) . "";
    $stmt = $db->prepare($baseSql);
    $stmt->execute($params);
    $surveys = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Pending Reports');

    $headers = ['Vessel Name', 'Client', 'Agent', 'Port', 'Country', 'Survey Type', 'Surveyor', 'Survey Completed Date'];
    $rows = [];
    foreach ($surveys as $row) {
        $country = $row['country'] ?? 'India';
        $rows[] = [
            'country' => $country,
            'cells' => [
                $row['vessel_name'] ?? '',
                $row['company_name'] ?? '',
                $row['agent_name'] ?? 'N/A',
                $row['port_name'] ?? 'N/A',
                $country,
                getCombinedSurveyTypeNames($db, $row['survey_type_ids'] ?? '', $row['type_name'] ?? 'N/A'),
                getCombinedSurveyorNames($db, $row['id'] ?? 0, $row['surveyor_name'] ?? 'N/A'),
                $row['survey_completed_date'] ?? '',
            ],
        ];
    }
    writeCountryGroupedSheet($sheet, $headers, $rows);

    while (ob_get_level() > 0) { ob_end_clean(); }

    $fileName = buildExportFileBaseName('Pending Reports', [
        ['label' => 'at', 'value' => $filter_place],
        ['label' => 'for', 'value' => $filter_client],
        ['label' => 'by', 'value' => $filter_surveyor],
    ]) . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('Expires: 0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;

} catch (Throwable $e) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    error_log('Export error: ' . $e->getMessage()); echo 'Something went wrong while generating this export. Please try again, or contact support if it keeps happening.';
    exit;
}

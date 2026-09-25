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
checkAuth();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

$db = getDB();
$role = $_SESSION['role'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);
// Admin and Super Admin see every completed vessel; Client sees only their own
// company's; Surveyor sees only their own (previously: everyone who isn't
// Admin got filtered by surveyor_id, silently emptying the export for both
// Super Admin and Client).
$is_full_access = in_array($role, ['Admin', 'Super Admin'], true);
$client_row_id = 0;
if ($role === 'Client') {
    try {
        $ccheck = $db->prepare("SELECT id FROM clients WHERE user_id = ? LIMIT 1");
        $ccheck->execute([$user_id]);
        $client_row_id = (int)($ccheck->fetchColumn() ?: 0);
    } catch (Throwable $e) { error_log('export_completed.php client lookup: ' . $e->getMessage()); }
}

try {
    @set_time_limit(120);
    @ini_set('memory_limit', '256M');

    $baseSql = "
        SELECT s.id, s.vessel_name, s.agent_name, c.company_name, t.type_name, s.survey_type_ids, u.full_name as surveyor_name, s.recovery_amount, s.report_uploaded_date,
               p.port_name, p.country
        FROM surveys s
        LEFT JOIN clients c ON s.client_id = c.id
        LEFT JOIN survey_types t ON s.survey_type_id = t.id
        LEFT JOIN users u ON s.surveyor_id = u.id
        LEFT JOIN ports p ON s.port_id = p.id
        WHERE s.status = 'Completed'
    ";
    if ($is_full_access) {
        $stmt = $db->prepare($baseSql . " ORDER BY s.report_uploaded_date DESC");
        $stmt->execute();
    } elseif ($role === 'Client') {
        $stmt = $db->prepare($baseSql . " AND s.client_id = ? ORDER BY s.report_uploaded_date DESC");
        $stmt->execute([$client_row_id]);
    } else {
        // Include completed surveys assigned via survey_surveyors (multi-surveyor),
        // not just the primary surveys.surveyor_id.
        $stmt = $db->prepare($baseSql . " AND (s.surveyor_id = ? OR s.id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = ?)) ORDER BY s.report_uploaded_date DESC");
        $stmt->execute([$user_id, $user_id]);
    }
    $surveys = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Completed Surveys');

    $headers = ['Vessel Name', 'Client', 'Agent', 'Port', 'Country', 'Survey Type', 'Surveyor', 'Recovery (MT)', 'Report Uploaded Date'];
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
                !empty($row['recovery_amount']) ? (float)$row['recovery_amount'] : 0,
                !empty($row['report_uploaded_date']) ? date('d-m-Y', strtotime($row['report_uploaded_date'])) : '',
            ],
        ];
    }
    writeCountryGroupedSheet($sheet, $headers, $rows);

    while (ob_get_level() > 0) { ob_end_clean(); }

    $fileName = 'Completed_Surveys_' . date('d_m_Y') . '.xlsx';
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

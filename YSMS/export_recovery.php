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
// Admin and Super Admin see every vessel's recovery; Client sees only their
// own company's; Surveyor sees only their own (previously: everyone who
// isn't Admin got filtered by surveyor_id, silently emptying the export for
// both Super Admin and Client — Client wasn't scoped by client_id at all).
$is_full_access = in_array($role, ['Admin', 'Super Admin'], true);
$client_row_id = 0;
if ($role === 'Client') {
    try {
        $ccheck = $db->prepare("SELECT id FROM clients WHERE user_id = ? LIMIT 1");
        $ccheck->execute([$user_id]);
        $client_row_id = (int)($ccheck->fetchColumn() ?: 0);
    } catch (Throwable $e) { error_log('export_recovery.php client lookup: ' . $e->getMessage()); }
}

$period = isset($_GET['period']) && $_GET['period'] === 'month' ? 'month' : 'total';

try {
    @set_time_limit(120);
    @ini_set('memory_limit', '256M');

    // p.country isn't shown as a column here (headers below are unchanged) — it's
    // only used to group the sheet into country sections, same as the other exports.
    $sql = "
        SELECT s.vessel_name, s.survey_type_ids, t.type_name,
               s.vlsfo_recovery, s.lsmgo_recovery, s.hsfo_recovery, s.recovery_amount, s.report_uploaded_date,
               p.country
        FROM surveys s
        LEFT JOIN survey_types t ON s.survey_type_id = t.id
        LEFT JOIN ports p ON s.port_id = p.id
        WHERE s.recovery_amount IS NOT NULL AND s.status IN ('Pending Report','Completed')
    ";
    $params = [];

    if ($period === 'month') {
        // Survey Completed Date in the current month (app timezone) — same as the dashboard card.
        $sql .= " AND s.survey_completed_date >= ? AND s.survey_completed_date < ?";
        $params[] = date('Y-m-01');
        $params[] = date('Y-m-01', strtotime('first day of next month'));
    }

    if ($role === 'Client') {
        $sql .= " AND s.client_id = ?";
        $params[] = $client_row_id;
    } elseif (!$is_full_access) {
        // Include surveys assigned via survey_surveyors (multi-surveyor), not
        // just the primary surveys.surveyor_id.
        $sql .= " AND (s.surveyor_id = ? OR s.id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = ?))";
        $params[] = $user_id;
        $params[] = $user_id;
    }

    $sql .= " ORDER BY s.survey_completed_date DESC, s.id DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $surveys = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle($period === 'month' ? 'This Month Recovery' : 'Total Recovery');

    $headers = ['Vessel Name', 'Survey Type', 'VLSFO Recovery', 'LSMGO Recovery', 'HSFO Recovery', 'Total Recovery'];
    $rows = [];
    foreach ($surveys as $row) {
        $typeLabel = $row['type_name'] ?? 'N/A';
        if (function_exists('getCombinedSurveyTypeNames')) {
            $typeLabel = getCombinedSurveyTypeNames($db, $row['survey_type_ids'] ?? '', $row['type_name'] ?? 'N/A');
        }
        if (isRecoveryNotApplicable($db, (string)($row['survey_type_ids'] ?? ''), (string)($row['type_name'] ?? ''))) {
            $rows[] = [
                'country' => $row['country'] ?? 'India',
                'cells' => [$row['vessel_name'] ?? '', $typeLabel, '', '', '', RECOVERY_NA_LABEL],
            ];
            continue;
        }
        $rows[] = [
            'country' => $row['country'] ?? 'India',
            'cells' => [
                $row['vessel_name'] ?? '',
                $typeLabel,
                !empty($row['vlsfo_recovery']) ? (float)$row['vlsfo_recovery'] : 0,
                !empty($row['lsmgo_recovery']) ? (float)$row['lsmgo_recovery'] : 0,
                !empty($row['hsfo_recovery']) ? (float)$row['hsfo_recovery'] : 0,
                !empty($row['recovery_amount']) ? (float)$row['recovery_amount'] : 0,
            ],
        ];
    }
    writeCountryGroupedSheet($sheet, $headers, $rows);

    while (ob_get_level() > 0) { ob_end_clean(); }

    $fileNamePrefix = $period === 'month' ? 'This_Month_Recovery_' : 'Total_Recovery_';
    $fileName = $fileNamePrefix . date('d_m_Y') . '.xlsx';
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

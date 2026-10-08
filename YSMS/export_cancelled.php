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
// Admin and Super Admin see every cancelled vessel; Client sees only their own
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
    } catch (Throwable $e) { error_log('export_cancelled.php client lookup: ' . $e->getMessage()); }
}

try {
    @set_time_limit(120);
    @ini_set('memory_limit', '256M');

    // 🌟 Mirror cancelled.php's own filter UI (search/type/place/client/
    // surveyor) so the export matches whatever's currently on screen: no
    // filter applied → every param below is empty → identical to the old
    // always-full export. The surveyor filter stays Admin-only gated, same
    // as cancelled.php's own dropdown (it isn't shown to Super Admin there).
    $q = trim((string)($_GET['q'] ?? ''));
    $filter_type = trim((string)($_GET['type'] ?? ''));
    $filter_place = trim((string)($_GET['place'] ?? ''));
    $filter_client = trim((string)($_GET['client'] ?? ''));
    $filter_surveyor = trim((string)($_GET['surveyor'] ?? ''));

    $where = ["s.status = 'Cancelled'"];
    $params = [];
    if ($is_full_access) {
        // no extra scoping
    } elseif ($role === 'Client') {
        $where[] = 's.client_id = ?';
        $params[] = $client_row_id;
    } else {
        $where[] = '(s.surveyor_id = ? OR s.id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = ?))';
        $params[] = $user_id;
        $params[] = $user_id;
    }
    if ($q !== '') {
        $where[] = '(s.vessel_name LIKE ? OR c.company_name LIKE ? OR s.agent_name LIKE ? OR t.type_name LIKE ? OR p.port_name LIKE ? OR u.full_name LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }
    if ($filter_type !== '') { $where[] = 't.type_name = ?'; $params[] = $filter_type; }
    if ($filter_place !== '') { $where[] = 'p.port_name = ?'; $params[] = $filter_place; }
    if ($filter_client !== '') { $where[] = 'c.company_name = ?'; $params[] = $filter_client; }
    if ($role === 'Admin' && $filter_surveyor !== '') {
        $where[] = "COALESCE((SELECT GROUP_CONCAT(u2.full_name ORDER BY ss2.id SEPARATOR ' + ') FROM survey_surveyors ss2 JOIN users u2 ON ss2.surveyor_id = u2.id WHERE ss2.survey_id = s.id), u.full_name) = ?";
        $params[] = $filter_surveyor;
    }
    $whereSql = implode(' AND ', $where);

    $baseSql = "
        SELECT s.id, s.vessel_name, s.agent_name, c.company_name, t.type_name, s.survey_type_ids, u.full_name as surveyor_name, s.assign_date, s.report_number,
               p.port_name, p.country
        FROM surveys s
        LEFT JOIN clients c ON s.client_id = c.id
        LEFT JOIN survey_types t ON s.survey_type_id = t.id
        LEFT JOIN users u ON s.surveyor_id = u.id
        LEFT JOIN ports p ON s.port_id = p.id
        WHERE $whereSql
        ORDER BY s.id DESC
    ";
    $stmt = $db->prepare($baseSql);
    $stmt->execute($params);
    $surveys = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Cancelled Vessels');

    $headers = ['Vessel Name', 'Report No', 'Client', 'Agent', 'Port', 'Country', 'Survey Type', 'Surveyor', 'Assigned Date'];
    $rows = [];
    foreach ($surveys as $row) {
        $country = $row['country'] ?? 'India';
        $rows[] = [
            'country' => $country,
            'cells' => [
                $row['vessel_name'] ?? '',
                $row['report_number'] ?? '',
                $row['company_name'] ?? '',
                $row['agent_name'] ?? 'N/A',
                $row['port_name'] ?? 'N/A',
                $country,
                getCombinedSurveyTypeNames($db, $row['survey_type_ids'] ?? '', $row['type_name'] ?? 'N/A'),
                getCombinedSurveyorNames($db, $row['id'] ?? 0, $row['surveyor_name'] ?? 'N/A'),
                !empty($row['assign_date']) ? date('d-m-Y H:i', strtotime($row['assign_date'])) : '',
            ],
        ];
    }
    writeCountryGroupedSheet($sheet, $headers, $rows);

    while (ob_get_level() > 0) { ob_end_clean(); }

    $fileName = 'Cancelled_Vessels_' . date('d_m_Y') . '.xlsx';
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

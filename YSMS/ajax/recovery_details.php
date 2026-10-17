<?php
require_once __DIR__ . '/../config/config.php';
// Current calendar month in the app's timezone (not the DB server's) for "This Month" figures.
$cm_start = date('Y-m-01');
$cm_end = date('Y-m-01', strtotime('first day of next month'));
checkAuth();

header('Content-Type: application/json; charset=utf-8');

$db = getDB();
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
// Admin and Super Admin see every vessel's recovery; Client sees only their
// own company's; Surveyor sees only their own (previously: everyone who
// isn't Admin got filtered by surveyor_id, silently emptying this modal for
// both Super Admin and Client — Client wasn't scoped by client_id at all).
$is_full_access = in_array($role, ['Admin', 'Super Admin'], true);
$client_row_id = 0;
if ($role === 'Client') {
    try {
        $ccheck = $db->prepare("SELECT id FROM clients WHERE user_id = ? LIMIT 1");
        $ccheck->execute([$user_id]);
        $client_row_id = (int)($ccheck->fetchColumn() ?: 0);
    } catch (Throwable $e) { error_log('recovery_details.php client lookup: ' . $e->getMessage()); }
}

// period=total  -> అన్ని అప్‌లోడ్ అయిన రికార్డులు (Total Recovery కార్డ్)
// period=month  -> Survey Completed Date in the current month (This Month Recovery card)
$period = isset($_GET['period']) && $_GET['period'] === 'month' ? 'month' : 'total';

$sql = "
    SELECT s.vessel_name, s.survey_type_ids, t.type_name,
           s.vlsfo_recovery, s.lsmgo_recovery, s.hsfo_recovery, s.recovery_amount, s.report_uploaded_date
    FROM surveys s
    LEFT JOIN survey_types t ON s.survey_type_id = t.id
    WHERE s.recovery_amount IS NOT NULL AND s.status IN ('Pending Report','Completed')
";

$params = [];

if ($period === 'month') {
    $sql .= " AND " . surveyCompletedDateSql('s') . " >= '$cm_start' AND " . surveyCompletedDateSql('s') . " < '$cm_end'";
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
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$data = [];
foreach ($rows as $row) {
    $na = isRecoveryNotApplicable($db, (string)($row['survey_type_ids'] ?? ''), (string)($row['type_name'] ?? ''));
    $data[] = [
        'vessel_name'    => $row['vessel_name'] ?? '',
        'survey_type'    => getCombinedSurveyTypeNames($db, $row['survey_type_ids'] ?? '', $row['type_name'] ?? 'N/A'),
        'vlsfo_recovery' => $na ? RECOVERY_NA_LABEL : ($row['vlsfo_recovery'] !== null ? number_format((float)$row['vlsfo_recovery'], 3) : '0.000'),
        'lsmgo_recovery' => $na ? RECOVERY_NA_LABEL : ($row['lsmgo_recovery'] !== null ? number_format((float)$row['lsmgo_recovery'], 3) : '0.000'),
        'hsfo_recovery'  => $na ? RECOVERY_NA_LABEL : ($row['hsfo_recovery'] !== null ? number_format((float)$row['hsfo_recovery'], 3) : '0.000'),
        'recovery_na'    => $na,
        'total_recovery' => $na ? RECOVERY_NA_LABEL : ($row['recovery_amount'] !== null ? number_format((float)$row['recovery_amount'], 3) : '0.000'),
    ];
}

echo json_encode(['success' => true, 'period' => $period, 'rows' => $data]);
exit;

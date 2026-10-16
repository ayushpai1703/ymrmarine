<?php
/**
 * Dashboard "Month Summary" card: ships surveyed and recovery for one
 * calendar month, by Survey Completed Date. Counts vessels that have left
 * Pending Vessels (Pending Report + Completed), scoped to what the viewer may
 * see (Admin / Super Admin: all, Client: own company, Surveyor: assigned).
 */
function dashboardMonthStats(PDO $db, string $role, int $userId, int $clientRowId, int $year, int $month): array {
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = date('Y-m-d', strtotime($start . ' +1 month'));
    $where = ["s.status IN ('Pending Report','Completed')", 's.survey_completed_date >= ?', 's.survey_completed_date < ?'];
    $params = [$start, $end];
    if ($role === 'Client') {
        $where[] = 's.client_id = ?';
        $params[] = $clientRowId;
    } elseif (!in_array($role, ['Admin', 'Super Admin'], true)) {
        $where[] = '(s.surveyor_id = ? OR s.id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = ?))';
        $params[] = $userId;
        $params[] = $userId;
    }
    $st = $db->prepare('SELECT COUNT(*) AS ships, COALESCE(SUM(s.recovery_amount), 0) AS recovery FROM surveys s WHERE ' . implode(' AND ', $where));
    $st->execute($params);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['ships' => 0, 'recovery' => 0];
    return ['ships' => (int)$r['ships'], 'recovery' => (float)$r['recovery'], 'label' => date('F Y', strtotime($start))];
}

/** Years offered in the selector: earliest survey-completed year → this year. */
function dashboardMonthYears(PDO $db): array {
    $min = (int)($db->query("SELECT MIN(YEAR(survey_completed_date)) FROM surveys WHERE survey_completed_date IS NOT NULL AND survey_completed_date > '2000-01-01'")->fetchColumn() ?: date('Y'));
    $cur = (int)date('Y');
    return range($cur, min($min, $cur));
}

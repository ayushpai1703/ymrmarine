<?php
/**
 * Dashboard "Month Summary" card: ships surveyed and recovery for one
 * calendar month, by Survey Completed Date. Counts vessels that have left
 * Pending Vessels (Pending Report + Completed), scoped to what the viewer may
 * see (Admin / Super Admin: all, Client: own company, Surveyor: assigned).
 */
/** WHERE clause + params selecting the month's vessels for this viewer (shared by the card and its download). */
function dashboardMonthWhere(string $role, int $userId, int $clientRowId, int $year, int $month): array {
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = date('Y-m-d', strtotime($start . ' +1 month'));
    $where = ["s.status IN ('Pending Report','Completed')", surveyCompletedDateSql('s') . ' >= ?', surveyCompletedDateSql('s') . ' < ?'];
    $params = [$start, $end];
    if ($role === 'Client') {
        $where[] = 's.client_id = ?';
        $params[] = $clientRowId;
    } elseif (!in_array($role, ['Admin', 'Super Admin'], true)) {
        $where[] = '(s.surveyor_id = ? OR s.id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = ?))';
        $params[] = $userId;
        $params[] = $userId;
    }
    return [implode(' AND ', $where), $params, $start];
}

function dashboardMonthStats(PDO $db, string $role, int $userId, int $clientRowId, int $year, int $month): array {
    [$whereSql, $params, $start] = dashboardMonthWhere($role, $userId, $clientRowId, $year, $month);
    $st = $db->prepare('SELECT COUNT(*) AS ships, COALESCE(SUM(' . recoveryCountedSql('s') . '), 0) AS recovery FROM surveys s WHERE ' . $whereSql);
    $st->execute($params);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['ships' => 0, 'recovery' => 0];
    return ['ships' => (int)$r['ships'], 'recovery' => (float)$r['recovery'], 'label' => date('F Y', strtotime($start))];
}

/** Years offered in the selector: earliest survey-completed year → this year. */
function dashboardMonthYears(PDO $db): array {
    // Every year that has a Pending Report / Completed vessel (same date as the month figures),
    // so no vessel is left in a year that can't be picked.
    $cd = surveyCompletedDateSql('s');
    $r = $db->query("SELECT MIN(YEAR($cd)) AS lo, MAX(YEAR($cd)) AS hi FROM surveys s WHERE s.status IN ('Pending Report','Completed') AND $cd > '2000-01-01'")->fetch(PDO::FETCH_ASSOC);
    $cur = (int)date('Y');
    return range(max($cur, min((int)($r['hi'] ?: $cur), 2100)), min((int)($r['lo'] ?: $cur), $cur));
}

<?php
/**
 * Possible duplicate vessel records (possible_duplicates.php): the same vessel + client in
 * Pending Reports / Completed more than once — typically entered live in YSMS and then
 * imported again from Excel. Read-only; deleting is done by the Admin, one record at a time.
 *
 *  'duplicate' — same non-zero recovery, Survey Completed Dates within 31 days (same survey)
 *  'check'     — Survey Completed Dates within 15 days, recovery different / zero (repeat
 *                survey or duplicate: the Admin decides)
 * Same thresholds as the completed import's duplicate check.
 */
require_once __DIR__ . '/completed_import_helpers.php';

const DUP_SAME_RECOVERY_DAYS = 31;
const DUP_CHECK_DAYS = 15;

/** @return array list of clusters: kind (duplicate|check), records, extra_mt / extra_ships (copies with the same recovery), month */
function findPossibleDuplicates(PDO $db): array {
    $rows = $db->query("
        SELECT s.id, s.vessel_name, s.client_id, c.company_name, s.status, s.report_number, s.survey_type_ids, st.type_name,
               u.full_name AS surveyor_name, s.survey_completed_date, s.report_uploaded_date, s.recovery_amount, s.notes,
               (SELECT COUNT(*) FROM uploads f WHERE f.survey_id = s.id) AS files
        FROM surveys s
        LEFT JOIN clients c ON c.id = s.client_id
        LEFT JOIN survey_types st ON st.id = s.survey_type_id
        LEFT JOIN users u ON u.id = s.surveyor_id
        WHERE s.status IN ('Pending Report','Completed') AND YEAR(s.survey_completed_date) > 0
        ORDER BY s.id
    ")->fetchAll(PDO::FETCH_ASSOC);

    $groups = [];
    foreach ($rows as $r) {
        $groups[(int)$r['client_id'] . '|' . completedImportVesselKey((string)$r['vessel_name'])][] = $r;
    }

    $clusters = [];
    foreach ($groups as $g) {
        if (count($g) < 2) continue;
        // pairs → connected clusters (a record can pair with several others)
        $n = count($g); $parent = range(0, $n - 1); $kind = [];
        $find = function ($i) use (&$parent, &$find) { return $parent[$i] === $i ? $i : ($parent[$i] = $find($parent[$i])); };
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $days = abs((strtotime(substr($g[$i]['survey_completed_date'], 0, 10)) - strtotime(substr($g[$j]['survey_completed_date'], 0, 10))) / 86400);
                $ri = round(abs((float)$g[$i]['recovery_amount']), 3); $rj = round(abs((float)$g[$j]['recovery_amount']), 3);
                $same = $g[$i]['recovery_amount'] !== null && $ri != 0.0 && abs($ri - $rj) < 0.0005;
                if (($same && $days <= DUP_SAME_RECOVERY_DAYS) || $days <= DUP_CHECK_DAYS) {
                    $a = $find($i); $b = $find($j); $parent[$b] = $a;
                    $kind[$i . '-' . $j] = ($same && $days <= DUP_SAME_RECOVERY_DAYS) ? 'duplicate' : 'check';
                }
            }
        }
        $byRoot = [];
        for ($i = 0; $i < $n; $i++) $byRoot[$find($i)][] = $i;
        foreach ($byRoot as $members) {
            if (count($members) < 2) continue;
            $k = 'check'; $extra = 0.0; $extraShips = 0;
            foreach ($kind as $pair => $v) {
                [$i, $j] = array_map('intval', explode('-', $pair));
                if (in_array($i, $members, true) && $v === 'duplicate') {
                    $k = 'duplicate';
                }
            }
            $records = array_map(fn($i) => $g[$i], $members);
            // MT counted more than once: every extra copy of the same recovery value
            $byRec = [];
            foreach ($records as $r) if ($r['recovery_amount'] !== null && (float)$r['recovery_amount'] != 0.0) $byRec[number_format(abs((float)$r['recovery_amount']), 3, '.', '')][] = 1;
            foreach ($byRec as $v => $copies) { $extra += (count($copies) - 1) * (float)$v; $extraShips += count($copies) - 1; }
            usort($records, fn($a, $b) => strcmp((string)$a['survey_completed_date'], (string)$b['survey_completed_date']) ?: ((int)$a['id'] <=> (int)$b['id']));
            $clusters[] = ['kind' => $k, 'records' => $records, 'extra_mt' => round($extra, 3), 'extra_ships' => $extraShips,
                           'month' => substr((string)$records[0]['survey_completed_date'], 0, 7)];
        }
    }
    usort($clusters, fn($a, $b) => [$a['kind'] === 'duplicate' ? 0 : 1, $b['month']] <=> [$b['kind'] === 'duplicate' ? 0 : 1, $a['month']]);
    return $clusters;
}

/** Imported from the Excel bulk import, or entered in YSMS. */
function duplicateRecordSource(array $r): string {
    return strpos((string)($r['notes'] ?? ''), 'Imported from Excel') === 0 ? 'Imported from Excel' : 'Entered in YSMS';
}

<?php
/**
 * Previous surveys of the same vessel (vessel_detail.php "Previous surveys of this vessel"):
 * when a vessel comes in again, the surveyor sees when / where / for whom it was surveyed
 * before, with every document uploaded on those surveys, for reference.
 *
 * Same vessel = the same name ignoring the MV./M.V./M/V/MT prefix, case, spaces and
 * punctuation, or the same IMO number. Any client. Only surveys that were actually done
 * (Pending Report / Completed).
 *
 * Access: Admin / Super Admin, and a Surveyor for vessels currently assigned to them
 * (Pending Vessel / Pending Report) — download.php uses vesselHistorySurveyorMayOpen() so
 * those documents open for that surveyor too. Clients never see other surveys.
 */

/** "MV. Ocean-Star  " → "OCEAN STAR". */
function vesselHistoryKey(string $name): string {
    $s = strtoupper(str_replace("\xC2\xA0", ' ', $name));
    $s = preg_replace('/^\s*(M\s*[.\/]?\s*[VT]\s*[.\/]?\s+|M[VT]\.)+/', '', $s);
    $s = preg_replace('/^\s*M\s*\/\s*V\s*/', '', $s);
    return trim(preg_replace('/[^A-Z0-9]+/', ' ', $s));
}

function vesselHistoryImo($v): string {
    $d = preg_replace('/\D+/', '', (string)$v);
    return strlen($d) === 7 ? $d : '';
}

function vesselHistoryHasImo(PDO $db): bool {
    static $has = null;
    if ($has === null) {
        try { $has = (bool)$db->query("SHOW COLUMNS FROM surveys LIKE 'imo_number'")->fetch(); } catch (Throwable $e) { $has = false; }
    }
    return $has;
}

/** Ids of done surveys (Pending Report / Completed) of the same vessel, excluding $excludeId. */
function vesselHistoryIds(PDO $db, string $vesselName, $imo, int $excludeId = 0): array {
    $key = vesselHistoryKey($vesselName);
    $imo = vesselHistoryImo($imo);
    if ($key === '' && $imo === '') return [];
    $cols = 'id, vessel_name' . (vesselHistoryHasImo($db) ? ', imo_number' : '');
    $st = $db->prepare("SELECT $cols FROM surveys WHERE status IN ('Pending Report','Completed') AND id <> ?");
    $st->execute([$excludeId]);
    $ids = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (($key !== '' && vesselHistoryKey((string)$r['vessel_name']) === $key)
            || ($imo !== '' && vesselHistoryImo($r['imo_number'] ?? '') === $imo)) {
            $ids[] = (int)$r['id'];
        }
    }
    return $ids;
}

/** Previous surveys (newest first), each with its uploaded documents. */
function vesselHistory(PDO $db, array $survey): array {
    $ids = vesselHistoryIds($db, (string)($survey['vessel_name'] ?? ''), $survey['imo_number'] ?? '', (int)$survey['id']);
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("
        SELECT s.id, s.vessel_name, s.status, s.report_number, s.survey_type_ids, st.type_name, s.survey_completed_date,
               s.report_uploaded_date, s.assign_date, c.company_name, p.port_name, p.country, u.full_name AS surveyor_name
        FROM surveys s
        LEFT JOIN clients c ON c.id = s.client_id
        LEFT JOIN ports p ON p.id = s.port_id
        LEFT JOIN survey_types st ON st.id = s.survey_type_id
        LEFT JOIN users u ON u.id = s.surveyor_id
        WHERE s.id IN ($in)
        ORDER BY COALESCE(s.survey_completed_date, s.assign_date) DESC, s.id DESC");
    $st->execute($ids);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $up = $db->prepare("SELECT id, survey_id, file_name, file_type, file_size, uploaded_at FROM uploads WHERE survey_id IN ($in) ORDER BY uploaded_at, id");
    $up->execute($ids);
    $files = [];
    foreach ($up->fetchAll(PDO::FETCH_ASSOC) as $f) $files[(int)$f['survey_id']][] = $f;
    foreach ($rows as &$r) {
        $r['types'] = getCombinedSurveyTypeNames($db, $r['survey_type_ids'] ?? '', $r['type_name'] ?? '');
        $r['surveyors'] = getCombinedSurveyorNames($db, (int)$r['id'], $r['surveyor_name'] ?? '');
        $r['files'] = $files[(int)$r['id']] ?? [];
    }
    unset($r);
    return $rows;
}

/** download.php: may this Surveyor open a document of survey $targetSurveyId as vessel history? */
function vesselHistorySurveyorMayOpen(PDO $db, int $targetSurveyId, int $userId): bool {
    $cols = 'id, vessel_name, status' . (vesselHistoryHasImo($db) ? ', imo_number' : '');
    $t = $db->prepare("SELECT $cols FROM surveys WHERE id = ?");
    $t->execute([$targetSurveyId]);
    $target = $t->fetch(PDO::FETCH_ASSOC);
    if (!$target || !in_array($target['status'], ['Pending Report', 'Completed'], true)) return false;
    $mine = $db->prepare("SELECT $cols FROM surveys s WHERE s.status IN ('Pending Vessel','Pending Report')
        AND (s.surveyor_id = ? OR s.id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = ?))");
    $mine->execute([$userId, $userId]);
    $tKey = vesselHistoryKey((string)$target['vessel_name']);
    $tImo = vesselHistoryImo($target['imo_number'] ?? '');
    foreach ($mine->fetchAll(PDO::FETCH_ASSOC) as $m) {
        if ((int)$m['id'] === $targetSurveyId) continue;
        if (($tKey !== '' && vesselHistoryKey((string)$m['vessel_name']) === $tKey)
            || ($tImo !== '' && vesselHistoryImo($m['imo_number'] ?? '') === $tImo)) {
            return true;
        }
    }
    return false;
}

<?php
/**
 * Multi-select list filters (survey type, port, client, surveyor) shared by the Excel exports and
 * the Recovery page. A filter arrives as one value (?place=Kakinada Port, older links) or several
 * (?place[]=Kakinada Port&place[]=Paradip Port); several values mean "any of these". Names are
 * matched case-insensitively (the desktop lists send them in lower case).
 */

/** The filter's values: trimmed, non-empty, without duplicates. */
function listFilterValues(array $src, string $key): array {
    $raw = $src[$key] ?? [];
    $raw = is_array($raw) ? $raw : [$raw];
    $out = [];
    foreach ($raw as $v) {
        if (!is_scalar($v)) continue;
        $v = trim((string)$v);
        if ($v !== '' && !in_array($v, $out, true)) $out[] = $v;
    }
    return array_slice($out, 0, 200);
}

/** One value for code that still works with a single filter value (first one, '' when none). */
function listFilterFirst(array $src, string $key): string {
    return listFilterValues($src, $key)[0] ?? '';
}

/** "LOWER(expr) IN (?, ?)" for the values (appended to $params), or '' when there are none. */
function listFilterInSql(string $expr, array $vals, array &$params): string {
    if (!$vals) return '';
    foreach ($vals as $v) $params[] = mb_strtolower($v);
    return 'LOWER(' . $expr . ') IN (' . implode(', ', array_fill(0, count($vals), '?')) . ')';
}

/**
 * Survey type: any of the vessel's survey types (surveys.survey_type_ids, or the single
 * survey_type_id on older rows) is one of the names.
 */
function listFilterTypeSql(array $names, array &$params, string $s = 's'): string {
    if (!$names) return '';
    foreach ($names as $v) $params[] = mb_strtolower($v);
    return "EXISTS (SELECT 1 FROM survey_types ltf WHERE LOWER(ltf.type_name) IN (" . implode(', ', array_fill(0, count($names), '?')) . ")
        AND FIND_IN_SET(ltf.id, REPLACE(COALESCE(NULLIF($s.survey_type_ids, ''), $s.survey_type_id), ' ', '')))";
}

/**
 * Surveyor (by name): one of the vessel's surveyors is one of the names — the surveyors in
 * survey_surveyors, or the primary surveyor when the vessel has none there (same rule as
 * getCombinedSurveyorNames(), which the lists show).
 */
function listFilterSurveyorNameSql(array $names, array &$params, string $s = 's', string $u = 'u'): string {
    if (!$names) return '';
    $in = implode(', ', array_fill(0, count($names), '?'));
    foreach ($names as $v) $params[] = mb_strtolower($v);
    foreach ($names as $v) $params[] = mb_strtolower($v);
    return "(EXISTS (SELECT 1 FROM survey_surveyors lfs JOIN users lfu ON lfu.id = lfs.surveyor_id
            WHERE lfs.survey_id = $s.id AND LOWER(lfu.full_name) IN ($in))
        OR (NOT EXISTS (SELECT 1 FROM survey_surveyors lfs2 WHERE lfs2.survey_id = $s.id) AND LOWER($u.full_name) IN ($in)))";
}

/** A list row's surveyor names ("A + B") as separate names, for the filter options. */
function listFilterSplitNames(string $combined): array {
    return array_values(array_filter(array_map('trim', explode(' + ', $combined)), 'strlen'));
}

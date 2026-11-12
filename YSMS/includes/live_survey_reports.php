<?php
/**
 * Live Survey Reports (Admin): the reports being filled on the page without sign-in
 * (survey_report_public.php), shown by the three values the surveyor types — Surveyor Name,
 * IMO number, Vessel Name — with a status from the time the report was started:
 *   under 4 hours   → Survey in live
 *   4 to 24 hours   → Might be completed — check now
 *   24 hours and on → Completed
 * Used by live_survey_reports.php and the Admin dashboard (index.php). Read-only.
 */
require_once __DIR__ . '/survey_report_guest.php';

const LSR_PROGRESS_HOURS = 4;
const LSR_DONE_HOURS = 24;

function lsrStatus(int $startedTs, ?int $now = null): array {
    $h = (($now ?? time()) - $startedTs) / 3600;
    if ($h < LSR_PROGRESS_HOURS) return ['key' => 'progress', 'label' => 'Survey in live', 'hours' => $h];
    if ($h < LSR_DONE_HOURS) return ['key' => 'check', 'label' => 'Might be completed — check now', 'hours' => $h];
    return ['key' => 'done', 'label' => 'Completed', 'hours' => $h];
}

function lsrAgo(float $hours): string {
    if ($hours < 1) return max(1, (int)round($hours * 60)) . ' min ago';
    if ($hours < 48) return (int)floor($hours) . ' h ' . (int)round(fmod($hours, 1) * 60) . ' min ago';
    return (int)floor($hours / 24) . ' days ago';
}

/**
 * Public-page reports, newest first. $sinceHours limits by start time (null = all), $limit the count.
 * Each: id, kind, started_at, updated_at, surveyor, imo, vessel, status, tank_changes.
 */
function lsrList(PDO $db, ?int $sinceHours = null, int $limit = 300): array {
    srgEnsure($db);
    srEnsureHistory($db);
    $where = $sinceHours !== null ? 'AND r.created_at >= ?' : '';
    $params = $sinceHours !== null ? [date('Y-m-d H:i:s', time() - $sinceHours * 3600)] : [];
    $q = $db->prepare("SELECT r.id, r.kind, r.data, r.created_at, r.updated_at,
            (SELECT COUNT(*) FROM survey_tank_entry_history h WHERE h.report_id = r.id AND h.version_number > 1) AS tank_changes,
            (SELECT COUNT(*) FROM survey_report_files f WHERE f.report_id = r.id AND f.type = 'pdf') AS pdfs
        FROM survey_report_guests g JOIN survey_reports r ON r.id = g.report_id
        WHERE r.survey_id IS NULL $where ORDER BY r.created_at DESC LIMIT " . max(1, min(1000, $limit)));
    $q->execute($params);
    $now = time();
    $out = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d = json_decode((string)$r['data'], true) ?: [];
        $f = (array)($d['fields'] ?? []);
        $txt = fn($k) => trim((string)(is_array($f[$k] ?? null) ? ($f[$k]['v'] ?? '') : ($f[$k] ?? '')));
        $started = strtotime((string)$r['created_at']) ?: $now;
        $out[] = [
            'id' => (int)$r['id'], 'kind' => RT_KINDS[$r['kind']] ?? $r['kind'],
            'started_at' => (string)$r['created_at'], 'updated_at' => (string)$r['updated_at'],
            'surveyor' => $txt('surveyor_name'), 'imo' => $txt('imo'), 'vessel' => $txt('vessel_name'),
            'status' => lsrStatus($started, $now), 'tank_changes' => (int)$r['tank_changes'], 'pdfs' => (int)$r['pdfs'],
        ];
    }
    return $out;
}

/** Status pill. */
function lsrPill(array $st): string {
    $cls = ['progress' => 'lsr-p', 'check' => 'lsr-c', 'done' => 'lsr-d'][$st['key']] ?? 'lsr-d';
    $ic = ['progress' => 'fa-spinner fa-spin-pulse', 'check' => 'fa-bell', 'done' => 'fa-circle-check'][$st['key']] ?? 'fa-circle-check';
    return '<span class="lsr-pill ' . $cls . '" data-testid="lsr-status-' . $st['key'] . '"><i class="fa-solid ' . $ic . '"></i> ' . htmlspecialchars($st['label'], ENT_QUOTES, 'UTF-8') . '</span>';
}

function lsrCss(): string {
    return '<style>
.lsr-pill{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:4px 10px;font-size:12px;font-weight:700;white-space:nowrap}
.lsr-p{background:#e0f2fe;color:#075985}.lsr-c{background:#fef3c7;color:#92400e}.lsr-d{background:#dcfce7;color:#166534}
.lsr-row{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(0,1fr) minmax(0,1fr) auto;gap:10px;align-items:center;padding:10px 12px;border-top:1px solid #eef2f7;text-decoration:none;color:#0f172a}
.lsr-row:hover{background:#f8fbff}.lsr-row b{font-weight:700}.lsr-row small{display:block;color:#64748b;font-size:11.5px}
.lsr-empty{color:#94a3b8;font-style:italic;font-weight:500}
@media (max-width:767.98px){.lsr-row{grid-template-columns:minmax(0,1fr) auto}.lsr-row .lsr-hide-sm{display:none}}
</style>';
}

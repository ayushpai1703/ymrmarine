<?php
require_once 'config/config.php';
require_once 'includes/list_filters.php';
require_once 'includes/report_table.php';
checkAuth();

$db = getDB();
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$client_id = ($role === 'Client') ? getClientIdForUser($db, $user_id) : 0;
// Admin and Super Admin both see every completed vessel (Super Admin also has
// its own Generate Invoice/Expenses restrictions on completed_detail.php, but
// must not be scoped down to "their own" surveys like a Surveyor here).
$is_full_access = in_array($role, ['Admin', 'Super Admin'], true);

/** Report Completed month ('YYYY-MM') — the only date the month filter uses. */
function cvReportMonth(array $r): string {
    $v = (string)($r['report_uploaded_date'] ?? '');
    return ($v !== '' && strpos($v, '0000') !== 0 && ($t = strtotime(substr($v, 0, 10)))) ? date('Y-m', $t) : '';
}
/** Recovery counted in the month/total summary (blank / N/A → 0). */
function cvRecovery(array $r): float {
    global $db;
    if ($r['recovery_amount'] === null) return 0.0;
    // NOT APPLICABLE survey types count as 0 — same as the cards and the Excel export.
    return isRecoveryNotApplicable($db, (string)($r['survey_type_ids'] ?? ''), (string)($r['type_name'] ?? '')) ? 0.0 : abs((float)$r['recovery_amount']);
}

/* Default order: Report Completed Date (report_uploaded_date), newest first; vessels without one last. */
/* Filters, sorting and paging run in SQL (includes/report_table.php); the table, the phone
   cards and the Download use the same state. */
[$rtScopeWhere, $rtScopeParams] = rtScope('Completed', $role, (int)$user_id, (int)$client_id, $is_full_access);
$rt = rtState($_GET, 'report', $is_full_access);
[$rtWhereSql, $rtParams] = rtWhere($rt, $rtScopeWhere, $rtScopeParams);
[$rtTotal, $rtRows, $rtPage, $rtPages] = rtFetchPage($db, $rt, $rtWhereSql, $rtParams);
$rt['page'] = $rtPage;
$rtOffset = ($rtPage - 1) * RT_PER_PAGE;
$rtOpts = rtOptions($db, $rtScopeWhere, $rtScopeParams, $is_full_access);
$rtYears = rtYears($db, $rtScopeWhere, $rtScopeParams, $rt['date']);
$rtBase = 'completed.php';
$rtExport = rtUrl('export_completed.php', $rt);
$rtChipsHtml = rtChips($rtBase, $rt);
// Recovery of every vessel the filters match (all pages), same rule as before (cvRecovery).
$recStmt = $db->prepare('SELECT s.recovery_amount, s.survey_type_ids, st.type_name ' . rtFromSql() . " WHERE $rtWhereSql");
$recStmt->execute($rtParams);
$cvRecoveryTotal = array_sum(array_map('cvRecovery', $recStmt->fetchAll(PDO::FETCH_ASSOC)));
$cvRecoveryLabel = $rt['ym'] !== '' ? date('M Y', strtotime($rt['ym'] . '-01')) . ' Recovery' : 'Total Recovery';

// 🌟 Recovery: Total/VLSFO/HSFO/LSMGO, formatted "12.500 MT" or "—" when unset.
// 'na' => true (shown as NOT APPLICABLE only) for survey types that don't track
// Recovery, and for completed vessels with no recovery recorded at all.
function recoveryDisplayParts(array $survey): array {
    global $db;
    if (isRecoveryNotApplicable($db, (string)($survey['survey_type_ids'] ?? ''), (string)($survey['type_name'] ?? ''))
        || (($survey['recovery_amount'] ?? null) === null && ($survey['vlsfo_recovery'] ?? null) === null
            && ($survey['hsfo_recovery'] ?? null) === null && ($survey['lsmgo_recovery'] ?? null) === null)) {
        return ['na' => true, 'total' => RECOVERY_NA_LABEL, 'vlsfo' => '', 'hsfo' => '', 'lsmgo' => ''];
    }
    // Onhire surveys show their recovery with a minus sign (display only; totals stay positive)
    $onhire = isOnhireSurvey($db, (string)($survey['survey_type_ids'] ?? ''), (string)($survey['type_name'] ?? ''));
    $fmt = function ($v) use ($onhire) {
        return $v !== null ? recoveryDisplay($v, $onhire) . ' MT' : '—';
    };
    return [
        'na' => false,
        'total' => $fmt($survey['recovery_amount'] ?? null),
        'vlsfo' => $fmt($survey['vlsfo_recovery'] ?? null),
        'hsfo' => $fmt($survey['hsfo_recovery'] ?? null),
        'lsmgo' => $fmt($survey['lsmgo_recovery'] ?? null),
    ];
}

include 'includes/header.php';
?>
<link rel="stylesheet" href="assets/css/report-table.css?v=<?= @filemtime(__DIR__ . '/assets/css/report-table.css') ?>">
<style>
/* Search box in main's toolbar: icon inside the field, box keeps its size */
.vd-toolbar .rt-vd-search { position: relative; flex: 0 0 auto !important; margin: 0; }
.vd-toolbar .rt-vd-search > i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 12.5px; pointer-events: none; }
.vd-toolbar .rt-vd-search input[type=search] { padding-left: 32px; }
</style>

<style>

    .pv-row-num {
        flex: none; width: 22px; height: 22px; border-radius: 50%;
        background: #F1F5F9; color: #64748b; font-size: 11px; font-weight: 800;
        display: flex; align-items: center; justify-content: center;
    }
    .list-desktop-view { display: none; }
    .list-mobile-view { display: block; }
    @media (min-width: 992px) {
        .list-mobile-view { display: none !important; }
        .list-desktop-view { display: block !important; }
        .vd-wrap { padding: 8px 20px 40px; max-width: 1400px; margin: 0 auto; }
        .vd-toolbar {
            display: flex; flex-wrap: nowrap; align-items: center; gap: 8px;
            margin-bottom: 14px; overflow-x: auto; padding-bottom: 4px;
        }
        .vd-toolbar form {
            display: flex; flex-wrap: nowrap; align-items: center; gap: 8px;
            flex: 1 1 auto; min-width: 0;
        }
        .vd-toolbar input[type=search],
        .vd-toolbar select {
            border: 1px solid #e2e8f0; border-radius: 10px; padding: 9px 12px;
            font-size: 13px; background: #fff; color: #0f172a; height: 40px; box-sizing: border-box;
        }
        .vd-toolbar input[type=search] { min-width: 180px; width: 220px; flex: 0 0 auto; }
        .vd-toolbar select { max-width: 140px; flex: 0 0 auto; }
        .vd-toolbar .btn-filter,
        .vd-toolbar .btn-clear,
        .vd-toolbar .btn-export {
            height: 40px; box-sizing: border-box; border-radius: 10px; padding: 0 14px;
            font-weight: 650; font-size: 13px; display: inline-flex; align-items: center;
            justify-content: center; gap: 6px; white-space: nowrap; flex: 0 0 auto;
            text-decoration: none; border: none; cursor: pointer; line-height: 1;
        }
        .vd-toolbar .btn-filter { background: #3b32b3; color: #fff; }
        .vd-toolbar .btn-clear { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
        .vd-toolbar .btn-export { background: #16a34a; color: #fff; margin-left: auto; }
        
        .vd-toolbar-inner {
            display: flex; flex-wrap: nowrap; align-items: center; gap: 8px;
            flex: 1 1 auto; min-width: 0;
        }
        .vd-export-row { margin: 0 0 12px 0; }
        .vd-export-row .btn-export {
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            height: 40px; padding: 0 16px; border-radius: 10px; font-weight: 650; font-size: 13px;
            background: #16a34a; color: #fff; text-decoration: none; border: none;
        }
        .vd-meta { font-size: 12px; color: #64748b; margin-bottom: 10px; }
        .vd-table-card {
            background: #fff; border: 1px solid #e2e8f0; border-radius: 16px;
            overflow: hidden; box-shadow: 0 4px 20px rgba(15,23,42,.04);
        }
        .vd-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .vd-table thead th {
            background: #0b1e46; color: #fff; font-weight: 650; font-size: 11px;
            letter-spacing: .04em; text-transform: uppercase; padding: 12px 14px; text-align: left; white-space: nowrap;
        }
        .vd-table tbody td {
            padding: 12px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; color: #334155;
        }
        .vd-table tbody tr:hover { background: #f8fafc; }
        .vd-table tbody tr:last-child td { border-bottom: none; }
        .vd-vessel { font-weight: 700; color: #0f172a; text-decoration: none; }
        .vd-vessel:hover { color: #3b32b3; }
        .vd-sub { font-size: 11px; color: #94a3b8; margin-top: 2px; }
        .vd-badge {
            display: inline-block; background: #e0f2fe; color: #0369a1;
            font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: 8px; text-transform: uppercase;
            max-width: 100%; text-align: left; line-height: 1.4;
        }
        .vd-actions { display: flex; gap: 6px; flex-wrap: wrap; }
        .vd-actions a {
            display: inline-flex; align-items: center; gap: 4px; padding: 6px 10px; border-radius: 8px;
            font-size: 11px; font-weight: 650; text-decoration: none; border: 1px solid #e2e8f0;
            color: #3b32b3; background: #fff;
        }
        .vd-actions a:hover { background: #3b32b3; color: #fff; border-color: #3b32b3; }
        .vd-pagination {
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;
            padding: 14px 16px; background: #f8fafc; border-top: 1px solid #e2e8f0;
        }
        .vd-page-info { font-size: 12px; color: #64748b; }
        .vd-page-links { display: flex; gap: 4px; flex-wrap: wrap; }
        .vd-page-links a, .vd-page-links span {
            min-width: 34px; height: 34px; display: inline-flex; align-items: center; justify-content: center;
            border-radius: 8px; font-size: 12px; font-weight: 650; text-decoration: none;
            border: 1px solid #e2e8f0; background: #fff; color: #334155; padding: 0 8px;
        }
        .vd-page-links a:hover { border-color: #3b32b3; color: #3b32b3; }
        .vd-page-links .active { background: #3b32b3; color: #fff; border-color: #3b32b3; }
        .vd-page-links .disabled { opacity: .45; pointer-events: none; }
    }

.vd-badge { background: #d1fae5 !important; color: #065f46 !important; }

/* Recovery: visible to every role. The surveyor-name tooltip is only
   present in the markup for Admin/Super Admin (see $is_full_access below),
   so .no-tooltip / plain cursor is purely cosmetic for everyone else.
   Shows on mouse hover/keyboard focus (desktop) and on tap via the
   .tooltip-open class toggled by JS (mobile/touch, no real :hover there). */
.recovery-cell { position: relative; display: inline-block; cursor: pointer; -webkit-tap-highlight-color: transparent; }
.recovery-cell.no-tooltip { cursor: default; }
.recovery-total {
    /* Total recovery as a pill (same shape as the survey-type pill), bigger
       font; the VLSFO/HSFO/LSMGO line below keeps its small size. */
    display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;
    font-size: 13px; font-weight: 700; line-height: 1.2; color: #fff;
    padding: 6px 12px; border-radius: 999px;
    background: linear-gradient(120deg, #4B2FD6 0%, #7C3AED 55%, #DB2777 100%);
    box-shadow: 0 4px 12px rgba(91,45,238,.28);
}
.recovery-total i { font-size: 11px; opacity: .9; }
.recovery-total b { font-weight: 800; }
.recovery-total.na { background: #F1F5F9; color: #475569; box-shadow: 0 0 0 1px #e2e8f0; font-size: 12px; letter-spacing: .03em; }
.recovery-parts { display: block; font-size: 10.5px; color: #64748b; font-weight: 500; margin-top: 5px; }
.recovery-cell .recovery-tooltip {
    position: absolute; left: 0; top: calc(100% + 6px); z-index: 60;
    background: #0f172a; color: #fff; font-size: 11px; font-weight: 600;
    padding: 6px 10px; border-radius: 8px; white-space: nowrap;
    opacity: 0; visibility: hidden; transform: translateY(-4px);
    transition: opacity .15s ease, transform .15s ease, visibility .15s ease;
    pointer-events: none;
    box-shadow: 0 8px 20px rgba(15,23,42,.25);
}
.recovery-cell:hover .recovery-tooltip,
.recovery-cell:focus .recovery-tooltip,
.recovery-cell.tooltip-open .recovery-tooltip {
    opacity: 1; visibility: visible; transform: translateY(0);
}
</style>
<div class="scroll-content" data-list-scope data-testid="completed-surveys-page">
    <?php $page_title = 'Completed Surveys'; $back_url = 'index.php'; $page_testid = 'completed-surveys'; include 'includes/top_app_bar.php'; ?>

    <div class="list-mobile-view">
    <div class="rt-mobile-top">
        <form class="search-filter-row" method="get" action="<?= sanitize($rtBase) ?>" role="search" data-rt-search>
            <?= rtHidden($rt, ['q']) ?>
            <div class="search-input-wrapper">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="search" name="q" class="search-control" value="<?= sanitize($rt['q']) ?>" placeholder="Search by vessel, report no, client or agent..." autocomplete="off" data-testid="rt-search-m">
            </div>
        </form>
        <div class="px-3 mb-2">
            <a href="<?= sanitize($rtExport) ?>" class="btn btn-success w-100" style="background-color:#28a745;border:none;font-weight:600;padding:8px;" data-testid="rt-download-m">
                <i class="fa-solid fa-file-excel me-2"></i> Export to Excel
            </a>
        </div>
        <div class="cv-rec-summary cv-rec-mob" data-testid="cv-recovery-mobile">
            <span><i class="fa-solid fa-droplet"></i> <?= sanitize($cvRecoveryLabel) ?></span>
            <b><?= number_format($cvRecoveryTotal, 3) ?> MT</b>
            <small><?= (int)$rtTotal ?> vessels</small>
        </div>
        <div class="px-3"><?= rtMobileBar($rt, $is_full_access) ?><?= $rtChipsHtml ?><?= rtMeta($rt, $rtTotal, $rtOffset, count($rtRows)) ?></div>
    </div>
    <div class="vessel-list-container">
        <?php if (count($rtRows) > 0): foreach ($rtRows as $rtI => $survey):
            $surveyorNames = getCombinedSurveyorNames($db, $survey['id'], $survey['surveyor_name'] ?? 'N/A');
            $card_date_src = $survey['report_uploaded_date'] ?? $survey['survey_completed_date'] ?? $survey['assign_date'] ?? '';
            $card_ts = (!empty($card_date_src) && $card_date_src !== '0000-00-00' && $card_date_src !== '0000-00-00 00:00:00')
                ? strtotime($card_date_src) : false;
        ?>
        <div class="vessel-card completed-list-card" onclick="location.href='completed_detail.php?id=<?= (int)$survey['id'] ?>'"
             style="background:white;border-radius:16px;padding:15px;margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;border-left:4px solid #10b981;cursor:pointer;"
             data-survey-card
             data-name="<?= sanitize(strtolower($survey['vessel_name'] ?? '')) ?>"
             data-type="<?= sanitize(getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? '')) ?>"
             data-place="<?= sanitize($survey['port_name'] ?? '') ?>"
             data-client="<?= sanitize($survey['company_name'] ?? '') ?>"
             data-surveyor="<?= sanitize($surveyorNames) ?>"
             data-status="completed"
             data-month="<?= sanitize(cvReportMonth($survey)) ?>"
             data-recovery="<?= cvRecovery($survey) ?>"
             data-date="<?= surveyReportTs($survey) ?>"
             data-search="<?= sanitize(strtolower(implode(' ', [$survey['vessel_name'] ?? '', $survey['company_name'] ?? '', $survey['type_name'] ?? '', $survey['port_name'] ?? '']))) ?>">
            <div style="display:flex;align-items:center;gap:12px;">
                <span class="pv-row-num" data-testid="pv-row-num"><?= $rtOffset + $rtI + 1 ?></span>
                <div style="width:42px;height:42px;border-radius:50%;background:#e6f4ea;color:#137333;display:flex;justify-content:center;align-items:center;font-weight:700;">
                    <?= strtoupper(substr($survey['vessel_name'] ?? 'X', 0, 1)) ?>
                </div>
                <div>
                    <h4 style="margin:0;font-size:14px;font-weight:700;"><?= sanitize($survey['vessel_name']) ?></h4>
                    <p style="margin:2px 0 0;font-size:12px;color:#64748b;">Client: <?= sanitize($survey['company_name'] ?? '') ?></p>
                    <p style="margin:2px 0 0;font-size:11px;">Type: <span style="color:#047857;font-weight:600;"><?= sanitize(getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? 'N/A')) ?></span></p>
                    <?php $rp = recoveryDisplayParts($survey); ?>
                    <p style="margin:4px 0 0;font-size:11px;">
                        <span class="recovery-cell<?= $is_full_access ? '' : ' no-tooltip' ?>"<?= $is_full_access ? ' tabindex="0"' : '' ?> data-testid="recovery-cell-mobile">
                            <?php if ($rp['na']): ?>
                            <span class="recovery-total na" data-testid="recovery-pill"><b><?= sanitize($rp['total']) ?></b></span>
                            <?php else: ?>
                            <span class="recovery-total" data-testid="recovery-pill"><i class="fa-solid fa-droplet"></i> Total: <b><?= sanitize($rp['total']) ?></b></span>
                            <span class="recovery-parts">VLSFO: <?= sanitize($rp['vlsfo']) ?> · HSFO: <?= sanitize($rp['hsfo']) ?> · LSMGO: <?= sanitize($rp['lsmgo']) ?></span>
                            <?php endif; ?>
                            <?php if ($is_full_access): ?>
                            <span class="recovery-tooltip"><i class="fa-solid fa-user-tie me-1"></i>Surveyor: <?= sanitize($surveyorNames) ?></span>
                            <?php endif; ?>
                        </span>
                    </p>
                </div>
            </div>
            <div style="text-align:right;">
                <span style="font-size:10px;padding:3px 8px;border-radius:6px;font-weight:600;background:#d1fae5;color:#065f46;"><i class="fa-solid fa-user-check"></i> Completed</span>
                <div style="font-size:11.5px;font-weight:700;color:#64748b;margin-top:4px;"><?= sanitize($surveyorNames) ?></div>
                <?php $rtD = rtRowDate($survey, $rt['date']); ?>
                <div style="font-size:11px;color:#64748b;margin-top:2px;" data-testid="cv-date"><i class="fa-solid fa-calendar-check"></i> <?= $rt['date'] === 'report' ? '' : sanitize(rtDateShort($rt['date'])) . ': ' ?><?= $rtD !== '' ? date('d M Y', strtotime($rtD)) : '—' ?></div>
            </div>
        </div>
        <?php endforeach; ?>
        <div class="py-3"><?= rtPager($rtBase, $rt, $rtPage, $rtPages) ?></div>
        <?php else: ?>
        <div class="bg-white p-4 text-center text-muted rounded-3 border border-dashed my-3"><i class="fa-solid fa-folder-open d-block mb-2 fs-2"></i><?= rtHasFilters($rt) ? 'No matching records.' : 'No completed surveys found.' ?></div>
        <?php endif; ?>
    </div>
    </div>


    
    <div class="list-desktop-view vessels-desktop-view">
        <div class="vd-wrap">
            <div class="vd-toolbar">
                <div class="vd-toolbar-inner">
                    <?= rtDeskSearch($rtBase, $rt) ?>
                    <?php if ($is_full_access): ?>
                    <button type="button" class="rt-tb-dd<?= $rt['surveyor'] ? ' has-value' : '' ?>" data-rt-open="surveyor" data-testid="rt-surveyor-filter">
                        <span><?= $rt['surveyor'] ? (count($rt['surveyor']) === 1 ? sanitize($rt['surveyor'][0]) : count($rt['surveyor']) . ' surveyors') : 'All surveyors' ?></span><i class="fa-solid fa-chevron-down"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="vd-export-row">
                <a href="<?= sanitize($rtExport) ?>" class="btn-export" data-testid="rt-download"><i class="fa-solid fa-file-excel"></i> Export Excel</a>
            </div>
            <div class="cv-rec-summary cv-rec-desk" data-testid="cv-recovery-desktop">
                <span><i class="fa-solid fa-droplet"></i> <?= sanitize($cvRecoveryLabel) ?></span>
                <b><?= number_format($cvRecoveryTotal, 3) ?> MT</b>
                <small><?= (int)$rtTotal ?> vessels</small>
            </div>
            <?= $rtChipsHtml ?>
            <?= rtMeta($rt, $rtTotal, $rtOffset, count($rtRows)) ?>
            <div class="vd-table-card">
                <?php if (!$rtRows): ?>
                <div class="rt-empty" data-testid="rt-empty"><i class="fa-solid fa-ship"></i><?= rtHasFilters($rt) ? 'No vessels match these filters.' : 'No completed vessels.' ?></div>
                <?php endif; ?>
                <div class="vd-table-wrap" style="overflow-x:auto;">
                    <table class="vd-table rt-table">
                        <thead>
                            <tr>
                                <?= rtHead($rt, 'num', '#') ?>
                                <?= rtHead($rt, 'vessel', 'Vessel') ?>
                                <?= rtHead($rt, 'client', 'Client / Agent') ?>
                                <?= rtHead($rt, 'type', 'Survey Type') ?>
                                <?= rtHead($rt, 'port', 'Port') ?>
                                <th class="rt-th static">Recovery</th>
                                <?= rtHead($rt, 'date', 'Date', rtDateShort($rt['date'])) ?>
                                <th class="rt-th static">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($rtRows)): foreach ($rtRows as $i => $survey):
                                $vessel_name = $survey['vessel_name'] ?: 'Vessel';
                                $typeLabel = getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? 'N/A');
                                $surveyorNames = getCombinedSurveyorNames($db, $survey['id'], $survey['surveyor_name'] ?? 'N/A');
                                $date_src = $survey['report_uploaded_date'] ?? $survey['survey_completed_date'] ?? $survey['assign_date'] ?? '';
                                $rtD = rtRowDate($survey, $rt['date']);
                                $date_disp = $rtD !== '' ? date('d M Y', strtotime($rtD)) : '—';
                                $ts = strtotime($date_src ?: '1970-01-01') ?: 0;
                            ?>
                            <tr class="vd-row"
                                data-search="<?= sanitize(strtolower(implode(' ', [$vessel_name, $survey['company_name'] ?? '', $survey['agent_name'] ?? '', $typeLabel, $survey['port_name'] ?? '', $surveyorNames, $survey['report_number'] ?? '']))) ?>"
                                data-name="<?= sanitize(strtolower($vessel_name)) ?>"
                                data-type="<?= sanitize(strtolower(getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? ''))) ?>"
                                data-place="<?= sanitize(strtolower($survey['port_name'] ?? '')) ?>"
                                data-client="<?= sanitize(strtolower($survey['company_name'] ?? '')) ?>"
                                data-surveyor="<?= sanitize(strtolower($surveyorNames)) ?>"
                                data-month="<?= sanitize(cvReportMonth($survey)) ?>"
                                data-recovery="<?= cvRecovery($survey) ?>"
                                data-date="<?= surveyReportTs($survey) ?>">
                                <td style="color:#94a3b8;font-weight:600;" data-testid="rt-num"><?= $rtOffset + $i + 1 ?></td>
                                <td>
                                    <a class="vd-vessel" href="completed_detail.php?id=<?= (int)$survey['id'] ?>"><?= sanitize($vessel_name) ?></a>
                                    <?php if (!empty($survey['report_number'])): ?>
                                        <div class="vd-sub">Report: <?= sanitize($survey['report_number']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div><?= sanitize($survey['company_name'] ?? '') ?></div>
                                    <div class="vd-sub">Agent: <?= sanitize($survey['agent_name'] ?? '—') ?></div>
                                </td>
                                <td><span class="vd-badge"><?= sanitize($typeLabel) ?></span></td>
                                <td><i class="fa-solid fa-location-dot" style="opacity:.5;"></i> <?= sanitize($survey['port_name'] ?? 'N/A') ?></td>
                                <?php $rp = recoveryDisplayParts($survey); ?>
                                <td>
                                    <span class="recovery-cell<?= $is_full_access ? '' : ' no-tooltip' ?>"<?= $is_full_access ? ' tabindex="0"' : '' ?> data-testid="recovery-cell-desktop">
                                        <?php if ($rp['na']): ?>
                                        <span class="recovery-total na" data-testid="recovery-pill"><b><?= sanitize($rp['total']) ?></b></span>
                                        <?php else: ?>
                                        <span class="recovery-total" data-testid="recovery-pill"><i class="fa-solid fa-droplet"></i> Total: <b><?= sanitize($rp['total']) ?></b></span>
                                        <span class="recovery-parts">VLSFO: <?= sanitize($rp['vlsfo']) ?> · HSFO: <?= sanitize($rp['hsfo']) ?> · LSMGO: <?= sanitize($rp['lsmgo']) ?></span>
                                        <?php endif; ?>
                                        <?php if ($is_full_access): ?>
                                        <span class="recovery-tooltip"><i class="fa-solid fa-user-tie me-1"></i>Surveyor: <?= sanitize($surveyorNames) ?></span>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td style="white-space:nowrap;"><?= sanitize($date_disp) ?></td>
                                <td>
                                    <div class="vd-actions">
                                        <a href="completed_detail.php?id=<?= (int)$survey['id'] ?>"><i class="fa-solid fa-eye"></i> View</a>
                                        <?php if ($role === 'Admin' && 'completed' === 'vessels'): ?>
                                        <a class="edit" href="assign_vessel.php?edit_id=<?= (int)$survey['id'] ?>"><i class="fa-solid fa-pen"></i> Edit</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="rt-foot">
                    <div>25 per page</div>
                    <?= rtPager($rtBase, $rt, $rtPage, $rtPages) ?>
                </div>
            </div>
        </div>
    </div>


<?= rtMenus($rtBase, $rt, $rtOpts, $rtYears, $is_full_access) ?>
<script src="assets/js/report-table.js?v=<?= @filemtime(__DIR__ . '/assets/js/report-table.js') ?>"></script>

<script>
// Recovery tooltip toggle (mobile card only — the desktop cell has no
// .recovery-tooltip child so this stays inert there). Capture phase is
// required: the mobile card itself has its own onclick="location.href=...'"
// navigation, and a bubble-phase listener here would run AFTER that
// ancestor onclick has already fired, making stopPropagation() too late.
document.addEventListener('click', function (ev) {
    var cell = ev.target.closest('.recovery-cell');
    var hasTooltip = cell && cell.querySelector('.recovery-tooltip');
    document.querySelectorAll('.recovery-cell.tooltip-open').forEach(function (openCell) {
        if (openCell !== cell) openCell.classList.remove('tooltip-open');
    });
    if (hasTooltip) {
        ev.stopPropagation();
        cell.classList.toggle('tooltip-open');
    }
}, true);
</script>

<style>
.cv-rec-summary { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin: 4px 14px 10px; padding: 10px 14px; border-radius: 14px; background: linear-gradient(120deg,#ECFDF5,#F0FDFA); border: 1px solid #A7F3D0; color: #065F46; font-size: 12.5px; }
.cv-rec-summary b { font-size: 15px; color: #047857; }
.cv-rec-summary small { margin-left: auto; color: #64748b; font-weight: 600; }
.cv-rec-desk { margin: 0 0 10px; }
@media (min-width: 992px) { .cv-rec-mob { display: none; } }
.rt-tb-dd { height: 40px; display: inline-flex; align-items: center; gap: 8px; padding: 0 12px; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; color: #0f172a; font-size: 13px; cursor: pointer; flex: none; }
.rt-tb-dd i { font-size: 9px; color: #94A3B8; }
.rt-tb-dd.has-value { border-color: #A78BFA; background: #F3EEFE; color: #5B2DEE; font-weight: 600; }
</style>

<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>

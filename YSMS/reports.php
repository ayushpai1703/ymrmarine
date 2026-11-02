<?php
require_once 'config/config.php';
require_once 'includes/list_filters.php';
require_once 'includes/report_table.php';
checkAuth();

$db = getDB();
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$client_id = ($role === 'Client') ? getClientIdForUser($db, $user_id) : 0;
// Admin and Super Admin both see every pending report (read-only for Super Admin —
// this page has no edit action either way — but must not be scoped down to "their
// own" surveys like a Surveyor).
$is_full_access = in_array($role, ['Admin', 'Super Admin'], true);

/* Default order: Survey Completed Date, newest first; reports without one last. */
/* Filters, sorting and paging run in SQL (includes/report_table.php); the table, the phone
   cards and the Download use the same state. */
[$rtScopeWhere, $rtScopeParams] = rtScope('Pending Report', $role, (int)$user_id, (int)$client_id, $is_full_access);
$rt = rtState($_GET, 'survey', $is_full_access);
[$rtWhereSql, $rtParams] = rtWhere($rt, $rtScopeWhere, $rtScopeParams);
[$rtTotal, $rtRows, $rtPage, $rtPages] = rtFetchPage($db, $rt, $rtWhereSql, $rtParams);
$rt['page'] = $rtPage;
$rtOffset = ($rtPage - 1) * RT_PER_PAGE;
$rtOpts = rtOptions($db, $rtScopeWhere, $rtScopeParams, $is_full_access);
$rtYears = rtYears($db, $rtScopeWhere, $rtScopeParams, $rt['date']);
$rtBase = 'reports.php';
$rtExport = rtUrl('export_reports.php', $rt);
$rtChipsHtml = rtChips($rtBase, $rt);

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

    /* Card base (mobile + shared) */
    .vessel-card {
        border: 2px solid #00a3df;
        background-color: #fff;
        padding: 0;
        border-radius: 12px;
        margin-bottom: 15px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        display: flex;
        flex-direction: column;
        align-items: stretch;
        overflow: hidden;
    }
    .vessel-card-top-content {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 16px;
        padding: 15px 15px 10px 15px;
        width: 100%;
        box-sizing: border-box;
    }
    .vessel-avatar-info {
        display: flex;
        align-items: flex-start;
        flex: 1 1 auto;
        min-width: 0;
        text-align: left;
    }
    .vessel-thumb { width: 50px; height: 50px; object-fit: cover; border-radius: 8px; margin-right: 12px; }
    .pv-row-num {
        flex: none; width: 22px; height: 22px; border-radius: 50%;
        background: #F1F5F9; color: #64748b; font-size: 11px; font-weight: 800;
        display: flex; align-items: center; justify-content: center;
        margin-right: 10px; margin-top: 2px;
    }
    .vessel-name-title {
        font-size: 16px;
        font-weight: 600;
        margin: 0 0 2px 0;
        text-align: left;
        word-break: break-word;
    }
    .vessel-client-sub { font-size: 11px; color: #666; margin: 2px 0 0 0; text-align: left; }
    .badge-assigned {
        background: #00a3df; color: #fff; padding: 5px 10px; border-radius: 10px;
        font-weight: 600; font-size: 10px; text-transform: uppercase; display: inline-block;
        max-width: 100%; text-align: left; line-height: 1.4;
    }
    .vessel-live-status-bar {
        background: #f1f5f9;
        border-top: 1px dashed #ced4da;
        padding: 10px 15px;
        margin-top: 0;
        font-size: 11.5px;
        width: 100%;
        box-sizing: border-box;
        text-align: left;
    }

    .search-filter-row { display: flex; gap: 10px; padding: 10px 15px; }
    .search-input-wrapper { flex-grow: 1; position: relative; }
    .search-control { width: 100%; padding: 8px 35px; border-radius: 8px; border: 1px solid #ddd; }
    .scroll-content { padding-bottom: 70px; }

    @media (max-width: 991px) {
        .vessel-card { flex-direction: column; align-items: stretch !important; padding: 0 !important; }
        .vessel-card-top-content { display: flex; justify-content: space-between; align-items: center; padding: 15px 15px 5px 15px; }
    }

    /* 🖥️ Desktop-only: full width, 2 cards per row */
    @media (min-width: 992px) {
        .vessel-list-container {
            max-width: none !important;
            width: 100%;
            padding: 0 16px 20px !important;
            display: grid !important;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            align-items: stretch;
        }
        .vessel-list-container > .text-center,
        .vessel-list-container > .list-empty-state {
            grid-column: 1 / -1;
        }
        .search-filter-row {
            max-width: none;
            width: 100%;
            padding: 14px 16px 10px;
        }
        .list-controls-panel {
            max-width: none;
            margin-left: 16px;
            margin-right: 16px;
        }
        .px-3.mb-3 {
            max-width: none;
            padding-left: 16px !important;
            padding-right: 16px !important;
        }
        .px-3.mb-3 .btn {
            width: auto !important;
            display: inline-flex;
            align-items: center;
            padding: 10px 18px !important;
            border-radius: 10px;
        }
        .vessel-card {
            display: flex !important;
            flex-direction: column !important;
            align-items: stretch !important;
            justify-content: flex-start !important;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-left: 4px solid #f59e0b;
            border-radius: 14px;
            margin-bottom: 0 !important;
            box-shadow: 0 2px 8px rgba(15,23,42,.04);
            height: 100%;
        }
        .vessel-card-top-content {
            display: flex !important;
            flex-direction: row !important;
            justify-content: space-between !important;
            align-items: flex-start !important;
            gap: 12px;
            padding: 14px 16px 10px;
            width: 100%;
        }
        .vessel-avatar-info {
            flex: 1 1 auto;
            min-width: 0;
            text-align: left !important;
        }
        .vessel-name-title {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
            text-align: left !important;
        }
        .vessel-client-sub {
            font-size: 12px;
            color: #64748b;
            text-align: left !important;
        }
        .text-end {
            flex: 0 0 auto;
            text-align: right !important;
            max-width: 48%;
        }
        .vessel-live-status-bar {
            width: 100% !important;
            margin: 0 !important;
            margin-top: auto !important;
            padding: 10px 16px;
            text-align: left !important;
            border-radius: 0 0 14px 14px;
            background: #fffbeb;
            border-top: 1px dashed #fcd34d;
        }
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
            font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: 999px; text-transform: uppercase;
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

.badge-assigned {
    background: #f59e0b; color: #fff; padding: 5px 10px; border-radius: 10px;
    font-weight: 600; font-size: 10px; text-transform: uppercase; display: inline-block;
    max-width: 100%; text-align: left; line-height: 1.4;
}
.vessel-card {
    border: 2px solid #f59e0b; background: #fff; border-radius: 12px; margin-bottom: 15px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.05); overflow: hidden; cursor: pointer;
}
.vessel-card-top-content { display:flex; justify-content:space-between; gap:16px; padding:15px; }
.vessel-name-title { margin:0; font-size:14px; font-weight:700; }
.vessel-client-sub { margin:2px 0 0; font-size:12px; color:#64748b; }
</style>
<div class="scroll-content" data-list-scope data-testid="pending-reports-page">
    <?php $page_title = 'Pending Reports'; $back_url = 'index.php'; $page_testid = 'pending-reports'; include 'includes/top_app_bar.php'; ?>

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
        <div class="px-3"><?= rtMobileBar($rt, $is_full_access) ?><?= $rtChipsHtml ?><?= rtMeta($rt, $rtTotal, $rtOffset, count($rtRows)) ?></div>
    </div>
    <div class="vessel-list-container">
        <?php if (!empty($rtRows)): foreach ($rtRows as $rtI => $survey):
            $vessel_name = $survey['vessel_name'] ?: 'Vessel';
            $typeLabel = getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? 'N/A');
            $surveyorNames = getCombinedSurveyorNames($db, $survey['id'], $survey['surveyor_name'] ?? 'N/A');
        ?>
        <div class="vessel-card" onclick="location.href='report_detail.php?id=<?= (int)$survey['id'] ?>'" data-survey-card
             data-name="<?= sanitize(strtolower($vessel_name)) ?>"
             data-type="<?= sanitize(getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? '')) ?>"
             data-place="<?= sanitize($survey['port_name'] ?? '') ?>"
             data-client="<?= sanitize($survey['company_name'] ?? '') ?>"
             data-surveyor="<?= sanitize($surveyorNames) ?>"
             data-status="pending"
             data-date="<?= surveyCompletedTs($survey) ?>"
             data-search="<?= sanitize(strtolower(implode(' ', [$vessel_name, $survey['company_name'] ?? '', $survey['agent_name'] ?? '', $typeLabel, $survey['port_name'] ?? '']))) ?>">
            <div class="vessel-card-top-content">
                <div class="vessel-avatar-info"><span class="pv-row-num" data-testid="pv-row-num"><?= $rtOffset + $rtI + 1 ?></span><div>
                    <h4 class="vessel-name-title"><?= sanitize($vessel_name) ?></h4>
                    <p class="vessel-client-sub">Client: <?= sanitize($survey['company_name'] ?? '') ?></p>
                    <p class="vessel-client-sub">Agent: <?= sanitize($survey['agent_name'] ?? '') ?></p>
                    <?php if ($is_full_access): ?><p class="vessel-client-sub">Surveyor: <?= sanitize($surveyorNames) ?></p><?php endif; ?>
                </div></div>
                <div class="vessel-badge-date text-end">
                    <span class="badge-assigned"><?= sanitize($typeLabel) ?></span>
                    <div><span class="badge-place"><i class="fa-solid fa-location-dot"></i> <?= sanitize($survey['port_name'] ?? 'N/A') ?></span></div>
                    <div class="vessel-client-sub mt-1" style="text-align:right" data-testid="pr-completed-date"><?php $rtD = rtRowDate($survey, $rt['date']); ?><i class="fa-solid fa-calendar-check"></i> <?= $rt['date'] === 'survey' ? '' : sanitize(rtDateShort($rt['date'])) . ': ' ?><?= $rtD !== '' ? date('d M Y', strtotime($rtD)) : '—' ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; else: ?>
            <div class="text-center py-5 px-3 mx-3 mt-4 bg-white rounded-4 border border-dashed shadow-sm"><h5><?= rtHasFilters($rt) ? 'No matching reports found' : 'No Pending Reports' ?></h5></div>
        <?php endif; ?>
        <div class="py-3 px-3"><?= rtPager($rtBase, $rt, $rtPage, $rtPages) ?></div>
    </div>
    </div>


    
    <div class="list-desktop-view vessels-desktop-view">
        <div class="vd-wrap">
            <div class="vd-toolbar">
                <div class="vd-toolbar-inner">
                    <?= rtDeskSearch($rtBase, $rt) ?>
                </div>
            </div>
            <div class="vd-export-row">
                <a href="<?= sanitize($rtExport) ?>" class="btn-export" data-testid="rt-download"><i class="fa-solid fa-file-excel"></i> Export Excel</a>
            </div>
            <?= $rtChipsHtml ?>
            <?= rtMeta($rt, $rtTotal, $rtOffset, count($rtRows)) ?>
            <div class="vd-table-card">
                <?php if (!$rtRows): ?>
                <div class="rt-empty" data-testid="rt-empty"><i class="fa-solid fa-ship"></i><?= rtHasFilters($rt) ? 'No reports match these filters.' : 'No pending reports.' ?></div>
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
                                <?php if ($is_full_access): ?><?= rtHead($rt, 'surveyor', 'Surveyor') ?><?php endif; ?>
                                <?= rtHead($rt, 'date', 'Date', rtDateShort($rt['date'])) ?>
                                <th class="rt-th static">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($rtRows)): foreach ($rtRows as $i => $survey):
                                $vessel_name = $survey['vessel_name'] ?: 'Vessel';
                                $typeLabel = getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? 'N/A');
                                $surveyorNames = getCombinedSurveyorNames($db, $survey['id'], $survey['surveyor_name'] ?? 'N/A');
                                $ts = surveyCompletedTs($survey);
                                $rtD = rtRowDate($survey, $rt['date']);
                                $date_disp = $rtD !== '' ? date('d M Y', strtotime($rtD)) : '—';
                            ?>
                            <tr class="vd-row"
                                data-search="<?= sanitize(strtolower(implode(' ', [$vessel_name, $survey['company_name'] ?? '', $survey['agent_name'] ?? '', $typeLabel, $survey['port_name'] ?? '', $surveyorNames, $survey['report_number'] ?? '']))) ?>"
                                data-name="<?= sanitize(strtolower($vessel_name)) ?>"
                                data-type="<?= sanitize(strtolower(getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? ''))) ?>"
                                data-place="<?= sanitize(strtolower($survey['port_name'] ?? '')) ?>"
                                data-client="<?= sanitize(strtolower($survey['company_name'] ?? '')) ?>"
                                data-surveyor="<?= sanitize(strtolower($surveyorNames)) ?>"
                                data-date="<?= (int)$ts ?>">
                                <td style="color:#94a3b8;font-weight:600;" data-testid="rt-num"><?= $rtOffset + $i + 1 ?></td>
                                <td>
                                    <a class="vd-vessel" href="report_detail.php?id=<?= (int)$survey['id'] ?>"><?= sanitize($vessel_name) ?></a>
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
                                <?php if ($is_full_access): ?>
                                <td><?= sanitize($surveyorNames) ?></td>
                                <?php endif; ?>
                                <td style="white-space:nowrap;"><?= sanitize($date_disp) ?></td>
                                <td>
                                    <div class="vd-actions">
                                        <a href="report_detail.php?id=<?= (int)$survey['id'] ?>"><i class="fa-solid fa-eye"></i> View</a>
                                        <?php if ($role === 'Admin' && 'reports' === 'vessels'): ?>
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

<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>

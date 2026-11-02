<?php
require_once 'config/config.php';
require_once 'includes/list_filters.php';
require_once 'includes/report_table.php';
checkAuth();

$db = getDB();

// 🌟 SAFETY NET: hsfo_recovery column, same as completed_detail.php /
// ajax/upload_handler.php — covers a live DB where this column hasn't been
// added yet, so the Recovery display below never silently breaks.
try {
    $cols = $db->query("SHOW COLUMNS FROM surveys LIKE 'hsfo_recovery'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE surveys ADD COLUMN hsfo_recovery DECIMAL(12,3) DEFAULT NULL");
    }
} catch (Exception $e) { error_log('completed.php hsfo_recovery column: ' . $e->getMessage()); }

/** Total/VLSFO/HSFO/LSMGO recovery, formatted "12.500 MT" (or "—" when not recorded yet).
 *  'na' => true for survey types that don't track Recovery — shown as NOT APPLICABLE only. */
function recoveryDisplayParts(array $survey): array {
    global $db;
    if (isRecoveryNotApplicable($db, (string)($survey['survey_type_ids'] ?? ''), (string)($survey['type_name'] ?? ''))) {
        return ['na' => true, 'total' => RECOVERY_NA_LABEL, 'vlsfo' => '', 'hsfo' => '', 'lsmgo' => ''];
    }
    // Completed with no recovery recorded at all -> also just NOT APPLICABLE
    if (($survey['recovery_amount'] ?? null) === null && ($survey['vlsfo_recovery'] ?? null) === null
        && ($survey['hsfo_recovery'] ?? null) === null && ($survey['lsmgo_recovery'] ?? null) === null) {
        return ['na' => true, 'total' => RECOVERY_NA_LABEL, 'vlsfo' => '', 'hsfo' => '', 'lsmgo' => ''];
    }
    // Onhire surveys show their recovery with a minus sign (display only; totals stay positive)
    $onhire = isOnhireSurvey($db, (string)($survey['survey_type_ids'] ?? ''), (string)($survey['type_name'] ?? ''));
    $fmt = function ($v) use ($onhire) { return $v !== null ? recoveryDisplay($v, $onhire) . ' MT' : '—'; };
    return [
        'na'    => false,
        'total' => $fmt($survey['recovery_amount'] ?? null),
        'vlsfo' => $fmt($survey['vlsfo_recovery'] ?? null),
        'hsfo'  => $fmt($survey['hsfo_recovery'] ?? null),
        'lsmgo' => $fmt($survey['lsmgo_recovery'] ?? null),
    ];
}

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

/* Filters, sorting and paging run in SQL (includes/report_table.php); the table, the phone
   cards and the Download use the same state. Default order: Report Completed Date
   (report_uploaded_date), newest first, vessels without one last. */
[$rtScopeWhere, $rtScopeParams] = rtScope('Completed', $role, (int)$user_id, (int)$client_id, $is_full_access);
$rt = rtState($_GET, 'report', $is_full_access);
[$rtWhereSql, $rtParams] = rtWhere($rt, $rtScopeWhere, $rtScopeParams);
[$rtTotal, $rtRows, $rtPage, $rtPages] = rtFetchPage($db, $rt, $rtWhereSql, $rtParams);
$rt['page'] = $rtPage;
$rtOffset = ($rtPage - 1) * RT_PER_PAGE;
$rtOpts = rtOptions($db, $rtScopeWhere, $rtScopeParams, $is_full_access);
$rtYears = rtYears($db, $rtScopeWhere, $rtScopeParams, $rt['date']);
$rtBase = 'completed.php';
// Recovery of every vessel the filters match (all pages), same rule as before (cvRecovery).
$recStmt = $db->prepare('SELECT s.recovery_amount, s.survey_type_ids, st.type_name ' . rtFromSql() . " WHERE $rtWhereSql");
$recStmt->execute($rtParams);
$cvRecoveryTotal = array_sum(array_map('cvRecovery', $recStmt->fetchAll(PDO::FETCH_ASSOC)));
$cvRecoveryLabel = $rt['ym'] !== '' ? date('M Y', strtotime($rt['ym'] . '-01')) . ' Recovery' : 'Total Recovery';
$rtExport = rtUrl('export_completed.php', $rt);
$rtChipsHtml = rtChips($rtBase, $rt);

// 🌟 Desktop hero banner stat + surveyor avatar helpers — display only,
// same helpers as vessels.php's redesign, duplicated here page-scoped.
function pvInitials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    $ini = '';
    foreach (array_slice($parts ?: [], 0, 2) as $p) {
        $ini .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $ini !== '' ? $ini : '?';
}
function pvAvatarGradient(string $name): string {
    static $palette = [
        ['#8B7CF6', '#4B2FD6'],
        ['#22D3C8', '#0D9488'],
        ['#FBBF24', '#F59E0B'],
        ['#F472B6', '#DB2777'],
        ['#60A5FA', '#2563EB'],
    ];
    $idx = crc32($name) % count($palette);
    [$from, $to] = $palette[$idx];
    return "linear-gradient(135deg, $from, $to)";
}
$pv_total = $rtTotal;

include 'includes/header.php';
?>
<link rel="stylesheet" href="assets/css/report-table.css?v=<?= @filemtime(__DIR__ . '/assets/css/report-table.css') ?>">

<style>

    /* Recovery display (replaces the Surveyor column/row for Admin/Super
       Admin) — deliberately OUTSIDE both the mobile and desktop media
       queries below so the same rules apply at every width; the surveyor
       name only ever appears in the hover/tap tooltip, never permanently. */
    .recovery-row .pv-info-val { white-space: normal; overflow: visible; text-overflow: clip; }
    .recovery-cell { position: relative; display: inline-block; cursor: pointer; -webkit-tap-highlight-color: transparent; }
    .recovery-cell.no-tooltip { cursor: default; }
    /* Total recovery as a pill (same shape as the survey-type pill), bigger
       font; the VLSFO/HSFO/LSMGO line below keeps its small size. */
    .recovery-total {
        display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;
        font-size: 13px; font-weight: 700; line-height: 1.2; color: #fff;
        padding: 6px 12px; border-radius: 999px;
        background: linear-gradient(120deg, #4B2FD6 0%, #7C3AED 55%, #DB2777 100%);
        box-shadow: 0 4px 12px rgba(91,45,238,.28);
    }
    .recovery-total i { font-size: 11px; opacity: .9; }
    .recovery-total b { font-weight: 800; }
    .recovery-total.na {
        background: #F1F5F9; color: #475569; box-shadow: 0 0 0 1px #e2e8f0;
        font-size: 12px; letter-spacing: .03em;
    }
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
    .recovery-cell .recovery-tooltip::before {
        content: ''; position: absolute; bottom: 100%; left: 14px;
        border: 5px solid transparent; border-bottom-color: #0f172a;
    }
    .recovery-cell:hover .recovery-tooltip,
    .recovery-cell:focus .recovery-tooltip,
    .recovery-cell.tooltip-open .recovery-tooltip {
        opacity: 1; visibility: visible; transform: translateY(0);
    }

    /* ═══ Mobile modern header + card redesign (same pattern as
       vessels.php) — page-scoped, only applies while this page's own
       <style> block is loaded, so it doesn't leak into other pages even
       though they share .top-app-bar/.vessel-card/.badge-place/etc.
       class names. Desktop (≥992px) is untouched. */
    @media (max-width: 991.98px) {
        .top-app-bar {
            position: fixed;
            top: 0; left: 0; right: 0;
            z-index: 900;
            background: linear-gradient(160deg, #1d6fe0 0%, #123f99 65%, #0b1e46 100%);
            border-bottom: none;
            box-shadow: 0 6px 18px rgba(11,30,70,.25);
        }
        .top-app-bar-title { color: #fff; font-size: 22px; font-weight: 800; letter-spacing: .02em; }
        .top-app-bar .hamburger-menu-btn {
            background: rgba(255,255,255,.16);
            border: 1px solid rgba(255,255,255,.32);
            color: #fff;
        }
        .top-app-bar .hamburger-menu-btn:hover { background: rgba(255,255,255,.26); }
        .top-app-bar .profile-menu-trigger { position: relative; }
        .top-app-bar .profile-avatar { border: 2px solid rgba(255,255,255,.85); }
        .top-app-bar .profile-menu-trigger::after {
            content: ''; position: absolute; bottom: 1px; right: 1px;
            width: 11px; height: 11px; border-radius: 50%;
            background: #22c55e; border: 2px solid #1560c9;
        }
        .list-mobile-view { padding-top: 88px; }

        .search-filter-row { padding: 0 14px 12px; }
        .search-control { border-radius: 14px; padding: 12px 12px 12px 38px; }
        .search-input-wrapper i { top: 14px; }
        .filter-btn { border-radius: 14px; }

        .pv-actions-row { display: flex; gap: 10px; padding: 0 14px 14px; }
        .pv-export-btn {
            flex: 1 1 auto;
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            background: linear-gradient(90deg, #16a34a, #0ea5b0);
            color: #fff; text-decoration: none; font-weight: 700; font-size: 13.5px;
            border-radius: 14px; padding: 12px 14px;
            box-shadow: 0 8px 18px rgba(16,163,74,.25);
        }
        .pv-import-btn {
            flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            background: #fff; color: #4B2FD6; border: 1.5px solid #C4B5FD; text-decoration: none;
            font-weight: 700; font-size: 13px; border-radius: 14px; padding: 0 12px;
        }
        .pv-sort-wrap {
            flex: 0 0 auto; display: flex; align-items: center; gap: 6px;
            background: #fff; border: 1px solid var(--border-color); border-radius: 14px;
            padding: 0 12px 0 14px; color: var(--text-dark);
        }
        .pv-sort-wrap i { font-size: 13px; color: #1d6fe0; }
        .pv-sort-select {
            border: none; background: transparent; appearance: none; -webkit-appearance: none;
            font-weight: 700; font-size: 13px; color: var(--text-dark);
            padding: 12px 18px 12px 6px;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='6'><path d='M0 0l5 6 5-6z' fill='%231d6fe0'/></svg>");
            background-repeat: no-repeat; background-position: right 0 center;
        }

        .vessel-card { display: flex; flex-direction: column; align-items: stretch; border: none; border-radius: 20px; box-shadow: 0 8px 22px rgba(15,23,42,.08); background: #fff; }
        .pv-card-top { display: flex; flex-direction: column; align-items: stretch; gap: 8px; padding: 16px 16px 10px; }
        .pv-card-headline { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .pv-ship-icon {
            width: 40px; height: 40px; border-radius: 12px; flex: none;
            background: #d1fae5; color: #047857; font-size: 17px;
            display: flex; align-items: center; justify-content: center;
        }
        .pv-row-num {
            flex: none; width: 22px; height: 22px; border-radius: 50%;
            background: #F1F5F9; color: #64748b; font-size: 11px; font-weight: 800;
            display: flex; align-items: center; justify-content: center;
        }
        .pv-vessel-name { font-size: 16.5px; font-weight: 800; color: var(--text-dark); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; flex: 1 1 auto; }
        /* Mobile alignment: cards line up with the search bar (14px gutter),
           no double padding (header.php's .vessel-card padding + this page's
           .pv-card-top padding), and the action row always fits the screen. */
        .vessel-list-container { padding: 0 14px 16px !important; }
        .vessel-list-container .vessel-card { padding: 0 !important; margin: 0 0 14px !important; width: 100%; }
        .pv-actions-row { display: flex; gap: 8px; padding: 0 14px 14px; align-items: stretch; }
        .pv-actions-row > * { min-width: 0; height: 46px; box-sizing: border-box; }
        .pv-export-btn { flex: 0 0 auto !important; padding: 0 14px !important; white-space: nowrap; font-size: 13px !important; }
        .pv-import-btn { flex: 0 0 auto !important; padding: 0 14px !important; white-space: nowrap; }
        .pv-sort-wrap { flex: 1 1 0 !important; padding: 0 8px 0 12px !important; overflow: hidden; }
        @media (max-width: 400px) {
            .pv-sort-wrap > i { display: none; }
            .pv-export-btn, .pv-import-btn { padding: 0 11px !important; }
        }
        .pv-sort-select { width: 100%; min-width: 0; padding: 12px 16px 12px 4px !important; text-overflow: ellipsis; white-space: nowrap; overflow: hidden; }
        .top-app-bar-title { font-size: clamp(15px, 4.9vw, 20px) !important; letter-spacing: 0 !important; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .pv-card-top { padding: 16px 16px 8px !important; }
        .pv-type-pill {
            align-self: flex-end; max-width: 100%; text-align: right;
            background: #6b8e23; color: #fff;
            padding: 5px 10px; border-radius: 50px; font-weight: 600; font-size: 10px;
            text-transform: uppercase; display: inline-block; line-height: 1.4;
        }
        .pv-info-rows { display: flex; flex-direction: column; gap: 7px; padding: 0 16px 16px; }
        .pv-info-row { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--text-dark); }
        .pv-info-row i { width: 16px; text-align: center; color: #94a3b8; font-size: 12px; flex: none; }
        .pv-info-label { color: var(--text-muted); flex: none; }
        .pv-info-val { font-weight: 650; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; flex: 1 1 auto; }
        .pv-info-row .badge-place { margin: 0; }
        .pv-status-pill {
            display: inline-flex; align-items: center; gap: 5px;
            background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7;
            font-size: 10px; padding: 4px 9px; border-radius: 50px; font-weight: 700;
        }

        /* ── Completed card (numbered header + label / value rows) ── */
        .vessel-list-container .vessel-card { border-radius: 20px !important; box-shadow: 0 6px 18px rgba(30,58,138,.07) !important; padding: 10px 10px 10px !important; margin-bottom: 12px !important; }
        .cc-head { display: flex; align-items: center; gap: 8px; padding: 0 2px 8px; min-width: 0; }
        .cc-num { flex: none; min-width: 34px; height: 30px; padding: 0 8px; border-radius: 12px; background: #F1F4F9; color: #0f172a; font-size: 13px; font-weight: 800; display: flex; align-items: center; justify-content: center; }
        .cc-ship { flex: none; width: 48px; height: 32px; border-radius: 12px; background: #d1fae5; color: #047857; font-size: 15px; display: flex; align-items: center; justify-content: center; }
        .cc-div { flex: none; width: 1.5px; height: 22px; border-radius: 2px; background: #cbd5e1; }
        .cc-name { flex: 1 1 auto; min-width: 0; font-size: 16px; font-weight: 800; color: #0f172a; line-height: 1.2; letter-spacing: .01em; text-transform: uppercase; overflow-wrap: break-word; }
        .cc-rows { display: grid; gap: 5px; }
        .cc-row { display: grid; grid-template-columns: 38% 12px minmax(0, 1fr); align-items: center; background: #F1F4F9; border-radius: 12px; overflow: visible; min-height: 32px; }
        .cc-k { display: flex; align-items: center; gap: 8px; padding: 5px 8px 5px 10px; min-width: 0; }
        .cc-k i { flex: none; width: 17px; text-align: center; color: #1D4ED8; font-size: 13px; }
        .cc-k span { font-size: 12.5px; font-weight: 700; color: #1e3a6e; line-height: 1.2; min-width: 0; overflow-wrap: anywhere; }
        .cc-c { display: flex; align-items: center; justify-content: center; font-weight: 800; color: #334155; font-size: 13px; }
        .cc-v { display: flex; align-items: center; padding: 5px 10px 5px 4px; min-width: 0; }
        .cc-v b { font-size: 12.5px; font-weight: 800; color: #0f172a; text-transform: uppercase; overflow-wrap: anywhere; line-height: 1.25; }
        .cc-v b.cc-date { text-transform: none; font-size: 13px; }
        .cc-v .recovery-total b, .cc-v .recovery-total.na b { color: inherit; text-transform: none; font-size: inherit; overflow-wrap: normal; }
        .cc-row .cc-type.pv-type-pill { align-self: center; text-align: left; border-radius: 12px; font-size: 10.5px; font-weight: 800; padding: 3px 11px; letter-spacing: .02em; }
        .cc-row.recovery-row .cc-v { padding-top: 6px; padding-bottom: 6px; }
        .cc-row .recovery-cell { display: block; min-width: 0; }
        .cc-row .recovery-total { font-size: 12px; padding: 4px 11px; }
        .cc-parts { display: flex !important; flex-wrap: wrap; gap: 1px 6px; margin-top: 4px !important; font-size: 10.5px !important; color: #475569 !important; font-weight: 600 !important; }
        .cc-parts i { font-style: normal; color: #94a3b8; }
        .cc-port { display: inline-flex; align-items: center; gap: 6px; max-width: 100%; background: #ECFDF5; color: #047857; border: 1.5px solid #86EFAC; border-radius: 14px; padding: 2px 11px; font-size: 11.5px; font-weight: 800; text-transform: uppercase; line-height: 1.25; overflow-wrap: anywhere; }
        .cc-port i { font-size: 11px; }
        .cc-status { display: inline-flex; align-items: center; gap: 6px; background: #ECFDF5; color: #15803D; border: 1.5px solid #6EE7B7; border-radius: 999px; padding: 2px 11px; font-size: 11.5px; font-weight: 800; }
        .cc-status i { font-size: 11px; }
        .cc-foot { display: flex; justify-content: flex-end; padding-top: 3px; }
        .cc-edit { display: inline-flex; align-items: center; gap: 6px; padding: 4px 13px; border-radius: 999px; border: 1.5px solid #FED7AA; color: #C2410C; background: #FFF7ED; font-size: 12px; font-weight: 700; text-decoration: none; }
        @media (max-width: 360px) { .cc-row { grid-template-columns: 42% 10px minmax(0, 1fr); } .cc-k span { font-size: 11px; } .cc-k { gap: 6px; padding: 8px 7px; } .cc-name { font-size: 15.5px; } }
    }

    .list-desktop-view { display: none; }
    .list-mobile-view { display: block; }
    @media (min-width: 992px) {
        .list-mobile-view { display: none !important; }
        .list-desktop-view { display: block !important; }

        /* The shared top app bar (title + profile) is redundant here — the
           hero banner below already shows the "Completed Vessels" title, and
           the desktop sidebar already carries navigation/profile. Mobile
           (where this is the only header, with the hamburger menu) is
           untouched — this rule only fires at >=992px. */
        .top-app-bar { display: none !important; }

        /* Desktop table UI — same "premium" redesign as vessels.php: hero
           banner, pill-style toolbar with custom dropdowns, icon-chip table
           cells. Sidebar and nav are untouched (page-scoped <style>). */
        .vd-wrap { padding: 8px 20px 40px; max-width: 1400px; margin: 0 auto; }

        .vd-hero {
            position: relative; overflow: hidden; border-radius: 20px;
            background: linear-gradient(120deg, #0f172a 0%, #1E1B6B 48%, #4B2FD6 100%);
            padding: 15px 26px; margin-bottom: 10px;
            display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px;
            box-shadow: 0 10px 28px rgba(30,27,107,.22);
        }
        .vd-hero-icon-bg {
            position: absolute; right: -22px; top: -38px; font-size: 150px; color: rgba(255,255,255,.07);
            pointer-events: none; line-height: 1;
        }
        .vd-hero-title { font-size: 19px; font-weight: 800; color: #fff; letter-spacing: -.01em; position: relative; }
        .vd-hero-sub { font-size: 11.5px; color: rgba(255,255,255,.62); margin-top: 2px; font-weight: 500; position: relative; }
        .vd-hero-stats { display: flex; align-items: center; gap: 10px; position: relative; flex-wrap: wrap; }
        .vd-hero-stat {
            background: rgba(255,255,255,.1); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(255,255,255,.16); border-radius: 14px; padding: 7px 16px; text-align: center; min-width: 68px;
        }
        .vd-hero-stat .n { font-size: 17px; font-weight: 800; color: #fff; line-height: 1.1; }
        .vd-hero-stat .l { font-size: 9px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: rgba(255,255,255,.55); margin-top: 2px; }

        .vd-toolbar {
            display: flex; flex-wrap: wrap; align-items: center; gap: 10px;
            margin-bottom: 10px; background: #fff; border: 1px solid #e2e8f0; border-radius: 16px;
            padding: 9px 14px; box-shadow: 0 2px 10px rgba(15,23,42,.05);
        }
        .vd-toolbar-inner {
            display: flex; flex-wrap: wrap; align-items: center; gap: 10px;
            flex: 1 1 auto; min-width: 0;
        }
        .vd-search {
            display: flex; align-items: center; gap: 7px;
            background: #F8FAFC; border: 1.5px solid #e2e8f0; border-radius: 999px;
            padding: 0 12px; height: 34px; flex: 0 0 150px; min-width: 120px; box-sizing: border-box;
            transition: border-color .15s ease, box-shadow .15s ease;
        }
        .vd-search:focus-within { border-color: #A78BFA; box-shadow: 0 0 0 4px rgba(91,45,238,.12); }
        .vd-search i { color: #94A3B8; font-size: 12.5px; flex: none; }
        .vd-search input[type=search] { border: none; background: transparent; font-size: 12.5px; color: #0f172a; flex: 1 1 auto; height: 100%; min-width: 0; }
        .vd-search input[type=search]:focus { outline: none; }

        /* Custom-styled dropdown: a pill trigger + floating menu, built by JS
           over the real <select data-desk-*> (kept in the DOM, just hidden)
           so the existing filter/sort logic below — which reads these
           selects' .value and listens for their 'change' event — is
           completely unchanged. */
        .vd-dd { position: relative; flex: 0 0 auto; }
        .vd-dd select { display: none; }
        .vd-dd-trigger {
            display: flex; align-items: center; gap: 5px;
            background: #F8FAFC; border: 1px solid #e2e8f0; border-radius: 999px;
            padding: 0 10px; height: 34px; box-sizing: border-box; max-width: 128px;
            font-size: 12px; font-weight: 600; color: #334155; cursor: pointer; white-space: nowrap;
            transition: border-color .15s ease, background .15s ease;
        }
        .vd-dd-trigger:hover { border-color: #c7d2e0; background: #fff; }
        .vd-dd-trigger.open { border-color: #A78BFA; background: #fff; box-shadow: 0 0 0 3px rgba(91,45,238,.12); }
        .vd-dd-trigger .vd-dd-icon { font-size: 11px; color: #94A3B8; flex: none; }
        .vd-dd-trigger .vd-dd-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1 1 auto; }
        .vd-dd-trigger .vd-dd-chevron { font-size: 8px; color: #94A3B8; flex: none; transition: transform .15s ease; }
        .vd-dd-trigger.open .vd-dd-chevron { transform: rotate(180deg); }
        .vd-dd-menu {
            position: absolute; top: calc(100% + 6px); left: 0; min-width: 170px; max-width: 240px;
            background: #fff; border: 1px solid #e2e8f0; border-radius: 14px;
            box-shadow: 0 16px 36px rgba(15,23,42,.14); padding: 6px; z-index: 40; max-height: 260px; overflow-y: auto;
            opacity: 0; visibility: hidden; transform: translateY(-6px) scale(.98); transform-origin: top left;
            transition: opacity .15s ease, transform .15s ease, visibility .15s ease;
        }
        .vd-dd-menu.open { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }
        .vd-dd-item {
            display: flex; align-items: center; justify-content: space-between; gap: 10px;
            padding: 8px 10px 8px 12px; border-radius: 10px; font-size: 13px; font-weight: 500; color: #334155;
            cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .vd-dd-item:hover { background: #F3EEFE; color: #5B2DEE; }
        .vd-dd-item.selected { background: #F3EEFE; color: #5B2DEE; font-weight: 700; }
        .vd-dd-item.selected::after {
            content: '\f00c'; font-family: 'Font Awesome 6 Free'; font-weight: 900; font-size: 10px; color: #5B2DEE; flex: none;
        }

        .vd-clear-btn {
            display: inline-flex; align-items: center; gap: 5px; padding: 0 10px; height: 34px; border-radius: 999px;
            font-size: 12px; font-weight: 600; color: #94A3B8; text-decoration: none; border: none;
            background: transparent; cursor: pointer; white-space: nowrap;
        }
        .vd-clear-btn:hover { color: #5B2DEE; }

        .btn-export {
            display: inline-flex; align-items: center; gap: 7px; margin-left: auto; flex: 0 0 auto;
            background: linear-gradient(120deg, #14B8A6, #0D9488); color: #fff;
            padding: 0 16px; border-radius: 999px; font-weight: 700; font-size: 12.5px; height: 34px; box-sizing: border-box;
            text-decoration: none; box-shadow: 0 6px 16px rgba(13,148,136,.3); white-space: nowrap;
            transition: box-shadow .18s ease, transform .18s ease;
        }
        .btn-export:hover { box-shadow: 0 10px 22px rgba(13,148,136,.4); transform: translateY(-1px); color: #fff; }
        .btn-import-completed {
            display: inline-flex; align-items: center; gap: 7px; flex: 0 0 auto; margin-left: 8px;
            background: #fff; color: #4B2FD6; border: 1.5px solid #C4B5FD;
            padding: 0 14px; border-radius: 999px; font-weight: 700; font-size: 12.5px; height: 34px; box-sizing: border-box;
            text-decoration: none; white-space: nowrap;
        }
        .btn-import-completed:hover { background: #F5F3FF; color: #4B2FD6; }
        .vd-export-icon {
            width: 18px; height: 18px; border-radius: 50%; background: rgba(255,255,255,.22);
            display: inline-flex; align-items: center; justify-content: center; font-size: 9px;
        }

        .vd-meta { font-size: 12.5px; color: #64748b; margin-bottom: 6px; font-weight: 500; }
        .vd-table-card {
            background: #fff; border: 1px solid #e2e8f0; border-radius: 20px;
            overflow: hidden; box-shadow: 0 4px 20px rgba(15,23,42,.05);
        }
        .vd-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .vd-table thead th {
            background: #F8FAFC; color: #64748b; font-weight: 700; font-size: 10.5px;
            letter-spacing: .06em; text-transform: uppercase;
            padding: 13px 12px; text-align: left; white-space: nowrap; border-bottom: 1px solid #e2e8f0;
        }
        .vd-table tbody td {
            padding: 13px 12px; border-bottom: 1px solid #f1f5f9;
            vertical-align: middle; color: #334155;
        }
        .vd-table tbody tr:hover { background: #FAF8FF; }
        .vd-table tbody tr:last-child td { border-bottom: none; }

        .vd-vessel-cell { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .vd-vessel-icon {
            width: 36px; height: 36px; border-radius: 11px; flex: none;
            background: linear-gradient(135deg, #EEF2FF, #DCE3FF); color: #4F46E5;
            display: flex; align-items: center; justify-content: center; font-size: 15px;
        }
        .vd-vessel { font-weight: 700; color: #0f172a; text-decoration: none; font-size: 13.5px; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 220px; }
        .vd-vessel:hover { color: #5B2DEE; }
        .vd-sub { font-size: 11px; color: #94a3b8; margin-top: 1px; font-weight: 500; }

        .vd-badge {
            display: inline-flex; align-items: center; gap: 5px;
            background: #EFF6FF; color: #1D4ED8; font-size: 10.5px; font-weight: 800;
            padding: 4px 12px; border-radius: 999px; text-transform: uppercase; letter-spacing: .03em;
            max-width: 100%; box-shadow: 0 0 0 1px rgba(37,99,235,.08);
        }
        .vd-badge .dot { width: 5px; height: 5px; border-radius: 50%; background: #2563EB; flex: none; }

        .vd-port-cell { display: flex; align-items: center; gap: 8px; }
        .vd-port-icon { width: 26px; height: 26px; border-radius: 8px; background: #ECFEFF; color: #0891B2; display: flex; align-items: center; justify-content: center; font-size: 11px; flex: none; }

        .vd-avatar-cell { display: flex; align-items: center; gap: 8px; }
        .vd-avatar {
            width: 24px; height: 24px; border-radius: 50%; color: #fff; font-size: 9.5px; font-weight: 800;
            display: flex; align-items: center; justify-content: center; flex: none;
        }

        .vd-actions { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
        .vd-actions a {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 8px 14px; border-radius: 999px; font-size: 12px; font-weight: 700;
            text-decoration: none; border: 1.5px solid #e2e8f0; color: #475569; background: #fff;
            transition: all .15s ease;
        }
        .vd-actions a:hover { background: #5B2DEE; color: #fff; border-color: #5B2DEE; box-shadow: 0 6px 16px rgba(91,45,238,.3); }
        .vd-actions a.edit { color: #C2410C; border-color: #FED7AA; }
        .vd-actions a.edit:hover { background: #EA580C; color: #fff; border-color: #EA580C; box-shadow: 0 6px 16px rgba(234,88,12,.3); }

        .vd-pagination {
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 10px; padding: 16px 20px; background: #F8FAFC;
            border-top: 1px solid #e2e8f0;
        }
        .vd-page-info { font-size: 12.5px; color: #64748b; font-weight: 500; }
        .vd-page-links { display: flex; gap: 6px; flex-wrap: wrap; }
        .vd-page-links a, .vd-page-links span {
            min-width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center;
            border-radius: 10px; font-size: 12.5px; font-weight: 700; text-decoration: none;
            border: 1px solid #e2e8f0; background: #fff; color: #475569; padding: 0 8px;
        }
        .vd-page-links a:hover { border-color: #A78BFA; color: #5B2DEE; background: #FAF8FF; }
        .vd-page-links .active { background: linear-gradient(135deg, #8B7CF6, #4B2FD6); color: #fff; border-color: transparent; box-shadow: 0 4px 12px rgba(91,45,238,.35); }
        .vd-page-links .disabled { opacity: .4; pointer-events: none; }
        .vd-empty { text-align: center; padding: 56px 20px; color: #94a3b8; }
    }

.vd-badge { background: #d1fae5 !important; color: #065f46 !important; box-shadow: 0 0 0 1px rgba(6,95,70,.1) !important; }
.vd-badge .dot { background: #059669 !important; }
</style>
<div class="scroll-content" data-list-scope data-testid="completed-surveys-page">
    <?php $page_title = 'Completed Surveys'; $back_url = ''; $page_testid = 'completed-surveys'; include 'includes/top_app_bar.php'; ?>

    <div class="list-mobile-view">
    <div class="rt-mobile-top">
        <?= rtMobileSearch($rtBase, $rt, $rtExport) ?>
        <div class="cv-rec-summary" data-testid="cv-recovery-mobile">
            <span><i class="fa-solid fa-droplet"></i> <?= sanitize($cvRecoveryLabel) ?></span>
            <b><?= number_format($cvRecoveryTotal, 3) ?> MT</b>
            <small><?= (int)$rtTotal ?> vessels</small>
        </div>
        <?= rtMobileBar($rt, $is_full_access) ?>
        <?= $rtChipsHtml ?>
        <?= rtMeta($rt, $rtTotal, $rtOffset, count($rtRows)) ?>
    </div>
    <div class="vessel-list-container">
        <?php if (count($rtRows) > 0): foreach ($rtRows as $rtI => $survey):
            $surveyorNames = getCombinedSurveyorNames($db, $survey['id'], $survey['surveyor_name'] ?? 'N/A');
            $typeLabel = getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? 'N/A');
            $card_date_src = $survey['report_uploaded_date'] ?? $survey['survey_completed_date'] ?? $survey['assign_date'] ?? '';
            $card_ts = (!empty($card_date_src) && $card_date_src !== '0000-00-00' && $card_date_src !== '0000-00-00 00:00:00')
                ? strtotime($card_date_src) : false;
        ?>
        <div class="vessel-card" onclick="location.href='completed_detail.php?id=<?= (int)$survey['id'] ?>'"
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
            <div class="cc-head">
                <span class="cc-num" data-testid="pv-row-num"><?= $rtOffset + $rtI + 1 ?></span>
                <span class="cc-ship"><i class="fa-solid fa-ship"></i></span>
                <span class="cc-div" aria-hidden="true"></span>
                <span class="cc-name"><?= sanitize($survey['vessel_name']) ?></span>
            </div>
            <div class="cc-rows">
                <div class="cc-row"><div class="cc-k"><i class="fa-solid fa-user"></i><span>Client</span></div><span class="cc-c">:</span><div class="cc-v"><b><?= sanitize($survey['company_name'] ?? '') ?: '—' ?></b></div></div>
                <div class="cc-row"><div class="cc-k"><i class="fa-solid fa-people-group"></i><span>Agent</span></div><span class="cc-c">:</span><div class="cc-v"><b><?= sanitize($survey['agent_name'] ?? '') ?: '—' ?></b></div></div>
                <div class="cc-row"><div class="cc-k"><i class="fa-solid fa-layer-group"></i><span>Survey Type</span></div><span class="cc-c">:</span><div class="cc-v"><span class="cc-type pv-type-pill"><?= sanitize($typeLabel) ?></span></div></div>
                <?php $rp = recoveryDisplayParts($survey); ?>
                <div class="cc-row recovery-row"><div class="cc-k"><i class="fa-solid fa-droplet"></i><span>Recovery</span></div><span class="cc-c">:</span>
                    <div class="cc-v">
                        <span class="recovery-cell<?= $is_full_access ? '' : ' no-tooltip' ?>"<?= $is_full_access ? ' tabindex="0"' : '' ?> data-testid="recovery-cell-mobile">
                            <?php if ($rp['na']): ?>
                            <span class="recovery-total na" data-testid="recovery-pill"><b><?= sanitize($rp['total']) ?></b></span>
                            <?php else: ?>
                            <span class="recovery-total" data-testid="recovery-pill"><i class="fa-solid fa-droplet"></i> Total: <b><?= sanitize($rp['total']) ?></b></span>
                            <span class="recovery-parts cc-parts"><span>VLSFO: <?= sanitize($rp['vlsfo']) ?></span><i>|</i><span>HSFO: <?= sanitize($rp['hsfo']) ?></span><i>|</i><span>LSMGO: <?= sanitize($rp['lsmgo']) ?></span></span>
                            <?php endif; ?>
                            <?php if ($is_full_access): ?>
                            <span class="recovery-tooltip"><i class="fa-solid fa-user-tie me-1"></i>Surveyor: <?= sanitize($surveyorNames) ?></span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
                <div class="cc-row"><div class="cc-k"><i class="fa-solid fa-location-dot"></i><span>Port</span></div><span class="cc-c">:</span><div class="cc-v"><span class="cc-port"><i class="fa-solid fa-location-dot"></i><?= sanitize($survey['port_name'] ?? 'N/A') ?></span></div></div>
                <?php $rtD = rtRowDate($survey, $rt['date']); ?>
                <div class="cc-row" data-testid="cv-date"><div class="cc-k"><i class="fa-solid fa-calendar-check"></i><span><?= sanitize(rtDateShort($rt['date'])) ?></span></div><span class="cc-c">:</span><div class="cc-v"><b class="cc-date"><?= $rtD !== '' ? date('d M Y', strtotime($rtD)) : '—' ?></b></div></div>
                <div class="cc-row"><div class="cc-k"><i class="fa-solid fa-circle-check"></i><span>Status</span></div><span class="cc-c">:</span><div class="cc-v"><span class="cc-status"><i class="fa-solid fa-check"></i> Completed</span></div></div>
                <?php if (($_SESSION['role'] ?? '') === 'Admin'): ?>
                <div class="cc-foot">
                    <a href="completed_detail.php?id=<?= (int)$survey['id'] ?>" onclick="event.stopPropagation();" data-testid="cv-edit-<?= (int)$survey['id'] ?>" class="cc-edit"><i class="fa-solid fa-pen"></i> Edit</a>
                </div>
                <?php endif; ?>
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

            <div class="vd-hero" data-testid="completed-hero">
                <i class="fa-solid fa-circle-check vd-hero-icon-bg" aria-hidden="true"></i>
                <div>
                    <div class="vd-hero-title">Completed Vessels</div>
                    <div class="vd-hero-sub"><?= (int)$rtTotal ?> survey<?= $rtTotal === 1 ? '' : 's' ?> completed</div>
                </div>
                <div class="vd-hero-stats">
                    <div class="vd-hero-stat">
                        <div class="n" data-testid="rt-total"><?= (int)$rtTotal ?></div>
                        <div class="l">Total</div>
                    </div>
                    <div class="vd-hero-stat" data-testid="cv-recovery-desktop">
                        <div class="n"><?= number_format($cvRecoveryTotal, 3) ?> <small style="font-size:11px;opacity:.7">MT</small></div>
                        <div class="l"><?= sanitize($cvRecoveryLabel) ?></div>
                    </div>
                </div>
            </div>

            <div class="vd-toolbar">
                <div class="vd-toolbar-inner">
                    <?= rtDeskSearch($rtBase, $rt) ?>
                    <?php if ($is_full_access): ?>
                    <button type="button" class="vd-dd-trigger rt-tb-dd<?= $rt['surveyor'] ? ' has-value' : '' ?>" data-rt-open="surveyor" data-testid="rt-surveyor-filter">
                        <i class="fa-solid fa-user-tie vd-dd-icon"></i><span class="vd-dd-label"><?= $rt['surveyor'] ? (count($rt['surveyor']) === 1 ? sanitize($rt['surveyor'][0]) : count($rt['surveyor']) . ' surveyors') : 'All surveyors' ?></span><i class="fa-solid fa-chevron-down vd-dd-chevron"></i>
                    </button>
                    <?php endif; ?>
                </div>
                <a href="<?= sanitize($rtExport) ?>" class="btn-export" data-testid="rt-download"><span class="vd-export-icon"><i class="fa-solid fa-arrow-down"></i></span> Download</a>
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
                                $date_disp = (!empty($date_src) && $date_src !== '0000-00-00' && $date_src !== '0000-00-00 00:00:00')
                                    ? date('d M Y H:i', strtotime($date_src)) : '—';
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
                                    <div class="vd-vessel-cell">
                                        <div class="vd-vessel-icon"><i class="fa-solid fa-ship"></i></div>
                                        <div style="min-width:0;">
                                            <a class="vd-vessel" href="completed_detail.php?id=<?= (int)$survey['id'] ?>"><?= sanitize($vessel_name) ?></a>
                                            <?php if (!empty($survey['report_number'])): ?>
                                                <div class="vd-sub">Report: <?= sanitize($survey['report_number']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div style="font-weight:600;color:#334155;"><?= sanitize($survey['company_name'] ?? '') ?></div>
                                    <div class="vd-sub">Agent: <?= sanitize($survey['agent_name'] ?? '—') ?></div>
                                </td>
                                <td><span class="vd-badge"><span class="dot"></span><?= sanitize($typeLabel) ?></span></td>
                                <td>
                                    <div class="vd-port-cell">
                                        <div class="vd-port-icon"><i class="fa-solid fa-location-dot"></i></div>
                                        <span><?= sanitize($survey['port_name'] ?? 'N/A') ?></span>
                                    </div>
                                </td>
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
                                <?php $rtD = rtRowDate($survey, $rt['date']); ?>
                                <td style="white-space:nowrap;color:#64748b;font-size:12.5px;"><?= $rtD !== '' ? sanitize(date('d M Y', strtotime($rtD))) : '—' ?></td>
                                <td>
                                    <div class="vd-actions">
                                        <a href="completed_detail.php?id=<?= (int)$survey['id'] ?>"><i class="fa-solid fa-eye"></i> View</a>
                                        <?php if ($role === 'Admin'): ?>
                                        <a class="edit" href="completed_detail.php?id=<?= (int)$survey['id'] ?>" data-testid="cv-edit-desk-<?= (int)$survey['id'] ?>"><i class="fa-solid fa-pen"></i> Edit</a>
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
// Recovery-cell surveyor tooltip: tap/click toggles it open (desktop still
// gets a plain CSS :hover too), and stopPropagation so tapping it on a
// mobile card doesn't also trigger the card's own onclick navigation.
(function () {
    // Capture phase (the `true` below) — a delegated *bubble*-phase listener
    // on document runs AFTER the card's own onclick has already fired (bubble
    // order visits the card long before it reaches document), so
    // stopPropagation() there is too late to stop the navigation. Capture
    // phase runs top-down, before the card's handler, so stopping it here
    // actually prevents the click from ever reaching the card's onclick.
    document.addEventListener('click', function (ev) {
        var cell = ev.target.closest('.recovery-cell');
        var hasTooltip = cell && cell.querySelector('.recovery-tooltip');
        document.querySelectorAll('.recovery-cell.tooltip-open').forEach(function (openCell) {
            if (openCell !== cell) openCell.classList.remove('tooltip-open');
        });
        // Only Admin/Super Admin cells actually have a .recovery-tooltip
        // (surveyor name) — for everyone else the cell has none, so this
        // stays inert and a mobile tap falls through to the card's own
        // navigation instead of being swallowed for nothing.
        if (hasTooltip) {
            ev.stopPropagation();
            cell.classList.toggle('tooltip-open');
        }
    }, true);
})();
</script>


<style>
.cv-rec-summary { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin: 0 0 10px; padding: 10px 14px; border-radius: 14px; background: linear-gradient(120deg,#ECFDF5,#F0FDFA); border: 1px solid #A7F3D0; color: #065F46; font-size: 12.5px; }
.cv-rec-summary b { font-size: 15px; color: #047857; }
.cv-rec-summary small { margin-left: auto; color: #64748b; font-weight: 600; }
.rt-tb-dd.has-value { border-color: #A78BFA; background: #F3EEFE; color: #5B2DEE; }
</style>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>

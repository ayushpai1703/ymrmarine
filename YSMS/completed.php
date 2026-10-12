<?php
require_once 'config/config.php';
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
    $fmt = function ($v) { return $v !== null ? number_format((float)$v, 3) . ' MT' : '—'; };
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

/* Mobile: full list */
if ($is_full_access) {
    $stmt = $db->prepare("
        SELECT s.*, c.company_name, u.full_name as surveyor_name, st.type_name, p.port_name
        FROM surveys s
        JOIN clients c ON s.client_id = c.id
        LEFT JOIN users u ON s.surveyor_id = u.id
        LEFT JOIN survey_types st ON s.survey_type_id = st.id
        LEFT JOIN ports p ON s.port_id = p.id
        WHERE s.status = 'Completed'
        ORDER BY s.id DESC
    ");
    $stmt->execute();
} elseif ($role === 'Client') {
    $stmt = $db->prepare("
        SELECT s.*, c.company_name, u.full_name as surveyor_name, st.type_name, p.port_name
        FROM surveys s
        JOIN clients c ON s.client_id = c.id
        LEFT JOIN users u ON s.surveyor_id = u.id
        LEFT JOIN survey_types st ON s.survey_type_id = st.id
        LEFT JOIN ports p ON s.port_id = p.id
        WHERE s.status = 'Completed' AND s.client_id = ?
        ORDER BY s.id DESC
    ");
    $stmt->execute([$client_id]);
} else {
    // 🌟 Include completed surveys assigned via survey_surveyors (multi-surveyor),
    // not just the primary surveys.surveyor_id.
    $stmt = $db->prepare("
        SELECT s.*, c.company_name, u.full_name as surveyor_name, st.type_name, p.port_name
        FROM surveys s
        JOIN clients c ON s.client_id = c.id
        LEFT JOIN users u ON s.surveyor_id = u.id
        LEFT JOIN survey_types st ON s.survey_type_id = st.id
        LEFT JOIN ports p ON s.port_id = p.id
        WHERE s.status = 'Completed' AND (s.surveyor_id = ? OR s.id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = ?))
        ORDER BY s.id DESC
    ");
    $stmt->execute([$user_id, $user_id]);
}
$surveys = $stmt->fetchAll();

$survey_types_filter = [];
$survey_places_filter = [];
$survey_clients_filter = [];
$survey_surveyors_filter = [];
// Month filter ('YYYY-MM' => ['label' => 'October 2026', 'count' => 12]) —
// lets every role see how many vessels were completed in a given month,
// right in the dropdown label, using the same date fallback chain the
// desktop table already uses for its "Date" column.
$survey_months_filter = [];
foreach ($db->query("SELECT type_name FROM survey_types ORDER BY type_name ASC")->fetchAll() as $st_row) {
    if (!empty($st_row['type_name'])) $survey_types_filter[$st_row['type_name']] = $st_row['type_name'];
}
foreach ($surveys as $row) {
    if (!empty($row['port_name'])) $survey_places_filter[$row['port_name']] = $row['port_name'];
    if (!empty($row['company_name'])) $survey_clients_filter[$row['company_name']] = $row['company_name'];
    $rowSurveyorNames = getCombinedSurveyorNames($db, $row['id'], $row['surveyor_name'] ?? '');
    if (!empty($rowSurveyorNames)) $survey_surveyors_filter[$rowSurveyorNames] = $rowSurveyorNames;

    $row_date_src = $row['report_uploaded_date'] ?? $row['survey_completed_date'] ?? $row['assign_date'] ?? '';
    $row_ts = (!empty($row_date_src) && $row_date_src !== '0000-00-00' && $row_date_src !== '0000-00-00 00:00:00')
        ? strtotime($row_date_src) : false;
    if ($row_ts) {
        $month_key = date('Y-m', $row_ts);
        if (!isset($survey_months_filter[$month_key])) {
            $survey_months_filter[$month_key] = ['label' => date('F Y', $row_ts), 'count' => 0];
        }
        $survey_months_filter[$month_key]['count']++;
    }
}
krsort($survey_months_filter);

/* Desktop: pagination */
$perPage = 25;
$page = max(1, (int)($_GET['page'] ?? 1));
$q = trim((string)($_GET['q'] ?? ''));
$filter_type = trim((string)($_GET['type'] ?? ''));
$filter_place = trim((string)($_GET['place'] ?? ''));
$filter_client = trim((string)($_GET['client'] ?? ''));
$filter_surveyor = trim((string)($_GET['surveyor'] ?? ''));
$sort = trim((string)($_GET['sort'] ?? 'newest'));

$where = ["s.status = 'Completed'"];
$params = [];
if ($role === 'Client') { $where[] = 's.client_id = ?'; $params[] = $client_id; } elseif (!$is_full_access) { $where[] = '(s.surveyor_id = ? OR s.id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = ?))'; $params[] = $user_id; $params[] = $user_id; }
if ($q !== '') {
    $where[] = '(s.vessel_name LIKE ? OR c.company_name LIKE ? OR s.agent_name LIKE ? OR st.type_name LIKE ? OR p.port_name LIKE ? OR u.full_name LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
if ($filter_type !== '') { $where[] = 'st.type_name = ?'; $params[] = $filter_type; }
if ($filter_place !== '') { $where[] = 'p.port_name = ?'; $params[] = $filter_place; }
if ($filter_client !== '') { $where[] = 'c.company_name = ?'; $params[] = $filter_client; }
if ($is_full_access && $filter_surveyor !== '') {
    $where[] = "COALESCE((SELECT GROUP_CONCAT(u2.full_name ORDER BY ss2.id SEPARATOR ' + ') FROM survey_surveyors ss2 JOIN users u2 ON ss2.surveyor_id = u2.id WHERE ss2.survey_id = s.id), u.full_name) = ?";
    $params[] = $filter_surveyor;
}
$whereSql = implode(' AND ', $where);
$orderSql = 's.id DESC';
if ($sort === 'oldest') $orderSql = 's.id ASC';
elseif ($sort === 'name-asc') $orderSql = 's.vessel_name ASC';
elseif ($sort === 'name-desc') $orderSql = 's.vessel_name DESC';

$countStmt = $db->prepare("
    SELECT COUNT(*) FROM surveys s
    JOIN clients c ON s.client_id = c.id
    LEFT JOIN users u ON s.surveyor_id = u.id
    LEFT JOIN survey_types st ON s.survey_type_id = st.id
    LEFT JOIN ports p ON s.port_id = p.id
    WHERE $whereSql
");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$deskStmt = $db->prepare("
    SELECT s.*, c.company_name, u.full_name as surveyor_name, st.type_name, p.port_name
    FROM surveys s
    JOIN clients c ON s.client_id = c.id
    LEFT JOIN users u ON s.surveyor_id = u.id
    LEFT JOIN survey_types st ON s.survey_type_id = st.id
    LEFT JOIN ports p ON s.port_id = p.id
    WHERE $whereSql
    ORDER BY $orderSql
    LIMIT $perPage OFFSET $offset
");
$deskStmt->execute($params);
$deskSurveys = $deskStmt->fetchAll(PDO::FETCH_ASSOC);

function vd_qs(array $extra = []): string {
    $base = [
        'q' => $_GET['q'] ?? '',
        'type' => $_GET['type'] ?? '',
        'place' => $_GET['place'] ?? '',
        'client' => $_GET['client'] ?? '',
        'surveyor' => $_GET['surveyor'] ?? '',
        'sort' => $_GET['sort'] ?? 'newest',
    ];
    $merged = array_filter(array_merge($base, $extra), fn($v) => $v !== '' && $v !== null);
    return http_build_query($merged);
}

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
$pv_total = count($surveys);

include 'includes/header.php';
?>

<style>

    /* Recovery display (replaces the Surveyor column/row for Admin/Super
       Admin) — deliberately OUTSIDE both the mobile and desktop media
       queries below so the same rules apply at every width; the surveyor
       name only ever appears in the hover/tap tooltip, never permanently. */
    .recovery-row .pv-info-val { white-space: normal; overflow: visible; text-overflow: clip; }
    .recovery-cell { position: relative; display: inline-block; cursor: pointer; -webkit-tap-highlight-color: transparent; }
    .recovery-cell.no-tooltip { cursor: default; }
    .recovery-total { display: block; font-size: 12.5px; font-weight: 700; color: #0f172a; }
    .recovery-total b { font-weight: 800; }
    .recovery-parts { display: block; font-size: 10.5px; color: #64748b; font-weight: 500; margin-top: 2px; }
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
            padding: 13px 16px; text-align: left; white-space: nowrap; border-bottom: 1px solid #e2e8f0;
        }
        .vd-table tbody td {
            padding: 13px 16px; border-bottom: 1px solid #f1f5f9;
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
    <div class="search-filter-row">
        <div class="search-input-wrapper">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" class="search-control" placeholder="Search by vessel, client or type..." data-list-search>
        </div>
        <button type="button" class="filter-btn" style="background:#ecfdf5;color:#047857;" data-filter-toggle aria-expanded="false"><i class="fa-solid fa-sliders"></i></button>
    </div>
    <div class="list-controls-panel" data-controls-panel>
        <select data-filter-type><option value="">All survey types</option><?php foreach ($survey_types_filter as $value): ?><option value="<?= sanitize($value) ?>"><?= sanitize($value) ?></option><?php endforeach; ?></select>
        <select data-filter-place><option value="">All survey places</option><?php foreach ($survey_places_filter as $value): ?><option value="<?= sanitize($value) ?>"><?= sanitize($value) ?></option><?php endforeach; ?></select>
        <select data-filter-client><option value="">All clients</option><?php foreach ($survey_clients_filter as $value): ?><option value="<?= sanitize($value) ?>"><?= sanitize($value) ?></option><?php endforeach; ?></select>
        <?php if ($is_full_access): ?>
        <select data-filter-surveyor><option value="">All surveyors</option><?php foreach ($survey_surveyors_filter as $value): ?><option value="<?= sanitize($value) ?>"><?= sanitize($value) ?></option><?php endforeach; ?></select>
        <?php endif; ?>
        <select data-filter-month><option value="">All months</option><?php foreach ($survey_months_filter as $m_key => $m_info): ?><option value="<?= sanitize($m_key) ?>"><?= sanitize($m_info['label']) ?> (<?= (int)$m_info['count'] ?>)</option><?php endforeach; ?></select>
        <button type="button" class="clear-filters-btn" data-clear-filters><i class="fa-solid fa-rotate-left"></i> Clear Filters</button>
    </div>
    <div class="pv-actions-row">
        <a href="export_completed.php" class="pv-export-btn" data-mobile-export data-export-base="export_completed.php"><i class="fa-solid fa-file-excel"></i> Export to Excel</a>
        <?php if ($is_full_access): ?>
        <a href="completed_import.php" class="pv-import-btn" title="Bulk import completed vessels from Excel" data-testid="completed-import-mobile"><i class="fa-solid fa-file-import"></i> Import</a>
        <?php endif; ?>
        <div class="pv-sort-wrap">
            <i class="fa-solid fa-arrow-down-wide-short"></i>
            <select class="pv-sort-select" data-list-sort>
                <option value="newest">Newest first</option>
                <option value="oldest">Oldest first</option>
                <option value="name-asc">Vessel A–Z</option>
                <option value="name-desc">Vessel Z–A</option>
            </select>
        </div>
    </div>
    <div class="vessel-list-container" data-list-container>
        <?php if (count($surveys) > 0): foreach ($surveys as $survey):
            $surveyorNames = getCombinedSurveyorNames($db, $survey['id'], $survey['surveyor_name'] ?? 'N/A');
            $typeLabel = getCombinedSurveyTypeNames($db, $survey['survey_type_ids'] ?? '', $survey['type_name'] ?? 'N/A');
            $card_date_src = $survey['report_uploaded_date'] ?? $survey['survey_completed_date'] ?? $survey['assign_date'] ?? '';
            $card_ts = (!empty($card_date_src) && $card_date_src !== '0000-00-00' && $card_date_src !== '0000-00-00 00:00:00')
                ? strtotime($card_date_src) : false;
        ?>
        <div class="vessel-card" onclick="location.href='completed_detail.php?id=<?= (int)$survey['id'] ?>'"
             data-survey-card
             data-name="<?= sanitize(strtolower($survey['vessel_name'] ?? '')) ?>"
             data-type="<?= sanitize($survey['type_name'] ?? '') ?>"
             data-place="<?= sanitize($survey['port_name'] ?? '') ?>"
             data-client="<?= sanitize($survey['company_name'] ?? '') ?>"
             data-surveyor="<?= sanitize($surveyorNames) ?>"
             data-status="completed"
             data-month="<?= $card_ts ? sanitize(date('Y-m', $card_ts)) : '' ?>"
             data-date="<?= strtotime($survey['report_uploaded_date'] ?? '1970-01-01') ?>"
             data-search="<?= sanitize(strtolower(implode(' ', [$survey['vessel_name'] ?? '', $survey['company_name'] ?? '', $survey['type_name'] ?? '', $survey['port_name'] ?? '']))) ?>">
            <div class="pv-card-top">
                <div class="pv-card-headline">
                    <span class="pv-row-num" data-row-num data-testid="pv-row-num"></span>
                    <div class="pv-ship-icon"><i class="fa-solid fa-ship"></i></div>
                    <div class="pv-vessel-name"><?= sanitize($survey['vessel_name']) ?></div>
                </div>
                <span class="badge-assigned pv-type-pill"><?= sanitize($typeLabel) ?></span>
            </div>
            <div class="pv-info-rows">
                <div class="pv-info-row"><i class="fa-solid fa-user"></i><span class="pv-info-label">Client</span><span>:</span><span class="pv-info-val"><?= sanitize($survey['company_name'] ?? '') ?></span></div>
                <div class="pv-info-row"><i class="fa-solid fa-people-group"></i><span class="pv-info-label">Agent</span><span>:</span><span class="pv-info-val"><?= sanitize($survey['agent_name'] ?? '') ?></span></div>
                <?php $rp = recoveryDisplayParts($survey); ?>
                    <div class="pv-info-row recovery-row">
                        <i class="fa-solid fa-droplet"></i><span class="pv-info-label">Recovery</span><span>:</span>
                        <span class="pv-info-val">
                            <span class="recovery-cell<?= $is_full_access ? '' : ' no-tooltip' ?>"<?= $is_full_access ? ' tabindex="0"' : '' ?> data-testid="recovery-cell-mobile">
                                <?php if ($rp['na']): ?>
                                <span class="recovery-total"><b><?= sanitize($rp['total']) ?></b></span>
                                <?php else: ?>
                                <span class="recovery-total">Total: <b><?= sanitize($rp['total']) ?></b></span>
                                <span class="recovery-parts">VLSFO: <?= sanitize($rp['vlsfo']) ?> · HSFO: <?= sanitize($rp['hsfo']) ?> · LSMGO: <?= sanitize($rp['lsmgo']) ?></span>
                                <?php endif; ?>
                                <?php if ($is_full_access): ?>
                                <span class="recovery-tooltip"><i class="fa-solid fa-user-tie me-1"></i>Surveyor: <?= sanitize($surveyorNames) ?></span>
                                <?php endif; ?>
                            </span>
                        </span>
                    </div>
                <div class="pv-info-row"><i class="fa-solid fa-location-dot"></i><span class="pv-info-label">Port</span><span>:</span><span class="badge-place"><i class="fa-solid fa-location-dot"></i><?= sanitize($survey['port_name'] ?? 'N/A') ?></span></div>
                <div class="pv-info-row"><i class="fa-solid fa-circle-check"></i><span class="pv-info-label">Status</span><span>:</span><span class="pv-status-pill"><i class="fa-solid fa-check"></i> Completed</span></div>
            </div>
        </div>
        <?php endforeach; ?>
        <div class="text-secondary px-2 mt-2" style="font-size:12px;">Showing <span data-list-count><?= count($surveys) ?></span> of <?= count($surveys) ?></div>
        <?php else: ?>
        <div class="bg-white p-4 text-center text-muted rounded-3 border border-dashed my-3"><i class="fa-solid fa-folder-open d-block mb-2 fs-2"></i>No completed surveys found.</div>
        <?php endif; ?>
        <div class="list-empty-state bg-white p-4 text-center text-muted rounded-3 border border-dashed my-3" data-list-empty style="display:none;">No matching records.</div>
    </div>
    </div>


    
    <div class="list-desktop-view vessels-desktop-view">
        <div class="vd-wrap">

            <div class="vd-hero" data-testid="completed-hero">
                <i class="fa-solid fa-circle-check vd-hero-icon-bg" aria-hidden="true"></i>
                <div>
                    <div class="vd-hero-title">Completed Vessels</div>
                    <div class="vd-hero-sub"><?= (int)$pv_total ?> survey<?= $pv_total === 1 ? '' : 's' ?> completed</div>
                </div>
                <div class="vd-hero-stats">
                    <div class="vd-hero-stat">
                        <div class="n"><?= (int)$pv_total ?></div>
                        <div class="l">Total</div>
                    </div>
                </div>
            </div>

            <div class="vd-toolbar">
                <div class="vd-toolbar-inner">
                    <div class="vd-search">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="search" data-desk-search placeholder="Search vessel" autocomplete="off">
                    </div>
                    <div class="vd-dd">
                        <i class="fa-solid fa-arrow-down-wide-short vd-dd-icon-src"></i>
                        <select data-desk-sort>
                            <option value="newest">Newest first</option>
                            <option value="oldest">Oldest first</option>
                            <option value="name-asc">Vessel A–Z</option>
                            <option value="name-desc">Vessel Z–A</option>
                        </select>
                    </div>
                    <div class="vd-dd">
                        <i class="fa-solid fa-location-dot vd-dd-icon-src"></i>
                        <select data-desk-place>
                            <option value="">All ports</option>
                            <?php foreach ($survey_places_filter as $v): ?>
                                <option value="<?= sanitize(strtolower($v)) ?>"><?= sanitize($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="vd-dd">
                        <i class="fa-solid fa-building vd-dd-icon-src"></i>
                        <select data-desk-client>
                            <option value="">All clients</option>
                            <?php foreach ($survey_clients_filter as $v): ?>
                                <option value="<?= sanitize(strtolower($v)) ?>"><?= sanitize($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="vd-dd">
                        <i class="fa-solid fa-calendar-days vd-dd-icon-src"></i>
                        <select data-desk-month>
                            <option value="">All months</option>
                            <?php foreach ($survey_months_filter as $m_key => $m_info): ?>
                                <option value="<?= sanitize($m_key) ?>"><?= sanitize($m_info['label']) ?> (<?= (int)$m_info['count'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($is_full_access): ?>
                    <div class="vd-dd">
                        <i class="fa-solid fa-user-tie vd-dd-icon-src"></i>
                        <select data-desk-surveyor>
                            <option value="">All surveyors</option>
                            <?php foreach ($survey_surveyors_filter as $v): ?>
                                <option value="<?= sanitize(strtolower($v)) ?>"><?= sanitize($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <button type="button" class="vd-clear-btn" data-desk-clear><i class="fa-solid fa-rotate-left"></i> Clear</button>
                </div>
                <a href="export_completed.php" class="btn-export" id="vdExportLink" data-desk-export><span class="vd-export-icon"><i class="fa-solid fa-arrow-down"></i></span> Download</a>
                <?php if ($is_full_access): ?>
                <a href="completed_import.php" class="btn-import-completed" title="Bulk import completed vessels from Excel" data-testid="completed-import-desktop"><i class="fa-solid fa-file-import"></i> Import Excel</a>
                <?php endif; ?>
            </div>
            <div class="vd-meta" data-desk-meta></div>
            <div class="vd-table-card">
                <div data-desk-empty style="display:none;text-align:center;padding:48px 20px;color:#94a3b8;">
                    <i class="fa-solid fa-ship d-block" style="font-size:32px;margin-bottom:10px;opacity:.5;"></i>
                    No records match your search.
                </div>
                <div class="vd-table-wrap" style="overflow-x:auto;">
                    <table class="vd-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Vessel</th>
                                <th>Client / Agent</th>
                                <th>Survey type</th>
                                <th>Port</th>
                                <th>Recovery</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($surveys)): foreach ($surveys as $i => $survey):
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
                                data-type="<?= sanitize(strtolower($survey['type_name'] ?? '')) ?>"
                                data-place="<?= sanitize(strtolower($survey['port_name'] ?? '')) ?>"
                                data-client="<?= sanitize(strtolower($survey['company_name'] ?? '')) ?>"
                                data-surveyor="<?= sanitize(strtolower($surveyorNames)) ?>"
                                data-month="<?= $ts ? sanitize(date('Y-m', $ts)) : '' ?>"
                                data-date="<?= (int)$ts ?>">
                                <td style="color:#94a3b8;font-weight:600;" data-row-num><?= $i + 1 ?></td>
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
                                        <span class="recovery-total"><b><?= sanitize($rp['total']) ?></b></span>
                                        <?php else: ?>
                                        <span class="recovery-total">Total: <b><?= sanitize($rp['total']) ?></b></span>
                                        <span class="recovery-parts">VLSFO: <?= sanitize($rp['vlsfo']) ?> · HSFO: <?= sanitize($rp['hsfo']) ?> · LSMGO: <?= sanitize($rp['lsmgo']) ?></span>
                                        <?php endif; ?>
                                        <?php if ($is_full_access): ?>
                                        <span class="recovery-tooltip"><i class="fa-solid fa-user-tie me-1"></i>Surveyor: <?= sanitize($surveyorNames) ?></span>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td style="white-space:nowrap;color:#64748b;font-size:12.5px;"><?= sanitize($date_disp) ?></td>
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
                <div class="vd-pagination">
                    <div class="vd-page-info">25 per page</div>
                    <div class="vd-page-links" data-desk-pager></div>
                </div>
            </div>
        </div>
    </div>


<script>
(function () {
  var PER = 25;
  var root = document.querySelector('.list-desktop-view, .vessels-desktop-view');
  if (!root) return;

  var search = root.querySelector('[data-desk-search]');
  var sortSel = root.querySelector('[data-desk-sort]');
  var placeSel = root.querySelector('[data-desk-place]');
  var clientSel = root.querySelector('[data-desk-client]');
  var surveyorSel = root.querySelector('[data-desk-surveyor]');
  var monthSel = root.querySelector('[data-desk-month]');
  var clearBtn = root.querySelector('[data-desk-clear]');
  var exportLink = root.querySelector('[data-desk-export]');
  var tbody = root.querySelector('.vd-table tbody');
  var meta = root.querySelector('[data-desk-meta]');
  var pager = root.querySelector('[data-desk-pager]');
  var empty = root.querySelector('[data-desk-empty]');
  if (!tbody) return;

  var allRows = Array.prototype.slice.call(tbody.querySelectorAll('tr.vd-row'));
  var page = 1;

  // 🌟 Modern custom-styled dropdown — a pill trigger + floating menu built
  // by JS over the real <select data-desk-*> (kept in the DOM, just
  // display:none'd), so filtered()/onChange() below still read these
  // selects' .value exactly as before; a custom item click just sets the
  // select's value and dispatches a real 'change' event on it.
  var enhancedDropdowns = [];
  function enhanceDropdown(selectEl) {
    if (!selectEl) return;
    var wrap = selectEl.closest('.vd-dd');
    if (!wrap) return;
    var iconSrc = wrap.querySelector('.vd-dd-icon-src');
    var iconClass = iconSrc ? iconSrc.className.replace('vd-dd-icon-src', '').trim() : '';
    if (iconSrc) iconSrc.remove();

    var trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'vd-dd-trigger';
    trigger.innerHTML = '<i class="' + iconClass + ' vd-dd-icon"></i><span class="vd-dd-label"></span><i class="fa-solid fa-chevron-down vd-dd-chevron"></i>';

    var menu = document.createElement('div');
    menu.className = 'vd-dd-menu';
    Array.prototype.forEach.call(selectEl.options, function (opt) {
      var item = document.createElement('div');
      item.className = 'vd-dd-item';
      item.textContent = opt.textContent;
      item.dataset.value = opt.value;
      item.addEventListener('click', function () {
        selectEl.value = opt.value;
        selectEl.dispatchEvent(new Event('change', { bubbles: true }));
        syncDropdownLabel(entry);
        closeAllDropdowns();
      });
      menu.appendChild(item);
    });

    wrap.appendChild(trigger);
    wrap.appendChild(menu);

    trigger.addEventListener('click', function (ev) {
      ev.stopPropagation();
      var wasOpen = menu.classList.contains('open');
      closeAllDropdowns();
      if (!wasOpen) { trigger.classList.add('open'); menu.classList.add('open'); }
    });

    var entry = { select: selectEl, trigger: trigger, menu: menu };
    enhancedDropdowns.push(entry);
    syncDropdownLabel(entry);
  }
  function syncDropdownLabel(entry) {
    var opt = entry.select.options[entry.select.selectedIndex];
    entry.trigger.querySelector('.vd-dd-label').textContent = opt ? opt.textContent : '';
    Array.prototype.forEach.call(entry.menu.children, function (item) {
      item.classList.toggle('selected', item.dataset.value === entry.select.value);
    });
  }
  function closeAllDropdowns() {
    enhancedDropdowns.forEach(function (e) {
      e.trigger.classList.remove('open');
      e.menu.classList.remove('open');
    });
  }
  document.addEventListener('click', closeAllDropdowns);
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') closeAllDropdowns(); });
  [sortSel, placeSel, clientSel, surveyorSel, monthSel].forEach(enhanceDropdown);

  function val(sel) { return sel ? (sel.value || '').toLowerCase() : ''; }

  function filtered() {
    var q = (search ? search.value : '').toLowerCase().trim();
    var parts = q ? q.split(/\s+/).filter(Boolean) : [];
    var place = val(placeSel);
    var client = val(clientSel);
    var surveyor = val(surveyorSel);
    var month = val(monthSel);
    var sort = sortSel ? sortSel.value : 'newest';

    var rows = allRows.filter(function (row) {
      var hay = (row.getAttribute('data-search') || '').toLowerCase();
      if (parts.length && !parts.every(function (p) { return hay.indexOf(p) !== -1; })) return false;
      if (place && (row.getAttribute('data-place') || '').toLowerCase() !== place) return false;
      if (client && (row.getAttribute('data-client') || '').toLowerCase() !== client) return false;
      if (surveyor && (row.getAttribute('data-surveyor') || '').toLowerCase() !== surveyor) return false;
      if (month && (row.getAttribute('data-month') || '').toLowerCase() !== month) return false;
      return true;
    });

    rows.sort(function (a, b) {
      var an = (a.getAttribute('data-name') || '');
      var bn = (b.getAttribute('data-name') || '');
      var ad = parseInt(a.getAttribute('data-date') || '0', 10);
      var bd = parseInt(b.getAttribute('data-date') || '0', 10);
      if (sort === 'name-asc') return an.localeCompare(bn);
      if (sort === 'name-desc') return bn.localeCompare(an);
      if (sort === 'oldest') return ad - bd;
      return bd - ad; // newest
    });
    return rows;
  }

  function render() {
    var rows = filtered();
    var total = rows.length;
    var pages = Math.max(1, Math.ceil(total / PER));
    if (page > pages) page = pages;
    var start = (page - 1) * PER;
    var slice = rows.slice(start, start + PER);

    allRows.forEach(function (r) { r.style.display = 'none'; });
    slice.forEach(function (r, i) {
      r.style.display = '';
      // Move the row into the table in sorted order too — this "#" badge
      // was already being computed against the sorted array, but without
      // actually reordering the <tr> elements the row STAYED at its
      // original (PHP query) position, so the number and the row it sat
      // next to disagreed whenever the client-side sort (e.g. "newest")
      // differed from the initial render order.
      tbody.appendChild(r);
      var num = r.querySelector('[data-row-num]');
      if (num) num.textContent = String(start + i + 1);
    });

    if (empty) empty.style.display = total === 0 ? 'block' : 'none';
    var tableWrap = root.querySelector('.vd-table-wrap');
    if (tableWrap) tableWrap.style.display = total === 0 ? 'none' : '';

    if (meta) {
      var from = total ? start + 1 : 0;
      var to = Math.min(start + slice.length, total);
      meta.innerHTML = 'Showing <strong>' + from + '</strong>–<strong>' + to + '</strong> of <strong>' + total + '</strong> · Page <strong>' + page + '</strong> / <strong>' + pages + '</strong> · 25 per page';
    }

    if (pager) {
      var html = '';
      function btn(label, p, disabled, active) {
        if (disabled) return '<span class="disabled">' + label + '</span>';
        if (active) return '<span class="active">' + label + '</span>';
        return '<a href="#" data-page="' + p + '">' + label + '</a>';
      }
      html += btn('&laquo;', 1, page <= 1);
      html += btn('&lsaquo; Prev', page - 1, page <= 1);
      var s = Math.max(1, page - 2), e = Math.min(pages, page + 2);
      for (var p = s; p <= e; p++) html += btn(String(p), p, false, p === page);
      html += btn('Next &rsaquo;', page + 1, page >= pages);
      html += btn('&raquo;', pages, page >= pages);
      pager.innerHTML = html;
      pager.querySelectorAll('a[data-page]').forEach(function (a) {
        a.addEventListener('click', function (ev) {
          ev.preventDefault();
          page = parseInt(a.getAttribute('data-page'), 10) || 1;
          render();
        });
      });
    }
  }

  // 🌟 Keep the Download/Export link in sync with the currently applied
  // filters — no filters applied → full export_completed.php (unfiltered,
  // same as before); any filter active → append it as a query param so
  // export_completed.php narrows the export to match what's on screen.
  function updateExportLink() {
    if (!exportLink) return;
    var params = [];
    var q = search ? search.value.trim() : '';
    if (q) params.push('q=' + encodeURIComponent(q));
    var place = val(placeSel);
    if (place) params.push('place=' + encodeURIComponent(place));
    var client = val(clientSel);
    if (client) params.push('client=' + encodeURIComponent(client));
    var surveyor = val(surveyorSel);
    if (surveyor) params.push('surveyor=' + encodeURIComponent(surveyor));
    var month = val(monthSel);
    if (month) params.push('month=' + encodeURIComponent(month));
    exportLink.href = 'export_completed.php' + (params.length ? '?' + params.join('&') : '');
  }

  function onChange() { page = 1; render(); updateExportLink(); }

  if (search) search.addEventListener('input', onChange);
  [sortSel, placeSel, clientSel, surveyorSel, monthSel].forEach(function (el) {
    if (el) el.addEventListener('change', onChange);
  });
  if (clearBtn) clearBtn.addEventListener('click', function (ev) {
    ev.preventDefault();
    if (search) search.value = '';
    [sortSel, placeSel, clientSel, surveyorSel, monthSel].forEach(function (el) {
      if (!el) return;
      if (el === sortSel) el.value = 'newest';
      else el.value = '';
    });
    enhancedDropdowns.forEach(syncDropdownLabel);
    page = 1;
    render();
    updateExportLink();
  });

  render();
  updateExportLink();
})();
</script>

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

<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>

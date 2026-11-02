<?php
/**
 * Pending Reports / Completed Vessels table: filters, sorting and paging done in SQL
 * (reports.php, completed.php and their Excel exports share this, so the download is
 * exactly what the table shows).
 *
 * State (GET): q, client[], agent[], type[], place[], surveyor[], date, ym, sort, dir, page.
 *   date — which date the Date column shows, sorts and filters by:
 *          appointed = surveys.appointment_date, survey = surveys.survey_completed_date,
 *          report = surveys.report_uploaded_date (set when the report is uploaded and the
 *          vessel moves to Completed).
 *   ym   — 'YYYY-MM': only vessels whose chosen date is in that calendar month.
 *   sort — num|vessel|client|type|port|surveyor|date, dir — asc|desc. No sort = the page's
 *          default: its own date newest first, vessels without that date last.
 */
require_once __DIR__ . '/list_filters.php';

const RT_DATE_FIELDS = [
    'appointed' => ['col' => 'appointment_date', 'label' => 'Appointed Date'],
    'survey'    => ['col' => 'survey_completed_date', 'label' => 'Survey Completed Date'],
    'report'    => ['col' => 'report_uploaded_date', 'label' => 'Report Completed Date'],
];
const RT_SORTS = ['num', 'vessel', 'client', 'type', 'port', 'surveyor', 'date'];
const RT_FIRST_YEAR = 2026;
const RT_PER_PAGE = 25;

/** The date as a DATE, NULL when missing or a stored zero date. */
function rtDateSql(string $key, string $s = 's'): string {
    $col = $s . '.' . RT_DATE_FIELDS[$key]['col'];
    return "(CASE WHEN YEAR($col) > 0 THEN DATE($col) END)";
}

/** The row's date (Y-m-d) for display, '' when missing. */
function rtRowDate(array $row, string $key): string {
    $v = (string)($row[RT_DATE_FIELDS[$key]['col']] ?? '');
    if ($v === '' || strpos($v, '0000') === 0 || ($t = strtotime(substr($v, 0, 10))) === false) return '';
    return date('Y-m-d', $t);
}

/** Survey type names as the lists show them ("Bunker Survey + Offhire"), for sorting. */
function rtTypeNamesSql(string $s = 's', string $st = 'st'): string {
    $ids = "REPLACE(COALESCE(NULLIF($s.survey_type_ids, ''), $s.survey_type_id), ' ', '')";
    return "COALESCE((SELECT GROUP_CONCAT(rtt.type_name ORDER BY FIND_IN_SET(rtt.id, $ids) SEPARATOR ' + ')
        FROM survey_types rtt WHERE FIND_IN_SET(rtt.id, $ids)), $st.type_name)";
}

/** Surveyor names as the lists show them (getCombinedSurveyorNames()), for sorting. */
function rtSurveyorNamesSql(string $s = 's', string $u = 'u'): string {
    return "COALESCE((SELECT GROUP_CONCAT(rtu.full_name ORDER BY rtss.id SEPARATOR ' + ')
        FROM survey_surveyors rtss JOIN users rtu ON rtu.id = rtss.surveyor_id WHERE rtss.survey_id = $s.id), $u.full_name)";
}

/** FROM + joins the pages use (aliases s, c, u, st, p). */
function rtFromSql(bool $leftJoinClients = false): string {
    return "FROM surveys s
        " . ($leftJoinClients ? 'LEFT JOIN' : 'JOIN') . " clients c ON s.client_id = c.id
        LEFT JOIN users u ON s.surveyor_id = u.id
        LEFT JOIN survey_types st ON s.survey_type_id = st.id
        LEFT JOIN ports p ON s.port_id = p.id";
}

/** What this user may see on the page: [where[], params[]]. */
function rtScope(string $status, string $role, int $userId, int $clientId, bool $fullAccess): array {
    $where = ['s.status = ?'];
    $params = [$status];
    if ($role === 'Client') {
        $where[] = 's.client_id = ?';
        $params[] = $clientId;
    } elseif (!$fullAccess) {
        $where[] = '(s.surveyor_id = ? OR s.id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = ?))';
        $params[] = $userId;
        $params[] = $userId;
    }
    return [$where, $params];
}

/** Filter / sort state from the query string. */
function rtState(array $g, string $defaultDate, bool $fullAccess): array {
    $date = isset(RT_DATE_FIELDS[$g['date'] ?? '']) ? (string)$g['date'] : $defaultDate;
    $ym = (string)($g['ym'] ?? ($g['month'] ?? '')); // ?month= — older Completed links (Report Completed month)
    if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $ym, $m) || (int)$m[1] < 2000) $ym = '';
    $sort = (string)($g['sort'] ?? '');
    $dir = ($g['dir'] ?? '') === 'asc' ? 'asc' : 'desc';
    // Older links: sort=newest|oldest|name-asc|name-desc
    if ($sort === 'oldest') { $sort = 'date'; $dir = 'asc'; $date = $defaultDate; }
    elseif ($sort === 'name-asc' || $sort === 'name-desc') { $dir = $sort === 'name-asc' ? 'asc' : 'desc'; $sort = 'vessel'; }
    if (!in_array($sort, RT_SORTS, true) || ($sort === 'surveyor' && !$fullAccess)) { $sort = ''; $dir = 'desc'; }
    return [
        'q' => mb_substr(trim((string)($g['q'] ?? '')), 0, 200),
        'client' => listFilterValues($g, 'client'),
        'agent' => listFilterValues($g, 'agent'),
        'type' => listFilterValues($g, 'type'),
        'place' => listFilterValues($g, 'place'),
        'surveyor' => $fullAccess ? listFilterValues($g, 'surveyor') : [],
        'date' => $date,
        'default_date' => $defaultDate,
        'ym' => $ym,
        'sort' => $sort,
        'dir' => $dir,
        'page' => max(1, (int)($g['page'] ?? 1)),
    ];
}

/** True when any filter or search is set (sorting alone doesn't count). */
function rtHasFilters(array $st): bool {
    return $st['q'] !== '' || $st['client'] || $st['agent'] || $st['type'] || $st['place'] || $st['surveyor']
        || $st['ym'] !== '' || $st['date'] !== $st['default_date'];
}

/** WHERE for the state on top of the scope: [sql, params]. */
function rtWhere(array $st, array $scopeWhere, array $scopeParams): array {
    $where = $scopeWhere;
    $params = $scopeParams;
    if ($st['q'] !== '') {
        $where[] = '(s.vessel_name LIKE ? OR c.company_name LIKE ? OR s.agent_name LIKE ? OR st.type_name LIKE ? OR p.port_name LIKE ? OR u.full_name LIKE ? OR s.report_number LIKE ?)';
        $like = '%' . addcslashes($st['q'], '%_\\') . '%';
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }
    // Client / Agent column: a row matches when its client or its agent is one of the ticked ones.
    $ca = [];
    if ($st['client']) $ca[] = listFilterInSql('c.company_name', $st['client'], $params);
    if ($st['agent']) $ca[] = listFilterInSql('s.agent_name', $st['agent'], $params);
    if ($ca) $where[] = '(' . implode(' OR ', $ca) . ')';
    if ($st['type']) $where[] = listFilterTypeSql($st['type'], $params);
    if ($st['place']) $where[] = listFilterInSql('p.port_name', $st['place'], $params);
    if ($st['surveyor']) $where[] = listFilterSurveyorNameSql($st['surveyor'], $params);
    if ($st['ym'] !== '') {
        $d = rtDateSql($st['date']);
        $start = $st['ym'] . '-01';
        $where[] = "$d >= ? AND $d < ?";
        $params[] = $start;
        $params[] = date('Y-m-d', strtotime($start . ' +1 month'));
    }
    return [implode(' AND ', $where), $params];
}

/** ORDER BY for the state. Every order ends with s.id so equal values keep a fixed order. */
function rtOrderSql(array $st): string {
    $def = rtDateSql($st['default_date']);
    $default = "($def IS NULL) ASC, $def DESC, s.id DESC";
    $dir = $st['dir'] === 'asc' ? 'ASC' : 'DESC';
    switch ($st['sort']) {
        case '':
            return $default;
        case 'num': // # follows the default order; descending is that order reversed
            return $st['dir'] === 'asc' ? $default : "($def IS NULL) DESC, $def ASC, s.id ASC";
        case 'date':
            $d = rtDateSql($st['date']);
            return "($d IS NULL) ASC, $d $dir, s.id $dir";
    }
    $exprs = [
        'vessel' => ['s.vessel_name'],
        'client' => ['c.company_name', 's.agent_name'],
        'type' => [rtTypeNamesSql()],
        'port' => ['p.port_name'],
        'surveyor' => [rtSurveyorNamesSql()],
    ][$st['sort']];
    $parts = ["(" . $exprs[0] . " IS NULL OR " . $exprs[0] . " = '') ASC"]; // blanks last
    foreach ($exprs as $e) $parts[] = "$e $dir";
    return implode(', ', $parts) . ", $def DESC, s.id DESC";
}

/** Count + one page of rows. Returns [total, rows, page, pages]. */
function rtFetchPage(PDO $db, array $st, string $whereSql, array $params, int $perPage = RT_PER_PAGE): array {
    $c = $db->prepare('SELECT COUNT(*) ' . rtFromSql() . " WHERE $whereSql");
    $c->execute($params);
    $total = (int)$c->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($st['page'], $pages);
    $offset = ($page - 1) * $perPage;
    $q = $db->prepare('SELECT s.*, c.company_name, u.full_name AS surveyor_name, st.type_name, p.port_name '
        . rtFromSql() . " WHERE $whereSql ORDER BY " . rtOrderSql($st) . ' LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset);
    $q->execute($params);
    return [$total, $q->fetchAll(PDO::FETCH_ASSOC), $page, $pages];
}

/** Filter choices from everything the user may see on the page (not narrowed by other filters). */
function rtOptions(PDO $db, array $scopeWhere, array $scopeParams, bool $fullAccess): array {
    $w = implode(' AND ', $scopeWhere);
    $col = function (string $sql) use ($db, $scopeParams): array {
        $q = $db->prepare($sql);
        $q->execute($scopeParams);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $v) {
            $v = trim((string)$v);
            if ($v !== '') $out[mb_strtolower($v)] = $v;
        }
        natcasesort($out);
        return array_values($out);
    };
    $types = [];
    foreach ($db->query('SELECT type_name FROM survey_types ORDER BY type_name ASC')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        if (trim((string)$t) !== '') $types[] = trim((string)$t);
    }
    $opts = [
        'client' => $col("SELECT DISTINCT c.company_name " . rtFromSql() . " WHERE $w"),
        'agent' => $col("SELECT DISTINCT s.agent_name " . rtFromSql() . " WHERE $w"),
        'type' => $types,
        'place' => $col("SELECT DISTINCT p.port_name " . rtFromSql() . " WHERE $w"),
        'surveyor' => [],
    ];
    if ($fullAccess) {
        $opts['surveyor'] = $col("SELECT DISTINCT COALESCE(ju.full_name, u.full_name) " . rtFromSql() . "
            LEFT JOIN survey_surveyors jss ON jss.survey_id = s.id LEFT JOIN users ju ON ju.id = jss.surveyor_id WHERE $w");
    }
    return $opts;
}

/** Years (newest first, from 2026) that the chosen date has on the page. */
function rtYears(PDO $db, array $scopeWhere, array $scopeParams, string $dateKey): array {
    $d = rtDateSql($dateKey);
    $q = $db->prepare("SELECT DISTINCT YEAR($d) FROM surveys s WHERE " . implode(' AND ', $scopeWhere) . " AND YEAR($d) >= " . RT_FIRST_YEAR . ' ORDER BY 1 DESC');
    $q->execute($scopeParams);
    $years = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    if (!$years) $years = [max(RT_FIRST_YEAR, (int)date('Y'))];
    return $years;
}

/** Query string for the state with changes ($set: key => value, null removes). Page resets unless set. */
function rtQs(array $st, array $set = []): string {
    if (!array_key_exists('page', $set)) $set['page'] = null;
    $st = array_merge($st, $set);
    $parts = [];
    if (($st['q'] ?? '') !== '') $parts[] = 'q=' . rawurlencode($st['q']);
    foreach (['client', 'agent', 'type', 'place', 'surveyor'] as $k) {
        foreach ((array)($st[$k] ?? []) as $v) $parts[] = $k . '%5B%5D=' . rawurlencode($v);
    }
    if (($st['date'] ?? '') !== ($st['default_date'] ?? '') && !empty($st['date'])) $parts[] = 'date=' . rawurlencode($st['date']);
    if (!empty($st['ym'])) $parts[] = 'ym=' . rawurlencode($st['ym']);
    if (!empty($st['sort'])) { $parts[] = 'sort=' . rawurlencode($st['sort']); $parts[] = 'dir=' . ($st['dir'] === 'asc' ? 'asc' : 'desc'); }
    if (!empty($st['page']) && (int)$st['page'] > 1) $parts[] = 'page=' . (int)$st['page'];
    return implode('&', $parts);
}

function rtUrl(string $base, array $st, array $set = []): string {
    $qs = rtQs($st, $set);
    return $base . ($qs !== '' ? '?' . $qs : '');
}

/** Hidden inputs carrying the state except the given keys (for the GET forms). */
function rtHidden(array $st, array $except): string {
    $html = '';
    parse_str(rtQs($st, array_fill_keys($except, null)), $vals);
    foreach ($vals as $k => $v) {
        foreach ((array)$v as $one) {
            $html .= '<input type="hidden" name="' . htmlspecialchars($k . (is_array($v) ? '[]' : ''), ENT_QUOTES) . '" value="' . htmlspecialchars((string)$one, ENT_QUOTES) . '">';
        }
    }
    return $html;
}

/** "How the table is sorted", for the line above it. */
function rtSortText(array $st): string {
    $up = $st['dir'] === 'asc';
    switch ($st['sort']) {
        case '': return RT_DATE_FIELDS[$st['default_date']]['label'] . ', newest first';
        case 'num': return '#, ' . ($up ? 'ascending' : 'descending');
        case 'date': return RT_DATE_FIELDS[$st['date']]['label'] . ', ' . ($up ? 'oldest first' : 'newest first');
    }
    $names = ['vessel' => 'Vessel', 'client' => 'Client / Agent', 'type' => 'Survey Type', 'port' => 'Port', 'surveyor' => 'Surveyor'];
    return $names[$st['sort']] . ', ' . ($up ? 'A–Z' : 'Z–A');
}

/** Sort icon for a column heading. */
function rtSortIcon(array $st, string $key): string {
    $active = $st['sort'] === $key || ($st['sort'] === '' && $key === 'date' && $st['date'] === $st['default_date']);
    if (!$active) return '<i class="fa-solid fa-sort rt-sort-ic" aria-hidden="true"></i>';
    $up = $st['sort'] === '' ? false : $st['dir'] === 'asc';
    return '<i class="fa-solid fa-sort-' . ($up ? 'up' : 'down') . ' rt-sort-ic on" aria-hidden="true"></i>';
}

/** Column heading that opens its menu. */
function rtHead(array $st, string $key, string $label, string $sub = ''): string {
    $filtered = in_array($key, ['client', 'type', 'port', 'surveyor', 'date'], true) && rtColumnFiltered($st, $key);
    return '<th class="rt-th" data-col="' . $key . '"><button type="button" class="rt-th-btn' . ($filtered ? ' filtered' : '') . '" data-rt-open="' . $key . '" aria-haspopup="true" data-testid="rt-head-' . $key . '">'
        . '<span class="rt-th-l">' . htmlspecialchars($label) . ($sub !== '' ? '<small>' . htmlspecialchars($sub) . '</small>' : '') . '</span>'
        . ($filtered ? '<i class="fa-solid fa-filter rt-filter-ic" aria-label="Filtered"></i>' : '')
        . rtSortIcon($st, $key) . '</button></th>';
}

function rtColumnFiltered(array $st, string $key): bool {
    switch ($key) {
        case 'client': return (bool)($st['client'] || $st['agent']);
        case 'type': return (bool)$st['type'];
        case 'port': return (bool)$st['place'];
        case 'surveyor': return (bool)$st['surveyor'];
        case 'date': return $st['ym'] !== '';
    }
    return false;
}

/** Sort choices for a menu. */
function rtSortItems(string $base, array $st, string $key, string $ascLabel, string $descLabel): string {
    $html = '<div class="rt-menu-sec rt-sorts">';
    foreach (['asc' => $ascLabel, 'desc' => $descLabel] as $dir => $label) {
        $on = $st['sort'] === $key && $st['dir'] === $dir;
        $html .= '<a class="rt-sort-item' . ($on ? ' on' : '') . '" href="' . htmlspecialchars(rtUrl($base, $st, ['sort' => $key, 'dir' => $dir])) . '" data-testid="rt-sort-' . $key . '-' . $dir . '">'
            . '<i class="fa-solid fa-arrow-' . ($dir === 'asc' ? 'up' : 'down') . '-short-wide"></i> ' . htmlspecialchars($label)
            . ($on ? ' <i class="fa-solid fa-check rt-check"></i>' : '') . '</a>';
    }
    return $html . '</div>';
}

/** Tick-box list for a filter (Select all / Clear / Apply). $groups: [[param, title, options[]], ...] */
function rtFilterForm(string $base, array $st, string $key, array $groups): string {
    $params = array_column($groups, 0);
    $count = 0;
    foreach ($groups as $g) $count += count($g[2]);
    $html = '<form class="rt-filter" method="get" action="' . htmlspecialchars($base) . '" data-rt-filter="' . $key . '">' . rtHidden($st, $params);
    $html .= '<div class="rt-filter-tools"><button type="button" class="rt-link" data-rt-all>Select all</button><button type="button" class="rt-link" data-rt-none>Clear selection</button></div>';
    if ($count > 8) $html .= '<input type="search" class="rt-find" placeholder="Find…" data-rt-find aria-label="Find in list">';
    $html .= '<div class="rt-opts">';
    foreach ($groups as [$param, $title, $options]) {
        if (count($groups) > 1) $html .= '<div class="rt-opt-group">' . htmlspecialchars($title) . '</div>';
        $picked = array_map('mb_strtolower', $st[$param]);
        if (!$options) $html .= '<div class="rt-opt-empty">None</div>';
        foreach ($options as $o) {
            $html .= '<label class="rt-opt"><input type="checkbox" name="' . $param . '[]" value="' . htmlspecialchars($o, ENT_QUOTES) . '"'
                . (in_array(mb_strtolower($o), $picked, true) ? ' checked' : '') . '> <span>' . htmlspecialchars($o) . '</span></label>';
        }
    }
    $html .= '</div><div class="rt-filter-bar"><button type="submit" class="rt-apply" data-testid="rt-apply-' . $key . '">Apply</button></div></form>';
    return $html;
}

/** All column menus (rendered once; opened from the headings or the mobile filter chips). */
function rtMenus(string $base, array $st, array $opts, array $years, bool $fullAccess): string {
    $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    $m = [];
    $m['num'] = ['#', rtSortItems($base, $st, 'num', 'Ascending', 'Descending')];
    $m['vessel'] = ['Vessel', rtSortItems($base, $st, 'vessel', 'A → Z', 'Z → A')];
    $m['client'] = ['Client / Agent', rtSortItems($base, $st, 'client', 'A → Z', 'Z → A')
        . rtFilterForm($base, $st, 'client', [['client', 'Clients', $opts['client']], ['agent', 'Agents', $opts['agent']]])];
    $m['type'] = ['Survey Type', rtSortItems($base, $st, 'type', 'A → Z', 'Z → A') . rtFilterForm($base, $st, 'type', [['type', 'Survey types', $opts['type']]])];
    $m['port'] = ['Port', rtSortItems($base, $st, 'port', 'A → Z', 'Z → A') . rtFilterForm($base, $st, 'port', [['place', 'Ports', $opts['place']]])];
    if ($fullAccess) {
        $m['surveyor'] = ['Surveyor', rtSortItems($base, $st, 'surveyor', 'A → Z', 'Z → A') . rtFilterForm($base, $st, 'surveyor', [['surveyor', 'Surveyors', $opts['surveyor']]])];
    }
    // Date: which date (radio), sort, Filter by Month (year → month)
    $d = rtSortItems($base, $st, 'date', 'Oldest first', 'Newest first');
    $d .= '<div class="rt-menu-sec"><div class="rt-menu-cap">Date type</div>';
    foreach (RT_DATE_FIELDS as $k => $f) {
        $on = $st['date'] === $k;
        $d .= '<a class="rt-sort-item' . ($on ? ' on' : '') . '" href="' . htmlspecialchars(rtUrl($base, $st, ['date' => $k])) . '" data-testid="rt-date-' . $k . '">'
            . '<i class="fa-regular fa-' . ($on ? 'circle-dot' : 'circle') . '"></i> ' . htmlspecialchars($f['label'])
            . ($k === $st['default_date'] ? ' <small>(default)</small>' : '') . '</a>';
    }
    $d .= '</div><div class="rt-menu-sec"><button type="button" class="rt-sort-item rt-month-toggle" data-rt-month-toggle aria-expanded="' . ($st['ym'] !== '' ? 'true' : 'false') . '" data-testid="rt-month-toggle">'
        . '<i class="fa-regular fa-calendar"></i> Filter by Month <i class="fa-solid fa-chevron-right rt-chev"></i></button>'
        . '<div class="rt-months"' . ($st['ym'] !== '' ? '' : ' hidden') . ' data-rt-months><div class="rt-menu-cap">By ' . htmlspecialchars(RT_DATE_FIELDS[$st['date']]['label']) . '</div>';
    $selY = $st['ym'] !== '' ? (int)substr($st['ym'], 0, 4) : 0;
    foreach ($years as $y) {
        $open = $selY === $y || ($selY === 0 && $y === $years[0]);
        $d .= '<button type="button" class="rt-year' . ($open ? ' open' : '') . '" data-rt-year="' . $y . '" data-testid="rt-year-' . $y . '">' . $y . ' <i class="fa-solid fa-chevron-right rt-chev"></i></button>'
            . '<div class="rt-month-grid"' . ($open ? '' : ' hidden') . ' data-rt-year-months="' . $y . '">';
        foreach ($months as $i => $mn) {
            $key = sprintf('%04d-%02d', $y, $i + 1);
            $d .= '<a class="rt-month' . ($st['ym'] === $key ? ' on' : '') . '" href="' . htmlspecialchars(rtUrl($base, $st, ['ym' => $key])) . '" data-testid="rt-month-' . $key . '">' . substr($mn, 0, 3) . '<span class="rt-sr">' . $mn . '</span></a>';
        }
        $d .= '</div>';
    }
    if ($st['ym'] !== '') $d .= '<a class="rt-link rt-clear-month" href="' . htmlspecialchars(rtUrl($base, $st, ['ym' => ''])) . '">Clear month</a>';
    $d .= '</div></div>';
    $m['date'] = ['Date', $d];
    // Mobile: one "Sort" menu with every column's sort
    $s = '';
    foreach ([['date', 'Date', 'Oldest first', 'Newest first'], ['vessel', 'Vessel', 'A → Z', 'Z → A'], ['client', 'Client / Agent', 'A → Z', 'Z → A'],
              ['type', 'Survey Type', 'A → Z', 'Z → A'], ['port', 'Port', 'A → Z', 'Z → A'], ['surveyor', 'Surveyor', 'A → Z', 'Z → A'], ['num', '#', 'Ascending', 'Descending']] as [$k, $l, $a, $z]) {
        if ($k === 'surveyor' && !$fullAccess) continue;
        $s .= '<div class="rt-menu-cap">' . $l . '</div>' . rtSortItems($base, $st, $k, $a, $z);
    }
    $m['sort'] = ['Sort', '<div class="rt-default-sort">' . ($st['sort'] === '' ? '<i class="fa-solid fa-check rt-check"></i> ' : '')
        . '<a href="' . htmlspecialchars(rtUrl($base, $st, ['sort' => '', 'dir' => 'desc'])) . '">Default — ' . htmlspecialchars(RT_DATE_FIELDS[$st['default_date']]['label']) . ', newest first</a></div>' . $s];

    $html = '';
    foreach ($m as $key => [$title, $body]) {
        $html .= '<div class="rt-menu" id="rt-menu-' . $key . '" data-rt-menu="' . $key . '" role="dialog" aria-label="' . htmlspecialchars($title) . '" hidden>'
            . '<div class="rt-menu-head">' . htmlspecialchars($title) . '<button type="button" class="rt-x" data-rt-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>'
            . '<div class="rt-menu-body">' . $body . '</div></div>';
    }
    return $html;
}

/** Chips for the active filters (each removable) + Clear all. */
function rtChips(string $base, array $st): string {
    $chips = [];
    $add = function (string $label, array $set) use (&$chips, $base, $st) {
        $chips[] = '<a class="rt-chip" href="' . htmlspecialchars(rtUrl($base, $st, $set)) . '" title="Remove">' . htmlspecialchars($label) . ' <i class="fa-solid fa-xmark"></i></a>';
    };
    if ($st['q'] !== '') $add('Search: ' . $st['q'], ['q' => '']);
    foreach (['client' => 'Client', 'agent' => 'Agent', 'type' => 'Type', 'place' => 'Port', 'surveyor' => 'Surveyor'] as $k => $l) {
        foreach ($st[$k] as $v) $add($l . ': ' . $v, [$k => array_values(array_diff($st[$k], [$v]))]);
    }
    if ($st['date'] !== $st['default_date']) $add('Date: ' . RT_DATE_FIELDS[$st['date']]['label'], ['date' => $st['default_date']]);
    if ($st['ym'] !== '') $add(date('F Y', strtotime($st['ym'] . '-01')) . ' · ' . RT_DATE_FIELDS[$st['date']]['label'], ['ym' => '']);
    if (!$chips && $st['sort'] === '') return '';
    return '<div class="rt-chips" data-testid="rt-chips">' . implode('', $chips)
        . '<a class="rt-clear-all" href="' . htmlspecialchars($base) . '" data-testid="rt-clear-all"><i class="fa-solid fa-rotate-left"></i> Clear all filters</a></div>';
}

/** Page links. */
function rtPager(string $base, array $st, int $page, int $pages): string {
    if ($pages <= 1) return '';
    $link = function (int $p, string $label, bool $on = false, bool $off = false) use ($base, $st) {
        if ($off) return '<span class="rt-pg off">' . $label . '</span>';
        return '<a class="rt-pg' . ($on ? ' on' : '') . '" href="' . htmlspecialchars(rtUrl($base, $st, ['page' => $p])) . '">' . $label . '</a>';
    };
    $html = $link(1, '«', false, $page === 1) . $link($page - 1, '‹ Prev', false, $page === 1);
    $from = max(1, $page - 2);
    $to = min($pages, $from + 4);
    $from = max(1, $to - 4);
    for ($p = $from; $p <= $to; $p++) $html .= $link($p, (string)$p, $p === $page);
    return '<nav class="rt-pager" aria-label="Pages">' . $html . $link($page + 1, 'Next ›', false, $page === $pages) . $link($pages, '»', false, $page === $pages) . '</nav>';
}

/** Page header: title (left), search + Download (middle), totals (right).
 *  $stats: [[number, label, extra class], ...]; $extra: more buttons after Download. */
function rtHeader(string $title, string $icon, string $base, array $st, string $exportUrl, array $stats, string $extra = ''): string {
    $h = '<div class="rt-head" data-testid="rt-head">'
        . '<h1 class="rt-title"><i class="' . htmlspecialchars($icon) . '" aria-hidden="true"></i>' . htmlspecialchars($title) . '</h1>'
        . '<div class="rt-mid"><form class="rt-search" method="get" action="' . htmlspecialchars($base) . '" role="search" data-rt-search>' . rtHidden($st, ['q'])
        . '<i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>'
        . '<input type="search" name="q" value="' . htmlspecialchars($st['q'], ENT_QUOTES) . '" placeholder="Search vessel, report no, client, agent, port…" aria-label="Search" autocomplete="off" data-testid="rt-search">'
        . ($st['q'] !== '' ? '<button type="button" class="rt-search-x" data-rt-search-clear aria-label="Clear search"><i class="fa-solid fa-xmark"></i></button>' : '')
        . '</form><a class="rt-btn dl" href="' . htmlspecialchars($exportUrl) . '" data-testid="rt-download"><i class="fa-solid fa-download"></i> Download</a>' . $extra . '</div>'
        . '<div class="rt-count">';
    foreach ($stats as $s) {
        $h .= '<div class="rt-stat ' . htmlspecialchars($s[2] ?? '') . '"><b>' . $s[0] . '</b><span>' . htmlspecialchars($s[1]) . '</span></div>';
    }
    return $h . '</div></div>';
}

/** "Showing 1–25 of 40 · Sorted by …" */
function rtMeta(array $st, int $total, int $offset, int $shown): string {
    $range = $total ? ($offset + 1) . '–' . ($offset + $shown) : '0';
    return '<div class="rt-meta" data-testid="rt-meta">Showing <b>' . $range . '</b> of <b>' . $total . '</b> · Sorted by ' . htmlspecialchars(rtSortText($st)) . '</div>';
}

/** Phone filter bar: chips that open the same column menus. */
function rtMobileBar(array $st, bool $fullAccess): string {
    $chip = function (string $key, string $icon, string $label, bool $on) {
        return '<button type="button" class="rt-mchip' . ($on ? ' on' : '') . '" data-rt-open="' . $key . '" data-testid="rt-mchip-' . $key . '"><i class="' . $icon . '"></i> ' . htmlspecialchars($label) . '</button>';
    };
    $n = function (array $a) { return $a ? ' (' . count($a) . ')' : ''; };
    $h = '<div class="rt-mbar">' . $chip('sort', 'fa-solid fa-arrow-down-wide-short', 'Sort', $st['sort'] !== '')
        . $chip('client', 'fa-solid fa-building', 'Client / Agent' . $n(array_merge($st['client'], $st['agent'])), (bool)($st['client'] || $st['agent']))
        . $chip('type', 'fa-solid fa-list-check', 'Survey Type' . $n($st['type']), (bool)$st['type'])
        . $chip('port', 'fa-solid fa-location-dot', 'Port' . $n($st['place']), (bool)$st['place']);
    if ($fullAccess) $h .= $chip('surveyor', 'fa-solid fa-user-tie', 'Surveyor' . $n($st['surveyor']), (bool)$st['surveyor']);
    $dl = $st['ym'] !== '' ? date('M Y', strtotime($st['ym'] . '-01')) : 'Date';
    return $h . $chip('date', 'fa-regular fa-calendar', $dl, $st['ym'] !== '' || $st['date'] !== $st['default_date']) . '</div>';
}

/** Short name of a date type for table cells / cards ("Survey Completed"). */
function rtDateShort(string $key): string {
    return preg_replace('/ Date$/', '', RT_DATE_FIELDS[$key]['label']);
}

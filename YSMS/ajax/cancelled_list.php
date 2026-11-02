<?php
/**
 * Cancelled Vessels grid data (cancelled.php). Same visibility as the page and its export
 * (includes/cancelled_list.php). Surveys are only read.
 *
 *   GET  a=list   q, type, place, client, surveyor (Admin), agent, from, to, ids, sort (JSON),
 *                 page, per → rows, total, pages, summary counts, survey-type chips
 *   POST a=prefs  prefs (JSON: order, hidden, widths, sort, per) → this user's layout
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/cancelled_list.php';
checkAuth();

function cnJson(int $code, array $data): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

$db = getDB();
$action = (string)($_REQUEST['a'] ?? '');

if ($action === 'list') {
    [$f, $where, $params] = cancelledFilters($db, $_GET);
    $per = (int)($_GET['per'] ?? 50);
    if (!in_array($per, [25, 50, 100, 200], true)) $per = 50;
    $cnt = $db->prepare('SELECT COUNT(*) ' . CANCELLED_FROM . " WHERE $where");
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();
    $pages = max(1, (int)ceil($total / $per));
    $page = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
    $st = $db->prepare('SELECT s.*, c.company_name, u.full_name AS surveyor_name, st.type_name, p.port_name, ' . CANCELLED_DATE_SQL . ' AS shown_date '
        . CANCELLED_FROM . " WHERE $where ORDER BY " . cancelledOrderSql(json_decode((string)($_GET['sort'] ?? '[]'), true)) . " LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($params);
    $rows = array_map(fn($r) => cancelledRow($db, $r), $st->fetchAll(PDO::FETCH_ASSOC));

    // summary over the filtered rows
    $sm = $db->prepare("SELECT COUNT(DISTINCT s.client_id) clients, COUNT(DISTINCT s.port_id) ports, COUNT(DISTINCT NULLIF(TRIM(s.agent_name), '')) agents " . CANCELLED_FROM . " WHERE $where");
    $sm->execute($params);
    $sum = array_map('intval', $sm->fetch(PDO::FETCH_ASSOC) ?: []);
    $sum['vessels'] = $total;
    $typeNames = [];
    foreach ($db->query('SELECT id, type_name FROM survey_types')->fetchAll(PDO::FETCH_ASSOC) as $t) $typeNames[(int)$t['id']] = (string)$t['type_name'];
    $idsOf = function (array $r): array { $raw = trim((string)($r['survey_type_ids'] ?? '')) !== '' ? $r['survey_type_ids'] : (string)($r['survey_type_id'] ?? ''); return array_filter(array_map('intval', explode(',', str_replace(' ', '', (string)$raw)))); };
    $tq = $db->prepare('SELECT s.id, s.survey_type_ids, s.survey_type_id ' . CANCELLED_FROM . " WHERE $where");
    $tq->execute($params);
    $types = [];
    $ids = [];
    foreach ($tq->fetchAll(PDO::FETCH_ASSOC) as $r) { $ids[] = (int)$r['id']; foreach ($idsOf($r) as $tid) if (isset($typeNames[$tid])) $types[$typeNames[$tid]] = true; }
    $sum['types'] = count($types);
    if (cancelledShowsSurveyor()) {
        $n = 0;
        if ($ids) {
            $in = implode(',', $ids);
            $n = (int)$db->query("SELECT COUNT(DISTINCT x) FROM (SELECT ss.surveyor_id x FROM survey_surveyors ss WHERE ss.survey_id IN ($in)
                UNION SELECT s.surveyor_id FROM surveys s WHERE s.id IN ($in) AND s.surveyor_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM survey_surveyors z WHERE z.survey_id = s.id)) t")->fetchColumn();
        }
        $sum['surveyors'] = $n;
    }
    // survey-type chips: counts without the type filter
    $g2 = $_GET; unset($g2['type']);
    [, $w2, $p2] = cancelledFilters($db, $g2);
    $tq2 = $db->prepare('SELECT s.survey_type_ids, s.survey_type_id ' . CANCELLED_FROM . " WHERE $w2");
    $tq2->execute($p2);
    $chips = []; $all = 0;
    foreach ($tq2->fetchAll(PDO::FETCH_ASSOC) as $r) { $all++; foreach (array_unique($idsOf($r)) as $tid) if (isset($typeNames[$tid])) $chips[$typeNames[$tid]] = ($chips[$typeNames[$tid]] ?? 0) + 1; }
    arsort($chips);
    cnJson(200, ['ok' => true, 'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $per,
        'summary' => $sum, 'chips' => array_slice($chips, 0, 6, true), 'chips_all' => $all]);
}

if ($action === 'prefs' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $p = json_decode((string)($_POST['prefs'] ?? ''), true);
    if (!is_array($p)) cnJson(422, ['ok' => false, 'error' => 'Bad layout.']);
    $cols = ['report_no', 'client', 'agent', 'type', 'port', 'surveyor', 'date', 'status', 'actions'];
    $clean = [
        'order' => array_values(array_intersect(array_map('strval', (array)($p['order'] ?? [])), $cols)),
        'hidden' => array_values(array_intersect(array_map('strval', (array)($p['hidden'] ?? [])), $cols)),
        'widths' => [], 'sort' => [],
        'per' => in_array((int)($p['per'] ?? 50), [25, 50, 100, 200], true) ? (int)$p['per'] : 50,
    ];
    foreach ((array)($p['widths'] ?? []) as $k => $w) if (in_array($k, $cols, true) || $k === 'vessel') $clean['widths'][$k] = max(60, min(600, (int)$w));
    foreach (array_slice((array)($p['sort'] ?? []), 0, 3) as $x) {
        if (is_array($x) && isset(CANCELLED_SORTS[$x['f'] ?? ''])) $clean['sort'][] = ['f' => $x['f'], 'd' => ($x['d'] ?? '') === 'desc' ? 'desc' : 'asc'];
    }
    listGridPrefsTable($db);
    $db->prepare('INSERT INTO list_grid_prefs (user_id, page, prefs) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE prefs = VALUES(prefs)')
       ->execute([(int)($_SESSION['user_id'] ?? 0), 'cancelled', json_encode($clean)]);
    cnJson(200, ['ok' => true, 'prefs' => $clean]);
}
cnJson(400, ['ok' => false, 'error' => 'Unknown action.']);

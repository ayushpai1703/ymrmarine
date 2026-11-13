<?php
/**
 * Dashboard global search: matches vessel name, agent name, client company
 * name or port name across every survey stage (Pending Vessel, Pending
 * Report, Completed, Cancelled) and returns each hit's current status so
 * the dropdown can show it and route to the right detail page on click.
 *
 * GET so checkAuth()'s CSRF check (POST-only) doesn't apply — this is a
 * read-only lookup, same as ajax/check_pending_vessel_duplicate.php.
 */
require_once __DIR__ . '/../config/config.php';
checkAuth();
header('Content-Type: application/json; charset=utf-8');

$db = getDB();
$role = $_SESSION['role'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);

$term = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($term) < 2) {
    echo json_encode(['results' => []]);
    exit;
}

$like = '%' . $term . '%';
$params = [$like, $like, $like, $like];

$sql = "
    SELECT s.id, s.vessel_name, s.report_number, s.agent_name, s.status,
           c.company_name, p.port_name
    FROM surveys s
    LEFT JOIN clients c ON s.client_id = c.id
    LEFT JOIN ports p ON s.port_id = p.id
    WHERE (s.vessel_name LIKE ? OR s.agent_name LIKE ? OR c.company_name LIKE ? OR p.port_name LIKE ?)
";

if ($role === 'Client') {
    $sql .= " AND s.client_id = ?";
    $params[] = getClientIdForUser($db, $user_id);
} elseif (!in_array($role, ['Admin', 'Super Admin'], true)) {
    // Surveyor — only vessels assigned to them, primary or via the
    // multi-surveyor junction table (same scoping as vessels.php).
    $sql .= " AND (s.surveyor_id = ? OR s.id IN (SELECT survey_id FROM survey_surveyors WHERE surveyor_id = ?))";
    $params[] = $user_id;
    $params[] = $user_id;
}

$sql .= " ORDER BY s.assign_date DESC, s.id DESC LIMIT 10";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('global_search: ' . $e->getMessage());
    echo json_encode(['results' => []]);
    exit;
}

$statusDetailPage = [
    'Pending Vessel' => 'vessel_detail.php',
    'Cancelled'       => 'vessel_detail.php',
    'Missed'          => 'vessel_detail.php',
    'Pending Report' => 'report_detail.php',
    'Completed'      => 'completed_detail.php',
];

$results = [];
foreach ($rows as $row) {
    $page = $statusDetailPage[$row['status']] ?? 'vessel_detail.php';
    $results[] = [
        'id'            => (int)$row['id'],
        'vessel_name'   => $row['vessel_name'],
        'report_number' => $row['report_number'] ?: '',
        'agent_name'    => $row['agent_name'] ?: '',
        'client_name'   => $row['company_name'] ?: '',
        'port_name'     => $row['port_name'] ?: '',
        'status'        => $row['status'],
        'detail_url'    => $page . '?id=' . (int)$row['id'],
    ];
}

echo json_encode(['results' => $results]);

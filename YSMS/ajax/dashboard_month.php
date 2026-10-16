<?php
// Dashboard Month Summary card — ships + recovery for ?y=YYYY&m=MM (Survey Completed Date).
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/dashboard_month.php';
checkAuth();
header('Content-Type: application/json; charset=utf-8');

$y = (int)($_GET['y'] ?? 0);
$m = (int)($_GET['m'] ?? 0);
if ($y < 2000 || $y > 2100 || $m < 1 || $m > 12) {
    echo json_encode(['success' => false, 'message' => 'Invalid month.']);
    exit;
}
$db = getDB();
$role = (string)($_SESSION['role'] ?? '');
$userId = (int)($_SESSION['user_id'] ?? 0);
$clientRowId = 0;
if ($role === 'Client') {
    $c = $db->prepare('SELECT id FROM clients WHERE user_id = ? LIMIT 1');
    $c->execute([$userId]);
    $clientRowId = (int)($c->fetchColumn() ?: 0);
}
$r = dashboardMonthStats($db, $role, $userId, $clientRowId, $y, $m);
echo json_encode(['success' => true, 'ships' => $r['ships'], 'recovery' => number_format($r['recovery'], 3) . ' MT', 'label' => $r['label']]);

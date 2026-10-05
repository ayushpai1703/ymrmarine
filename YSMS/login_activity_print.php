<?php
/**
 * Login Activity — print-friendly export ("PDF" via the browser's own
 * Print > Save as PDF). This codebase has no PDF-generation library
 * (confirmed: no composer dependency for one), so rather than adding an
 * unvetted new dependency for a single export button, PDF export is this
 * plain print-styled HTML page — the same filters as the on-screen list,
 * opened in a new tab, that the user saves as PDF from the browser's own
 * print dialog.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/login_activity_helpers.php';
checkAuth();

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['Admin', 'Super Admin'], true)) {
    http_response_code(403);
    exit('Access Denied');
}

$db = getDB();
ensureLoginActivityTable($db);

$where = [];
$params = [];

$search = trim((string)($_GET['search'] ?? ''));
if ($search !== '') {
    $where[] = '(user_name LIKE ? OR user_email LIKE ? OR username LIKE ? OR user_id = ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = ctype_digit($search) ? (int)$search : -1;
}

$filterRole = trim((string)($_GET['role'] ?? 'All'));
if ($filterRole !== '' && $filterRole !== 'All') {
    $where[] = 'role = ?';
    $params[] = $filterRole;
}

$status = trim((string)($_GET['status'] ?? 'All'));
if ($status === 'Successful') {
    $where[] = "login_status = 'SUCCESS'";
} elseif ($status === 'Failed') {
    $where[] = "login_status = 'FAILED'";
}

$device = trim((string)($_GET['device'] ?? 'All'));
if ($device !== '' && $device !== 'All') {
    $where[] = 'device_type = ?';
    $params[] = $device;
}

$country = trim((string)($_GET['country'] ?? 'All'));
if ($country !== '' && $country !== 'All') {
    $where[] = 'country = ?';
    $params[] = $country;
}

$dateRange = trim((string)($_GET['date_range'] ?? ''));
if ($dateRange === 'today') {
    $where[] = 'DATE(login_time) = CURDATE()';
} elseif ($dateRange === 'yesterday') {
    $where[] = 'DATE(login_time) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)';
} elseif ($dateRange === 'last7') {
    $where[] = 'login_time >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)';
} elseif ($dateRange === 'last30') {
    $where[] = 'login_time >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)';
} elseif ($dateRange === 'custom') {
    $from = trim((string)($_GET['date_from'] ?? ''));
    $to = trim((string)($_GET['date_to'] ?? ''));
    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $where[] = 'login_time >= ?';
        $params[] = $from . ' 00:00:00';
    }
    if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $where[] = 'login_time <= ?';
        $params[] = $to . ' 23:59:59';
    }
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$rows = [];
try {
    $stmt = $db->prepare("SELECT user_name, username, user_email, role, login_time, logout_time,
            session_duration, ip_address, country, device_type, operating_system,
            browser, login_status, failure_reason
        FROM login_activity $whereSql ORDER BY login_time DESC, id DESC LIMIT 50000");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('login_activity_print: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Login Activity Report</title>
<style>
    body { font-family: Arial, sans-serif; color: #1e293b; margin: 24px; }
    h1 { font-size: 18px; margin-bottom: 4px; }
    .meta { color: #64748b; font-size: 12px; margin-bottom: 16px; }
    table { width: 100%; border-collapse: collapse; font-size: 11px; }
    th, td { border: 1px solid #cbd5e1; padding: 5px 7px; text-align: left; }
    th { background: #1e3a8a; color: #fff; }
    tr:nth-child(even) { background: #f7f8fc; }
    .status-success { color: #16a34a; font-weight: 600; }
    .status-failed { color: #dc2626; font-weight: 600; }
    .print-bar { margin-bottom: 16px; }
    .print-bar button {
        background: #1e3a8a; color: #fff; border: none; padding: 8px 16px;
        border-radius: 6px; font-size: 13px; cursor: pointer;
    }
    @media print { .print-bar { display: none; } }
</style>
</head>
<body>
<div class="print-bar">
    <button onclick="window.print()">Print / Save as PDF</button>
</div>
<h1>Login Activity Report</h1>
<div class="meta">Generated <?= htmlspecialchars(date('d M Y, H:i'), ENT_QUOTES) ?> &middot; <?= count($rows) ?> record(s)</div>
<table>
    <thead>
        <tr>
            <th>Name</th><th>Role</th><th>Login Time</th><th>Logout Time</th>
            <th>Duration</th><th>IP Address</th><th>Country</th><th>Device</th>
            <th>OS</th><th>Browser</th><th>Status</th><th>Failure Reason</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['user_name'] ?? '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['role'] ?? '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['login_time'] ?? '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['logout_time'] ?? 'Not recorded', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars(formatSessionDuration($r['session_duration'] !== null ? (int)$r['session_duration'] : null), ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['ip_address'] ?? '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['country'] ?: 'Unknown', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['device_type'] ?? '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['operating_system'] ?? '', ENT_QUOTES) ?></td>
            <td><?= htmlspecialchars($r['browser'] ?? '', ENT_QUOTES) ?></td>
            <td class="<?= $r['login_status'] === 'SUCCESS' ? 'status-success' : 'status-failed' ?>"><?= $r['login_status'] === 'SUCCESS' ? 'Successful' : 'Failed' ?></td>
            <td><?= htmlspecialchars($r['failure_reason'] ?? '', ENT_QUOTES) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
        <tr><td colspan="12" style="text-align:center;color:#64748b;">No records match the selected filters.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</body>
</html>

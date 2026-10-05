<?php
/**
 * Login Activity — export (CSV or XLSX), respecting the exact same filters
 * as ajax/login_activity_list.php (search/role/status/device/country/
 * date_range/date_from/date_to). GET param `format` = csv|xlsx (default csv).
 *
 * Audit-log export: read-only, no edit/delete. Admin/Super Admin only —
 * same independent backend role check as every other login-activity
 * endpoint, never only a hidden menu/button.
 */
ob_start();
ini_set('display_errors', '0');
error_reporting(0);

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo "Export Fatal Error: " . $error['message'];
    }
});

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/login_activity_helpers.php';
checkAuth();

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['Admin', 'Super Admin'], true)) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Access Denied']);
    exit;
}

$db = getDB();
ensureLoginActivityTable($db);

// Same filter-building logic as login_activity_list.php, duplicated rather
// than shared, matching this codebase's existing per-endpoint convention.
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
$format = ($_GET['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv';

try {
    @set_time_limit(120);
    @ini_set('memory_limit', '256M');

    // Capped at 50,000 rows — a sane ceiling for a browser-triggered export;
    // the on-screen list is already paginated for anything larger.
    $sql = "SELECT user_name, username, user_email, role, login_time, logout_time,
                   session_duration, ip_address, country, device_type, operating_system,
                   browser, login_status, failure_reason
            FROM login_activity
            $whereSql
            ORDER BY login_time DESC, id DESC
            LIMIT 50000";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $logins = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $headers = ['Name', 'Username', 'Email', 'Role', 'Login Time', 'Logout Time',
        'Session Duration', 'IP Address', 'Country', 'Device', 'Operating System',
        'Browser', 'Status', 'Failure Reason'];
    $rows = [];
    foreach ($logins as $row) {
        $rows[] = [
            $row['user_name'] ?? '',
            $row['username'] ?? '',
            $row['user_email'] ?? '',
            $row['role'] ?? '',
            $row['login_time'] ?? '',
            $row['logout_time'] ?? 'Not recorded',
            formatSessionDuration($row['session_duration'] !== null ? (int)$row['session_duration'] : null),
            $row['ip_address'] ?? '',
            $row['country'] ?: 'Unknown',
            $row['device_type'] ?? '',
            $row['operating_system'] ?? '',
            $row['browser'] ?? '',
            $row['login_status'] === 'SUCCESS' ? 'Successful' : 'Failed',
            $row['failure_reason'] ?? '',
        ];
    }

    $baseName = 'Login_Activity_' . date('d_m_Y');

    if ($format === 'xlsx') {
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
            if (class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
                $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle('Login Activity');
                $sheet->fromArray([$headers], null, 'A1');
                $headerStyle = $sheet->getStyle('A1:N1');
                $headerStyle->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $headerStyle->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('1E3A8A');
                $sheet->fromArray($rows, null, 'A2');
                foreach (range('A', 'N') as $col) {
                    $sheet->getColumnDimension($col)->setAutoSize(true);
                }

                while (ob_get_level() > 0) { ob_end_clean(); }
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . $baseName . '.xlsx"');
                header('Content-Transfer-Encoding: binary');
                header('Cache-Control: private, max-age=0, must-revalidate');
                header('Pragma: public');
                header('Expires: 0');
                $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
                $writer->save('php://output');
                exit;
            }
        }
        // Falls through to CSV if PhpSpreadsheet isn't available for any reason.
    }

    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $baseName . '.csv"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('Expires: 0');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($rows as $cells) {
        fputcsv($out, $cells);
    }
    fclose($out);
    exit;
} catch (Throwable $e) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    error_log('login_activity_export: ' . $e->getMessage());
    echo 'Something went wrong while generating this export. Please try again, or contact support if it keeps happening.';
    exit;
}

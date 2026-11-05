<?php
/**
 * Sales role (Phase 1). One "Sales" role (roles table) for the sales person:
 *  - sales_dashboard.php  this month's surveys / new clients against the targets the Admin set,
 *                         completed surveys, open surveys, the requests they made, top clients
 *  - sales_clients.php    every client: add and edit (no delete, no client logins)
 *  - sales_surveys.php    every survey, view only: vessel, client, port, type, status, dates
 *  - assign_vessel.php    Request Survey for any client; no surveyor is chosen — the Admin
 *                         assigns one (as for a client's own request)
 * Recovery figures (totals, month-wise, client-wise) are on the dashboard; no invoice or payment
 * data anywhere, no vessel detail pages, no uploads.
 * Access is deny-by-default: config.php sends a Sales user away from every page or endpoint
 * that is not in salesAllowedPaths().
 * Admin: admin_sales.php — add / deactivate sales persons, monthly targets, achievements.
 */

const SALES_ROLE = 'Sales';

/** Pages and endpoints (relative to the YSMS folder) a Sales user may open. */
function salesAllowedPaths(): array {
    return [
        'sales_dashboard.php', 'sales_clients.php', 'sales_surveys.php', 'assign_vessel.php',
        'profile.php', 'logout.php', 'login.php', 'forgot_password.php', 'reset_password.php', 'coming_soon.php',
        'ajax/sales.php', 'ajax/preview_report_number.php', 'ajax/check_pending_vessel_duplicate.php',
    ];
}

/**
 * Called from config.php on every request of a signed-in Sales user: anything not allowed is
 * refused (pages go to the Sales dashboard, endpoints get 403).
 */
function salesGuard(string $ysmsRoot): void {
    $file = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) ?: '';
    $root = realpath($ysmsRoot) ?: $ysmsRoot;
    $rel = strpos($file, $root . DIRECTORY_SEPARATOR) === 0 ? str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($root) + 1)) : '';
    if ($rel !== '' && in_array($rel, salesAllowedPaths(), true)) return;
    $ajax = strpos($rel, 'ajax/') === 0 || strpos($rel, 'api/') === 0 || $rel === 'app_api.php'
        || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
        || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    if ($ajax || $rel === '') {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'success' => false, 'error' => 'Not available for the Sales role.', 'message' => 'Not available for the Sales role.']);
        exit;
    }
    header('Location: ' . (strpos($rel, '/') !== false ? '../' : '') . 'sales_dashboard.php');
    exit;
}

function salesIsSales(): bool { return ($_SESSION['role'] ?? '') === SALES_ROLE; }
function salesIsAdmin(): bool { return ($_SESSION['role'] ?? '') === 'Admin'; }

/** The Sales role id (the role row is added once), tables, and the clients' created_at / created_by. */
function salesEnsure(PDO $db): int {
    static $rid = null;
    if ($rid !== null) return $rid;
    $q = $db->prepare('SELECT id FROM roles WHERE name = ?');
    $q->execute([SALES_ROLE]);
    $rid = (int)$q->fetchColumn();
    if (!$rid) {
        $db->prepare('INSERT INTO roles (name) VALUES (?)')->execute([SALES_ROLE]);
        $rid = (int)$db->lastInsertId();
    }
    $db->exec("CREATE TABLE IF NOT EXISTS `sales_targets` (
        `user_id` int(11) NOT NULL, `ym` char(7) NOT NULL COMMENT 'YYYY-MM',
        `surveys` int(11) DEFAULT NULL COMMENT 'surveys in the month (by assign date, not cancelled)',
        `new_clients` int(11) DEFAULT NULL COMMENT 'clients added in the month',
        `updated_by` int(11) DEFAULT NULL, `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`user_id`, `ym`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS `sales_requests` (
        `survey_id` int(11) NOT NULL, `user_id` int(11) NOT NULL, `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`survey_id`), KEY `idx_sales_requests_user` (`user_id`, `created_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $have = array_flip(array_map('strtolower', $db->query('SHOW COLUMNS FROM clients')->fetchAll(PDO::FETCH_COLUMN)));
    if (!isset($have['created_at'])) {
        // existing clients keep NULL (their date is unknown); clients added from now on get the time
        $db->exec('ALTER TABLE clients ADD COLUMN `created_at` DATETIME NULL');
        $db->exec('ALTER TABLE clients MODIFY `created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP');
    }
    if (!isset($have['created_by'])) $db->exec('ALTER TABLE clients ADD COLUMN `created_by` INT NULL');
    return $rid;
}

function salesMonth(?string $ym): string {
    return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$ym) ? (string)$ym : date('Y-m');
}
function salesMonthRange(string $ym): array { return [$ym . '-01 00:00:00', date('Y-m-d 00:00:00', strtotime($ym . '-01 +1 month'))]; }

/** Achieved figures of a month (all clients — one sales person covers every client). */
function salesMetrics(PDO $db, string $ym, int $uid): array {
    [$from, $to] = salesMonthRange($ym);
    $one = function (string $sql, array $p) use ($db) { $q = $db->prepare($sql); $q->execute($p); return (int)$q->fetchColumn(); };
    return [
        'surveys' => $one("SELECT COUNT(*) FROM surveys WHERE assign_date >= ? AND assign_date < ? AND status <> 'Cancelled'", [$from, $to]),
        'completed' => $one("SELECT COUNT(*) FROM surveys WHERE survey_completed_date >= ? AND survey_completed_date < ? AND status <> 'Cancelled'", [$from, $to]),
        'new_clients' => $one('SELECT COUNT(*) FROM clients WHERE created_at >= ? AND created_at < ?', [$from, $to]),
        'my_requests' => $one('SELECT COUNT(*) FROM sales_requests WHERE user_id = ? AND created_at >= ? AND created_at < ?', [$uid, $from, $to]),
        'pending_vessel' => $one("SELECT COUNT(*) FROM surveys WHERE status = 'Pending Vessel'", []),
        'pending_report' => $one("SELECT COUNT(*) FROM surveys WHERE status = 'Pending Report'", []),
    ];
}

/**
 * Completed vessels and recovery, by the same rules as the Admin dashboard (index.php):
 * a vessel counts once it has left Pending Vessels (Pending Report / Completed), in the month of
 * its own Survey Completed Date; recovery = recoveryCountedSql (NOT APPLICABLE types count 0).
 * → totals, the 12 months ending with $ym, per client and per country (month and all time).
 */
function salesCompletionStats(PDO $db, string $ym): array {
    $done = "s.status IN ('Pending Report','Completed')";
    $cd = surveyCompletedDateSql('s');
    $rec = recoveryCountedSql('s');
    [$from, $to] = salesMonthRange($ym);
    $f = substr($from, 0, 10); $t = substr($to, 0, 10);
    $inMonth = "$cd >= '$f' AND $cd < '$t'";
    $tot = $db->query("SELECT COUNT(*) n, COALESCE(SUM($rec), 0) r, COUNT(CASE WHEN $inMonth THEN 1 END) mn, COALESCE(SUM(CASE WHEN $inMonth THEN $rec END), 0) mr FROM surveys s WHERE $done")->fetch(PDO::FETCH_ASSOC);
    $first = date('Y-m-01', strtotime($ym . '-01 -11 month'));
    $rows = $db->query("SELECT DATE_FORMAT($cd, '%Y-%m') ym, COUNT(*) n, COALESCE(SUM($rec), 0) r FROM surveys s WHERE $done AND $cd >= '$first' AND $cd < '$t' GROUP BY ym")->fetchAll(PDO::FETCH_ASSOC);
    $by = []; foreach ($rows as $r) $by[$r['ym']] = $r;
    $months = [];
    for ($i = 11; $i >= 0; $i--) {
        $m = date('Y-m', strtotime($ym . '-01 -' . $i . ' month'));
        $months[] = ['ym' => $m, 'vessels' => (int)($by[$m]['n'] ?? 0), 'recovery' => round((float)($by[$m]['r'] ?? 0), 3)];
    }
    $clients = $db->query("SELECT COALESCE(c.company_name, '(no client)') name, COUNT(*) n, COALESCE(SUM($rec), 0) r,
            COUNT(CASE WHEN $inMonth THEN 1 END) mn, COALESCE(SUM(CASE WHEN $inMonth THEN $rec END), 0) mr
        FROM surveys s LEFT JOIN clients c ON c.id = s.client_id WHERE $done GROUP BY s.client_id, c.company_name ORDER BY mn DESC, n DESC, name")->fetchAll(PDO::FETCH_ASSOC);
    $countries = $db->query("SELECT COALESCE(NULLIF(p.country, ''), 'India') name, COUNT(*) n, COUNT(CASE WHEN $inMonth THEN 1 END) mn
        FROM surveys s LEFT JOIN ports p ON p.id = s.port_id WHERE $done GROUP BY name ORDER BY mn DESC, n DESC, name")->fetchAll(PDO::FETCH_ASSOC);
    $num = fn($rows, $keys) => array_map(function ($r) use ($keys) { foreach ($keys as $k) $r[$k] = in_array($k, ['r', 'mr'], true) ? round((float)$r[$k], 3) : (int)$r[$k]; return $r; }, $rows);
    return [
        'total_clients' => (int)$db->query('SELECT COUNT(*) FROM clients')->fetchColumn(),
        'vessels' => (int)$tot['n'], 'recovery' => round((float)$tot['r'], 3),
        'month_vessels' => (int)$tot['mn'], 'month_recovery' => round((float)$tot['mr'], 3),
        'months' => $months,
        'clients' => $num($clients, ['n', 'mn', 'r', 'mr']),
        'countries' => $num($countries, ['n', 'mn']),
    ];
}

function salesTarget(PDO $db, int $uid, string $ym): array {
    $q = $db->prepare('SELECT surveys, new_clients FROM sales_targets WHERE user_id = ? AND ym = ?');
    $q->execute([$uid, $ym]);
    $r = $q->fetch(PDO::FETCH_ASSOC) ?: [];
    return ['surveys' => isset($r['surveys']) ? (int)$r['surveys'] : null, 'new_clients' => isset($r['new_clients']) ? (int)$r['new_clients'] : null];
}

/** Active and inactive sales persons. */
function salesUsers(PDO $db): array {
    $rid = salesEnsure($db);
    $q = $db->prepare('SELECT id, username, full_name, email, phone, status, created_at FROM users WHERE role_id = ? ORDER BY status, full_name');
    $q->execute([$rid]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** Clean client details from a form → [values, error]. */
function salesClientInput(array $in): array {
    $v = [];
    foreach (['company_name' => 255, 'contact_person' => 150, 'mobile' => 20, 'email' => 150, 'address_line1' => 255, 'address_line2' => 255, 'short_code' => 10] as $k => $max) {
        $v[$k] = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($in[$k] ?? ''))), 0, $max);
    }
    $v['short_code'] = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $v['short_code']));
    if ($v['company_name'] === '') return [null, 'Company name is required.'];
    if ($v['email'] !== '' && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) return [null, 'Email address is not valid.'];
    if ($v['mobile'] !== '' && !preg_match('/^[0-9 +()\-]{6,20}$/', $v['mobile'])) return [null, 'Phone number is not valid.'];
    return [$v, ''];
}

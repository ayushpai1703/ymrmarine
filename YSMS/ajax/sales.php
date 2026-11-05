<?php
/**
 * Sales role actions (includes/sales.php). CSRF for POST is checked in config.php (checkAuth).
 *   POST a=client_save   id (empty = new), company_name, contact_person, mobile, email,
 *                        address_line1, address_line2, short_code          — Sales, Admin
 *   POST a=target_save   user_id, ym, surveys, new_clients                  — Admin
 *   POST a=user_add      full_name, username, email, phone, password       — Admin
 *   POST a=user_status   id, status (Active|Inactive)                       — Admin
 *   POST a=user_password id, password                                       — Admin
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/sales.php';
checkAuth();

function salesJson(int $code, array $d): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($d);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') salesJson(405, ['ok' => false, 'error' => 'POST required.']);
$db = getDB();
$rid = salesEnsure($db);
$a = (string)($_POST['a'] ?? '');
$uid = (int)($_SESSION['user_id'] ?? 0);

try {
    if ($a === 'client_save') {
        if (!salesIsSales() && !salesIsAdmin()) salesJson(403, ['ok' => false, 'error' => 'Not allowed.']);
        [$v, $err] = salesClientInput($_POST);
        if ($err) salesJson(422, ['ok' => false, 'error' => $err]);
        $id = (int)($_POST['id'] ?? 0);
        $dup = $db->prepare('SELECT id FROM clients WHERE LOWER(TRIM(company_name)) = LOWER(?) AND id <> ? LIMIT 1');
        $dup->execute([$v['company_name'], $id]);
        if ($dup->fetchColumn()) salesJson(409, ['ok' => false, 'error' => 'A client with this company name already exists.']);
        if ($v['short_code'] !== '') {
            $sc = $db->prepare('SELECT company_name FROM clients WHERE short_code = ? AND id <> ? LIMIT 1');
            $sc->execute([$v['short_code'], $id]);
            if ($other = $sc->fetchColumn()) salesJson(409, ['ok' => false, 'error' => 'Short code ' . $v['short_code'] . ' is already used by ' . $other . '.']);
        }
        $cols = ['company_name', 'contact_person', 'mobile', 'email', 'address_line1', 'address_line2', 'short_code'];
        $vals = array_map(fn($c) => $v[$c] !== '' ? $v[$c] : null, $cols);
        $vals[0] = $v['company_name'];
        if ($id > 0) {
            $ex = $db->prepare('SELECT id FROM clients WHERE id = ?');
            $ex->execute([$id]);
            if (!$ex->fetchColumn()) salesJson(404, ['ok' => false, 'error' => 'Client not found.']);
            $db->prepare('UPDATE clients SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ' WHERE id = ?')->execute([...$vals, $id]);
            salesJson(200, ['ok' => true, 'id' => $id, 'created' => false]);
        }
        $db->prepare('INSERT INTO clients (' . implode(', ', $cols) . ', created_by) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ', ?)')->execute([...$vals, $uid ?: null]);
        salesJson(200, ['ok' => true, 'id' => (int)$db->lastInsertId(), 'created' => true]);
    }

    if (!salesIsAdmin()) salesJson(403, ['ok' => false, 'error' => 'Only Admin can do this.']);

    $salesUser = function (int $id) use ($db, $rid): ?array {
        $q = $db->prepare('SELECT id, full_name, status FROM users WHERE id = ? AND role_id = ?');
        $q->execute([$id, $rid]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    };

    if ($a === 'target_save') {
        $u = $salesUser((int)($_POST['user_id'] ?? 0));
        if (!$u) salesJson(404, ['ok' => false, 'error' => 'Sales person not found.']);
        $ym = (string)($_POST['ym'] ?? '');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) salesJson(422, ['ok' => false, 'error' => 'Choose a month.']);
        $num = function (string $k): ?int {
            $v = trim((string)($_POST[$k] ?? ''));
            if ($v === '') return null;
            if (!preg_match('/^\d{1,6}$/', $v)) throw new InvalidArgumentException('Targets are whole numbers (0 or more).');
            return (int)$v;
        };
        try { $s = $num('surveys'); $c = $num('new_clients'); } catch (InvalidArgumentException $e) { salesJson(422, ['ok' => false, 'error' => $e->getMessage()]); }
        $db->prepare('INSERT INTO sales_targets (user_id, ym, surveys, new_clients, updated_by) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE surveys = VALUES(surveys), new_clients = VALUES(new_clients), updated_by = VALUES(updated_by)')
           ->execute([$u['id'], $ym, $s, $c, $uid ?: null]);
        salesJson(200, ['ok' => true]);
    }
    if ($a === 'user_add') {
        $name = mb_substr(trim((string)($_POST['full_name'] ?? '')), 0, 150);
        $user = mb_substr(trim((string)($_POST['username'] ?? '')), 0, 100);
        $email = mb_substr(trim((string)($_POST['email'] ?? '')), 0, 150);
        $phone = mb_substr(trim((string)($_POST['phone'] ?? '')), 0, 30);
        $pw = (string)($_POST['password'] ?? '');
        if ($name === '' || $user === '') salesJson(422, ['ok' => false, 'error' => 'Name and username are required.']);
        if (!preg_match('/^[A-Za-z0-9._@\-]{3,100}$/', $user)) salesJson(422, ['ok' => false, 'error' => 'Username: letters, numbers and . _ @ - only (3 or more).']);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) salesJson(422, ['ok' => false, 'error' => 'Email address is not valid.']);
        if (strlen($pw) < 8) salesJson(422, ['ok' => false, 'error' => 'Password must have at least 8 characters.']);
        $ex = $db->prepare('SELECT id FROM users WHERE username = ?');
        $ex->execute([$user]);
        if ($ex->fetchColumn()) salesJson(409, ['ok' => false, 'error' => 'This username is already taken.']);
        $db->prepare("INSERT INTO users (role_id, username, password, full_name, phone, email, status) VALUES (?, ?, ?, ?, ?, ?, 'Active')")
           ->execute([$rid, $user, password_hash($pw, PASSWORD_BCRYPT), $name, $phone !== '' ? $phone : null, $email !== '' ? $email : null]);
        salesJson(200, ['ok' => true, 'id' => (int)$db->lastInsertId()]);
    }
    if ($a === 'user_status') {
        $u = $salesUser((int)($_POST['id'] ?? 0));
        if (!$u) salesJson(404, ['ok' => false, 'error' => 'Sales person not found.']);
        $st = ($_POST['status'] ?? '') === 'Inactive' ? 'Inactive' : 'Active';
        $db->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$st, $u['id']]);
        salesJson(200, ['ok' => true, 'status' => $st]);
    }
    if ($a === 'user_password') {
        $u = $salesUser((int)($_POST['id'] ?? 0));
        if (!$u) salesJson(404, ['ok' => false, 'error' => 'Sales person not found.']);
        $pw = (string)($_POST['password'] ?? '');
        if (strlen($pw) < 8) salesJson(422, ['ok' => false, 'error' => 'Password must have at least 8 characters.']);
        $db->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_BCRYPT), $u['id']]);
        salesJson(200, ['ok' => true]);
    }
} catch (Throwable $e) {
    error_log('ajax/sales ' . $a . ': ' . $e->getMessage());
    salesJson(500, ['ok' => false, 'error' => 'Something went wrong. Please try again.']);
}
salesJson(400, ['ok' => false, 'error' => 'Unknown action.']);

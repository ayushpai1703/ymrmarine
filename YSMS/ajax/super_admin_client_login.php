<?php
/**
 * Manage the LOGIN (users row: username/password/full_name/email/phone)
 * linked to an existing clients row — separate from ajax/admin_master.php,
 * which only ever touches clients.company_name/short_code/address_line*.
 *
 * Super Admin only. Every action resolves the target `users` row strictly
 * through the given client_id's own clients.user_id — never through a
 * raw user id from the request — so this can only ever create/edit/delete
 * the ONE login belonging to that specific client, never any other
 * account (Admin/Surveyor/another Client) in the system.
 */
require_once __DIR__ . '/../config/config.php';
checkAuth();
header('Content-Type: application/json');

if (($_SESSION['role'] ?? '') !== 'Super Admin') {
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit;
}

$db = getDB();
$action = isset($_REQUEST['action']) ? trim((string)$_REQUEST['action']) : '';

// Same safety-net pattern as add_client.php — phone/email may not exist yet
// on older deployments.
try {
    $cols = [];
    foreach ($db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $cols[strtolower($c['Field'])] = true;
    }
    if (empty($cols['phone'])) $db->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(30) DEFAULT NULL");
    if (empty($cols['email'])) $db->exec("ALTER TABLE users ADD COLUMN email VARCHAR(150) DEFAULT NULL");
} catch (Throwable $e) {
    error_log('super_admin_client_login column ensure: ' . $e->getMessage());
}

try {
    if ($action === 'list_logins') {
        // One row per client, with its login info (or nulls if none yet) —
        // used to enrich the Manage Clients list without touching the
        // generic clients-list endpoint in ajax/admin_master.php.
        $stmt = $db->query("
            SELECT c.id AS client_id, u.id AS user_id, u.username, u.full_name, u.email, u.phone
            FROM clients c
            LEFT JOIN users u ON u.id = c.user_id
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $byClient = [];
        foreach ($rows as $r) {
            $byClient[(int)$r['client_id']] = [
                'user_id'   => $r['user_id'] !== null ? (int)$r['user_id'] : null,
                'username'  => $r['username'],
                'full_name' => $r['full_name'],
                'email'     => $r['email'],
                'phone'     => $r['phone'],
            ];
        }
        echo json_encode(['success' => true, 'logins' => $byClient]);
        exit;
    }

    if ($action === 'save') {
        $clientId = (int)($_POST['client_id'] ?? 0);
        $username = trim((string)($_POST['username'] ?? ''));
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $email    = trim((string)($_POST['email'] ?? ''));
        $phone    = trim((string)($_POST['phone'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($clientId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid client.']);
            exit;
        }
        if ($username === '' || $fullName === '') {
            echo json_encode(['success' => false, 'message' => 'Username and Contact Person are required.']);
            exit;
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
            exit;
        }

        $clientStmt = $db->prepare('SELECT id, user_id, company_name FROM clients WHERE id = ?');
        $clientStmt->execute([$clientId]);
        $clientRow = $clientStmt->fetch(PDO::FETCH_ASSOC);
        if (!$clientRow) {
            echo json_encode(['success' => false, 'message' => 'Client not found.']);
            exit;
        }
        $existingUserId = (int)($clientRow['user_id'] ?? 0);

        $dupStmt = $db->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
        $dupStmt->execute([$username, $existingUserId]);
        if ($dupStmt->fetchColumn()) {
            echo json_encode(['success' => false, 'message' => 'Username already exists. Please choose another.']);
            exit;
        }

        if ($existingUserId > 0) {
            // Editing an existing login — password only changes if a new one was typed.
            $sets = 'username = ?, full_name = ?, email = ?, phone = ?';
            $params = [$username, $fullName, $email, $phone];
            if ($password !== '') {
                $sets .= ', password = ?';
                $params[] = password_hash($password, PASSWORD_BCRYPT);
            }
            $params[] = $existingUserId;
            $upd = $db->prepare("UPDATE users SET $sets WHERE id = ?");
            $upd->execute($params);
            echo json_encode(['success' => true, 'message' => 'Login details updated.']);
            exit;
        }

        // No login yet for this client — create one and link it.
        if ($password === '') {
            echo json_encode(['success' => false, 'message' => 'Password is required to create a new login.']);
            exit;
        }
        $roleId = (int)$db->query("SELECT id FROM roles WHERE name = 'Client' LIMIT 1")->fetchColumn();
        if ($roleId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Client role is not configured. Please contact the developer.']);
            exit;
        }
        $db->beginTransaction();
        try {
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $insertUser = $db->prepare("
                INSERT INTO users (role_id, username, password, full_name, phone, email, status)
                VALUES (?, ?, ?, ?, ?, ?, 'Active')
            ");
            $insertUser->execute([$roleId, $username, $hashed, $fullName, $phone, $email]);
            $newUserId = (int)$db->lastInsertId();

            $linkStmt = $db->prepare('UPDATE clients SET user_id = ?, contact_person = ? WHERE id = ?');
            $linkStmt->execute([$newUserId, $fullName, $clientId]);

            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Login created for ' . $clientRow['company_name'] . '.']);
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        exit;
    }

    if ($action === 'delete') {
        $clientId = (int)($_POST['client_id'] ?? 0);
        if ($clientId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid client.']);
            exit;
        }
        $clientStmt = $db->prepare('SELECT user_id FROM clients WHERE id = ?');
        $clientStmt->execute([$clientId]);
        $userId = (int)($clientStmt->fetchColumn() ?: 0);
        if ($userId <= 0) {
            echo json_encode(['success' => false, 'message' => 'This client has no login to delete.']);
            exit;
        }
        // clients.user_id has ON DELETE SET NULL, so the clients row (and
        // its company data / vessels / surveys) is untouched — only the
        // login itself is removed.
        $del = $db->prepare('DELETE FROM users WHERE id = ?');
        $del->execute([$userId]);
        echo json_encode(['success' => true, 'message' => 'Login deleted. This client\'s company record and vessels are unaffected.']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
} catch (Throwable $e) {
    error_log('super_admin_client_login.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not save. Please try again.']);
}

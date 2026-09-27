<?php
/**
 * Manage LOGINS (users rows: username/password/full_name/email/phone) for
 * the Client role — separate from ajax/admin_master.php, which only ever
 * touches clients.company_name/short_code/address_line*.
 *
 * Super Admin only.
 *
 * list_logins enumerates every Client-role `users` row (starting FROM
 * users, LEFT JOIN clients) rather than starting from `clients` — a login
 * can authenticate successfully (username+password only ever check
 * `users`) even when no `clients` row points back at it via
 * clients.user_id, and an earlier version of this endpoint that started
 * FROM clients could never show such an "orphaned" login at all, even
 * though that account could log in right now and would land on a blank
 * dashboard (nothing resolves via getClientIdForUser()). Every write
 * action below still resolves its target user strictly through either a
 * given client_id's own clients.user_id, or (for an orphan with no
 * client_id) the given user_id after confirming that id's role is
 * Client — never an arbitrary id that could reach another account.
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

/** Returns the id of a Client-role user, or 0 if that id isn't one. */
function clientRoleUserId(PDO $db, int $userId): int {
    if ($userId <= 0) return 0;
    $stmt = $db->prepare("
        SELECT u.id FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE u.id = ? AND r.name = 'Client'
    ");
    $stmt->execute([$userId]);
    return (int)($stmt->fetchColumn() ?: 0);
}

try {
    if ($action === 'list_logins') {
        $stmt = $db->query("
            SELECT u.id AS user_id, u.username, u.full_name, u.email, u.phone,
                   c.id AS client_id, c.company_name
            FROM users u
            JOIN roles r ON r.id = u.role_id
            LEFT JOIN clients c ON c.user_id = u.id
            WHERE r.name = 'Client'
            ORDER BY c.company_name IS NULL, c.company_name, u.username
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $all = [];
        $byClient = [];
        foreach ($rows as $r) {
            $entry = [
                'user_id'      => (int)$r['user_id'],
                'username'     => $r['username'],
                'full_name'    => $r['full_name'],
                'email'        => $r['email'],
                'phone'        => $r['phone'],
                'client_id'    => $r['client_id'] !== null ? (int)$r['client_id'] : null,
                'company_name' => $r['company_name'],
            ];
            $all[] = $entry;
            if ($entry['client_id'] !== null) {
                $byClient[$entry['client_id']] = $entry;
            }
        }
        // Companies that exist but have no login at all — not reachable from
        // the users-driven query above — still needed by the Companies view
        // (it iterates its own client list and looks up by_client[id]), so
        // that map is left exactly as those clients rows expect: simply
        // absent, same as before.
        echo json_encode(['success' => true, 'all' => $all, 'by_client' => $byClient]);
        exit;
    }

    if ($action === 'unlinked_clients') {
        // Companies with no login yet — the pick-list for "Link to Company".
        $stmt = $db->query("SELECT id, company_name FROM clients WHERE user_id IS NULL ORDER BY company_name");
        echo json_encode(['success' => true, 'clients' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'save') {
        $clientId = (int)($_POST['client_id'] ?? 0);
        $userId   = (int)($_POST['user_id'] ?? 0);
        $username = trim((string)($_POST['username'] ?? ''));
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $email    = trim((string)($_POST['email'] ?? ''));
        $phone    = trim((string)($_POST['phone'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($username === '' || $fullName === '') {
            echo json_encode(['success' => false, 'message' => 'Username and Contact Person are required.']);
            exit;
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
            exit;
        }

        // Editing an orphan login directly by user_id (no clients row
        // involved at all) — used from the Client Logins view for a login
        // that isn't linked to any company yet.
        if ($clientId <= 0 && $userId > 0) {
            $targetUserId = clientRoleUserId($db, $userId);
            if ($targetUserId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Login not found.']);
                exit;
            }
            $dupStmt = $db->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
            $dupStmt->execute([$username, $targetUserId]);
            if ($dupStmt->fetchColumn()) {
                echo json_encode(['success' => false, 'message' => 'Username already exists. Please choose another.']);
                exit;
            }
            $sets = 'username = ?, full_name = ?, email = ?, phone = ?';
            $params = [$username, $fullName, $email, $phone];
            if ($password !== '') { $sets .= ', password = ?'; $params[] = password_hash($password, PASSWORD_BCRYPT); }
            $params[] = $targetUserId;
            $db->prepare("UPDATE users SET $sets WHERE id = ?")->execute($params);
            echo json_encode(['success' => true, 'message' => 'Login details updated.']);
            exit;
        }

        if ($clientId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid client.']);
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

    if ($action === 'link') {
        // Attach an existing orphan login (found with no clients row
        // pointing at it) onto a chosen company that currently has none.
        $userId = (int)($_POST['user_id'] ?? 0);
        $clientId = (int)($_POST['client_id'] ?? 0);
        $targetUserId = clientRoleUserId($db, $userId);
        if ($targetUserId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Login not found.']);
            exit;
        }
        if ($clientId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Please choose a company.']);
            exit;
        }
        $clientStmt = $db->prepare('SELECT id, user_id, company_name FROM clients WHERE id = ?');
        $clientStmt->execute([$clientId]);
        $clientRow = $clientStmt->fetch(PDO::FETCH_ASSOC);
        if (!$clientRow) {
            echo json_encode(['success' => false, 'message' => 'Client not found.']);
            exit;
        }
        if (!empty($clientRow['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'That company already has a different login. Unlink or delete it first.']);
            exit;
        }
        $fullNameStmt = $db->prepare('SELECT full_name FROM users WHERE id = ?');
        $fullNameStmt->execute([$targetUserId]);
        $fullName = (string)($fullNameStmt->fetchColumn() ?: '');

        $db->prepare('UPDATE clients SET user_id = ?, contact_person = ? WHERE id = ?')
           ->execute([$targetUserId, $fullName, $clientId]);
        echo json_encode(['success' => true, 'message' => 'Login linked to ' . $clientRow['company_name'] . '.']);
        exit;
    }

    if ($action === 'delete') {
        $clientId = (int)($_POST['client_id'] ?? 0);
        $userId   = (int)($_POST['user_id'] ?? 0);

        if ($clientId > 0) {
            $clientStmt = $db->prepare('SELECT user_id FROM clients WHERE id = ?');
            $clientStmt->execute([$clientId]);
            $targetUserId = (int)($clientStmt->fetchColumn() ?: 0);
            if ($targetUserId <= 0) {
                echo json_encode(['success' => false, 'message' => 'This client has no login to delete.']);
                exit;
            }
        } elseif ($userId > 0) {
            // Deleting an orphan login directly — not attached to any
            // clients row, so nothing else needs to change.
            $targetUserId = clientRoleUserId($db, $userId);
            if ($targetUserId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Login not found.']);
                exit;
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid request.']);
            exit;
        }

        // clients.user_id has ON DELETE SET NULL, so a still-linked client's
        // company record (and its vessels) is untouched — only the login
        // itself is removed.
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$targetUserId]);
        echo json_encode(['success' => true, 'message' => 'Login deleted. Any linked company record and vessels are unaffected.']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
} catch (Throwable $e) {
    error_log('super_admin_client_login.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not save. Please try again.']);
}

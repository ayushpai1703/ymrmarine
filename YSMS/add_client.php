<?php
require_once 'config/config.php';
require_once 'includes/notifications.php';
checkAuth();
// Add Client is available to Admin and Super Admin only — Surveyor and Client
// are blocked here (and can't reach this page through the UI either).
if (!in_array($_SESSION['role'] ?? '', ['Admin', 'Super Admin'], true)) {
    header('Location: index.php');
    exit;
}

$db = getDB();
$error = '';
$success = false;

// Ensure optional columns exist on users (same safety-net pattern as add_surveyor.php)
try {
    $cols = [];
    foreach ($db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $cols[strtolower($c['Field'])] = true;
    }
    if (empty($cols['phone'])) $db->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(30) DEFAULT NULL");
    if (empty($cols['email'])) $db->exec("ALTER TABLE users ADD COLUMN email VARCHAR(150) DEFAULT NULL");
} catch (Exception $e) {
    error_log('add_client column ensure: ' . $e->getMessage());
}

$company_name = $contact_person = $username = $mobile = $email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company_name   = trim($_POST['company_name'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $username       = trim($_POST['username'] ?? '');
    $mobile         = trim($_POST['mobile'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $password       = $_POST['password'] ?? '';
    $confirm        = $_POST['confirm_password'] ?? '';

    if ($company_name === '' || $contact_person === '' || $username === '' || $mobile === '' || $email === '' || $password === '' || $confirm === '') {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $check = $db->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $check->execute([$username]);
        if ($check->fetchColumn()) {
            $error = 'Username already exists. Please choose another.';
        } else {
            $roleId = (int)$db->query("SELECT id FROM roles WHERE name = 'Client' LIMIT 1")->fetchColumn();
            if ($roleId <= 0) {
                $error = 'Client role is not configured. Please contact the developer.';
            } else {
                // Reuse the clients row for this company if one already
                // exists (e.g. created earlier from Admin Controls with
                // vessels/surveys already attached), instead of inserting a
                // second row for the same company — otherwise the new
                // login gets linked to an empty duplicate while the real
                // vessel data stays attached to the original row, and the
                // client sees nothing after logging in.
                $findClient = $db->prepare('SELECT id, user_id FROM clients WHERE LOWER(TRIM(company_name)) = LOWER(TRIM(?)) LIMIT 1');
                $findClient->execute([$company_name]);
                $existingClient = $findClient->fetch(PDO::FETCH_ASSOC);

                if ($existingClient && !empty($existingClient['user_id'])) {
                    $error = 'A login already exists for "' . $company_name . '". Please use their existing username, or contact the developer to reset the password.';
                    $existingClient = null; // fall through without touching the DB
                }
            }
            if ($error === '' && $roleId > 0) {
                try {
                    $db->beginTransaction();

                    $hashed = password_hash($password, PASSWORD_BCRYPT);
                    $insertUser = $db->prepare("
                        INSERT INTO users (role_id, username, password, full_name, phone, email, status)
                        VALUES (?, ?, ?, ?, ?, ?, 'Active')
                    ");
                    $insertUser->execute([$roleId, $username, $hashed, $contact_person, $mobile, $email]);
                    $newUserId = (int)$db->lastInsertId();

                    if (!empty($existingClient)) {
                        $linkClient = $db->prepare('UPDATE clients SET user_id = ?, contact_person = ? WHERE id = ?');
                        $linkClient->execute([$newUserId, $contact_person, $existingClient['id']]);
                    } else {
                        $insertClient = $db->prepare('INSERT INTO clients (user_id, company_name, contact_person) VALUES (?, ?, ?)');
                        $insertClient->execute([$newUserId, $company_name, $contact_person]);
                    }

                    $db->commit();
                    $success = true;

                    try {
                        createNotification($db, $newUserId, 'Welcome to YSMS',
                            'Your client account has been created. You can log in to track your vessels here.',
                            'client', 'index.php', (int)($_SESSION['user_id'] ?? 0));
                        notifyAllAdmins($db, 'New client added',
                            ($_SESSION['full_name'] ?? 'Admin') . ' added a new client: ' . $company_name . '.',
                            'client', 'add_client.php', (int)($_SESSION['user_id'] ?? 0));
                    } catch (Throwable $ne) { error_log('add client notif: ' . $ne->getMessage()); }

                    $company_name = $contact_person = $username = $mobile = $email = '';
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    error_log('add_client insert error: ' . $e->getMessage());
                    $error = 'Could not create the client account. Please try again.';
                }
            }
        }
    }
}
include 'includes/header.php';
?>
<style>
    /* ═══ Ultra-modern redesign — same hero + icon-chip visual language as
       vessel_detail.php / report_detail.php / completed_detail.php. This is
       a single standalone form (no list/detail split), so one responsive
       card works at every width; only the hero banner is desktop-only
       (replacing the plain top bar, same as the other redesigned pages). */
    .vdd-wrap { padding: 8px 20px 40px; max-width: 640px; margin: 0 auto; }

    .vdd-hero { display: none; }
    @media (min-width: 992px) {
        .top-app-bar { display: none !important; }
        .vdd-hero {
            position: relative; overflow: hidden; border-radius: 22px;
            background: linear-gradient(120deg, #0f172a 0%, #1E1B6B 48%, #4B2FD6 100%);
            padding: 26px 30px; margin-bottom: 18px;
            display: flex; align-items: center; gap: 16px;
            box-shadow: 0 14px 34px rgba(30,27,107,.22);
        }
        .vdd-hero-icon-bg {
            position: absolute; right: -26px; top: -40px; font-size: 170px; color: rgba(255,255,255,.07);
            pointer-events: none; line-height: 1;
        }
        .vdd-hero-ship {
            width: 52px; height: 52px; border-radius: 15px; flex: none; position: relative;
            background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.18);
            display: flex; align-items: center; justify-content: center; color: #fff; font-size: 22px;
        }
        .vdd-hero-title { font-size: 22px; font-weight: 800; color: #fff; letter-spacing: -.01em; position: relative; }
        .vdd-hero-sub { font-size: 12.5px; color: rgba(255,255,255,.66); margin-top: 4px; font-weight: 500; position: relative; }
    }

    .vdd-form-card {
        background: #fff; border: 1px solid #e2e8f0; border-radius: 20px;
        box-shadow: 0 4px 20px rgba(15,23,42,.05); overflow: hidden;
        max-width: 640px; margin: 18px auto 24px;
    }
    .vdd-form-head {
        padding: 18px 22px; border-bottom: 1px solid #f1f5f9;
        display: flex; align-items: center; gap: 10px;
    }
    .vdd-form-head-icon {
        width: 32px; height: 32px; border-radius: 10px; flex: none;
        background: linear-gradient(135deg, #8B7CF6, #4B2FD6); color: #fff;
        display: flex; align-items: center; justify-content: center; font-size: 14px;
    }
    .vdd-form-head-title { font-size: 15px; font-weight: 800; color: #0f172a; }
    .vdd-form-head-sub { font-size: 11.5px; color: #94a3b8; margin-top: 1px; font-weight: 500; }
    .vdd-form-body { padding: 22px; }

    .vdd-alert { border-radius: 12px; padding: 11px 14px; font-size: 12.5px; font-weight: 600; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
    .vdd-alert-success { background: #D1FAE5; color: #065f46; }
    .vdd-alert-danger { background: #FEE2E2; color: #991b1b; }

    .vdd-field-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .vdd-field { margin-bottom: 14px; }
    .vdd-field.span-2 { grid-column: 1 / -1; }
    .vdd-field label { display: flex; align-items: center; gap: 6px; font-size: 11.5px; font-weight: 700; color: #64748b; margin-bottom: 6px; }
    .vdd-field label i { color: #A78BFA; font-size: 11px; width: 14px; text-align: center; }
    .vdd-field input {
        width: 100%; box-sizing: border-box; border: 1.5px solid #e2e8f0; border-radius: 12px;
        padding: 11px 14px; font-size: 13.5px; color: #0f172a; font-family: inherit; background: #F8FAFC;
    }
    .vdd-field input:focus { outline: none; border-color: #A78BFA; box-shadow: 0 0 0 4px rgba(91,45,238,.12); background: #fff; }
    .vdd-field input::placeholder { color: #cbd5e1; }

    @media (max-width: 680px) {
        .vdd-field-grid { grid-template-columns: 1fr; }
        .vdd-form-card { margin: 16px 14px 20px; }
    }

    .vdd-submit-btn {
        width: 100%; border: none; border-radius: 14px; height: 48px; margin-top: 6px;
        background: linear-gradient(120deg, #1E1B6B, #4B2FD6); color: #fff; font-weight: 800; font-size: 13.5px;
        display: flex; align-items: center; justify-content: center; gap: 8px; cursor: pointer;
        box-shadow: 0 10px 22px rgba(75,47,214,.3);
    }
</style>
<div class="scroll-content">
    <?php $page_title = 'Add Client'; $back_url = 'index.php'; $page_testid = 'add-client'; include 'includes/top_app_bar.php'; ?>

    <div class="vdd-wrap">
        <div class="vdd-hero">
            <i class="fa-solid fa-building-user vdd-hero-icon-bg" aria-hidden="true"></i>
            <div class="vdd-hero-ship"><i class="fa-solid fa-building-user"></i></div>
            <div>
                <div class="vdd-hero-title">Add Client</div>
                <div class="vdd-hero-sub">Create a client login to view their own vessels and reports</div>
            </div>
        </div>

        <div class="vdd-form-card">
            <div class="vdd-form-head">
                <div class="vdd-form-head-icon"><i class="fa-solid fa-user-plus"></i></div>
                <div>
                    <div class="vdd-form-head-title">Client Details</div>
                    <div class="vdd-form-head-sub">The client signs in with this username and password</div>
                </div>
            </div>
            <div class="vdd-form-body">
                <?php if ($success): ?>
                    <div class="vdd-alert vdd-alert-success"><i class="fa-solid fa-circle-check"></i> Client added successfully.</div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="vdd-alert vdd-alert-danger"><i class="fa-solid fa-circle-exclamation"></i> <?= sanitize($error) ?></div>
                <?php endif; ?>

                <form method="POST" autocomplete="off"><?= csrf_field() ?>
                    <div class="vdd-field-grid">
                        <div class="vdd-field span-2">
                            <label><i class="fa-solid fa-building"></i> Company Name *</label>
                            <input type="text" name="company_name" value="<?= sanitize($company_name) ?>" required>
                        </div>
                        <div class="vdd-field span-2">
                            <label><i class="fa-solid fa-user"></i> Contact Person *</label>
                            <input type="text" name="contact_person" value="<?= sanitize($contact_person) ?>" required>
                        </div>
                        <div class="vdd-field">
                            <label><i class="fa-solid fa-at"></i> Username *</label>
                            <input type="text" name="username" value="<?= sanitize($username) ?>" required autocomplete="off">
                        </div>
                        <div class="vdd-field">
                            <label><i class="fa-solid fa-phone"></i> Mobile *</label>
                            <input type="text" name="mobile" value="<?= sanitize($mobile) ?>" placeholder="10-digit mobile" required>
                        </div>
                        <div class="vdd-field span-2">
                            <label><i class="fa-solid fa-envelope"></i> Email *</label>
                            <input type="email" name="email" value="<?= sanitize($email) ?>" placeholder="client@company.com" required>
                        </div>
                        <div class="vdd-field">
                            <label><i class="fa-solid fa-lock"></i> Password *</label>
                            <input type="password" name="password" required autocomplete="new-password">
                        </div>
                        <div class="vdd-field">
                            <label><i class="fa-solid fa-lock"></i> Re-enter Password *</label>
                            <input type="password" name="confirm_password" required autocomplete="new-password">
                        </div>
                    </div>
                    <button type="submit" class="vdd-submit-btn"><i class="fa-solid fa-user-plus"></i> Create Client</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>

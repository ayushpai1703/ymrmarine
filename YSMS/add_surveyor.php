<?php
require_once 'config/config.php';
require_once 'includes/notifications.php';
checkAuth();
if (($_SESSION['role'] ?? '') !== 'Admin') {
    header('Location: index.php');
    exit;
}

$db = getDB();

// Ensure the columns this page already relied on exist (pre-existing
// self-healing pattern — unchanged from before).
try {
    $cols = [];
    foreach ($db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $cols[strtolower($c['Field'])] = true;
    }
    if (empty($cols['first_name'])) $db->exec("ALTER TABLE users ADD COLUMN first_name VARCHAR(80) DEFAULT NULL AFTER full_name");
    if (empty($cols['last_name']))  $db->exec("ALTER TABLE users ADD COLUMN last_name VARCHAR(80) DEFAULT NULL AFTER first_name");
    if (empty($cols['dob']))        $db->exec("ALTER TABLE users ADD COLUMN dob DATE DEFAULT NULL");
    if (empty($cols['date_of_joining'])) $db->exec("ALTER TABLE users ADD COLUMN date_of_joining DATE DEFAULT NULL");
    if (empty($cols['phone']))      $db->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(30) DEFAULT NULL");
    if (empty($cols['email']))      $db->exec("ALTER TABLE users ADD COLUMN email VARCHAR(150) DEFAULT NULL");
    if (empty($cols['profile_pic'])) $db->exec("ALTER TABLE users ADD COLUMN profile_pic VARCHAR(255) DEFAULT NULL");
    if (empty($cols['avatar']))      $db->exec("ALTER TABLE users ADD COLUMN avatar VARCHAR(255) DEFAULT NULL");
} catch (Exception $e) {
    error_log('add_surveyor column ensure: ' . $e->getMessage());
}

$STATUS_OPTIONS = ['Active', 'Inactive'];

$id = (int)($_GET['id'] ?? 0);
$isEdit = $id > 0;
$user = null;

if ($isEdit) {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND role_id = 2 LIMIT 1");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        header('Location: admin_controls.php?tab=surveyors');
        exit;
    }
}

$error = '';
$success = '';

// ── Add / Edit — same fields the Surveyor Profile already has ────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_surveyor'])) {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');
    $dob        = trim($_POST['dob'] ?? '');
    $doj        = trim($_POST['date_of_joining'] ?? '');
    $username   = trim($_POST['username'] ?? '');
    $mobile     = trim($_POST['phone'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $status     = trim($_POST['status'] ?? 'Active');
    $password   = $_POST['password'] ?? '';
    $confirm    = $_POST['confirm_password'] ?? '';

    $full_name = trim($first_name . ' ' . $last_name);

    if ($first_name === '' || $last_name === '' || $username === '') {
        $error = 'First name, last name and username are required.';
    } elseif (!$isEdit && ($password === '' || $confirm === '')) {
        $error = 'Password is required for a new surveyor.';
    } elseif ($password !== '' && $password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!in_array($status, $STATUS_OPTIONS, true)) {
        $error = 'Invalid status value.';
    } else {
        $chk = $db->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
        $chk->execute([$username, $id]);
        if ($chk->fetchColumn()) {
            $error = 'Username already exists. Please choose another.';
        }
    }

    // Photo upload (optional, same convention already used by profile.php)
    $uploaded_pic_path = null;
    if ($error === '' && isset($_FILES['profile_photo']) && is_array($_FILES['profile_photo']) && (int)$_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $target_dir = 'uploads/profile/';
        if (!is_dir($target_dir)) @mkdir($target_dir, 0755, true);
        $file_ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
        $allowed_exts = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($file_ext, $allowed_exts, true)) {
            $error = 'Invalid photo format. Allowed: JPG, PNG, WEBP.';
        } else {
            $new_pic_name = 'user_' . ($id ?: 'new') . '_' . time() . '.' . $file_ext;
            $full_target_path = $target_dir . $new_pic_name;
            if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $full_target_path)) {
                $uploaded_pic_path = $full_target_path;
            } else {
                $error = 'Could not save the uploaded photo. Please try again.';
            }
        }
    }

    if ($error === '') {
        $dobVal = ($dob !== '') ? $dob : null;
        $dojVal = ($doj !== '') ? $doj : null;

        if (!$isEdit) {
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $insert = $db->prepare("
                INSERT INTO users (role_id, username, password, full_name, first_name, last_name, dob, date_of_joining, phone, email, status, profile_pic, avatar)
                VALUES (2, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insert->execute([
                $username, $hashed, $full_name, $first_name, $last_name, $dobVal, $dojVal,
                $mobile ?: null, $email ?: null, $status, $uploaded_pic_path, $uploaded_pic_path,
            ]);
            $newId = (int)$db->lastInsertId();

            try {
                if ($newId > 0) {
                    createNotification($db, $newId, 'Welcome to YSMS',
                        'Your surveyor account has been created. Please update your profile.',
                        'surveyor', 'profile.php', (int)($_SESSION['user_id'] ?? 0));
                    notifyAllAdmins($db, 'New surveyor added',
                        ($_SESSION['full_name'] ?? 'Admin') . ' added a new surveyor account.',
                        'surveyor', 'add_surveyor.php?id=' . $newId, (int)($_SESSION['user_id'] ?? 0));
                }
            } catch (Throwable $ne) { error_log('add surveyor notif: ' . $ne->getMessage()); }

            $_SESSION['flash_msg'] = 'Surveyor added successfully.';
            header('Location: add_surveyor.php?id=' . $newId . '&created=1');
            exit;
        } else {
            $query_parts = [
                'full_name = ?', 'first_name = ?', 'last_name = ?', 'dob = ?',
                'username = ?', 'phone = ?', 'email = ?', 'status = ?', 'date_of_joining = ?',
            ];
            $params = [
                $full_name, $first_name, $last_name, $dobVal,
                $username, $mobile ?: null, $email ?: null, $status, $dojVal,
            ];
            if ($uploaded_pic_path) {
                $query_parts[] = 'profile_pic = ?';
                $query_parts[] = 'avatar = ?';
                $params[] = $uploaded_pic_path;
                $params[] = $uploaded_pic_path;
            }
            $params[] = $id;

            try {
                $stmt = $db->prepare('UPDATE users SET ' . implode(', ', $query_parts) . ' WHERE id = ? AND role_id = 2');
                $stmt->execute($params);

                $_SESSION['flash_msg'] = 'Surveyor profile updated successfully.';
                header('Location: add_surveyor.php?id=' . $id . '&updated=1');
                exit;
            } catch (Throwable $e) {
                error_log('add_surveyor update: ' . $e->getMessage());
                $error = (stripos($e->getMessage(), 'duplicate') !== false)
                    ? 'That username or email is already in use.'
                    : 'Could not save the surveyor profile. Please try again.';
            }
        }
    }
}

// Re-fetch after a validation error so the form re-renders with the current
// saved values (a successful save already redirected above).
if ($isEdit) {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND role_id = 2 LIMIT 1");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: $user;
}

if (!empty($_SESSION['flash_msg'])) {
    $success = $_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}

// When the save attempt above hit a validation error, re-render the form
// with what the Admin just typed instead of the stale pre-edit values.
$formPostback = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_surveyor']) && $error !== '');
function sf_val($user, $key) {
    global $formPostback;
    if ($formPostback && isset($_POST[$key])) return $_POST[$key];
    return $user[$key] ?? '';
}

include 'includes/header.php';
?>
<style>
    .surveyor-form-card { max-width: 640px; margin: 24px auto; background: #fff; border: 1px solid var(--border-color); border-radius: 18px; padding: 24px; box-shadow: 0 8px 24px rgba(15,23,42,.06); }
    .surveyor-form-title { font-size: 18px; font-weight: 700; color: var(--text-dark); margin-bottom: 4px; }
    .surveyor-form-copy { color: var(--text-muted); font-size: 12px; margin-bottom: 22px; }
    .surveyor-field { margin-bottom: 16px; }
    .surveyor-field label { display: block; margin-bottom: 6px; color: var(--text-muted); font-size: 12px; font-weight: 650; }
    .surveyor-field input, .surveyor-field select { width: 100%; padding: 11px 13px; border: 1px solid var(--border-color); border-radius: 10px; background: #f8fafc; color: var(--text-dark); font-size: 13px; outline: none; }
    .surveyor-field input:focus, .surveyor-field select:focus { border-color: var(--accent-purple); background: #fff; }
    .surveyor-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    @media (max-width: 680px) {
        .surveyor-form-card { margin: 18px 16px; padding: 20px; }
        .surveyor-grid { grid-template-columns: 1fr; }
    }
    .btn-save-surveyor {
        width: 100%; background: #3b32b3; color: #fff; border: none; padding: 12px; border-radius: 12px;
        font-weight: 700; font-size: 14px; margin-top: 8px;
    }
    .surveyor-photo-row { display: flex; align-items: center; gap: 14px; margin-bottom: 18px; }
    .surveyor-photo-preview { width: 60px; height: 60px; border-radius: 50%; object-fit: cover; border: 2px solid var(--accent-purple); background: #f1f5f9; }
</style>
<div class="scroll-content">
    <?php $page_title = $isEdit ? 'Edit Surveyor' : 'Add Surveyor'; $back_url = 'admin_controls.php?tab=surveyors'; $page_testid = 'add-surveyor'; include 'includes/top_app_bar.php'; ?>
    <div class="surveyor-form-card">
        <div class="surveyor-form-title"><?= $isEdit ? 'Edit Surveyor' : 'Add Surveyor' ?></div>
        <div class="surveyor-form-copy">
            <?= $isEdit
                ? 'Update this surveyor\'s profile. Surveyors can also change first name, last name, DOB, email, phone and photo from their own Profile.'
                : 'Create a new surveyor login. Surveyors can later change first name, last name, DOB and password only from Profile.' ?>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success py-2"><?= sanitize($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= sanitize($error) ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" autocomplete="off"><?= csrf_field() ?>
            <div class="surveyor-photo-row">
                <?php
                    $photoPath = $isEdit ? ($user['profile_pic'] ?: $user['avatar'] ?: '') : '';
                    $photoUrl = ($photoPath && is_file($photoPath)) ? $photoPath : 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?auto=format&fit=crop&w=100&q=80';
                ?>
                <img src="<?= sanitize($photoUrl) ?>" class="surveyor-photo-preview" alt="Profile photo">
                <div style="flex:1;">
                    <label class="d-block mb-1" style="font-size:12px;font-weight:650;color:var(--text-muted);">Profile Photo</label>
                    <input type="file" name="profile_photo" accept=".jpg,.jpeg,.png,.webp">
                </div>
            </div>
            <div class="surveyor-grid">
                <div class="surveyor-field">
                    <label>First Name *</label>
                    <input type="text" name="first_name" value="<?= sanitize(sf_val($user, 'first_name')) ?>" required>
                </div>
                <div class="surveyor-field">
                    <label>Last Name *</label>
                    <input type="text" name="last_name" value="<?= sanitize(sf_val($user, 'last_name')) ?>" required>
                </div>
                <div class="surveyor-field">
                    <label>Date of Birth (optional)</label>
                    <input type="date" name="dob" value="<?= sanitize(sf_val($user, 'dob')) ?>">
                </div>
                <div class="surveyor-field">
                    <label>Date of Joining</label>
                    <input type="date" name="date_of_joining" value="<?= sanitize(sf_val($user, 'date_of_joining')) ?>">
                </div>
                <div class="surveyor-field">
                    <label>Username *</label>
                    <input type="text" name="username" value="<?= sanitize(sf_val($user, 'username')) ?>" required autocomplete="off">
                </div>
                <div class="surveyor-field">
                    <label>Mobile</label>
                    <input type="text" name="phone" value="<?= sanitize(sf_val($user, 'phone')) ?>" placeholder="10-digit mobile">
                </div>
                <div class="surveyor-field" style="grid-column:1 / -1;">
                    <label>Email</label>
                    <input type="email" name="email" value="<?= sanitize(sf_val($user, 'email')) ?>" placeholder="surveyor@company.com">
                </div>
                <div class="surveyor-field">
                    <label>Status</label>
                    <select name="status">
                        <?php $curStatus = sf_val($user, 'status') ?: 'Active'; foreach ($STATUS_OPTIONS as $opt): ?>
                            <option value="<?= $opt ?>" <?= $curStatus === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (!$isEdit): ?>
                <div class="surveyor-field">
                    <label>Password *</label>
                    <input type="password" name="password" required autocomplete="new-password">
                </div>
                <div class="surveyor-field">
                    <label>Re-enter Password *</label>
                    <input type="password" name="confirm_password" required autocomplete="new-password">
                </div>
                <?php endif; ?>
            </div>
            <button type="submit" name="save_surveyor" class="btn-save-surveyor"><i class="fa-solid <?= $isEdit ? 'fa-floppy-disk' : 'fa-user-plus' ?> me-1"></i> <?= $isEdit ? 'Save Changes' : 'Create Surveyor' ?></button>
        </form>
    </div>
</div>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>

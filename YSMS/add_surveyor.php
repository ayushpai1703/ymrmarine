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
    /* ═══ Ultra-modern redesign — same hero + icon-chip visual language as
       vessel_detail.php / add_client.php. Standalone form, one responsive
       card at every width; only the hero banner is desktop-only. */
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
    .vdd-form-head-sub { font-size: 11.5px; color: #94a3b8; margin-top: 1px; font-weight: 500; line-height: 1.5; }
    .vdd-form-body { padding: 22px; }

    .vdd-alert { border-radius: 12px; padding: 11px 14px; font-size: 12.5px; font-weight: 600; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
    .vdd-alert-success { background: #D1FAE5; color: #065f46; }
    .vdd-alert-danger { background: #FEE2E2; color: #991b1b; }

    .vdd-photo-row {
        display: flex; align-items: center; gap: 16px; margin-bottom: 20px;
        padding: 16px; background: #F8FAFC; border: 1px solid #e2e8f0; border-radius: 16px;
    }
    .vdd-photo-avatar-wrap { position: relative; flex: none; }
    .vdd-photo-preview { width: 64px; height: 64px; border-radius: 50%; object-fit: cover; border: 3px solid #fff; box-shadow: 0 0 0 2px #A78BFA; background: #f1f5f9; }
    .vdd-photo-badge {
        position: absolute; bottom: -2px; right: -2px; width: 24px; height: 24px; border-radius: 50%;
        background: #5B2DEE; color: #fff; display: flex; align-items: center; justify-content: center;
        font-size: 10px; border: 2px solid #fff;
    }
    .vdd-photo-label { font-size: 12.5px; font-weight: 700; color: #0f172a; margin-bottom: 4px; }
    .vdd-photo-hint { font-size: 11px; color: #94a3b8; margin-bottom: 2px; }
    .vdd-photo-choose {
        position: relative; overflow: hidden; display: inline-flex; align-items: center; gap: 6px;
        background: #fff; border: 1px solid #e2e8f0; color: #334155; font-size: 11.5px; font-weight: 700;
        padding: 7px 14px; border-radius: 999px; margin-top: 4px;
    }
    .vdd-photo-choose input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; }

    .vdd-field-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .vdd-field { margin-bottom: 14px; }
    .vdd-field.span-2 { grid-column: 1 / -1; }
    .vdd-field label { display: flex; align-items: center; gap: 6px; font-size: 11.5px; font-weight: 700; color: #64748b; margin-bottom: 6px; }
    .vdd-field label i { color: #A78BFA; font-size: 11px; width: 14px; text-align: center; }
    .vdd-field input, .vdd-field select {
        width: 100%; box-sizing: border-box; border: 1.5px solid #e2e8f0; border-radius: 12px;
        padding: 11px 14px; font-size: 13.5px; color: #0f172a; font-family: inherit; background: #F8FAFC;
        appearance: none; -webkit-appearance: none;
    }
    .vdd-field select {
        background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='6'><path d='M0 0l5 6 5-6z' fill='%2394A3B8'/></svg>");
        background-repeat: no-repeat; background-position: right 14px center; padding-right: 32px;
    }
    .vdd-field input:focus, .vdd-field select:focus { outline: none; border-color: #A78BFA; box-shadow: 0 0 0 4px rgba(91,45,238,.12); background: #fff; }
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
    <?php $page_title = $isEdit ? 'Edit Surveyor' : 'Add Surveyor'; $back_url = 'admin_controls.php?tab=surveyors'; $page_testid = 'add-surveyor'; include 'includes/top_app_bar.php'; ?>

    <div class="vdd-wrap">
        <div class="vdd-hero">
            <i class="fa-solid fa-user-tie vdd-hero-icon-bg" aria-hidden="true"></i>
            <div class="vdd-hero-ship"><i class="fa-solid fa-user-tie"></i></div>
            <div>
                <div class="vdd-hero-title"><?= $isEdit ? 'Edit Surveyor' : 'Add Surveyor' ?></div>
                <div class="vdd-hero-sub"><?= $isEdit ? 'Update this surveyor\'s profile' : 'Create a new surveyor login' ?></div>
            </div>
        </div>

        <div class="vdd-form-card">
            <div class="vdd-form-head">
                <div class="vdd-form-head-icon"><i class="fa-solid <?= $isEdit ? 'fa-user-pen' : 'fa-user-plus' ?>"></i></div>
                <div>
                    <div class="vdd-form-head-title"><?= $isEdit ? 'Edit Surveyor' : 'Add Surveyor' ?></div>
                    <div class="vdd-form-head-sub">
                        <?= $isEdit
                            ? 'Surveyors can also change first name, last name, DOB, email, phone and photo from their own Profile.'
                            : 'Surveyors can later change first name, last name, DOB and password only from Profile.' ?>
                    </div>
                </div>
            </div>
            <div class="vdd-form-body">
                <?php if ($success): ?>
                    <div class="vdd-alert vdd-alert-success"><i class="fa-solid fa-circle-check"></i> <?= sanitize($success) ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="vdd-alert vdd-alert-danger"><i class="fa-solid fa-circle-exclamation"></i> <?= sanitize($error) ?></div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" autocomplete="off"><?= csrf_field() ?>
                    <div class="vdd-photo-row">
                        <?php
                            $photoPath = $isEdit ? ($user['profile_pic'] ?: $user['avatar'] ?: '') : '';
                            $photoUrl = ($photoPath && is_file($photoPath)) ? $photoPath : 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?auto=format&fit=crop&w=100&q=80';
                        ?>
                        <div class="vdd-photo-avatar-wrap">
                            <img src="<?= sanitize($photoUrl) ?>" class="vdd-photo-preview" alt="Profile photo">
                            <div class="vdd-photo-badge"><i class="fa-solid fa-camera"></i></div>
                        </div>
                        <div style="flex:1;min-width:0;">
                            <div class="vdd-photo-label">Profile Photo</div>
                            <div class="vdd-photo-hint">JPG, PNG or WEBP</div>
                            <label class="vdd-photo-choose"><i class="fa-solid fa-arrow-up-from-bracket"></i> Choose photo<input type="file" name="profile_photo" accept=".jpg,.jpeg,.png,.webp"></label>
                        </div>
                    </div>
                    <div class="vdd-field-grid">
                        <div class="vdd-field">
                            <label><i class="fa-solid fa-user"></i> First Name *</label>
                            <input type="text" name="first_name" value="<?= sanitize(sf_val($user, 'first_name')) ?>" required>
                        </div>
                        <div class="vdd-field">
                            <label><i class="fa-solid fa-user"></i> Last Name *</label>
                            <input type="text" name="last_name" value="<?= sanitize(sf_val($user, 'last_name')) ?>" required>
                        </div>
                        <div class="vdd-field">
                            <label><i class="fa-solid fa-cake-candles"></i> Date of Birth (optional)</label>
                            <input type="date" name="dob" value="<?= sanitize(sf_val($user, 'dob')) ?>">
                        </div>
                        <div class="vdd-field">
                            <label><i class="fa-solid fa-calendar-check"></i> Date of Joining</label>
                            <input type="date" name="date_of_joining" value="<?= sanitize(sf_val($user, 'date_of_joining')) ?>">
                        </div>
                        <div class="vdd-field">
                            <label><i class="fa-solid fa-at"></i> Username *</label>
                            <input type="text" name="username" value="<?= sanitize(sf_val($user, 'username')) ?>" required autocomplete="off">
                        </div>
                        <div class="vdd-field">
                            <label><i class="fa-solid fa-phone"></i> Mobile</label>
                            <input type="text" name="phone" value="<?= sanitize(sf_val($user, 'phone')) ?>" placeholder="10-digit mobile">
                        </div>
                        <div class="vdd-field span-2">
                            <label><i class="fa-solid fa-envelope"></i> Email</label>
                            <input type="email" name="email" value="<?= sanitize(sf_val($user, 'email')) ?>" placeholder="surveyor@company.com">
                        </div>
                        <div class="vdd-field">
                            <label><i class="fa-solid fa-toggle-on"></i> Status</label>
                            <select name="status">
                                <?php $curStatus = sf_val($user, 'status') ?: 'Active'; foreach ($STATUS_OPTIONS as $opt): ?>
                                    <option value="<?= $opt ?>" <?= $curStatus === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if (!$isEdit): ?>
                        <div class="vdd-field">
                            <label><i class="fa-solid fa-lock"></i> Password *</label>
                            <input type="password" name="password" required autocomplete="new-password">
                        </div>
                        <div class="vdd-field">
                            <label><i class="fa-solid fa-lock"></i> Re-enter Password *</label>
                            <input type="password" name="confirm_password" required autocomplete="new-password">
                        </div>
                        <?php endif; ?>
                    </div>
                    <button type="submit" name="save_surveyor" class="vdd-submit-btn"><i class="fa-solid <?= $isEdit ? 'fa-floppy-disk' : 'fa-user-plus' ?>"></i> <?= $isEdit ? 'Save Changes' : 'Create Surveyor' ?></button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// 🌟 Photo "chosen" preview — purely display, swaps the avatar image to a
// local object URL of the selected file so the Admin sees what they picked
// before saving. Field name/behavior on submit is unchanged.
document.querySelectorAll('input[name="profile_photo"]').forEach(function (input) {
    input.addEventListener('change', function () {
        if (!input.files || !input.files[0]) return;
        var img = input.closest('.vdd-photo-row').querySelector('.vdd-photo-preview');
        if (img) img.src = URL.createObjectURL(input.files[0]);
    });
});
</script>

<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>

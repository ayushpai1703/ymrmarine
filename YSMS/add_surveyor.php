<?php
require_once 'config/config.php';
require_once 'includes/notifications.php';
require_once 'includes/surveyor_profile.php';
checkAuth();
if (($_SESSION['role'] ?? '') !== 'Admin') {
    header('Location: index.php');
    exit;
}

$db = getDB();

// Ensure core columns (pre-existing feature) + extended profile columns exist.
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
    if (empty($cols['reset_token'])) $db->exec("ALTER TABLE users ADD COLUMN reset_token VARCHAR(255) DEFAULT NULL");
    if (empty($cols['reset_token_expires'])) $db->exec("ALTER TABLE users ADD COLUMN reset_token_expires DATETIME DEFAULT NULL");
} catch (Exception $e) {
    error_log('add_surveyor column ensure: ' . $e->getMessage());
}
ensureSurveyorProfileColumns($db);

// Field groups for the extended profile (rendering + save loop). Core fields
// (first_name, last_name, dob, username, email, phone, date_of_joining,
// status) are handled separately below because they need required/uniqueness
// rules the generic loop doesn't apply.
$PERSONAL_FIELDS = [
    ['gender', 'Gender', 'select', ['', 'Male', 'Female', 'Other', 'Prefer not to say']],
    ['nationality', 'Nationality', 'text'],
    ['address', 'Address', 'text'],
    ['city', 'City', 'text'],
    ['state', 'State', 'text'],
    ['country', 'Country', 'text'],
    ['pincode', 'PIN / Postal Code', 'text'],
];
$CONTACT_FIELDS = [
    ['alternate_phone', 'Alternate Phone Number', 'text'],
    ['emergency_contact_name', 'Emergency Contact Name', 'text'],
    ['emergency_contact_phone', 'Emergency Contact Number', 'text'],
];
$EMPLOYMENT_FIELDS = [
    ['employee_id', 'Employee ID', 'text'],
    ['designation', 'Designation', 'text'],
    ['department', 'Department', 'text'],
    ['office_location', 'Office / Location', 'text'],
];
$MARINE_FIELDS = [
    ['surveyor_type', 'Surveyor Type', 'text'],
    ['license_number', 'Surveyor License Number', 'text'],
    ['license_issue_date', 'License Issue Date', 'date'],
    ['license_expiry_date', 'License Expiry Date', 'date'],
    ['experience', 'Experience', 'text'],
    ['port_coverage', 'Port Coverage', 'text'],
    ['specialization', 'Specialization', 'text'],
    ['certification_details', 'Certification Details', 'textarea'],
];
$ALL_EXTRA_FIELDS = array_merge($PERSONAL_FIELDS, $CONTACT_FIELDS, $EMPLOYMENT_FIELDS, $MARINE_FIELDS);

$STATUS_OPTIONS = ['Active', 'Inactive', 'Suspended'];

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

$admins = $db->query("SELECT id, full_name FROM users WHERE role_id = 1 AND status = 'Active' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$error = '';
$success = '';

// ── Send password reset link (Admin-initiated, reuses the existing
//    Forgot Password token + email architecture — never sets/shows a
//    plaintext password) ─────────────────────────────────────────────
if ($isEdit && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_reset_link'])) {
    require_once 'includes/mailer.php';
    if (empty($user['email'])) {
        $error = 'This surveyor has no email on file. Add an email address first, then send the reset link.';
    } else {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+60 minutes'));
        $upd = $db->prepare("UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE id = ?");
        $upd->execute([$token, $expires, $id]);

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $resetLink = $scheme . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/reset_password.php?token=' . $token;

        if (sendPasswordResetEmail($user['email'], $user['full_name'], $resetLink)) {
            logSurveyorProfileChange($db, $id, (int)($_SESSION['user_id'] ?? 0), 'password_reset', null, 'Reset link sent to ' . $user['email']);
            $_SESSION['flash_msg'] = 'Password reset link sent to ' . $user['email'] . '.';
        } else {
            $_SESSION['flash_msg'] = 'Could not send the reset email — please check SMTP settings and try again.';
        }
    }
    if ($error === '') {
        header('Location: add_surveyor.php?id=' . $id);
        exit;
    }
}

// ── Add / Edit main profile form ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_surveyor'])) {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');
    $dob        = trim($_POST['dob'] ?? '');
    $doj        = trim($_POST['date_of_joining'] ?? '');
    $username   = trim($_POST['username'] ?? '');
    $mobile     = trim($_POST['phone'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $status     = trim($_POST['status'] ?? 'Active');
    $reportingManagerId = (int)($_POST['reporting_manager_id'] ?? 0);
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
    } elseif ($dob !== '' && strtotime($dob) > time()) {
        $error = 'Date of Birth cannot be in the future.';
    } elseif (!in_array($status, $STATUS_OPTIONS, true)) {
        $error = 'Invalid status value.';
    } else {
        // Username uniqueness
        $chk = $db->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
        $chk->execute([$username, $id]);
        if ($chk->fetchColumn()) {
            $error = 'Username already exists. Please choose another.';
        }
        // Email uniqueness (only when supplied)
        if ($error === '' && $email !== '') {
            $chk = $db->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
            $chk->execute([$email, $id]);
            if ($chk->fetchColumn()) {
                $error = 'That email address is already used by another account.';
            }
        }
        // Employee ID uniqueness (only when supplied)
        $employeeId = trim($_POST['employee_id'] ?? '');
        if ($error === '' && $employeeId !== '') {
            $chk = $db->prepare('SELECT id FROM users WHERE employee_id = ? AND id != ?');
            $chk->execute([$employeeId, $id]);
            if ($chk->fetchColumn()) {
                $error = 'That Employee ID is already used by another surveyor.';
            }
        }
    }

    // Photo upload (optional, same convention as profile.php)
    $uploaded_pic_path = null;
    if ($error === '' && isset($_FILES['profile_photo']) && is_array($_FILES['profile_photo']) && (int)$_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $target_dir = 'uploads/profile/';
        if (!is_dir($target_dir)) @mkdir($target_dir, 0755, true);
        $file_ext = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
        $allowed_exts = ['jpg', 'jpeg', 'png', 'webp'];
        $maxBytes = 5 * 1024 * 1024;
        if (!in_array($file_ext, $allowed_exts, true)) {
            $error = 'Invalid photo format. Allowed: JPG, PNG, WEBP.';
        } elseif ((int)$_FILES['profile_photo']['size'] > $maxBytes) {
            $error = 'Photo is too large. Maximum size is 5 MB.';
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
        try {
            $db->beginTransaction();

            $dobVal = ($dob !== '') ? $dob : null;
            $dojVal = ($doj !== '') ? $doj : null;
            $reportingManagerVal = $reportingManagerId > 0 ? $reportingManagerId : null;

            if (!$isEdit) {
                $insert = $db->prepare("
                    INSERT INTO users (role_id, username, password, full_name, first_name, last_name, dob, date_of_joining, phone, email, status, employee_id, reporting_manager_id, profile_pic, avatar)
                    VALUES (2, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $hashed = password_hash($password, PASSWORD_BCRYPT);
                $insert->execute([
                    $username, $hashed, $full_name, $first_name, $last_name, $dobVal, $dojVal,
                    $mobile ?: null, $email ?: null, $status, $employeeId ?: null, $reportingManagerVal,
                    $uploaded_pic_path, $uploaded_pic_path,
                ]);
                $newId = (int)$db->lastInsertId();

                // Extended profile fields (all optional text/date/textarea), one batched UPDATE.
                $extraData = [];
                foreach ($ALL_EXTRA_FIELDS as [$fname, , ]) {
                    if ($fname === 'employee_id') continue; // already inserted above
                    $val = trim((string)($_POST[$fname] ?? ''));
                    if ($val !== '') $extraData[$fname] = $val;
                }
                if ($extraData) {
                    $setSql = implode(',', array_map(fn($c) => "`$c` = ?", array_keys($extraData)));
                    $db->prepare("UPDATE users SET $setSql WHERE id = ?")->execute([...array_values($extraData), $newId]);
                }

                logSurveyorProfileChange($db, $newId, (int)($_SESSION['user_id'] ?? 0), 'account', null, 'Surveyor account created');
                $db->commit();

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
                // Build the full new-value map, diff against $user, update, and
                // log every field that actually changed.
                $newValues = [
                    'first_name' => $first_name, 'last_name' => $last_name, 'full_name' => $full_name,
                    'dob' => $dobVal, 'username' => $username, 'phone' => $mobile ?: null,
                    'email' => $email ?: null, 'status' => $status, 'date_of_joining' => $dojVal,
                    'employee_id' => $employeeId ?: null, 'reporting_manager_id' => $reportingManagerVal,
                ];
                foreach ($ALL_EXTRA_FIELDS as [$fname, , ]) {
                    if ($fname === 'employee_id') continue;
                    $newValues[$fname] = trim((string)($_POST[$fname] ?? '')) ?: null;
                }
                if ($uploaded_pic_path) {
                    $newValues['profile_pic'] = $uploaded_pic_path;
                    $newValues['avatar'] = $uploaded_pic_path;
                }

                $setSql = implode(',', array_map(fn($c) => "`$c` = ?", array_keys($newValues)));
                $stmt = $db->prepare("UPDATE users SET $setSql WHERE id = ? AND role_id = 2");
                $stmt->execute([...array_values($newValues), $id]);

                $adminId = (int)($_SESSION['user_id'] ?? 0);
                foreach ($newValues as $field => $newVal) {
                    if ($field === 'profile_pic' || $field === 'avatar') continue; // logged once below
                    $oldVal = $user[$field] ?? null;
                    logSurveyorProfileChange($db, $id, $adminId, $field, $oldVal !== null ? (string)$oldVal : null, $newVal !== null ? (string)$newVal : null);
                }
                if ($uploaded_pic_path) {
                    logSurveyorProfileChange($db, $id, $adminId, 'profile_pic', $user['profile_pic'] ?? null, $uploaded_pic_path);
                }

                $db->commit();

                if (($user['status'] ?? '') !== $status) {
                    try {
                        createNotification($db, $id, 'Account status updated',
                            'Your account status was changed to ' . $status . ' by ' . ($_SESSION['full_name'] ?? 'Admin') . '.',
                            'surveyor', 'profile.php', $adminId);
                    } catch (Throwable $ne) { error_log('status change notif: ' . $ne->getMessage()); }
                }

                $_SESSION['flash_msg'] = 'Surveyor profile updated successfully.';
                header('Location: add_surveyor.php?id=' . $id . '&updated=1');
                exit;
            }
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('add_surveyor save: ' . $e->getMessage());
            $error = (stripos($e->getMessage(), 'duplicate') !== false)
                ? 'That username or email is already in use.'
                : 'Could not save the surveyor profile. Please try again.';
        }
    }
}

// Re-fetch after any successful mutation handled above via redirect; this
// only runs when we're rendering (GET, or a POST that hit a validation error
// and is falling through to re-render the form with $error set).
if ($isEdit) {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND role_id = 2 LIMIT 1");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: $user;
}

if (!empty($_SESSION['flash_msg'])) {
    $success = $_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}

$history = $isEdit ? getSurveyorProfileHistory($db, $id, 30) : [];

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
    .surveyor-form-card { max-width: 760px; margin: 20px auto; background: #fff; border: 1px solid var(--border-color); border-radius: 18px; padding: 24px; box-shadow: 0 8px 24px rgba(15,23,42,.06); }
    .surveyor-form-title { font-size: 16px; font-weight: 700; color: var(--text-dark); margin-bottom: 2px; display: flex; align-items: center; gap: 8px; }
    .surveyor-form-copy { color: var(--text-muted); font-size: 12px; margin-bottom: 18px; }
    .surveyor-field { margin-bottom: 16px; }
    .surveyor-field label { display: block; margin-bottom: 6px; color: var(--text-muted); font-size: 12px; font-weight: 650; }
    .surveyor-field input, .surveyor-field select, .surveyor-field textarea { width: 100%; padding: 11px 13px; border: 1px solid var(--border-color); border-radius: 10px; background: #f8fafc; color: var(--text-dark); font-size: 13px; outline: none; font-family: inherit; }
    .surveyor-field textarea { resize: vertical; min-height: 70px; }
    .surveyor-field input:focus, .surveyor-field select:focus, .surveyor-field textarea:focus { border-color: var(--accent-purple); background: #fff; }
    .surveyor-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    @media (max-width: 680px) {
        .surveyor-form-card { margin: 16px; padding: 18px; }
        .surveyor-grid { grid-template-columns: 1fr; }
    }
    .btn-save-surveyor {
        width: 100%; background: #3b32b3; color: #fff; border: none; padding: 12px; border-radius: 12px;
        font-weight: 700; font-size: 14px; margin-top: 8px;
    }
    .surveyor-photo-row { display: flex; align-items: center; gap: 14px; margin-bottom: 18px; }
    .surveyor-photo-preview { width: 68px; height: 68px; border-radius: 50%; object-fit: cover; border: 2px solid var(--accent-purple); background: #f1f5f9; }
    .surveyor-status-badge { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; }
    .surveyor-status-badge.active { background: rgba(22,163,74,.1); color: #16a34a; }
    .surveyor-status-badge.inactive { background: rgba(100,116,139,.12); color: #64748b; }
    .surveyor-status-badge.suspended { background: rgba(220,38,38,.1); color: #dc2626; }
    .surveyor-section-title { font-size: 13px; font-weight: 700; color: var(--text-dark); margin: 22px 0 12px; padding-top: 14px; border-top: 1px dashed var(--border-color); }
    .surveyor-section-title:first-of-type { border-top: none; margin-top: 0; padding-top: 0; }
    .surveyor-reset-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 12px; padding: 12px 14px; margin-bottom: 6px; }
    .surveyor-reset-copy { font-size: 12px; color: #92400e; }
    .btn-reset-link { border: none; background: #b45309; color: #fff; padding: 9px 14px; border-radius: 9px; font-size: 12px; font-weight: 700; white-space: nowrap; }
    .surveyor-history-item { font-size: 12px; color: var(--text-dark); padding: 8px 0; border-bottom: 1px dashed var(--border-color); }
    .surveyor-history-item:last-child { border-bottom: none; }
    .surveyor-history-meta { color: var(--text-muted); font-size: 11px; }
</style>
<div class="scroll-content">
    <?php $page_title = $isEdit ? 'Edit Surveyor' : 'Add Surveyor'; $back_url = 'admin_controls.php?tab=surveyors'; $page_testid = 'add-surveyor'; include 'includes/top_app_bar.php'; ?>
    <div class="surveyor-form-card" data-testid="surveyor-profile-card">
        <div class="surveyor-form-title">
            <?= $isEdit ? 'Edit Surveyor Profile' : 'Add Surveyor' ?>
            <?php if ($isEdit): $st = strtolower($user['status'] ?? 'active'); ?>
                <span class="surveyor-status-badge <?= sanitize($st) ?>"><?= sanitize($user['status'] ?? 'Active') ?></span>
            <?php endif; ?>
        </div>
        <div class="surveyor-form-copy">
            <?= $isEdit
                ? 'Full profile management. Surveyors can also change first name, last name, DOB, email, phone and photo from their own Profile page.'
                : 'Create a new surveyor login with a complete profile. Additional details can be added now or later from Edit.' ?>
        </div>

        <?php if ($success): ?><div class="alert alert-success py-2" data-testid="surveyor-success"><?= sanitize($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger py-2" data-testid="surveyor-error"><?= sanitize($error) ?></div><?php endif; ?>

        <?php if ($isEdit): ?>
        <div class="surveyor-reset-row" data-testid="surveyor-reset-password-row">
            <div class="surveyor-reset-copy">
                <i class="fa-solid fa-key me-1"></i>
                Send a secure password reset link to <?= sanitize($user['email'] ?: 'this surveyor (no email on file)') ?>. The surveyor sets their own new password — Admin never sees or sets it directly.
            </div>
            <form method="POST" onsubmit="return confirm('Send a password reset link to this surveyor\'s email?');"><?= csrf_field() ?>
                <button type="submit" name="send_reset_link" class="btn-reset-link" data-testid="surveyor-send-reset-link" <?= empty($user['email']) ? 'disabled' : '' ?>>Send Reset Link</button>
            </form>
        </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" autocomplete="off" data-testid="surveyor-profile-form"><?= csrf_field() ?>
            <div class="surveyor-photo-row">
                <?php
                    $photoPath = $isEdit ? ($user['profile_pic'] ?: $user['avatar'] ?: '') : '';
                    $photoUrl = ($photoPath && is_file($photoPath)) ? $photoPath : 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?auto=format&fit=crop&w=100&q=80';
                ?>
                <img src="<?= sanitize($photoUrl) ?>" class="surveyor-photo-preview" alt="Profile photo">
                <div>
                    <label class="d-block mb-1" style="font-size:12px;font-weight:650;color:var(--text-muted);">Profile Photo</label>
                    <input type="file" name="profile_photo" accept=".jpg,.jpeg,.png,.webp" data-testid="surveyor-photo-input">
                </div>
            </div>

            <div class="surveyor-section-title">Personal Information</div>
            <div class="surveyor-grid">
                <div class="surveyor-field">
                    <label>First Name *</label>
                    <input type="text" name="first_name" value="<?= sanitize(sf_val($user, 'first_name')) ?>" required data-testid="surveyor-field-first_name">
                </div>
                <div class="surveyor-field">
                    <label>Last Name *</label>
                    <input type="text" name="last_name" value="<?= sanitize(sf_val($user, 'last_name')) ?>" required data-testid="surveyor-field-last_name">
                </div>
                <div class="surveyor-field">
                    <label>Date of Birth</label>
                    <input type="date" name="dob" value="<?= sanitize(sf_val($user, 'dob')) ?>" max="<?= date('Y-m-d') ?>" data-testid="surveyor-field-dob">
                </div>
                <?php foreach ($PERSONAL_FIELDS as [$fname, $flabel, $ftype, $fopts]): ?>
                    <div class="surveyor-field">
                        <label><?= sanitize($flabel) ?></label>
                        <?php if ($ftype === 'select'): $cur = sf_val($user, $fname); ?>
                            <select name="<?= $fname ?>" data-testid="surveyor-field-<?= $fname ?>">
                                <?php foreach ($fopts as $opt): ?>
                                    <option value="<?= sanitize($opt) ?>" <?= $cur === $opt ? 'selected' : '' ?>><?= $opt === '' ? '— Select —' : sanitize($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="text" name="<?= $fname ?>" value="<?= sanitize(sf_val($user, $fname)) ?>" data-testid="surveyor-field-<?= $fname ?>">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="surveyor-section-title">Contact Information</div>
            <div class="surveyor-grid">
                <div class="surveyor-field">
                    <label>Email</label>
                    <input type="email" name="email" value="<?= sanitize(sf_val($user, 'email')) ?>" placeholder="surveyor@company.com" data-testid="surveyor-field-email">
                </div>
                <div class="surveyor-field">
                    <label>Phone Number</label>
                    <input type="text" name="phone" value="<?= sanitize(sf_val($user, 'phone')) ?>" placeholder="10-digit mobile" data-testid="surveyor-field-phone">
                </div>
                <?php foreach ($CONTACT_FIELDS as [$fname, $flabel, ]): ?>
                    <div class="surveyor-field">
                        <label><?= sanitize($flabel) ?></label>
                        <input type="text" name="<?= $fname ?>" value="<?= sanitize(sf_val($user, $fname)) ?>" data-testid="surveyor-field-<?= $fname ?>">
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="surveyor-section-title">Employment Information</div>
            <div class="surveyor-grid">
                <?php foreach ($EMPLOYMENT_FIELDS as [$fname, $flabel, ]): ?>
                    <div class="surveyor-field">
                        <label><?= sanitize($flabel) ?></label>
                        <input type="text" name="<?= $fname ?>" value="<?= sanitize(sf_val($user, $fname)) ?>" data-testid="surveyor-field-<?= $fname ?>">
                    </div>
                <?php endforeach; ?>
                <div class="surveyor-field">
                    <label>Date of Joining</label>
                    <input type="date" name="date_of_joining" value="<?= sanitize(sf_val($user, 'date_of_joining')) ?>" data-testid="surveyor-field-date_of_joining">
                </div>
                <div class="surveyor-field">
                    <label>Reporting Manager</label>
                    <select name="reporting_manager_id" data-testid="surveyor-field-reporting_manager_id">
                        <option value="">— None —</option>
                        <?php $curMgr = (int)sf_val($user, 'reporting_manager_id'); foreach ($admins as $a): ?>
                            <option value="<?= (int)$a['id'] ?>" <?= $curMgr === (int)$a['id'] ? 'selected' : '' ?>><?= sanitize($a['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="surveyor-field">
                    <label>Employment Status *</label>
                    <select name="status" data-testid="surveyor-field-status">
                        <?php $curStatus = sf_val($user, 'status') ?: 'Active'; foreach ($STATUS_OPTIONS as $opt): ?>
                            <option value="<?= $opt ?>" <?= $curStatus === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="surveyor-section-title">Marine / Surveyor Information</div>
            <div class="surveyor-grid">
                <?php foreach ($MARINE_FIELDS as [$fname, $flabel, $ftype]): ?>
                    <div class="surveyor-field" <?= $ftype === 'textarea' ? 'style="grid-column:1 / -1;"' : '' ?>>
                        <label><?= sanitize($flabel) ?></label>
                        <?php if ($ftype === 'textarea'): ?>
                            <textarea name="<?= $fname ?>" data-testid="surveyor-field-<?= $fname ?>"><?= sanitize(sf_val($user, $fname)) ?></textarea>
                        <?php else: ?>
                            <input type="<?= $ftype === 'date' ? 'date' : 'text' ?>" name="<?= $fname ?>" value="<?= sanitize(sf_val($user, $fname)) ?>" data-testid="surveyor-field-<?= $fname ?>">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="surveyor-section-title">Account</div>
            <div class="surveyor-grid">
                <div class="surveyor-field">
                    <label>Username *</label>
                    <input type="text" name="username" value="<?= sanitize(sf_val($user, 'username')) ?>" required autocomplete="off" data-testid="surveyor-field-username">
                </div>
                <?php if (!$isEdit): ?>
                <div class="surveyor-field">
                    <label>Password *</label>
                    <input type="password" name="password" required autocomplete="new-password" data-testid="surveyor-field-password">
                </div>
                <div class="surveyor-field">
                    <label>Re-enter Password *</label>
                    <input type="password" name="confirm_password" required autocomplete="new-password" data-testid="surveyor-field-confirm_password">
                </div>
                <?php endif; ?>
            </div>

            <button type="submit" name="save_surveyor" class="btn-save-surveyor" data-testid="surveyor-save-button">
                <i class="fa-solid <?= $isEdit ? 'fa-floppy-disk' : 'fa-user-plus' ?> me-1"></i> <?= $isEdit ? 'Save Changes' : 'Create Surveyor' ?>
            </button>
        </form>

        <?php if ($isEdit && $history): ?>
        <div class="surveyor-section-title">Change History</div>
        <div data-testid="surveyor-change-history">
            <?php foreach ($history as $h):
                $label = surveyorFieldLabel($h['field_name']);
                $when = date('d-M-Y H:i', strtotime($h['changed_at']));
                $adminName = $h['admin_name'] ?: 'Admin';
            ?>
                <div class="surveyor-history-item">
                    <div class="surveyor-history-meta"><?= sanitize($when) ?> — <?= sanitize($adminName) ?></div>
                    <?php if ($h['field_name'] === 'account' || $h['field_name'] === 'password_reset'): ?>
                        <?= sanitize($label) ?>: <?= sanitize((string)$h['new_value']) ?>
                    <?php else: ?>
                        Changed <strong><?= sanitize($label) ?></strong>: <?= sanitize((string)($h['old_value'] ?: '—')) ?> → <?= sanitize((string)($h['new_value'] ?: '—')) ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>

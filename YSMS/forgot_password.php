<?php
require_once 'config/config.php';
require_once 'includes/mailer.php';

// 🌟 Safety-net: ensure the password-reset columns exist even if
// database/migration_password_reset.sql was never run against this
// database — without this, a fresh/un-migrated DB throws an "Unknown
// column" SQL error the moment someone uses Forgot Password.
try {
    $db_boot = getDB();
    $cols = $db_boot->query("SHOW COLUMNS FROM users LIKE 'reset_token'")->fetchColumn();
    if (!$cols) {
        $db_boot->exec("ALTER TABLE users
            ADD COLUMN IF NOT EXISTS email VARCHAR(150) NULL,
            ADD COLUMN IF NOT EXISTS reset_token VARCHAR(255) NULL,
            ADD COLUMN IF NOT EXISTS reset_token_expires DATETIME NULL");
    }
} catch (Throwable $e) { error_log('forgot_password.php column ensure: ' . $e->getMessage()); }

$message = '';
$messageType = 'info'; // info | success | danger

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
        $messageType = 'danger';
    } else {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, full_name, email FROM users WHERE email = ? AND status = 'Active' LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // 🌟 అదే మెసేజ్‌ని ఎప్పుడూ చూపిస్తాం (ఈమెయిల్ ఉందా లేదా అని బయటకు తెలియకుండా - సెక్యూరిటీ కోసం)
        $message = 'If an account exists for that email, a password reset link has been sent.';
        $messageType = 'success';

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+60 minutes'));

            $upd = $db->prepare("UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE id = ?");
            $upd->execute([$token, $expires, $user['id']]);

            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $resetLink = $scheme . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/reset_password.php?token=' . $token;

            sendPasswordResetEmail($user['email'], $user['full_name'], $resetLink);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password | YSMS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    :root { --primary-bg:#0b1e46; --accent-purple:#3b32b3; }
    * { box-sizing: border-box; }
    body {
        font-family:'Lexend',sans-serif; margin:0; min-height:100vh;
        background: radial-gradient(circle at top left, #1e4fa8 0%, #123879 55%, #081c48 100%);
        display:flex; align-items:center; justify-content:center; padding:20px;
        position: relative; overflow-x: hidden;
    }
    .bg-glow { position: fixed; border-radius: 50%; filter: blur(90px); opacity: .32; z-index: 0; pointer-events: none; }
    .bg-glow.one { width: 300px; height: 300px; background: #3b5bdb; top: -60px; left: -60px; }
    .bg-glow.two { width: 260px; height: 260px; background: #0ea5e9; bottom: -40px; right: -40px; }
    .auth-shell { position: relative; z-index: 1; width: 100%; max-width: 420px; }
    .brand-row { display:flex; align-items:center; justify-content:center; gap:12px; margin-bottom:20px; animation: fadeDown .6s ease; }
    .brand-row .brand-mark { width:44px; height:44px; border-radius:14px; background:rgba(255,255,255,.92); display:flex; align-items:center; justify-content:center; box-shadow:0 10px 25px rgba(5,18,48,.35); flex-shrink:0; padding:6px; }
    .brand-mark-img { width:100%; height:100%; object-fit:contain; display:block; }
    .brand-row .brand-text { color:#fff; line-height:1.2; }
    .brand-row .brand-text b { display:block; font-size:14.5px; font-weight:700; letter-spacing:.3px; }
    .brand-row .brand-text span { display:block; font-size:10px; font-weight:500; color:rgba(255,255,255,.6); letter-spacing:.5px; text-transform:uppercase; }
    @keyframes fadeDown { from{opacity:0; transform:translateY(-10px);} to{opacity:1; transform:translateY(0);} }
    .auth-card {
        width:100%; background:rgba(255,255,255,0.07);
        backdrop-filter: blur(22px); -webkit-backdrop-filter: blur(22px);
        border:1px solid rgba(255,255,255,0.14); border-radius:26px;
        padding:36px 28px; color:#fff; box-shadow:0 25px 60px rgba(0,0,0,0.45), inset 0 1px 0 rgba(255,255,255,.08);
        animation: floatIn .55s cubic-bezier(.2,.8,.2,1);
    }
    @keyframes floatIn { from{opacity:0; transform:translateY(16px);} to{opacity:1; transform:translateY(0);} }
    .auth-icon { width:56px; height:56px; border-radius:16px; background:rgba(59,50,179,.35); display:flex; align-items:center; justify-content:center; font-size:22px; margin:0 auto 18px; }
    h1 { font-size:20px; font-weight:700; text-align:center; margin:0 0 6px; }
    p.sub { text-align:center; color:rgba(255,255,255,.7); font-size:13px; margin:0 0 26px; }
    .form-label { font-size:12px; font-weight:600; color:rgba(255,255,255,.75); margin-bottom:6px; }
    .form-control {
        background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.18); color:#fff;
        border-radius:12px; padding:12px 14px; font-size:15px;
    }
    .form-control::placeholder { color:rgba(255,255,255,.45); }
    .form-control:focus { background:rgba(255,255,255,.12); border-color:#7c72e0; box-shadow:0 0 0 3px rgba(124,114,224,.25); color:#fff; }
    .btn-primary-glow {
        width:100%; background:linear-gradient(135deg,#4338ca,#0ea5e9); border:none; color:#fff;
        padding:13.5px; border-radius:13px; font-weight:700; font-size:15px; margin-top:8px;
        display:flex; align-items:center; justify-content:center; gap:8px;
        box-shadow: 0 12px 28px rgba(59,50,179,.4);
        transition: transform .18s ease, box-shadow .18s ease, opacity .18s ease;
    }
    .btn-primary-glow:hover { transform:translateY(-2px); box-shadow:0 16px 34px rgba(59,50,179,.5); }
    .btn-primary-glow:disabled { opacity:.75; cursor:progress; transform:none; }
    .btn-primary-glow .fa-spinner { animation: spin .7s linear infinite; }
    @keyframes spin { to { transform: rotate(360deg); } }
    .back-link { display:block; text-align:center; margin-top:20px; color:rgba(255,255,255,.65); font-size:13px; text-decoration:none; transition: color .15s ease; }
    .back-link:hover { color:#fff; }
    .alert { border-radius:12px; font-size:13px; }
</style>
</head>
<body>
    <div class="bg-glow one"></div>
    <div class="bg-glow two"></div>
    <div class="auth-shell">
        <div class="brand-row">
            <div class="brand-mark"><img src="assets/images/logo.png" alt="YMR Survey logo" class="brand-mark-img"></div>
            <div class="brand-text"><b>YMR SURVEY</b><span>Management System</span></div>
        </div>
        <div class="auth-card">
            <div class="auth-icon"><i class="fa-solid fa-key"></i></div>
            <h1>Forgot Password</h1>
            <p class="sub">Enter your registered email and we'll send you a secure link to reset your password.</p>

            <?php if ($message): ?>
                <div class="alert alert-<?= $messageType === 'danger' ? 'danger' : ($messageType === 'success' ? 'success' : 'info') ?> py-2 mb-3"><?= sanitize($message) ?></div>
            <?php endif; ?>

            <form action="forgot_password.php" method="POST" novalidate id="forgotForm"><?= csrf_field() ?>
                <label class="form-label" for="email">Email Address</label>
                <input type="email" class="form-control mb-3" id="email" name="email" placeholder="you@company.com" required autofocus>
                <button type="submit" class="btn-primary-glow" id="forgotSubmitBtn"><i class="fa-solid fa-paper-plane"></i>Send Reset Link</button>
            </form>

            <a href="login.php" class="back-link"><i class="fa-solid fa-arrow-left me-1"></i> Back to Login</a>
        </div>
    </div>
    <script>
        document.getElementById('forgotForm').addEventListener('submit', function () {
            var btn = document.getElementById('forgotSubmitBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner"></i> Sending&hellip;';
        });
    </script>
</body>
</html>

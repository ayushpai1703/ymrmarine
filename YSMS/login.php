<?php
require_once 'config/config.php';

// 🌟 Safety-net: ensure the Super Admin role exists (see database/migration_super_admin_role.sql)
try {
    $db_boot = getDB();
    $chk = $db_boot->query("SELECT id FROM roles WHERE name = 'Super Admin'")->fetchColumn();
    if (!$chk) {
        $db_boot->exec("INSERT INTO roles (name) VALUES ('Super Admin')");
    }
} catch (Throwable $e) { error_log('login.php super admin role ensure: ' . $e->getMessage()); }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    $db = getDB();
    $stmt = $db->prepare("SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.username = ? AND u.status = 'Active'");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    // ప్లెయిన్ టెక్స్ట్ లేదా హ్యాష్... ఏది మ్యాచ్ అయినా లాగిన్ అనుమతిస్తుంది
    if ($user && ($password === $user['password'] || password_verify($password, $user['password']) || hash_equals($user['password'], crypt($password, $user['password'])))) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role_name'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['avatar'] = !empty($user['avatar']) ? $user['avatar'] : (!empty($user['profile_pic']) ? $user['profile_pic'] : null);

        // 🌟 New-format popup: if Admin uploaded/replaced a format in the last 7 days,
        // show a one-time "please use these formats" popup to the surveyor on this login.
        if ($user['role_name'] === 'Surveyor') {
            $formats_dir = __DIR__ . '/formats';
            $latest_format_ts = 0;
            if (is_dir($formats_dir)) {
                foreach (glob($formats_dir . '/*') as $ff) {
                    if (is_file($ff)) $latest_format_ts = max($latest_format_ts, filemtime($ff));
                }
            }
            if ($latest_format_ts > 0 && (time() - $latest_format_ts) <= 7 * 86400) {
                $_SESSION['show_format_popup'] = true;
            }
        }

        header("Location: index.php");
        exit;
    } else {
        $error = "Invalid credential parameters. Please check username/password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Login | YMR Survey Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-bg: #0b1e46;
            --app-bg: #f4f6fa;
            --text-dark: #1e293b;
            --text-muted: #64748b;
            --accent-purple: #3b32b3;
        }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        html { overflow-x: hidden; }
        body {
            font-family: 'Lexend', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #06163a;
            margin: 0; padding: 0;
            min-height: 100vh; min-height: 100dvh;
            overflow-x: hidden;
        }

        /* ===== Split-screen shell ===== */
        .split-shell {
            display: flex;
            min-height: 100vh; min-height: 100dvh;
        }

        /* ---- Left: marine showcase panel (desktop only) ---- */
        .showcase-panel {
            display: none;
            position: relative;
            flex: 1 1 52%;
            overflow: hidden;
            background: #06163a;
        }
        .showcase-panel .showcase-bg {
            position: absolute; inset: 0;
            background: url('https://images.unsplash.com/photo-1566288623394-377af472d81b?auto=format&fit=crop&w=1400&q=80') center/cover no-repeat;
            transform: scale(1.04);
            animation: slowZoom 22s ease-in-out infinite alternate;
        }
        @keyframes slowZoom { from { transform: scale(1.04); } to { transform: scale(1.12); } }
        .showcase-panel .showcase-overlay {
            position: absolute; inset: 0;
            background:
                linear-gradient(180deg, rgba(6,22,58,.55) 0%, rgba(6,22,58,.4) 40%, rgba(5,13,34,.92) 100%),
                linear-gradient(120deg, rgba(59,50,179,.5) 0%, rgba(6,22,58,0) 55%);
        }
        .showcase-content {
            position: relative; z-index: 2;
            display: flex; flex-direction: column; justify-content: space-between;
            height: 100%; padding: 56px 56px 48px;
            color: #fff;
        }
        .showcase-brand { display: flex; align-items: center; gap: 14px; }
        .showcase-brand .brand-mark {
            width: 50px; height: 50px; border-radius: 15px;
            background: rgba(255,255,255,.12); backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,.25);
            display: flex; align-items: center; justify-content: center; font-size: 21px;
            flex-shrink: 0;
        }
        .showcase-brand b { display: block; font-size: 17px; font-weight: 700; letter-spacing: .3px; }
        .showcase-brand span { display: block; font-size: 11px; font-weight: 500; color: rgba(255,255,255,.65); letter-spacing: .5px; text-transform: uppercase; }

        .showcase-headline { max-width: 460px; }
        .showcase-headline h2 { font-size: 34px; font-weight: 700; line-height: 1.25; margin: 0 0 14px; }
        .showcase-headline p { font-size: 14.5px; color: rgba(255,255,255,.72); line-height: 1.6; margin: 0 0 26px; }

        .showcase-features { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 13px; }
        .showcase-features li { display: flex; align-items: center; gap: 11px; font-size: 13.5px; color: rgba(255,255,255,.88); }
        .showcase-features li i {
            width: 28px; height: 28px; border-radius: 9px; flex-shrink: 0;
            background: rgba(14,165,233,.22); border: 1px solid rgba(14,165,233,.4);
            display: flex; align-items: center; justify-content: center; font-size: 12px; color: #7dd3fc;
        }

        .showcase-footer { font-size: 11.5px; color: rgba(255,255,255,.4); }

        /* ---- Right: form panel ---- */
        .form-panel {
            flex: 1 1 48%;
            display: flex; align-items: center; justify-content: center;
            background: radial-gradient(circle at 85% 15%, #12275c 0%, #06163a 50%, #030b21 100%);
            position: relative;
            padding: 32px 20px;
        }
        .bg-glow { position: absolute; border-radius: 50%; filter: blur(90px); opacity: .3; z-index: 0; pointer-events: none; }
        .bg-glow.one { width: 320px; height: 320px; background: #3b32b3; top: -60px; right: -60px; }
        .bg-glow.two { width: 260px; height: 260px; background: #0ea5e9; bottom: -40px; left: -40px; }

        .auth-shell {
            position: relative; z-index: 1;
            width: 100%; max-width: 400px;
        }

        .brand-row {
            display: none;
            align-items: center; justify-content: center; gap: 12px;
            margin-bottom: 24px; animation: fadeDown .6s ease;
        }
        .brand-row .brand-mark {
            width: 48px; height: 48px; border-radius: 15px;
            background: linear-gradient(135deg, #4338ca, #0ea5e9);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 20px; box-shadow: 0 10px 25px rgba(59,50,179,.45);
            flex-shrink: 0;
        }
        .brand-row .brand-text { color: #fff; line-height: 1.2; }
        .brand-row .brand-text b { display: block; font-size: 16px; font-weight: 700; letter-spacing: .3px; }
        .brand-row .brand-text span { display: block; font-size: 10.5px; font-weight: 500; color: rgba(255,255,255,.6); letter-spacing: .5px; text-transform: uppercase; }

        /* 🌟 Glass Card */
        .glass-card {
            background: rgba(255,255,255,0.065);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(255,255,255,0.14);
            border-radius: 28px;
            padding: 34px 28px;
            box-shadow: 0 30px 70px rgba(0,0,0,.5), inset 0 1px 0 rgba(255,255,255,.08);
            animation: floatUp .55s cubic-bezier(.2,.8,.2,1);
        }

        h1.welcome-title { color: #fff; font-size: 22px; font-weight: 700; margin: 0 0 4px; text-align: center; letter-spacing: -.2px; }
        p.welcome-sub { color: rgba(255,255,255,.6); font-size: 13px; text-align: center; margin: 0 0 28px; }

        .field-group { margin-bottom: 17px; opacity: 0; animation: fieldIn .5s ease forwards; }
        .field-group:nth-of-type(1) { animation-delay: .08s; }
        .field-group:nth-of-type(2) { animation-delay: .16s; }
        @keyframes fieldIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }

        .field-label { display: block; font-size: 11.5px; font-weight: 600; color: rgba(255,255,255,.72); margin-bottom: 7px; letter-spacing: .3px; text-transform: uppercase; }
        .field-wrap { position: relative; }
        .field-wrap i.field-icon { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: rgba(255,255,255,.5); font-size: 15px; transition: color .18s ease; }
        .field-wrap input {
            width: 100%; background: rgba(255,255,255,.07); border: 1.5px solid rgba(255,255,255,.14);
            border-radius: 14px; padding: 13px 14px 13px 42px; font-size: 15px; color: #fff; outline: none;
            transition: border-color .18s ease, background-color .18s ease, box-shadow .18s ease;
        }
        .field-wrap input::placeholder { color: rgba(255,255,255,.38); }
        .field-wrap input:focus { border-color: #7c72e0; background: rgba(255,255,255,.11); box-shadow: 0 0 0 4px rgba(124,114,224,.18); }
        .field-wrap input:focus ~ i.field-icon,
        .field-wrap:focus-within i.field-icon { color: #a5b4fc; }
        .field-wrap .toggle-pass { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); color: rgba(255,255,255,.5); cursor: pointer; background: none; border: none; padding: 6px; font-size: 14px; }
        .field-wrap .toggle-pass:hover { color: #fff; }

        .options-row { display: flex; justify-content: space-between; align-items: center; margin: 2px 0 24px; font-size: 12.5px; }
        .remember-label { display: flex; align-items: center; gap: 7px; color: rgba(255,255,255,.7); cursor: pointer; user-select: none; }
        .remember-label input { accent-color: #7c72e0; width: 15px; height: 15px; }
        .forgot-link { color: #a5b4fc; text-decoration: none; font-weight: 600; transition: color .15s ease; }
        .forgot-link:hover { color: #fff; }

        .login-submit-btn {
            width: 100%; background: linear-gradient(135deg,#4338ca,#0ea5e9); color: #fff; border: none;
            padding: 14.5px; border-radius: 15px; font-size: 15.5px; font-weight: 700; letter-spacing: .2px;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            box-shadow: 0 12px 28px rgba(59,50,179,.4);
            transition: transform .18s ease, box-shadow .18s ease, opacity .18s ease;
            position: relative;
        }
        .login-submit-btn:hover { transform: translateY(-2px); box-shadow: 0 16px 34px rgba(59,50,179,.5); }
        .login-submit-btn:active { transform: translateY(0) scale(.98); }
        .login-submit-btn:disabled { opacity: .75; cursor: progress; transform: none; }
        .login-submit-btn .fa-spinner { animation: spin .7s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .auth-alert {
            border: 1px solid rgba(248,113,113,.35); background: rgba(248,113,113,.12); color: #fecaca;
            border-radius: 12px; padding: 10px 14px; font-size: 12.5px; margin-bottom: 18px;
            display: flex; align-items: center; gap: 8px;
            animation: shake .4s ease;
        }
        @keyframes shake { 10%,90%{transform:translateX(-1px);} 20%,80%{transform:translateX(2px);} 30%,50%,70%{transform:translateX(-4px);} 40%,60%{transform:translateX(4px);} }

        .footer-note { text-align: center; color: rgba(255,255,255,.4); font-size: 11px; margin-top: 20px; }

        @keyframes floatUp { from { opacity: 0; transform: translateY(22px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes fadeDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

        @media (max-width: 380px) {
            .glass-card { padding: 28px 20px; border-radius: 22px; }
        }

        /* ---- Desktop: reveal split layout ---- */
        @media (min-width: 992px) {
            .showcase-panel { display: block; }
            .brand-row { display: flex; }
            .form-panel { flex: 1 1 48%; }
            .auth-shell { max-width: 400px; margin: 0 auto; }
        }
        @media (min-width: 1200px) {
            .showcase-content { padding: 64px; }
            .showcase-headline h2 { font-size: 38px; }
        }
    </style>
</head>
<body>
    <div class="split-shell">
        <!-- Left showcase panel: desktop only -->
        <div class="showcase-panel">
            <div class="showcase-bg"></div>
            <div class="showcase-overlay"></div>
            <div class="showcase-content">
                <div class="showcase-brand">
                    <div class="brand-mark"><i class="fa-solid fa-anchor"></i></div>
                    <div>
                        <b>YMR SURVEY</b>
                        <span>Management System</span>
                    </div>
                </div>

                <div class="showcase-headline">
                    <h2>Marine surveys,<br>managed end&#8209;to&#8209;end.</h2>
                    <p>One workspace for job assignment, on-site reporting, AI-assisted photo analysis, and client delivery — built for surveyors on the move.</p>
                    <ul class="showcase-features">
                        <li><i class="fa-solid fa-camera"></i> AI-assisted photo classification for faster reports</li>
                        <li><i class="fa-solid fa-file-shield"></i> Secure, role-based access across your team</li>
                        <li><i class="fa-solid fa-gauge-high"></i> Real-time job tracking from assignment to delivery</li>
                    </ul>
                </div>

                <div class="showcase-footer">&copy; <?= date('Y') ?> YMR Marine Solutions LLP. All rights reserved.</div>
            </div>
        </div>

        <!-- Right form panel -->
        <div class="form-panel">
            <div class="bg-glow one"></div>
            <div class="bg-glow two"></div>

            <div class="auth-shell">
                <div class="brand-row">
                    <div class="brand-mark"><i class="fa-solid fa-anchor"></i></div>
                    <div class="brand-text">
                        <b>YMR SURVEY</b>
                        <span>Management System</span>
                    </div>
                </div>

                <div class="glass-card">
                    <h1 class="welcome-title">Welcome back</h1>
                    <p class="welcome-sub">Sign in to continue to your dashboard</p>

                    <?php if ($error): ?>
                        <div class="auth-alert"><i class="fa-solid fa-circle-exclamation"></i><span><?= sanitize($error) ?></span></div>
                    <?php endif; ?>

                    <form action="login.php" method="POST" novalidate id="loginForm">
                        <div class="field-group">
                            <label class="field-label" for="username">Username</label>
                            <div class="field-wrap">
                                <i class="fa-regular fa-user field-icon"></i>
                                <input type="text" id="username" name="username" placeholder="Enter your username" autocomplete="username" required>
                            </div>
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="passInput">Password</label>
                            <div class="field-wrap">
                                <i class="fa-solid fa-lock field-icon"></i>
                                <input type="password" name="password" id="passInput" placeholder="Enter your password" autocomplete="current-password" required>
                                <button type="button" class="toggle-pass" id="togglePassBtn" aria-label="Show password"><i class="fa-regular fa-eye"></i></button>
                            </div>
                        </div>

                        <div class="options-row">
                            <label class="remember-label">
                                <input type="checkbox" name="remember"> Remember me
                            </label>
                            <a href="forgot_password.php" class="forgot-link">Forgot Password?</a>
                        </div>

                        <button type="submit" class="login-submit-btn" id="loginSubmitBtn"><i class="fa-solid fa-right-to-bracket"></i> Sign In</button>
                    </form>
                </div>

                <p class="footer-note">&copy; <?= date('Y') ?> YMR Marine Solutions LLP. All rights reserved.</p>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('togglePassBtn').addEventListener('click', function () {
            var input = document.getElementById('passInput');
            var icon = this.querySelector('i');
            var isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            icon.classList.toggle('fa-eye', !isHidden);
            icon.classList.toggle('fa-eye-slash', isHidden);
            this.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        });

        document.getElementById('loginForm').addEventListener('submit', function () {
            var btn = document.getElementById('loginSubmitBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner"></i> Signing in&hellip;';
        });
    </script>
</body>
</html>

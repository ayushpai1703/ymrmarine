<?php
require_once 'config/config.php';
require_once 'includes/email_account.php';
require_once 'includes/imap_client.php';
checkAuth();
if (($_SESSION['role'] ?? '') !== 'Admin') {
    header('Location: index.php');
    exit;
}

$db = getDB();
ensureEmailIntegrationTables($db);

$message = '';
$error = '';
$test_result = null; // ['ok'=>bool,'message'=>string,'folder'=>string,'recent'=>int|null]

$account = getEmailAccount($db);

$fetch_limit_options = [10, 20, 50];
$fetch_period_options = [24 => 'Last 24 hours', 72 => 'Last 3 days', 168 => 'Last 7 days'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fields = [
        'label'                => 'Primary Survey Mailbox',
        'mailbox_email'        => trim($_POST['mailbox_email'] ?? 'survey@ymrmarine.com'),
        'provider'             => in_array($_POST['provider'] ?? '', ['gmail', 'm365', 'cpanel'], true) ? $_POST['provider'] : 'cpanel',
        'imap_host'            => trim($_POST['imap_host'] ?? ''),
        'imap_port'            => (int)($_POST['imap_port'] ?? 993) ?: 993,
        'imap_encryption'      => in_array($_POST['imap_encryption'] ?? '', ['ssl', 'tls', 'none'], true) ? $_POST['imap_encryption'] : 'ssl',
        'auth_type'            => in_array($_POST['auth_type'] ?? '', ['app_password', 'oauth2'], true) ? $_POST['auth_type'] : 'app_password',
        'imap_username'        => trim($_POST['imap_username'] ?? ''),
        'folder'               => trim($_POST['folder'] ?? 'INBOX') ?: 'INBOX',
        'fetch_limit'          => in_array((int)($_POST['fetch_limit'] ?? 20), $fetch_limit_options, true) ? (int)$_POST['fetch_limit'] : 20,
        'fetch_period_hours'   => array_key_exists((int)($_POST['fetch_period_hours'] ?? 24), $fetch_period_options) ? (int)$_POST['fetch_period_hours'] : 24,
        'auto_refresh_seconds' => max(0, (int)($_POST['auto_refresh_seconds'] ?? 60)),
    ];
    $newSecret = trim($_POST['imap_secret'] ?? '');

    if (isset($_POST['save_settings'])) {
        if ($fields['mailbox_email'] === '' || !filter_var($fields['mailbox_email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid mailbox email address.';
        } elseif ($fields['imap_host'] === '') {
            $error = 'Please enter the incoming IMAP server hostname (ask your email hosting provider — do not guess it).';
        } elseif ($fields['imap_username'] === '') {
            $error = 'Please enter the IMAP username (usually the same as the mailbox email address).';
        } else {
            try {
                saveEmailAccount($db, $fields, $newSecret !== '' ? $newSecret : null);
                logEmailAudit($db, $account['id'] ?? null, 'save_settings', 'host=' . $fields['imap_host'] . ' user=' . $fields['imap_username']);
                $message = 'Email integration settings saved.';
                $account = getEmailAccount($db);
            } catch (Throwable $e) {
                error_log('admin_email_integration save: ' . $e->getMessage());
                $error = 'Could not save settings. Please try again.';
            }
        }
    } elseif (isset($_POST['test_connection'])) {
        // Test with whatever is currently in the form (unsaved changes included),
        // falling back to the already-saved secret if the password field was left blank.
        $testAccount = $fields;
        $testAccount['imap_secret'] = $newSecret !== '' ? $newSecret : ysmsEmailDecrypt((string)($account['imap_secret_enc'] ?? ''));
        $test_result = imapTestConnection($testAccount);
        if (!empty($account['id'])) {
            recordEmailTestResult($db, (int)$account['id'], $test_result['ok'], $test_result['message']);
        }
        logEmailAudit($db, $account['id'] ?? null, 'test_connection', ($test_result['ok'] ? 'success' : 'failed') . ': ' . $test_result['message']);
        $account = getEmailAccount($db);
    }
}

$account = $account ?: getEmailAccount($db);

include 'includes/header.php';
?>

<style>
    .eint-box {
        background: #fff;
        margin: 15px 20px;
        border-radius: 16px;
        padding: 20px;
        border: 1px solid var(--border-color);
    }
    .eint-box .form-group-custom { margin-bottom: 15px; }
    .eint-box label { font-size: 12px; font-weight: 600; color: var(--text-muted); margin-bottom: 5px; display: block; }
    .eint-box input, .eint-box select {
        width: 100%; border: 1px solid var(--border-color); border-radius: 10px;
        padding: 10px 12px; font-size: 13.5px; color: var(--text-dark); background: #f8fafc;
    }
    .eint-box input:focus, .eint-box select:focus { border-color: #3b32b3; background: #fff; outline: none; }
    .eint-locked { background: #f1f5f9 !important; color: #0b1e46; font-weight: 700; }
    .eint-hint { font-size: 11px; color: var(--text-muted); margin-top: 4px; }
    .eint-source-badge {
        display: inline-flex; align-items: center; gap: 6px; background: #eef2ff; color: #3b32b3;
        font-weight: 700; font-size: 12px; padding: 6px 12px; border-radius: 999px; margin: 0 20px 6px;
    }
    .eint-status-pill {
        display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 700;
        padding: 4px 10px; border-radius: 999px;
    }
    .eint-status-ok { background: #dcfce7; color: #15803d; }
    .eint-status-fail { background: #fee2e2; color: #b91c1c; }
    .eint-status-unknown { background: #f1f5f9; color: #64748b; }
    .eint-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 6px; }
    .eint-actions button { flex: 1 1 160px; }
    .eint-test-result { margin-top: 14px; padding: 12px 14px; border-radius: 10px; font-size: 13px; }
    .eint-test-ok { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
    .eint-test-fail { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
    @media (min-width: 992px) {
        .eint-box { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 20px; max-width: 900px; margin: 20px auto; padding: 28px; }
        .eint-box > .form-group-custom.full,
        .eint-box > .eint-actions,
        .eint-box > .eint-test-result,
        .eint-box > .alert { grid-column: 1 / -1; }
        .eint-source-badge { margin-left: auto; margin-right: auto; display: flex; width: fit-content; }
    }
</style>

<div class="scroll-content">
    <?php $page_title = 'Email Integration'; $back_url = 'assign_vessel.php'; $page_testid = 'admin-email-integration'; include 'includes/top_app_bar.php'; ?>

    <div class="eint-source-badge" data-testid="eint-source-badge">
        <i class="fa-solid fa-envelope-circle-check"></i> Incoming source: survey@ymrmarine.com
    </div>

    <?php if ($message): ?><div class="alert alert-success mx-3 py-2" style="font-size:12.5px;" data-testid="eint-save-success"><?= sanitize($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger mx-3 py-2" style="font-size:12.5px;" data-testid="eint-save-error"><?= sanitize($error) ?></div><?php endif; ?>

    <?php if ($account && !empty($account['last_test_status'])): ?>
        <div class="mx-3 mb-1">
            Connection Status:
            <span class="eint-status-pill <?= $account['last_test_status'] === 'success' ? 'eint-status-ok' : 'eint-status-fail' ?>" data-testid="eint-connection-status">
                <i class="fa-solid fa-circle" style="font-size:8px;"></i>
                <?= $account['last_test_status'] === 'success' ? 'Connected' : 'Not Connected' ?>
            </span>
            <span class="text-muted" style="font-size:11px;"> — last checked <?= !empty($account['last_test_at']) ? sanitize(date('d M Y, h:i A', strtotime($account['last_test_at']))) : 'never' ?></span>
        </div>
    <?php endif; ?>

    <form method="POST" id="emailIntegrationForm">
        <?= csrf_field() ?>
        <div class="eint-box shadow-sm">

            <div class="form-group-custom full">
                <label>Mailbox Email Address</label>
                <input type="email" name="mailbox_email" value="<?= sanitize($account['mailbox_email'] ?? 'survey@ymrmarine.com') ?>" required data-testid="eint-mailbox-email">
                <div class="eint-hint">The only mailbox this feature reads from. Sending (assignment notifications, reports) still uses the existing SMTP setup — unchanged.</div>
            </div>

            <div class="form-group-custom">
                <label>Email Provider</label>
                <select name="provider" id="eintProvider" data-testid="eint-provider">
                    <option value="gmail" <?= ($account['provider'] ?? '') === 'gmail' ? 'selected' : '' ?>>Gmail / Google Workspace</option>
                    <option value="m365" <?= ($account['provider'] ?? '') === 'm365' ? 'selected' : '' ?>>Microsoft 365 / Outlook</option>
                    <option value="cpanel" <?= ($account['provider'] ?? 'cpanel') === 'cpanel' ? 'selected' : '' ?>>cPanel / Other IMAP</option>
                </select>
            </div>

            <div class="form-group-custom">
                <label>Authentication</label>
                <select name="auth_type" data-testid="eint-auth-type">
                    <option value="app_password" <?= ($account['auth_type'] ?? 'app_password') === 'app_password' ? 'selected' : '' ?>>App Password / Mailbox Password</option>
                    <option value="oauth2" <?= ($account['auth_type'] ?? '') === 'oauth2' ? 'selected' : '' ?>>OAuth 2.0 (coming soon)</option>
                </select>
            </div>

            <div class="form-group-custom">
                <label>Incoming Server / IMAP Host</label>
                <input type="text" name="imap_host" id="eintHost" value="<?= sanitize($account['imap_host'] ?? '') ?>" placeholder="e.g. mail.yourhostingprovider.com" required data-testid="eint-imap-host">
                <div class="eint-hint">Enter the real hostname from your provider — never guessed automatically.</div>
            </div>

            <div class="form-group-custom">
                <label>IMAP Port</label>
                <input type="number" name="imap_port" id="eintPort" value="<?= (int)($account['imap_port'] ?? 993) ?>" required data-testid="eint-imap-port">
            </div>

            <div class="form-group-custom">
                <label>Encryption</label>
                <select name="imap_encryption" data-testid="eint-encryption">
                    <option value="ssl" <?= ($account['imap_encryption'] ?? 'ssl') === 'ssl' ? 'selected' : '' ?>>SSL/TLS</option>
                    <option value="tls" <?= ($account['imap_encryption'] ?? '') === 'tls' ? 'selected' : '' ?>>STARTTLS</option>
                    <option value="none" <?= ($account['imap_encryption'] ?? '') === 'none' ? 'selected' : '' ?>>None</option>
                </select>
            </div>

            <div class="form-group-custom">
                <label>IMAP Username</label>
                <input type="text" name="imap_username" value="<?= sanitize($account['imap_username'] ?? 'survey@ymrmarine.com') ?>" required data-testid="eint-imap-username">
            </div>

            <div class="form-group-custom">
                <label>App Password / Mailbox Password</label>
                <input type="password" name="imap_secret" placeholder="<?= !empty($account['imap_secret_enc']) ? '•••••••• (leave blank to keep current)' : 'Enter password' ?>" autocomplete="new-password" data-testid="eint-imap-secret">
                <div class="eint-hint">Stored encrypted on the server. Never shown again, never sent to the browser, never logged.</div>
            </div>

            <div class="form-group-custom">
                <label>Email Folder</label>
                <input type="text" name="folder" value="<?= sanitize($account['folder'] ?? 'INBOX') ?>" data-testid="eint-folder">
            </div>

            <div class="form-group-custom">
                <label>Fetch Emails</label>
                <select name="fetch_limit" data-testid="eint-fetch-limit">
                    <?php foreach ($fetch_limit_options as $opt): ?>
                        <option value="<?= $opt ?>" <?= (int)($account['fetch_limit'] ?? 20) === $opt ? 'selected' : '' ?>>Latest <?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group-custom">
                <label>Read Emails From</label>
                <select name="fetch_period_hours" data-testid="eint-fetch-period">
                    <?php foreach ($fetch_period_options as $hrs => $lbl): ?>
                        <option value="<?= $hrs ?>" <?= (int)($account['fetch_period_hours'] ?? 24) === $hrs ? 'selected' : '' ?>><?= sanitize($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group-custom">
                <label>Automatic Refresh (seconds, 0 = off)</label>
                <input type="number" name="auto_refresh_seconds" min="0" value="<?= (int)($account['auto_refresh_seconds'] ?? 60) ?>" data-testid="eint-auto-refresh">
            </div>

            <div class="eint-actions">
                <button type="submit" name="test_connection" value="1" class="btn btn-light border fw-bold" data-testid="eint-test-connection-btn">
                    <i class="fa-solid fa-plug"></i> Test Connection
                </button>
                <button type="submit" name="save_settings" value="1" class="blue-action-btn" style="background:#3b32b3;" data-testid="eint-save-settings-btn">
                    <i class="fa-solid fa-floppy-disk"></i> Save Configuration
                </button>
            </div>

            <?php if ($test_result !== null): ?>
                <div class="eint-test-result <?= $test_result['ok'] ? 'eint-test-ok' : 'eint-test-fail' ?>" data-testid="eint-test-result">
                    <?php if ($test_result['ok']): ?>
                        <div class="fw-bold mb-1"><i class="fa-solid fa-circle-check"></i> Connection successful</div>
                        <div>Mailbox: <?= sanitize($account['mailbox_email'] ?? '') ?></div>
                        <div>Folder: <?= sanitize($test_result['folder']) ?></div>
                        <div>Recent Emails: <?= $test_result['recent'] !== null ? (int)$test_result['recent'] : '—' ?></div>
                    <?php else: ?>
                        <div class="fw-bold mb-1"><i class="fa-solid fa-circle-xmark"></i> Connection failed</div>
                        <div>Reason: <?= sanitize($test_result['message']) ?></div>
                        <div class="eint-hint mt-1">Please check the incoming mail server, IMAP/API configuration, authentication, app password, and internet/server connectivity.</div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </form>
</div>

<script>
    document.getElementById('eintProvider')?.addEventListener('change', function () {
        var host = document.getElementById('eintHost');
        var port = document.getElementById('eintPort');
        if (host.value.trim() !== '') return; // never overwrite something the admin already typed
        if (this.value === 'gmail') { host.value = 'imap.gmail.com'; port.value = 993; }
        else if (this.value === 'm365') { host.value = 'outlook.office365.com'; port.value = 993; }
    });
</script>

<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>

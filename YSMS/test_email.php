<?php
/**
 * Admin-only SMTP test page.
 * Open while logged in as Admin: /test_email.php
 */
require_once 'config/config.php';
require_once 'includes/mailer.php';
require_once 'includes/gmail_service.php';
checkAuth();
if (($_SESSION['role'] ?? '') !== 'Admin') {
    die('Admin only.');
}

$result = '';
$resultType = 'info';
$to = '';
$gmailResult = '';
$gmailResultType = 'info';
$gmailFrom = '';
$gmailTo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['to_email'])) {
    $to = trim($_POST['to_email'] ?? '');
    $name = trim($_POST['to_name'] ?? 'Test User');
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $result = 'Enter a valid email address.';
        $resultType = 'danger';
    } else {
        $ok = sendHtmlEmail(
            $to,
            $name,
            'YSMS SMTP test',
            '<div style="font-family:Arial,sans-serif;padding:16px;"><h2>YSMS test email</h2><p>If you received this, SMTP is working.</p><p style="color:#64748b;font-size:12px;">Sent at ' . date('Y-m-d H:i:s') . '</p></div>',
            'YSMS test email — SMTP is working. Sent at ' . date('Y-m-d H:i:s')
        );
        if ($ok) {
            $result = 'SUCCESS — test email sent to ' . $to;
            $resultType = 'success';
        } else {
            $err = $GLOBALS['ysms_last_mail_error'] ?? 'Unknown error';
            $result = 'FAILED — ' . $err;
            $resultType = 'danger';
        }
    }
}

// Direct Gmail API test (no SMTP fallback) — shows the real delegation
// error for a specific "send as" address instead of silently falling back.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gmail_from'])) {
    $gmailFrom = trim($_POST['gmail_from'] ?? '');
    $gmailTo = trim($_POST['gmail_to'] ?? '');
    if ($gmailFrom === '' || !filter_var($gmailFrom, FILTER_VALIDATE_EMAIL) || $gmailTo === '' || !filter_var($gmailTo, FILTER_VALIDATE_EMAIL)) {
        $gmailResult = 'Enter valid From and To addresses.';
        $gmailResultType = 'danger';
    } else {
        $raw = "From: {$gmailFrom}\r\nTo: {$gmailTo}\r\nSubject: YSMS Gmail API test\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nGmail API direct test, sent at " . date('Y-m-d H:i:s') . "\r\n";
        $ok = gmailApiSendRaw($gmailFrom, $raw);
        if ($ok) {
            $gmailResult = 'SUCCESS — sent as ' . $gmailFrom . ' to ' . $gmailTo . '. Check ' . $gmailFrom . "'s Sent folder.";
            $gmailResultType = 'success';
        } else {
            $gmailResult = 'FAILED — ' . ($GLOBALS['ysms_last_mail_error'] ?? 'Unknown error');
            $gmailResultType = 'danger';
        }
    }
}

$cfg = [
    'SMTP_HOST' => defined('SMTP_HOST') ? SMTP_HOST : '?',
    'SMTP_PORT' => defined('SMTP_PORT') ? (string)SMTP_PORT : '?',
    'SMTP_SECURE' => defined('SMTP_SECURE') ? SMTP_SECURE : '?',
    'SMTP_USERNAME' => defined('SMTP_USERNAME') ? SMTP_USERNAME : '?',
    'SMTP_FROM_EMAIL' => defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : '?',
    'SMTP_PASSWORD' => (defined('SMTP_PASSWORD') && SMTP_PASSWORD !== '') ? ('(set, ' . strlen(SMTP_PASSWORD) . ' chars)') : '(EMPTY — this is why mail fails)',
];
$relayCfg = [
    'SMTP_RELAY_ENABLED' => defined('SMTP_RELAY_ENABLED') ? (SMTP_RELAY_ENABLED ? 'true' : 'false') : '?',
    'SMTP_RELAY_HOST' => defined('SMTP_RELAY_HOST') ? SMTP_RELAY_HOST : '?',
    'SMTP_RELAY_USERNAME' => defined('SMTP_RELAY_USERNAME') ? (SMTP_RELAY_USERNAME !== '' ? SMTP_RELAY_USERNAME : '(EMPTY)') : '?',
    'SMTP_RELAY_PASSWORD' => (defined('SMTP_RELAY_PASSWORD') && SMTP_RELAY_PASSWORD !== '') ? ('(set, ' . strlen(SMTP_RELAY_PASSWORD) . ' chars)') : '(EMPTY — dynamic From/relay won\'t work)',
];
$gmailCfg = [
    'GMAIL_API_ENABLED' => defined('GMAIL_API_ENABLED') ? (GMAIL_API_ENABLED ? 'true' : 'false') : '?',
    'GMAIL_SERVICE_ACCOUNT_EMAIL' => defined('GMAIL_SERVICE_ACCOUNT_EMAIL') ? GMAIL_SERVICE_ACCOUNT_EMAIL : '?',
    'GMAIL_API_WORKSPACE_DOMAIN' => defined('GMAIL_API_WORKSPACE_DOMAIN') ? GMAIL_API_WORKSPACE_DOMAIN : '?',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SMTP Test | YSMS</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4" style="max-width:520px;">
  <h1 class="h5 mb-3">YSMS SMTP Test</h1>
  <div class="card mb-3">
    <div class="card-body small">
      <div class="fw-semibold mb-2">Current config (default SMTP)</div>
      <?php foreach ($cfg as $k => $v): ?>
        <div><code><?= htmlspecialchars($k) ?></code> = <?= htmlspecialchars((string)$v) ?></div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="card mb-3">
    <div class="card-body small">
      <div class="fw-semibold mb-2">Workspace SMTP relay (fallback — assign/upload emails)</div>
      <?php foreach ($relayCfg as $k => $v): ?>
        <div><code><?= htmlspecialchars($k) ?></code> = <?= htmlspecialchars((string)$v) ?></div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="card mb-3">
    <div class="card-body small">
      <div class="fw-semibold mb-2">Gmail API (Domain-Wide Delegation — preferred, lands in sender's own Sent)</div>
      <?php foreach ($gmailCfg as $k => $v): ?>
        <div><code><?= htmlspecialchars($k) ?></code> = <?= htmlspecialchars((string)$v) ?></div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php if ($result): ?>
    <div class="alert alert-<?= htmlspecialchars($resultType) ?>"><?= htmlspecialchars($result) ?></div>
  <?php endif; ?>
  <form method="POST" class="card card-body mb-3"><?= csrf_field() ?>
    <label class="form-label">Send test email to</label>
    <input type="email" name="to_email" class="form-control mb-2" required value="<?= htmlspecialchars($to) ?>" placeholder="you@example.com">
    <input type="text" name="to_name" class="form-control mb-3" value="Test User" placeholder="Name">
    <button class="btn btn-primary w-100">Send test email</button>
  </form>

  <?php if ($gmailResult): ?>
    <div class="alert alert-<?= htmlspecialchars($gmailResultType) ?>"><?= htmlspecialchars($gmailResult) ?></div>
  <?php endif; ?>
  <form method="POST" class="card card-body mb-3"><?= csrf_field() ?>
    <label class="form-label">Gmail API direct test (no fallback — shows real error)</label>
    <input type="email" name="gmail_from" class="form-control mb-2" required value="<?= htmlspecialchars($gmailFrom) ?>" placeholder="Send as (e.g. surveyor1@ymrmarine.com)">
    <input type="email" name="gmail_to" class="form-control mb-3" required value="<?= htmlspecialchars($gmailTo) ?>" placeholder="Send to">
    <button class="btn btn-outline-primary w-100">Test Gmail API</button>
  </form>
  <a href="index.php" class="d-block text-center mt-3 small">Back</a>
</div>
</body>
</html>

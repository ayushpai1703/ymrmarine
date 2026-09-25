<?php
/**
 * Request Callback — public AJAX submission endpoint for the floating
 * button's popup form (includes/callback_widget.php).
 *
 * POST only. Returns JSON: {"success": true, "message": "..."} or
 * {"success": false, "message": "..."}.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/callback_helpers.php';

header('Content-Type: application/json; charset=UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!csrf_valid()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Your session has expired. Please refresh the page and try again.']);
    exit;
}

$fullName = trim((string)($_POST['full_name'] ?? ''));
$companyName = trim((string)($_POST['company_name'] ?? ''));
$mobileRaw = trim((string)($_POST['mobile_number'] ?? ''));
$altMobileRaw = trim((string)($_POST['alternate_mobile_number'] ?? ''));

// Never trust client-side validation alone — every rule the popup enforces
// in JS is re-checked here.
$errors = [];

if ($fullName === '') {
    $errors[] = 'Full Name is required.';
} elseif (mb_strlen($fullName) > 100) {
    $errors[] = 'Full Name is too long.';
}

if (mb_strlen($companyName) > 150) {
    $errors[] = 'Company Name is too long.';
}

$mobile = sanitizeMobileNumber($mobileRaw);
if ($mobile === '') {
    $errors[] = 'Mobile Number is required.';
} elseif (!isValidMobileNumber($mobile)) {
    $errors[] = 'Please enter a valid Mobile Number.';
}

$altMobile = $altMobileRaw !== '' ? sanitizeMobileNumber($altMobileRaw) : '';
if ($altMobileRaw !== '' && !isValidMobileNumber($altMobile)) {
    $errors[] = 'Please enter a valid Alternate Mobile Number.';
}

if (!empty($errors)) {
    echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
    exit;
}

// Basic spam/duplicate-submit protection — one request per IP per 30s.
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$ip = trim(explode(',', $ip)[0]);
if (callbackRequestOnCooldown($ip)) {
    echo json_encode(['success' => false, 'message' => 'You just submitted a request. Please wait a moment before trying again.']);
    exit;
}

try {
    $pdo = getDB();
    ensureCallbackRequestsTable($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO callback_requests (full_name, company_name, mobile_number, alternate_mobile_number, status)
         VALUES (?, ?, ?, ?, \'Pending\')'
    );
    $stmt->execute([$fullName, $companyName !== '' ? $companyName : null, $mobile, $altMobile !== '' ? $altMobile : null]);

    echo json_encode([
        'success' => true,
        'message' => "Thank you for contacting us. Our team will get in touch with you shortly.",
    ]);
} catch (Throwable $e) {
    error_log('callback_request.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Something went wrong. Please try again in a moment.']);
}

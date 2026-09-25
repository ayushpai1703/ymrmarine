<?php
/**
 * Request Callback feature — shared helpers.
 * Mirrors the ensureSiteVisitsTable() pattern in includes/analytics.php:
 * the table is created on demand (once per request) rather than requiring
 * a manual migration, matching this project's schema-evolution convention.
 */

function ensureCallbackRequestsTable(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS callback_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(100) NOT NULL,
            company_name VARCHAR(150) NULL,
            mobile_number VARCHAR(20) NOT NULL,
            alternate_mobile_number VARCHAR(20) NULL,
            status ENUM('Pending','Contacted','Closed') NOT NULL DEFAULT 'Pending',
            admin_notes TEXT NULL,
            called_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_status (status),
            KEY idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('ensureCallbackRequestsTable: ' . $e->getMessage());
    }
}

/**
 * Keep only digits and a leading + (Indian mobiles won't have one, but this
 * doesn't assume a single country). Returns '' if nothing usable is left.
 */
function sanitizeMobileNumber(string $raw): string {
    $raw = trim($raw);
    $hasPlus = strpos($raw, '+') === 0;
    $digits = preg_replace('/\D/', '', $raw);
    return $digits === '' ? '' : ($hasPlus ? '+' : '') . $digits;
}

/**
 * A valid mobile number: 7–15 digits (ITU E.164 range), optional leading +.
 */
function isValidMobileNumber(string $sanitized): bool {
    return (bool)preg_match('/^\+?\d{7,15}$/', $sanitized);
}

/**
 * Lightweight duplicate/spam-submit guard for the public callback form —
 * same shape as includes/rate_limit.php (temp-dir JSON files) but keyed by
 * IP so one visitor can't fire off repeated requests, without needing a
 * login identifier or a schema change. A callback request is infrequent by
 * nature, so a short per-IP cooldown is enough to stop double-clicks and
 * basic spam without getting in a real caller's way.
 */
function callbackRequestOnCooldown(string $ip, int $cooldownSeconds = 30): bool {
    $dir = sys_get_temp_dir() . '/ymr_callback_requests';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $file = $dir . '/' . sha1($ip) . '.json';
    if (is_file($file)) {
        $last = (int)@file_get_contents($file);
        if ($last > 0 && (time() - $last) < $cooldownSeconds) {
            return true;
        }
    }
    @file_put_contents($file, (string)time());
    return false;
}

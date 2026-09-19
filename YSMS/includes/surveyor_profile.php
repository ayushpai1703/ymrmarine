<?php
/**
 * Admin Controls -> Surveyors: extended profile support.
 *
 * Self-healing column/table helpers, following the same pattern already used
 * throughout YSMS (add_surveyor.php, profile.php, admin_surveyor_cards.php):
 * ALTER TABLE / CREATE TABLE IF NOT EXISTS, safe to call on every request.
 *
 * Reuses the existing `users` table (role_id = 2 for Surveyor) and existing
 * columns (first_name, last_name, dob, date_of_joining, phone, email,
 * username, status, profile_pic, avatar) — only adds columns that don't
 * already exist anywhere else in the app.
 */

/**
 * Ensures every extended Surveyor profile column exists on `users`.
 * Returns the set of column names now present (lowercased keys).
 */
function ensureSurveyorProfileColumns(PDO $db): array {
    static $cached = null;
    if ($cached !== null) return $cached;

    $cols = [];
    try {
        foreach ($db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $cols[strtolower($c['Field'])] = true;
        }
    } catch (Exception $e) {
        error_log('ensureSurveyorProfileColumns SHOW: ' . $e->getMessage());
        return $cols;
    }

    // name => column definition
    $wanted = [
        // Personal
        'gender'                   => "VARCHAR(20) DEFAULT NULL",
        'nationality'               => "VARCHAR(80) DEFAULT NULL",
        'address'                   => "VARCHAR(255) DEFAULT NULL",
        'city'                      => "VARCHAR(100) DEFAULT NULL",
        'state'                     => "VARCHAR(100) DEFAULT NULL",
        'country'                   => "VARCHAR(100) DEFAULT NULL",
        'pincode'                   => "VARCHAR(20) DEFAULT NULL",
        // Contact
        'alternate_phone'           => "VARCHAR(30) DEFAULT NULL",
        'emergency_contact_name'    => "VARCHAR(150) DEFAULT NULL",
        'emergency_contact_phone'   => "VARCHAR(30) DEFAULT NULL",
        // Employment
        'employee_id'               => "VARCHAR(50) DEFAULT NULL",
        'designation'               => "VARCHAR(100) DEFAULT NULL",
        'department'                => "VARCHAR(100) DEFAULT NULL",
        'reporting_manager_id'      => "INT DEFAULT NULL",
        'office_location'           => "VARCHAR(150) DEFAULT NULL",
        // Marine / Surveyor
        'surveyor_type'             => "VARCHAR(100) DEFAULT NULL",
        'license_number'            => "VARCHAR(100) DEFAULT NULL",
        'license_issue_date'        => "DATE DEFAULT NULL",
        'license_expiry_date'       => "DATE DEFAULT NULL",
        'certification_details'     => "TEXT DEFAULT NULL",
        'experience'                => "VARCHAR(100) DEFAULT NULL",
        'port_coverage'             => "VARCHAR(255) DEFAULT NULL",
        'specialization'            => "VARCHAR(255) DEFAULT NULL",
    ];

    foreach ($wanted as $col => $def) {
        if (!isset($cols[$col])) {
            try {
                $db->exec("ALTER TABLE `users` ADD COLUMN `$col` $def");
                $cols[$col] = true;
            } catch (Exception $e) {
                error_log("ensureSurveyorProfileColumns ADD $col: " . $e->getMessage());
            }
        }
    }

    // `status` may still be the original MySQL ENUM('Active','Inactive') from
    // database/schema.sql — an ENUM silently rejects/truncates any value not
    // in its list, which would break the new 'Suspended' status. Widen it to
    // a plain VARCHAR (same default, all existing values preserved) so the
    // application-level validation in add_surveyor.php / admin_modules.php
    // is the only thing enforcing allowed values, matching how every other
    // status-like column in this codebase already works.
    try {
        $statusCol = $db->query("SHOW COLUMNS FROM users LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        if ($statusCol && stripos((string)$statusCol['Type'], 'enum') === 0 && stripos((string)$statusCol['Type'], 'suspended') === false) {
            $db->exec("ALTER TABLE users MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'Active'");
        }
    } catch (Throwable $e) {
        error_log('ensureSurveyorProfileColumns status widen: ' . $e->getMessage());
    }

    $cached = $cols;
    return $cols;
}

/**
 * Ensures the profile-change audit log table exists.
 */
function ensureSurveyorAuditLogTable(PDO $db): void {
    static $done = false;
    if ($done) return;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS `surveyor_profile_audit_log` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `surveyor_user_id` INT(11) NOT NULL,
          `admin_user_id` INT(11) DEFAULT NULL,
          `field_name` VARCHAR(100) NOT NULL,
          `old_value` TEXT NULL,
          `new_value` TEXT NULL,
          `ip_address` VARCHAR(64) DEFAULT NULL,
          `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_audit_surveyor` (`surveyor_user_id`, `changed_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('ensureSurveyorAuditLogTable: ' . $e->getMessage());
    }
    $done = true;
}

/**
 * Records one changed field. Never call this with password/hash values —
 * callers are responsible for excluding sensitive fields before invoking.
 */
function logSurveyorProfileChange(PDO $db, int $surveyorUserId, ?int $adminUserId, string $field, ?string $oldValue, ?string $newValue): void {
    if ($surveyorUserId <= 0) return;
    if ((string)$oldValue === (string)$newValue) return; // no actual change
    try {
        ensureSurveyorAuditLogTable($db);
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt = $db->prepare("INSERT INTO surveyor_profile_audit_log
            (surveyor_user_id, admin_user_id, field_name, old_value, new_value, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$surveyorUserId, $adminUserId, substr($field, 0, 100), $oldValue, $newValue, $ip]);
    } catch (Throwable $e) {
        error_log('logSurveyorProfileChange: ' . $e->getMessage());
    }
}

/**
 * Fetches recent change-history rows for a surveyor, newest first, with the
 * admin's display name resolved.
 */
function getSurveyorProfileHistory(PDO $db, int $surveyorUserId, int $limit = 50): array {
    try {
        ensureSurveyorAuditLogTable($db);
        $stmt = $db->prepare("
            SELECT l.field_name, l.old_value, l.new_value, l.changed_at,
                   u.full_name AS admin_name
            FROM surveyor_profile_audit_log l
            LEFT JOIN users u ON l.admin_user_id = u.id
            WHERE l.surveyor_user_id = ?
            ORDER BY l.changed_at DESC, l.id DESC
            LIMIT " . (int)$limit . "
        ");
        $stmt->execute([$surveyorUserId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('getSurveyorProfileHistory: ' . $e->getMessage());
        return [];
    }
}

/**
 * Human-friendly labels for audit log field names, used both when writing
 * history rows and when displaying them.
 */
function surveyorFieldLabel(string $field): string {
    $labels = [
        'full_name' => 'Full Name', 'first_name' => 'First Name', 'last_name' => 'Last Name',
        'username' => 'Username', 'email' => 'Email', 'phone' => 'Phone',
        'alternate_phone' => 'Alternate Phone', 'emergency_contact_name' => 'Emergency Contact Name',
        'emergency_contact_phone' => 'Emergency Contact Phone', 'dob' => 'Date of Birth',
        'gender' => 'Gender', 'nationality' => 'Nationality', 'address' => 'Address',
        'city' => 'City', 'state' => 'State', 'country' => 'Country', 'pincode' => 'PIN Code',
        'employee_id' => 'Employee ID', 'designation' => 'Designation', 'department' => 'Department',
        'date_of_joining' => 'Date of Joining', 'employment_status' => 'Employment Status',
        'status' => 'Status', 'reporting_manager_id' => 'Reporting Manager',
        'office_location' => 'Office / Location', 'surveyor_type' => 'Surveyor Type',
        'license_number' => 'License Number', 'license_issue_date' => 'License Issue Date',
        'license_expiry_date' => 'License Expiry Date', 'certification_details' => 'Certification Details',
        'experience' => 'Experience', 'port_coverage' => 'Port Coverage', 'specialization' => 'Specialization',
        'password_reset' => 'Password Reset',
    ];
    return $labels[$field] ?? ucwords(str_replace('_', ' ', $field));
}

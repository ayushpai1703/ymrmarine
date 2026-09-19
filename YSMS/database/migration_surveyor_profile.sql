-- Migration: extended Surveyor profile fields (Admin Controls -> Surveyors).
-- Purely additive. Safe to run once on the live database — existing data,
-- columns and roles are untouched. This is also applied automatically at
-- runtime (self-healing ALTER TABLE, same pattern as every other YSMS
-- migration) by includes/surveyor_profile.php, so running this file by hand
-- is optional; it exists for reference / manual DBA review.

ALTER TABLE `users`
    -- Personal
    ADD COLUMN IF NOT EXISTS `gender` VARCHAR(20) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `nationality` VARCHAR(80) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `address` VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `city` VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `state` VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `country` VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `pincode` VARCHAR(20) DEFAULT NULL,
    -- Contact
    ADD COLUMN IF NOT EXISTS `alternate_phone` VARCHAR(30) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `emergency_contact_name` VARCHAR(150) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `emergency_contact_phone` VARCHAR(30) DEFAULT NULL,
    -- Employment
    ADD COLUMN IF NOT EXISTS `employee_id` VARCHAR(50) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `designation` VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `department` VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `reporting_manager_id` INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `office_location` VARCHAR(150) DEFAULT NULL,
    -- Marine / Surveyor
    ADD COLUMN IF NOT EXISTS `surveyor_type` VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `license_number` VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `license_issue_date` DATE DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `license_expiry_date` DATE DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `certification_details` TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `experience` VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `port_coverage` VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `specialization` VARCHAR(255) DEFAULT NULL;

-- `status` may still be the original ENUM('Active','Inactive') from
-- database/schema.sql, which would reject/truncate the new 'Suspended'
-- value. Widen it to VARCHAR (all existing values are preserved); this is a
-- safe no-op if the column has already been widened. Allowed values are
-- enforced in application code (add_surveyor.php, config/admin_modules.php).
ALTER TABLE `users` MODIFY COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'Active';

-- Profile-change audit log (Admin Controls -> Surveyors -> Edit Profile).
CREATE TABLE IF NOT EXISTS `surveyor_profile_audit_log` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

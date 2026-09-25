-- ============================================================
-- Migration: Request Callback feature
-- Safe to run on live DB. The app also creates this table itself on
-- demand (CREATE TABLE IF NOT EXISTS, see includes/callback_helpers.php),
-- so running this by hand is optional — it's here for reference only.
-- ============================================================

CREATE TABLE IF NOT EXISTS callback_requests (
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
);

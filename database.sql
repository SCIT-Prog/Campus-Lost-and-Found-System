-- Campus Lost & Found schema.
-- Safe to re-run on an existing installation: every statement is idempotent,
-- new columns are only added when missing, and no existing rows are deleted.
-- Works on MariaDB (XAMPP) and MySQL 8. Import it with phpMyAdmin or:
--   mysql -u root < database.sql

CREATE DATABASE IF NOT EXISTS campus_lost_found
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE campus_lost_found;

-- ---------------------------------------------------------------------------
-- Original tables (unchanged definitions, kept for fresh installs)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS reports (
  id CHAR(36) NOT NULL PRIMARY KEY,
  report_type ENUM('lost', 'found') NOT NULL,
  category VARCHAR(50) NOT NULL,
  title VARCHAR(70) NOT NULL,
  location VARCHAR(90) NOT NULL,
  report_date DATE NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  contact VARCHAR(120) DEFAULT NULL,
  status ENUM('pending', 'active', 'resolved') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX reports_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Staff accounts. Create them with:  php tools/create-admin.php <username> [--role=staff|admin]
CREATE TABLE IF NOT EXISTS admins (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY admins_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Short-lived records used to throttle report submissions and login attempts.
CREATE TABLE IF NOT EXISTS rate_limits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  bucket VARCHAR(20) NOT NULL,
  key_hash CHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX rate_limits_lookup (bucket, key_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Version 3 upgrades: add missing columns without touching existing data.
-- Each block checks information_schema and only runs the ALTER when needed.
-- ---------------------------------------------------------------------------

-- reports.reference: short code people keep to look up their report (e.g. LF-7KQ2M9XA).
SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE reports ADD COLUMN reference VARCHAR(12) NULL AFTER id',
  'DO 0') FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'reports' AND column_name = 'reference');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- reports.photo / photo_mime: optional uploaded image, stored in storage/reports/.
SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE reports ADD COLUMN photo VARCHAR(80) NULL AFTER contact, ADD COLUMN photo_mime VARCHAR(20) NULL AFTER photo',
  'DO 0') FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'reports' AND column_name = 'photo');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- reports.status_changed_at: when staff last changed the status.
SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE reports ADD COLUMN status_changed_at TIMESTAMP NULL DEFAULT NULL AFTER status',
  'DO 0') FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'reports' AND column_name = 'status_changed_at');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Give every existing report a reference code, then make codes unique.
UPDATE reports SET reference = CONCAT('LF-', UPPER(SUBSTRING(REPLACE(id, '-', ''), 1, 8)))
  WHERE reference IS NULL OR reference = '';
SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE reports ADD UNIQUE KEY reports_reference (reference)',
  'DO 0') FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'reports' AND index_name = 'reports_reference');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- admins.role: existing accounts become administrators so nobody loses access.
SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE admins ADD COLUMN role ENUM(''admin'', ''staff'') NOT NULL DEFAULT ''admin'' AFTER password_hash',
  'DO 0') FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'admins' AND column_name = 'role');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- New accounts default to the more limited staff role.
ALTER TABLE admins MODIFY role ENUM('admin', 'staff') NOT NULL DEFAULT 'staff';

-- admins.full_name / email / is_active: shown on match assignments; email gets assignment notices.
SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE admins ADD COLUMN full_name VARCHAR(80) NULL AFTER role, ADD COLUMN email VARCHAR(120) NULL AFTER full_name, ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER email',
  'DO 0') FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'admins' AND column_name = 'full_name');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Version 3 tables
-- ---------------------------------------------------------------------------

-- Every status change made by staff.
CREATE TABLE IF NOT EXISTS report_status_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  report_id CHAR(36) NOT NULL,
  from_status VARCHAR(20) NULL,
  to_status VARCHAR(20) NOT NULL,
  changed_by INT UNSIGNED NULL,
  changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX rsh_report (report_id, changed_at),
  CONSTRAINT rsh_report_fk FOREIGN KEY (report_id) REFERENCES reports (id) ON DELETE CASCADE,
  CONSTRAINT rsh_admin_fk FOREIGN KEY (changed_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A lost report paired with a found report, optionally assigned to a staff member.
CREATE TABLE IF NOT EXISTS matches (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  lost_report_id CHAR(36) NOT NULL,
  found_report_id CHAR(36) NOT NULL,
  status ENUM('open', 'confirmed', 'dismissed') NOT NULL DEFAULT 'open',
  assigned_staff_id INT UNSIGNED NULL,
  assignment_status ENUM('unassigned', 'assigned', 'in_progress', 'completed') NOT NULL DEFAULT 'unassigned',
  assigned_by INT UNSIGNED NULL,
  assigned_at TIMESTAMP NULL DEFAULT NULL,
  notes VARCHAR(500) NOT NULL DEFAULT '',
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY matches_pair (lost_report_id, found_report_id),
  INDEX matches_staff (assigned_staff_id),
  CONSTRAINT matches_lost_fk FOREIGN KEY (lost_report_id) REFERENCES reports (id) ON DELETE CASCADE,
  CONSTRAINT matches_found_fk FOREIGN KEY (found_report_id) REFERENCES reports (id) ON DELETE CASCADE,
  CONSTRAINT matches_staff_fk FOREIGN KEY (assigned_staff_id) REFERENCES admins (id) ON DELETE SET NULL,
  CONSTRAINT matches_assigner_fk FOREIGN KEY (assigned_by) REFERENCES admins (id) ON DELETE SET NULL,
  CONSTRAINT matches_creator_fk FOREIGN KEY (created_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ownership claims submitted by the public for found items.
CREATE TABLE IF NOT EXISTS claims (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(12) NOT NULL,
  report_id CHAR(36) NOT NULL,
  claimant_name VARCHAR(80) NOT NULL,
  claimant_email VARCHAR(120) NOT NULL,
  claimant_phone VARCHAR(30) NULL,
  proof VARCHAR(1000) NOT NULL,
  proof_photo VARCHAR(80) NULL,
  proof_mime VARCHAR(20) NULL,
  status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  review_note VARCHAR(500) NULL,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY claims_reference (reference),
  INDEX claims_status_created (status, created_at),
  INDEX claims_report (report_id),
  CONSTRAINT claims_report_fk FOREIGN KEY (report_id) REFERENCES reports (id) ON DELETE CASCADE,
  CONSTRAINT claims_reviewer_fk FOREIGN KEY (reviewed_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS claim_status_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  claim_id INT UNSIGNED NOT NULL,
  from_status VARCHAR(20) NULL,
  to_status VARCHAR(20) NOT NULL,
  note VARCHAR(500) NULL,
  changed_by INT UNSIGNED NULL,
  changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX csh_claim (claim_id, changed_at),
  CONSTRAINT csh_claim_fk FOREIGN KEY (claim_id) REFERENCES claims (id) ON DELETE CASCADE,
  CONSTRAINT csh_admin_fk FOREIGN KEY (changed_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every email the system tried to send, with its delivery result.
CREATE TABLE IF NOT EXISTS email_notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  template VARCHAR(40) NOT NULL,
  recipient VARCHAR(120) NOT NULL,
  subject VARCHAR(200) NOT NULL,
  body_text TEXT NOT NULL,
  status ENUM('sent', 'failed') NOT NULL,
  error VARCHAR(500) NULL,
  item_title VARCHAR(70) NULL,
  report_id CHAR(36) NULL,
  claim_id INT UNSIGNED NULL,
  match_id INT UNSIGNED NULL,
  triggered_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX en_created (created_at),
  INDEX en_status (status, created_at),
  INDEX en_report (report_id),
  CONSTRAINT en_report_fk FOREIGN KEY (report_id) REFERENCES reports (id) ON DELETE SET NULL,
  CONSTRAINT en_claim_fk FOREIGN KEY (claim_id) REFERENCES claims (id) ON DELETE SET NULL,
  CONSTRAINT en_match_fk FOREIGN KEY (match_id) REFERENCES matches (id) ON DELETE SET NULL,
  CONSTRAINT en_admin_fk FOREIGN KEY (triggered_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

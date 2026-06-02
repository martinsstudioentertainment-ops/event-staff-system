-- Phase 25: admin user roles (admin / manager / staff)
-- Idempotent: run each statement separately; skip if column already exists.

-- email
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'email');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE admin_users ADD COLUMN email VARCHAR(255) NULL DEFAULT NULL AFTER full_name',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- role
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'role');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE admin_users ADD COLUMN role ENUM(''admin'', ''manager'', ''staff'') NOT NULL DEFAULT ''staff'' AFTER full_name',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE admin_users SET role = 'admin' WHERE role = 'staff' AND username = 'admin';

-- is_active
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'is_active');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE admin_users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- last_login_at
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'last_login_at');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE admin_users ADD COLUMN last_login_at TIMESTAMP NULL DEFAULT NULL AFTER is_active',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

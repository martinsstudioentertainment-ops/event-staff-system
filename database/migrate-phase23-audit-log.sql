-- Phase 23: admin audit log
CREATE TABLE IF NOT EXISTS admin_audit_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id        INT UNSIGNED NULL DEFAULT NULL,
    admin_username  VARCHAR(100) NOT NULL DEFAULT '',
    action          VARCHAR(80) NOT NULL,
    target_type     VARCHAR(40) NULL DEFAULT NULL,
    target_id       INT UNSIGNED NULL DEFAULT NULL,
    details         TEXT NULL,
    ip_address      VARCHAR(45) NULL DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_created (created_at DESC),
    INDEX idx_audit_action (action),
    INDEX idx_audit_admin (admin_id)
);

-- Phase 69: Native Android Mobile API infrastructure tables
-- Does NOT modify staff, staff_registrations, attendance, or any business tables.

CREATE TABLE IF NOT EXISTS mobile_refresh_tokens (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id     INT UNSIGNED NOT NULL,
    token_hash   CHAR(64) NOT NULL,
    device_id    VARCHAR(64) NOT NULL,
    device_label VARCHAR(128) NULL,
    user_agent   VARCHAR(255) NULL,
    expires_at   DATETIME NOT NULL,
    revoked_at   DATETIME NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    UNIQUE KEY uq_mobile_refresh_hash (token_hash),
    INDEX idx_mobile_refresh_staff (staff_id),
    INDEX idx_mobile_refresh_device (staff_id, device_id),
    INDEX idx_mobile_refresh_expires (expires_at),
    CONSTRAINT fk_mobile_refresh_staff
        FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS fcm_device_tokens (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id     INT UNSIGNED NOT NULL,
    device_id    VARCHAR(64) NOT NULL,
    fcm_token    VARCHAR(512) NOT NULL,
    platform     ENUM('android', 'ios') NOT NULL DEFAULT 'android',
    is_active    TINYINT(1) NOT NULL DEFAULT 1,
    last_seen_at DATETIME NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fcm_token (fcm_token(255)),
    UNIQUE KEY uq_fcm_staff_device (staff_id, device_id),
    INDEX idx_fcm_staff_active (staff_id, is_active),
    CONSTRAINT fk_fcm_staff
        FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS mobile_api_audit (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id    INT UNSIGNED NULL,
    endpoint    VARCHAR(128) NOT NULL,
    method      VARCHAR(8) NOT NULL,
    ip_address  VARCHAR(45) NULL,
    status_code SMALLINT UNSIGNED NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mobile_audit_staff (staff_id, created_at),
    INDEX idx_mobile_audit_endpoint (endpoint, created_at)
);

-- Optional performance indexes on existing tables (safe if already present)
-- MySQL 8.0.13+ supports IF NOT EXISTS on indexes; omit if unsupported on host.
-- CREATE INDEX idx_sr_staff_event ON staff_registrations (staff_id, event_id);
-- CREATE INDEX idx_app_notif_staff_unread ON app_notifications (staff_email, is_read, created_at);

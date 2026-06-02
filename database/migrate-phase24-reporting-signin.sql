-- Phase 24: reporting point + optional email-only sign-in
ALTER TABLE events
    ADD COLUMN reporting_point VARCHAR(255) NULL DEFAULT NULL
        COMMENT 'Gate, entrance, or reporting point instructions'
        AFTER location;

INSERT INTO system_settings (setting_key, setting_value) VALUES
    ('signin_require_pps_last4', '1')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

ALTER TABLE attendance
    MODIFY checked_in_method ENUM('self', 'admin', 'scan') NOT NULL DEFAULT 'self';

-- Phase 4 migration — global system settings
USE event_staff_system;

CREATE TABLE IF NOT EXISTS system_settings (
    setting_key   VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO system_settings (setting_key, setting_value) VALUES
('site_name', 'Event Staff System'),
('notify_staff_enabled', '1'),
('notify_on_registration', '0'),
('mail_from_name', 'Event Staff System'),
('mail_from_email', 'noreply@event-staff.local')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

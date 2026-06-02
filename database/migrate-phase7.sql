-- Phase 7 migration — status tokens, theme settings
USE event_staff_system;

ALTER TABLE staff_registrations ADD COLUMN status_token VARCHAR(64) NULL UNIQUE;

INSERT INTO system_settings (setting_key, setting_value) VALUES
('theme_primary_color', ''),
('theme_font_family', 'poppins')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

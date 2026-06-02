-- Phase 10 migration — interface theme presets
USE event_staff_system;

INSERT INTO system_settings (setting_key, setting_value) VALUES
('theme_preset', 'security-classic-blue')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

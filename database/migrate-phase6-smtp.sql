-- Phase 6 migration — SMTP email transport settings
USE event_staff_system;

INSERT INTO system_settings (setting_key, setting_value) VALUES
('mail_transport', 'php_mail'),
('smtp_host', ''),
('smtp_port', '587'),
('smtp_encryption', 'tls'),
('smtp_username', ''),
('smtp_password', '')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

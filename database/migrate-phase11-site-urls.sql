-- Phase 11: Separate registration site URL (form is not on the main/admin domain)
INSERT INTO system_settings (setting_key, setting_value) VALUES
    ('registration_site_url', ''),
    ('admin_site_url', '')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

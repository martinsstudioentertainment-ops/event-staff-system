-- Phase 13: Website page content (JSON)
INSERT INTO system_settings (setting_key, setting_value) VALUES
    ('website_content', '')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

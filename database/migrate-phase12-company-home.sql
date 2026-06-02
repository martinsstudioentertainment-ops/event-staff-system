-- Phase 12: Company homepage content (main website)
INSERT INTO system_settings (setting_key, setting_value) VALUES
    ('company_name', 'Event Staff Ireland'),
    ('company_tagline', 'Helping people find security, steward, and event jobs — even if you have never done it before'),
    ('company_email', 'info@example.com'),
    ('company_phone', '+353 1 000 0000'),
    ('company_about', 'Many people want security or event work but do not know where to start. We run a simple registration portal so you can apply for upcoming festivals, concerts, and events in one place — no confusing agencies, no endless forms.')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

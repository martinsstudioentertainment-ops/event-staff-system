-- Phase 15: WhatsApp contact fields

INSERT INTO system_settings (setting_key, setting_value) VALUES
('company_whatsapp', '+353 1 000 0000'),
('company_whatsapp_group', '')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

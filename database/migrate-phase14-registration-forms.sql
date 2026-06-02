-- Phase 14: DSP / Static / Steward registration forms + staff_role expansion

UPDATE staff_registrations SET staff_role = 'dsp' WHERE staff_role = 'security';

ALTER TABLE staff_registrations
    MODIFY staff_role ENUM('dsp', 'static', 'steward') NOT NULL;

INSERT INTO system_settings (setting_key, setting_value) VALUES
('registration_forms', '')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

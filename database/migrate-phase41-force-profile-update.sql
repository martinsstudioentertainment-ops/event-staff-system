-- Phase 41: Force existing staff to update profile on mobile app (optional one-time run)
INSERT INTO system_settings (setting_key, setting_value)
VALUES ('staff_profile_update_required', '1')
ON DUPLICATE KEY UPDATE setting_value = '1';

INSERT INTO system_settings (setting_key, setting_value)
VALUES ('staff_profile_refresh_at', UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE setting_value = UTC_TIMESTAMP();

UPDATE staff SET profile_completed = 0;

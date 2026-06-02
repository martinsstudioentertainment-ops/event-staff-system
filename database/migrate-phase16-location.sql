-- Phase 16: registration location coordinates

ALTER TABLE staff_registrations
    ADD COLUMN location_lat DECIMAL(10, 7) NULL DEFAULT NULL AFTER eircode,
    ADD COLUMN location_lng DECIMAL(10, 7) NULL DEFAULT NULL AFTER location_lat;

INSERT INTO system_settings (setting_key, setting_value) VALUES
('google_maps_api_key', '')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

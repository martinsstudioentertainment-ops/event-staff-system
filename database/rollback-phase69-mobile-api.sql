-- Rollback Phase 69: Mobile API infrastructure tables
-- WARNING: Revokes all mobile refresh tokens and FCM registrations.
-- Does NOT touch business tables (staff, registrations, attendance, etc.).
-- Run only when fully removing the mobile API layer.

-- Drop in dependency order (child tables first)
DROP TABLE IF EXISTS mobile_api_audit;
DROP TABLE IF EXISTS fcm_device_tokens;
DROP TABLE IF EXISTS mobile_refresh_tokens;

-- Remove mobile API system_settings keys (optional — run manually if needed)
-- DELETE FROM system_settings WHERE setting_key IN (
--     'mobile_api_enabled',
--     'mobile_jwt_secret',
--     'mobile_min_app_version',
--     'mobile_jwt_access_ttl',
--     'mobile_jwt_refresh_days',
--     'fcm_service_account_path',
--     'fcm_project_id'
-- );

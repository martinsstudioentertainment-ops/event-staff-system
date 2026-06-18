-- Rollback Phase 53 GPS attendance Phase 1.5 columns
-- Also set feature_gps_attendance_v2 = 0 in Admin → Feature flags.

ALTER TABLE attendance
    DROP COLUMN IF EXISTS last_gps_at,
    DROP COLUMN IF EXISTS last_gps_accuracy_m,
    DROP COLUMN IF EXISTS last_gps_lng,
    DROP COLUMN IF EXISTS last_gps_lat;

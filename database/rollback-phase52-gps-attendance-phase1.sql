-- Rollback Phase 52 GPS attendance Phase 1 columns (run only if reverting Phase 1)
-- Does not remove feature flag setting — disable feature_gps_attendance_v2 in Admin → Feature flags.

ALTER TABLE attendance
    DROP COLUMN IF EXISTS check_in_gps_at,
    DROP COLUMN IF EXISTS check_in_accuracy_m,
    DROP COLUMN IF EXISTS check_in_lng,
    DROP COLUMN IF EXISTS check_in_lat,
    DROP COLUMN IF EXISTS activated_at,
    DROP COLUMN IF EXISTS attendance_status;

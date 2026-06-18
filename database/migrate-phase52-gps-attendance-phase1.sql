-- Phase 52: GPS attendance Phase 1 — pre-check-in, hibernation, GPS snapshot, activation timestamp

ALTER TABLE attendance
    ADD COLUMN attendance_status VARCHAR(32) NOT NULL DEFAULT 'active' AFTER checked_in_method,
    ADD COLUMN activated_at DATETIME NULL AFTER attendance_status,
    ADD COLUMN check_in_lat DECIMAL(10,7) NULL AFTER activated_at,
    ADD COLUMN check_in_lng DECIMAL(10,7) NULL AFTER check_in_lat,
    ADD COLUMN check_in_accuracy_m SMALLINT UNSIGNED NULL AFTER check_in_lng,
    ADD COLUMN check_in_gps_at DATETIME NULL AFTER check_in_accuracy_m;

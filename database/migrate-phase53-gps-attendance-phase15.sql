-- Phase 53: GPS attendance Phase 1.5 — last-known GPS for activation proof

ALTER TABLE attendance
    ADD COLUMN last_gps_lat DECIMAL(10,7) NULL AFTER check_in_gps_at,
    ADD COLUMN last_gps_lng DECIMAL(10,7) NULL AFTER last_gps_lat,
    ADD COLUMN last_gps_accuracy_m SMALLINT UNSIGNED NULL AFTER last_gps_lng,
    ADD COLUMN last_gps_at DATETIME NULL AFTER last_gps_accuracy_m;

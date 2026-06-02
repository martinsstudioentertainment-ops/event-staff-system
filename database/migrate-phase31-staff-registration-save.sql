-- Phase 31: columns required for public registration save
-- Safe to run once; skip lines that error with "Duplicate column" if already applied.

ALTER TABLE staff_registrations
    ADD COLUMN location_lat DECIMAL(10, 7) NULL DEFAULT NULL AFTER eircode;

ALTER TABLE staff_registrations
    ADD COLUMN location_lng DECIMAL(10, 7) NULL DEFAULT NULL AFTER location_lat;

ALTER TABLE staff_registrations
    ADD COLUMN status_token VARCHAR(64) NULL DEFAULT NULL;

ALTER TABLE staff_registrations
    ADD COLUMN privacy_consented_at TIMESTAMP NULL DEFAULT NULL AFTER status_token;

ALTER TABLE staff_registrations
    MODIFY staff_role VARCHAR(32) NOT NULL DEFAULT 'dsp';

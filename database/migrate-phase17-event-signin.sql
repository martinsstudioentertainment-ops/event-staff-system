-- Phase 17: Event sign-in QR, location, and time window

ALTER TABLE events
    ADD COLUMN location VARCHAR(255) NULL DEFAULT NULL AFTER event_date,
    ADD COLUMN start_time TIME NOT NULL DEFAULT '09:00:00' AFTER location,
    ADD COLUMN end_time TIME NOT NULL DEFAULT '23:00:00' AFTER start_time,
    ADD COLUMN signin_token VARCHAR(64) NULL UNIQUE AFTER end_time;

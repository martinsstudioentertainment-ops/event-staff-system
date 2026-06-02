-- Add checkin_token column (run once; ignore error if column already exists)
USE event_staff_system;
ALTER TABLE staff_registrations ADD COLUMN checkin_token VARCHAR(64) NULL UNIQUE AFTER exported_at;

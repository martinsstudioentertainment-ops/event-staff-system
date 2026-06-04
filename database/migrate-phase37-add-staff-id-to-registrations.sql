-- Phase 37: Add staff_id column to staff_registrations table
-- This migration adds a foreign key column to link registrations to staff
-- The column is nullable initially to allow for gradual migration

ALTER TABLE staff_registrations 
ADD COLUMN staff_id INT UNSIGNED NULL DEFAULT NULL AFTER id,
ADD INDEX idx_staff_id (staff_id),
ADD CONSTRAINT fk_registration_staff
    FOREIGN KEY (staff_id) REFERENCES staff(id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

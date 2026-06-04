-- Rollback script for Phase 35-38: Staff table normalization
-- This script reverses the staff table migration
-- WARNING: This will delete the staff table and remove staff_id from registrations
-- Only run this if you need to rollback the migration

-- Step 1: Remove foreign key constraint
ALTER TABLE staff_registrations DROP FOREIGN KEY fk_registration_staff;

-- Step 2: Remove staff_id column from staff_registrations
ALTER TABLE staff_registrations DROP COLUMN staff_id;

-- Step 3: Drop the staff table
DROP TABLE IF EXISTS staff;

-- Phase 38: Link existing registrations to staff records
-- This migration populates the staff_id column in staff_registrations
-- by matching email addresses with the staff table

UPDATE staff_registrations sr
INNER JOIN staff s ON sr.email = s.email
SET sr.staff_id = s.id
WHERE sr.staff_id IS NULL;

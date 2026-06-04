-- Phase 36: Populate staff table from existing staff_registrations
-- This migration deduplicates staff by email and migrates personal data to the staff table
-- It uses INSERT IGNORE to handle duplicate emails gracefully

INSERT IGNORE INTO staff (
    surname, 
    first_name, 
    full_address, 
    eircode, 
    location_lat, 
    location_lng, 
    email, 
    mobile, 
    date_of_birth, 
    gender, 
    pps_number, 
    bank_iban, 
    staff_role,
    created_at
)
SELECT DISTINCT
    surname, 
    first_name, 
    full_address, 
    eircode, 
    location_lat, 
    location_lng, 
    email, 
    mobile, 
    date_of_birth, 
    gender, 
    pps_number, 
    bank_iban, 
    staff_role,
    created_at
FROM staff_registrations
ORDER BY created_at ASC;

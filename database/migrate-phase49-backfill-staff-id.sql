-- Phase 49: Backfill staff_registrations.staff_id from staff.email match
-- Safe to re-run: only updates NULL/0 staff_id rows

UPDATE staff_registrations sr
INNER JOIN staff s ON LOWER(TRIM(s.email)) = LOWER(TRIM(sr.email))
SET sr.staff_id = s.id
WHERE sr.staff_id IS NULL OR sr.staff_id = 0;

-- Create staff rows for registrations that have no staff record yet (edge case)
INSERT INTO staff (
    surname, first_name, full_address, eircode, email, mobile, date_of_birth,
    gender, pps_number, bank_iban, staff_role, created_at, updated_at
)
SELECT
    sr.surname,
    sr.first_name,
    sr.full_address,
    sr.eircode,
    LOWER(TRIM(sr.email)),
    sr.mobile,
    sr.date_of_birth,
    sr.gender,
    sr.pps_number,
    sr.bank_iban,
    COALESCE(NULLIF(TRIM(sr.staff_role), ''), 'steward'),
    MIN(sr.created_at),
    MAX(sr.updated_at)
FROM staff_registrations sr
LEFT JOIN staff s ON LOWER(TRIM(s.email)) = LOWER(TRIM(sr.email))
WHERE s.id IS NULL
GROUP BY LOWER(TRIM(sr.email)), sr.surname, sr.first_name, sr.full_address, sr.eircode,
    sr.mobile, sr.date_of_birth, sr.gender, sr.pps_number, sr.bank_iban, sr.staff_role;

-- Link again after insert
UPDATE staff_registrations sr
INNER JOIN staff s ON LOWER(TRIM(s.email)) = LOWER(TRIM(sr.email))
SET sr.staff_id = s.id
WHERE sr.staff_id IS NULL OR sr.staff_id = 0;

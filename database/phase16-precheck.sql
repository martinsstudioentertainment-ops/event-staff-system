-- Phase 16 PRE-CHECK (read-only diagnostics)
-- Run in phpMyAdmin or mysql CLI BEFORE applying migrate-phase48 through migrate-phase51.
-- Save output with timestamp. Do not run on production without a full backup.

-- 1. Migration runner gap: tables that may exist only via runtime ensure*Schema
SELECT 'staff' AS tbl, COUNT(*) AS row_count FROM staff
UNION ALL SELECT 'staff_messages', COUNT(*) FROM staff_messages
UNION ALL SELECT 'saved_job_records', COUNT(*) FROM saved_job_records
UNION ALL SELECT 'personal_invoice_lines', COUNT(*) FROM personal_invoice_lines
UNION ALL SELECT 'app_notifications', COUNT(*) FROM app_notifications
UNION ALL SELECT 'website_visits', COUNT(*) FROM website_visits;

-- 2. staff_id backfill status
SELECT
    COUNT(*) AS total_registrations,
    SUM(CASE WHEN staff_id IS NULL OR staff_id = 0 THEN 1 ELSE 0 END) AS missing_staff_id,
    SUM(CASE WHEN staff_id IS NOT NULL AND staff_id > 0 THEN 1 ELSE 0 END) AS linked_staff_id
FROM staff_registrations;

-- 3. Orphan staff_id (FK would fail)
SELECT sr.id, sr.email, sr.staff_id, sr.event_id
FROM staff_registrations sr
LEFT JOIN staff s ON s.id = sr.staff_id
WHERE sr.staff_id IS NOT NULL AND sr.staff_id > 0 AND s.id IS NULL
LIMIT 50;

-- 4. Registrations with email not in staff table
SELECT sr.id, sr.email, sr.event_id, sr.status
FROM staff_registrations sr
LEFT JOIN staff s ON LOWER(TRIM(s.email)) = LOWER(TRIM(sr.email))
WHERE s.id IS NULL
LIMIT 50;

-- 5. staff_messages orphan staff_id
SELECT sm.id, sm.staff_id, sm.staff_email
FROM staff_messages sm
LEFT JOIN staff s ON s.id = sm.staff_id
WHERE s.id IS NULL
LIMIT 50;

-- 6. personal_invoice_lines orphan invoice_id
SELECT pil.id, pil.invoice_id
FROM personal_invoice_lines pil
LEFT JOIN saved_job_records sjr ON sjr.id = pil.invoice_id
WHERE sjr.id IS NULL
LIMIT 50;

-- 7. saved_job_records orphan event_id (nullable column; FK only for non-null)
SELECT sjr.id, sjr.invoice_number, sjr.event_id
FROM saved_job_records sjr
LEFT JOIN events e ON e.id = sjr.event_id
WHERE sjr.event_id IS NOT NULL AND e.id IS NULL
LIMIT 50;

-- 8. Index existence (MySQL 8+)
SELECT TABLE_NAME, INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('staff_registrations', 'events', 'staff', 'app_notifications')
  AND INDEX_NAME IN ('idx_staff_reg_event_status', 'idx_events_active_date', 'idx_staff_email_normalized')
GROUP BY TABLE_NAME, INDEX_NAME;

-- 9. Event #1 Nick Cave baseline (production sanity)
SELECT event_id, status, COUNT(*) AS cnt
FROM staff_registrations
WHERE event_id = 1
GROUP BY event_id, status;

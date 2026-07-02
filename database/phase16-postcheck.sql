-- Phase 16 POST-CHECK (run after migrate-phase48 through migrate-phase51)
-- All orphan queries should return 0 rows.

SELECT 'missing_staff_id' AS check_name, COUNT(*) AS bad_count
FROM staff_registrations WHERE staff_id IS NULL OR staff_id = 0
UNION ALL
SELECT 'orphan_staff_id', COUNT(*)
FROM staff_registrations sr
LEFT JOIN staff s ON s.id = sr.staff_id
WHERE sr.staff_id IS NOT NULL AND s.id IS NULL
UNION ALL
SELECT 'orphan_messages', COUNT(*)
FROM staff_messages sm LEFT JOIN staff s ON s.id = sm.staff_id WHERE s.id IS NULL
UNION ALL
SELECT 'orphan_invoice_lines', COUNT(*)
FROM personal_invoice_lines pil
LEFT JOIN saved_job_records sjr ON sjr.id = pil.invoice_id WHERE sjr.id IS NULL;

-- Verify indexes
SHOW INDEX FROM staff_registrations WHERE Key_name = 'idx_staff_reg_event_status';
SHOW INDEX FROM events WHERE Key_name = 'idx_events_active_date';

-- Verify platform tables
SELECT TABLE_NAME FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'platform_cleanup_log','platform_storage_snapshots','platform_audit_runs',
    'platform_ssl_checks','emergency_event_log',
    'equipment_categories','equipment_items','equipment_rentals'
  )
ORDER BY TABLE_NAME;

-- Production baseline unchanged
SELECT event_id, status, COUNT(*) AS cnt FROM staff_registrations WHERE event_id = 1 GROUP BY event_id, status;

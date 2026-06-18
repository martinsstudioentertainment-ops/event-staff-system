-- ROLLBACK Phase 48–51
-- WARNING: Run only after backup. Order matters (FKs before tables).
-- Does NOT remove data from staff_registrations.staff_id backfill.

-- Phase 51: Drop platform + equipment tables
DROP TABLE IF EXISTS equipment_rentals;
DROP TABLE IF EXISTS equipment_items;
DROP TABLE IF EXISTS equipment_categories;
DROP TABLE IF EXISTS emergency_event_log;
DROP TABLE IF EXISTS platform_ssl_checks;
DROP TABLE IF EXISTS platform_audit_runs;
DROP TABLE IF EXISTS platform_storage_snapshots;
DROP TABLE IF EXISTS platform_cleanup_log;

-- Phase 50: Drop foreign keys
ALTER TABLE saved_job_records DROP FOREIGN KEY IF EXISTS fk_saved_job_created_by;
ALTER TABLE saved_job_records DROP FOREIGN KEY IF EXISTS fk_saved_job_event;
ALTER TABLE personal_invoice_lines DROP FOREIGN KEY IF EXISTS fk_personal_lines_invoice;
ALTER TABLE staff_messages DROP FOREIGN KEY IF EXISTS fk_staff_messages_staff;

-- Phase 48: Drop indexes (ignore errors if missing)
ALTER TABLE app_notifications DROP INDEX IF EXISTS idx_notif_admin_feed;
ALTER TABLE staff DROP INDEX IF EXISTS idx_staff_email_lower;
ALTER TABLE events DROP INDEX IF EXISTS idx_events_active_date;
ALTER TABLE staff_registrations DROP INDEX IF EXISTS idx_staff_reg_event_status;

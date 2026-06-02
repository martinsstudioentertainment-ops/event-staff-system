-- Phase 8 migration — backfill status tokens for existing registrations
USE event_staff_system;

UPDATE staff_registrations
SET status_token = LOWER(SHA2(CONCAT('status-', id, '-', email, '-', UNIX_TIMESTAMP(created_at)), 256))
WHERE status_token IS NULL OR status_token = '';

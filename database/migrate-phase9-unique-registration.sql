-- Phase 9 migration — one registration per email per event
USE event_staff_system;

ALTER TABLE staff_registrations
    ADD UNIQUE KEY uq_staff_email_event (email, event_id);

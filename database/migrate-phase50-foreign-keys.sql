-- Phase 50: Foreign keys (run ONLY after phase16-precheck.sql shows zero orphans)
-- If precheck returns rows in sections 5–7, fix data before running this file.

-- staff_messages.staff_id → staff(id)
ALTER TABLE staff_messages
    ADD CONSTRAINT fk_staff_messages_staff
        FOREIGN KEY (staff_id) REFERENCES staff(id)
        ON DELETE CASCADE ON UPDATE CASCADE;

-- personal_invoice_lines.invoice_id → saved_job_records(id)
ALTER TABLE personal_invoice_lines
    ADD CONSTRAINT fk_personal_lines_invoice
        FOREIGN KEY (invoice_id) REFERENCES saved_job_records(id)
        ON DELETE CASCADE ON UPDATE CASCADE;

-- saved_job_records.event_id → events(id) (nullable; FK applies when set)
ALTER TABLE saved_job_records
    ADD CONSTRAINT fk_saved_job_event
        FOREIGN KEY (event_id) REFERENCES events(id)
        ON DELETE SET NULL ON UPDATE CASCADE;

-- saved_job_records.created_by → admin_users(id)
ALTER TABLE saved_job_records
    ADD CONSTRAINT fk_saved_job_created_by
        FOREIGN KEY (created_by) REFERENCES admin_users(id)
        ON DELETE SET NULL ON UPDATE CASCADE;

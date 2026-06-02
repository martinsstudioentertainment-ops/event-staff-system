-- Phase 26: payable work hours per check-in (admin can adjust down)

ALTER TABLE attendance
    ADD COLUMN work_end_at DATETIME NULL AFTER checked_in_method,
    ADD COLUMN scheduled_hours DECIMAL(6,2) NULL AFTER work_end_at,
    ADD COLUMN hours_worked DECIMAL(6,2) NULL AFTER scheduled_hours,
    ADD COLUMN hours_paid DECIMAL(6,2) NULL AFTER hours_worked,
    ADD COLUMN hours_note VARCHAR(255) NULL AFTER hours_paid,
    ADD COLUMN hours_adjusted_by INT NULL AFTER hours_note,
    ADD COLUMN hours_adjusted_at TIMESTAMP NULL AFTER hours_adjusted_by;

ALTER TABLE attendance
    ADD CONSTRAINT fk_attendance_hours_admin
        FOREIGN KEY (hours_adjusted_by) REFERENCES admin_users(id) ON DELETE SET NULL;

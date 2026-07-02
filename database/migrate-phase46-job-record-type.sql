ALTER TABLE saved_job_records
    ADD COLUMN IF NOT EXISTS invoice_type ENUM('staff_commission', 'personal') NOT NULL DEFAULT 'staff_commission' AFTER id;

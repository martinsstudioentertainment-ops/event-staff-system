-- Operations Automation Batch 2 enhancements (phase 67)
-- Non-destructive additions. Safe to run multiple times.

CREATE TABLE IF NOT EXISTS comms_message_templates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    channel ENUM('email','sms','whatsapp','internal') NOT NULL DEFAULT 'email',
    subject VARCHAR(255) NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_templates_channel (channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_notes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id INT UNSIGNED NOT NULL,
    note_text TEXT NOT NULL,
    created_by_admin_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_client_notes_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recruitment_interview_notes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pipeline_id INT UNSIGNED NOT NULL,
    note_text TEXT NOT NULL,
    interview_date DATE NULL,
    created_by_admin_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_interview_pipeline (pipeline_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_payroll_adjustments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    staff_id INT UNSIGNED NOT NULL,
    event_id INT UNSIGNED NULL,
    adjustment_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    adjustment_note VARCHAR(255) NULL,
    work_date DATE NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_payroll_adj_staff (staff_id),
    KEY idx_payroll_adj_date (work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extend incident management (phase 67)
ALTER TABLE staff_incidents
    MODIFY incident_type ENUM(
        'attendance','venue','conduct','gps','safety','client_complaint','other'
    ) NOT NULL DEFAULT 'other';

-- Columns below: ignore duplicate-column errors on re-run
ALTER TABLE staff_incidents ADD COLUMN evidence_text TEXT NULL AFTER description;
ALTER TABLE staff_incidents ADD COLUMN actions_taken TEXT NULL AFTER evidence_text;
ALTER TABLE staff_incidents ADD COLUMN risk_level ENUM('low','medium','high','critical') NULL DEFAULT 'medium' AFTER actions_taken;

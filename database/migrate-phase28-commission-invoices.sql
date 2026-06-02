-- Phase 28: Commission invoices (one per event, editable lines per lad)
-- Note: IDs use INT UNSIGNED to match events, admin_users, attendance, staff_registrations.

CREATE TABLE IF NOT EXISTS commission_invoices (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id            INT UNSIGNED NOT NULL,
    client_name         VARCHAR(255) NULL,
    invoice_number      VARCHAR(50) NULL,
    invoice_date        DATE NOT NULL,
    status              ENUM('draft','sent','paid','void') NOT NULL DEFAULT 'draft',
    currency            CHAR(3) NOT NULL DEFAULT 'EUR',
    staff_count         INT UNSIGNED NOT NULL DEFAULT 0,
    total_hours_worked  DECIMAL(8,2) NOT NULL DEFAULT 0,
    total_hours_billed  DECIMAL(8,2) NOT NULL DEFAULT 0,
    subtotal_amount     DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_amount        DECIMAL(12,2) NOT NULL DEFAULT 0,
    notes               TEXT NULL,
    created_by          INT UNSIGNED NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_commission_invoice_event (event_id),
    CONSTRAINT fk_commission_invoice_event
        FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT,
    CONSTRAINT fk_commission_invoice_admin
        FOREIGN KEY (created_by) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS commission_invoice_lines (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id          INT UNSIGNED NOT NULL,
    attendance_id       INT UNSIGNED NULL,
    registration_id     INT UNSIGNED NOT NULL,
    staff_name          VARCHAR(255) NOT NULL,
    staff_role          VARCHAR(50) NULL,
    hours_worked        DECIMAL(6,2) NOT NULL DEFAULT 0,
    hours_billed        DECIMAL(6,2) NOT NULL DEFAULT 0,
    hourly_rate         DECIMAL(8,2) NOT NULL DEFAULT 0,
    line_amount         DECIMAL(10,2) NOT NULL DEFAULT 0,
    amount_override     TINYINT(1) NOT NULL DEFAULT 0,
    note                VARCHAR(255) NULL,
    sort_order          INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_commission_line_invoice
        FOREIGN KEY (invoice_id) REFERENCES commission_invoices(id) ON DELETE CASCADE,
    CONSTRAINT fk_commission_line_attendance
        FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE SET NULL,
    CONSTRAINT fk_commission_line_registration
        FOREIGN KEY (registration_id) REFERENCES staff_registrations(id) ON DELETE RESTRICT,
    INDEX idx_commission_line_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

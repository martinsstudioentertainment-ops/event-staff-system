-- Phase 28 (safe for shared hosting): commission invoices without foreign keys.
-- Use if Go live reports errno 150, or import this in phpMyAdmin after selecting your database.

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
    print_layout        ENUM('detailed','summary') NOT NULL DEFAULT 'detailed',
    notes               TEXT NULL,
    created_by          INT UNSIGNED NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_commission_invoice_event (event_id),
    KEY idx_commission_invoice_event (event_id),
    KEY idx_commission_invoice_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS commission_invoice_lines (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id          INT UNSIGNED NOT NULL,
    attendance_id       INT UNSIGNED NULL,
    registration_id     INT UNSIGNED NULL,
    staff_name          VARCHAR(255) NOT NULL,
    staff_role          VARCHAR(50) NULL,
    hours_worked        DECIMAL(6,2) NOT NULL DEFAULT 0,
    hours_billed        DECIMAL(6,2) NOT NULL DEFAULT 0,
    hourly_rate         DECIMAL(8,2) NOT NULL DEFAULT 0,
    line_amount         DECIMAL(10,2) NOT NULL DEFAULT 0,
    amount_override     TINYINT(1) NOT NULL DEFAULT 0,
    note                VARCHAR(255) NULL,
    sort_order          INT NOT NULL DEFAULT 0,
    KEY idx_commission_line_invoice (invoice_id),
    KEY idx_commission_line_attendance (attendance_id),
    KEY idx_commission_line_registration (registration_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Workforce Batch 1: staff availability & leave requests
-- Run once on production ERP database (admin.olasentra.com)

CREATE TABLE IF NOT EXISTS staff_availability (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    staff_id INT UNSIGNED NOT NULL,
    avail_date DATE NOT NULL,
    status ENUM(
        'available',
        'unavailable',
        'leave_requested',
        'holiday_requested',
        'leave_approved',
        'holiday_approved'
    ) NOT NULL DEFAULT 'available',
    notes VARCHAR(500) NULL,
    admin_approved TINYINT(1) NOT NULL DEFAULT 0,
    reviewed_by_admin_id INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_staff_avail_date (staff_id, avail_date),
    KEY idx_avail_date (avail_date),
    KEY idx_avail_status (status),
    KEY idx_avail_staff (staff_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin shift override, waiting list, and assignment audit (Phase 68)

CREATE TABLE IF NOT EXISTS staff_waitlist (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    staff_id INT UNSIGNED NULL DEFAULT NULL,
    email VARCHAR(150) NOT NULL,
    surname VARCHAR(100) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    staff_role VARCHAR(32) NOT NULL DEFAULT 'dsp',
    form_slug VARCHAR(64) NULL DEFAULT NULL,
    venue_id INT UNSIGNED NULL DEFAULT NULL,
    preferred_event_id INT UNSIGNED NULL DEFAULT NULL,
    allocation_type ENUM('waiting_list', 'pending_allocation', 'reserve_staff') NOT NULL DEFAULT 'waiting_list',
    status ENUM('active', 'allocated', 'withdrawn') NOT NULL DEFAULT 'active',
    allocated_registration_id INT UNSIGNED NULL DEFAULT NULL,
    notes TEXT NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_waitlist_email (email),
    KEY idx_waitlist_status (status, allocation_type),
    KEY idx_waitlist_event (preferred_event_id, status),
    KEY idx_waitlist_staff (staff_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_shift_assignment_log (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id INT UNSIGNED NULL DEFAULT NULL,
    admin_username VARCHAR(100) NULL DEFAULT NULL,
    staff_id INT UNSIGNED NULL DEFAULT NULL,
    registration_id INT UNSIGNED NULL DEFAULT NULL,
    waitlist_id INT UNSIGNED NULL DEFAULT NULL,
    email VARCHAR(150) NULL DEFAULT NULL,
    action VARCHAR(64) NOT NULL,
    from_event_id INT UNSIGNED NULL DEFAULT NULL,
    to_event_id INT UNSIGNED NULL DEFAULT NULL,
    reason TEXT NULL DEFAULT NULL,
    details TEXT NULL DEFAULT NULL,
    ip_address VARCHAR(45) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_assignment_log_staff (staff_id, created_at),
    KEY idx_assignment_log_reg (registration_id, created_at),
    KEY idx_assignment_log_event (to_event_id, created_at),
    KEY idx_assignment_log_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

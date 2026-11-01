-- Operations Automation Batch 2 (Modules 11–20)
-- Non-destructive: new tables only. Safe to run multiple times (IF NOT EXISTS).

-- Module 11: Event rostering — shift slots and assignments
CREATE TABLE IF NOT EXISTS event_roster_slots (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id INT UNSIGNED NOT NULL,
    role_name VARCHAR(120) NOT NULL DEFAULT 'General',
    shift_label VARCHAR(120) NULL,
    shift_start TIME NULL,
    shift_end TIME NULL,
    slots_needed INT UNSIGNED NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_roster_slot_event (event_id),
    KEY idx_roster_slot_role (role_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_roster_assignments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slot_id INT UNSIGNED NOT NULL,
    registration_id INT UNSIGNED NULL,
    staff_id INT UNSIGNED NULL,
    assignment_status ENUM('assigned','confirmed','declined','checked_in') NOT NULL DEFAULT 'assigned',
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at DATETIME NULL,
    notes VARCHAR(500) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roster_slot_registration (slot_id, registration_id),
    KEY idx_roster_assign_staff (staff_id),
    KEY idx_roster_assign_status (assignment_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Module 13: Recruitment pipeline
CREATE TABLE IF NOT EXISTS recruitment_pipeline (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    staff_id INT UNSIGNED NULL,
    registration_id INT UNSIGNED NULL,
    stage ENUM(
        'application_received','screening','interview','approved',
        'training','active_staff','rejected'
    ) NOT NULL DEFAULT 'application_received',
    notes TEXT NULL,
    stage_changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_recruitment_stage (stage),
    KEY idx_recruitment_staff (staff_id),
    KEY idx_recruitment_registration (registration_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Module 14: Training management
CREATE TABLE IF NOT EXISTS staff_training_records (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    staff_id INT UNSIGNED NOT NULL,
    course_key VARCHAR(64) NOT NULL,
    course_name VARCHAR(200) NOT NULL,
    record_status ENUM('completed','pending','expired','upcoming') NOT NULL DEFAULT 'pending',
    completed_at DATE NULL,
    expiry_date DATE NULL,
    scheduled_date DATE NULL,
    notes VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_training_staff (staff_id),
    KEY idx_training_status (record_status),
    KEY idx_training_course (course_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Module 15: Payroll preparation — rate cards
CREATE TABLE IF NOT EXISTS staff_rate_cards (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    staff_id INT UNSIGNED NOT NULL,
    hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    role_name VARCHAR(120) NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rate_staff (staff_id),
    KEY idx_rate_effective (effective_from, effective_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Module 16: Communication campaigns
CREATE TABLE IF NOT EXISTS comms_campaigns (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel ENUM('email','sms','whatsapp','internal') NOT NULL DEFAULT 'email',
    subject VARCHAR(255) NULL,
    body TEXT NOT NULL,
    filter_json JSON NULL,
    target_count INT UNSIGNED NOT NULL DEFAULT 0,
    sent_count INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('draft','sending','sent','failed') NOT NULL DEFAULT 'draft',
    created_by_admin_id INT UNSIGNED NULL,
    sent_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_comms_channel (channel),
    KEY idx_comms_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Module 17: Incident management
CREATE TABLE IF NOT EXISTS staff_incidents (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    staff_id INT UNSIGNED NULL,
    event_id INT UNSIGNED NULL,
    incident_type ENUM(
        'attendance','venue','conduct','gps','safety','other'
    ) NOT NULL DEFAULT 'other',
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    incident_status ENUM('open','investigating','resolved','closed') NOT NULL DEFAULT 'open',
    reported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    resolution_notes TEXT NULL,
    reported_by_admin_id INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_incident_status (incident_status),
    KEY idx_incident_type (incident_type),
    KEY idx_incident_staff (staff_id),
    KEY idx_incident_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Module 18: Client management
CREATE TABLE IF NOT EXISTS clients (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(200) NOT NULL,
    contact_name VARCHAR(200) NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(50) NULL,
    address TEXT NULL,
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_clients_name (name),
    KEY idx_clients_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_contacts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id INT UNSIGNED NOT NULL,
    contact_name VARCHAR(200) NOT NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(50) NULL,
    role_title VARCHAR(120) NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_client_contacts_client (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional link events to clients (nullable, non-breaking)
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'events' AND COLUMN_NAME = 'client_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE events ADD COLUMN client_id INT UNSIGNED NULL AFTER venue_id, ADD KEY idx_events_client (client_id)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Module 19: Contract management
CREATE TABLE IF NOT EXISTS staff_contracts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    staff_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    contract_status ENUM('draft','active','expired','renewal_due','terminated') NOT NULL DEFAULT 'draft',
    signed_at DATE NULL,
    document_path VARCHAR(500) NULL,
    compliance_status ENUM('valid','expiring','expired','missing') NOT NULL DEFAULT 'missing',
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_staff_contracts_staff (staff_id),
    KEY idx_staff_contracts_status (contract_status),
    KEY idx_staff_contracts_end (end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_contracts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    contract_status ENUM('draft','active','expired','renewal_due','terminated') NOT NULL DEFAULT 'draft',
    signed_at DATE NULL,
    document_path VARCHAR(500) NULL,
    value_amount DECIMAL(12,2) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_client_contracts_client (client_id),
    KEY idx_client_contracts_status (contract_status),
    KEY idx_client_contracts_end (end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Module 20: Operations automation log
CREATE TABLE IF NOT EXISTS ops_automation_log (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    alert_type VARCHAR(64) NOT NULL,
    message VARCHAR(500) NOT NULL,
    target_type VARCHAR(64) NULL,
    target_id INT UNSIGNED NULL,
    severity ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
    acknowledged TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ops_alert_type (alert_type),
    KEY idx_ops_alert_ack (acknowledged),
    KEY idx_ops_alert_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Shift response on registrations (Module 12 self-service)
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_registrations' AND COLUMN_NAME = 'shift_response'
);
SET @sql = IF(@col_exists = 0,
    "ALTER TABLE staff_registrations ADD COLUMN shift_response ENUM('pending','accepted','declined') NOT NULL DEFAULT 'pending' AFTER status",
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

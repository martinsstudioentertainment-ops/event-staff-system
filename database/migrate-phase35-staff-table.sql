-- Phase 35: Create staff table for normalized database structure
-- This migration adds a new staff table without modifying existing data
-- Staff personal information will be stored once and referenced by staff_registrations

CREATE TABLE IF NOT EXISTS staff (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    surname         VARCHAR(100) NOT NULL,
    first_name      VARCHAR(100) NOT NULL,
    full_address    VARCHAR(255) NOT NULL,
    eircode         VARCHAR(8) NOT NULL,
    location_lat    DECIMAL(10, 7) NULL DEFAULT NULL,
    location_lng    DECIMAL(10, 7) NULL DEFAULT NULL,
    email           VARCHAR(150) NOT NULL UNIQUE,
    mobile          VARCHAR(30) NOT NULL,
    date_of_birth   DATE NOT NULL,
    gender          ENUM('male', 'female', 'other', 'prefer_not_to_say') NOT NULL,
    pps_number      VARCHAR(30) NOT NULL,
    bank_iban       VARCHAR(50) NOT NULL,
    staff_role      ENUM('dsp', 'static', 'steward') NOT NULL DEFAULT 'steward',
    is_blacklisted  TINYINT(1) NOT NULL DEFAULT 0,
    blacklist_reason VARCHAR(255) NULL DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_staff_email (email),
    INDEX idx_staff_role (staff_role),
    INDEX idx_staff_blacklist (is_blacklisted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

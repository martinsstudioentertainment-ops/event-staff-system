-- Event Staff System — Database Schema
-- Phase 2: import this file into MySQL via Laragon/phpMyAdmin
-- Form field event_ids[] creates one row per selected event in staff_registrations
-- Spreadsheet export column order matches registration-fields.js

CREATE DATABASE IF NOT EXISTS event_staff_system
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE event_staff_system;

-- Events master list (matches assets/js/events.js)
CREATE TABLE IF NOT EXISTS events (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(150) NOT NULL,
    event_date    DATE NOT NULL,
    location      VARCHAR(255) NULL DEFAULT NULL,
    venue_eircode VARCHAR(8) NULL DEFAULT NULL,
    venue_lat     DECIMAL(10, 7) NULL DEFAULT NULL,
    venue_lng     DECIMAL(10, 7) NULL DEFAULT NULL,
    signin_radius_m SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    start_time    TIME NOT NULL DEFAULT '09:00:00',
    end_time      TIME NOT NULL DEFAULT '23:00:00',
    signin_token  VARCHAR(64) NULL UNIQUE,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Staff registration submissions
CREATE TABLE IF NOT EXISTS staff_registrations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    surname         VARCHAR(100) NOT NULL,
    first_name      VARCHAR(100) NOT NULL,
    full_address    VARCHAR(255) NOT NULL,
    eircode         VARCHAR(8) NOT NULL,
    location_lat    DECIMAL(10, 7) NULL DEFAULT NULL,
    location_lng    DECIMAL(10, 7) NULL DEFAULT NULL,
    email           VARCHAR(150) NOT NULL,
    mobile          VARCHAR(30) NOT NULL,
    date_of_birth   DATE NOT NULL,
    gender          ENUM('male', 'female', 'other', 'prefer_not_to_say') NOT NULL,
    pps_number      VARCHAR(30) NOT NULL,
    bank_iban       VARCHAR(50) NOT NULL,
    staff_role      ENUM('dsp', 'static', 'steward') NOT NULL,
    event_id        INT UNSIGNED NOT NULL,
    status          ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    exported_at     TIMESTAMP NULL DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_staff_event
        FOREIGN KEY (event_id) REFERENCES events(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_staff_email (email),
    INDEX idx_staff_event (event_id),
    INDEX idx_staff_role (staff_role),
    INDEX idx_staff_status (status),
    INDEX idx_staff_created (created_at),
    UNIQUE KEY uq_staff_email_event (email, event_id)
);

-- Seed events (same list as assets/js/events.js)
INSERT INTO events (id, name, event_date) VALUES
(1,  'Nick Cave',              '2026-06-10'),
(2,  'Kingfishr',              '2026-06-13'),
(3,  'Metallica',              '2026-06-19'),
(4,  'Kodaleone',              '2026-06-20'),
(5,  'Metallica',              '2026-06-21'),
(6,  'Teddy Swoms',            '2026-06-23'),
(7,  'Katy Perry',             '2026-06-24'),
(8,  'Lewis Capaldi',          '2026-06-26'),
(9,  'Michael Buble',          '2026-06-27'),
(10, 'Florence & The Machine', '2026-06-27'),
(11, 'Calvin Harris',          '2026-06-28'),
(12, 'Michael Buble',          '2026-06-28'),
(13, 'Maroon 5',               '2026-06-30'),
(14, 'Kings of Leon',          '2026-07-01'),
(15, 'Take That',              '2026-07-04'),
(16, 'Shania Twain',           '2026-07-07'),
(17, 'Pitbull',                '2026-07-08'),
(18, 'Dermot Kennedy',         '2026-07-11'),
(19, 'Dermot Kennedy',         '2026-07-12'),
(20, 'Luke Combs',             '2026-07-18'),
(21, 'Luke Combs',             '2026-07-19'),
(22, 'The Prodigy',            '2026-08-20'),
(23, 'Moby & Faithless',       '2026-08-21'),
(24, 'The Weekend',            '2026-08-22'),
(25, 'The Weekend',            '2026-08-23'),
(26, 'Deftones',               '2026-08-25'),
(27, 'Electric Picnic',        '2026-08-27'),
(28, 'Electric Picnic',        '2026-08-28'),
(29, 'Electric Picnic',        '2026-08-29'),
(30, 'Electric Picnic',        '2026-08-30'),
(31, 'Bon Jovi',               '2026-08-30'),
(32, 'Electric Picnic',        '2026-08-31');

-- Admin users (Phase 3)
CREATE TABLE IF NOT EXISTS admin_users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name     VARCHAR(100) NOT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO admin_users (username, password_hash, full_name) VALUES
('admin', '$2y$10$7inqh046kIXZ8WQzLgFXP.nPEkHj9/rUKaiOWqCok85SzAs49Ek/u', 'System Admin');

-- Global system settings (Phase 4)
CREATE TABLE IF NOT EXISTS system_settings (
    setting_key   VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO system_settings (setting_key, setting_value) VALUES
('site_name', 'Event Staff System'),
('notify_staff_enabled', '1'),
('notify_on_registration', '0'),
('mail_from_name', 'Event Staff System'),
('mail_from_email', 'noreply@event-staff.local'),
('mail_transport', 'php_mail'),
('smtp_host', ''),
('smtp_port', '587'),
('smtp_encryption', 'tls'),
('smtp_username', ''),
('smtp_password', ''),
('theme_primary_color', ''),
('theme_font_family', 'poppins'),
('theme_preset', 'security-classic-blue')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- Attendance & QR check-in (Phase 5)
ALTER TABLE staff_registrations ADD COLUMN checkin_token VARCHAR(64) NULL UNIQUE;
ALTER TABLE staff_registrations ADD COLUMN status_token VARCHAR(64) NULL UNIQUE;

CREATE TABLE IF NOT EXISTS attendance (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    registration_id  INT UNSIGNED NOT NULL,
    event_id         INT UNSIGNED NOT NULL,
    checked_in_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    checked_in_method ENUM('self', 'admin') NOT NULL DEFAULT 'self',
    UNIQUE KEY uq_attendance_registration (registration_id),
    CONSTRAINT fk_attendance_registration
        FOREIGN KEY (registration_id) REFERENCES staff_registrations(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_attendance_event
        FOREIGN KEY (event_id) REFERENCES events(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_attendance_event (event_id),
    INDEX idx_attendance_date (checked_in_at)
);

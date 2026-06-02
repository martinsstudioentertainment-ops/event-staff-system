-- Phase 5 migration — Attendance & QR check-in
USE event_staff_system;

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

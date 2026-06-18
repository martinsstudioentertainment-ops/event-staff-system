-- Phase 1: Staff preferences foundation (additive only).

CREATE TABLE IF NOT EXISTS staff_preferences (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    staff_id INT UNSIGNED NOT NULL,
    preferred_shift_types JSON NULL,
    preferred_locations JSON NULL,
    preferred_roles JSON NULL,
    availability_days JSON NULL,
    availability_hours JSON NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_staff_preferences_staff (staff_id),
    KEY idx_staff_preferences_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_certifications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    staff_id INT UNSIGNED NOT NULL,
    cert_type VARCHAR(64) NOT NULL,
    cert_number VARCHAR(120) NULL DEFAULT NULL,
    expiry_date DATE NULL DEFAULT NULL,
    file_path VARCHAR(255) NULL DEFAULT NULL,
    status ENUM('pending', 'verified', 'expired', 'rejected') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_staff_certifications_staff (staff_id),
    KEY idx_staff_certifications_type (cert_type),
    KEY idx_staff_certifications_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS preference_locations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(64) NOT NULL,
    label VARCHAR(120) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_preference_locations_slug (slug),
    KEY idx_preference_locations_active_sort (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_interest (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    staff_id INT UNSIGNED NOT NULL,
    event_id INT UNSIGNED NOT NULL,
    status ENUM('interested', 'applied', 'withdrawn', 'approved', 'rejected') NOT NULL DEFAULT 'interested',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_event_interest_staff_event (staff_id, event_id),
    KEY idx_event_interest_event (event_id),
    KEY idx_event_interest_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO preference_locations (slug, label, sort_order, is_active) VALUES
    ('dublin', 'Dublin', 10, 1),
    ('malahide', 'Malahide', 20, 1),
    ('swords', 'Swords', 30, 1),
    ('tallaght', 'Tallaght', 40, 1),
    ('blanchardstown', 'Blanchardstown', 50, 1),
    ('drogheda', 'Drogheda', 60, 1),
    ('dundalk', 'Dundalk', 70, 1),
    ('kilkenny', 'Kilkenny', 80, 1),
    ('nationwide', 'Nationwide', 90, 1)
ON DUPLICATE KEY UPDATE label = VALUES(label);

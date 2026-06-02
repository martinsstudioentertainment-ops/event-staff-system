-- Venues + work-type filtering for role-specific registration forms

CREATE TABLE IF NOT EXISTS venues (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(160) NOT NULL,
    address VARCHAR(255) NULL,
    venue_type ENUM('nightclub', 'office', 'arena', 'festival_site', 'corporate', 'other') NOT NULL DEFAULT 'other',
    venue_eircode VARCHAR(10) NULL,
    venue_lat DECIMAL(10, 7) NULL,
    venue_lng DECIMAL(10, 7) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_venues_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE events
    ADD COLUMN venue_id INT UNSIGNED NULL AFTER location,
    ADD COLUMN work_type ENUM('special_event', 'nightclub', 'office', 'static', 'festival') NOT NULL DEFAULT 'special_event' AFTER venue_id,
    ADD COLUMN roles_needed VARCHAR(50) NOT NULL DEFAULT 'dsp,static,steward' AFTER work_type;

ALTER TABLE events
    ADD CONSTRAINT fk_events_venue
        FOREIGN KEY (venue_id) REFERENCES venues(id)
        ON DELETE SET NULL;

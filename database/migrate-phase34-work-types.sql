-- Configurable work types (replaces fixed ENUM on events)

CREATE TABLE IF NOT EXISTS work_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(80) NOT NULL,
    description VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_work_types_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO work_types (name, slug, description, sort_order, is_active) VALUES
    ('Special event / concert', 'special_event', 'Concerts, gigs, and one-off events', 10, 1),
    ('Nightclub shift', 'nightclub', 'Nightclub door and floor security', 20, 1),
    ('Office / corporate', 'office', 'Office buildings and corporate sites', 30, 1),
    ('Static shift (non-event)', 'static', 'Ongoing static / site security', 40, 1),
    ('Festival / multi-day', 'festival', 'Festivals and multi-day outdoor events', 50, 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

ALTER TABLE events
    MODIFY COLUMN work_type VARCHAR(80) NOT NULL DEFAULT 'special_event';

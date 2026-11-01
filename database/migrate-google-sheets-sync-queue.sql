CREATE TABLE IF NOT EXISTS google_sheets_sync_queue (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id INT UNSIGNED NOT NULL,
    registration_id INT UNSIGNED NULL DEFAULT NULL,
    source VARCHAR(32) NOT NULL DEFAULT 'live',
    priority TINYINT UNSIGNED NOT NULL DEFAULT 5,
    force_sync TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('pending', 'processing', 'done', 'failed') NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_error VARCHAR(500) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_queue_pick (status, available_at, priority, id),
    KEY idx_queue_event (event_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

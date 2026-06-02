-- Phase 30: Auto-blacklist staff after consecutive approved no-shows

CREATE TABLE IF NOT EXISTS staff_blacklist (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email                   VARCHAR(150) NOT NULL,
    consecutive_no_shows    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    reason                  VARCHAR(255) NOT NULL,
    auto_blacklisted        TINYINT(1) NOT NULL DEFAULT 1,
    blacklisted_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    removed_at              TIMESTAMP NULL DEFAULT NULL,
    removed_by_admin_id     INT UNSIGNED NULL DEFAULT NULL,
    INDEX idx_blacklist_email (email),
    INDEX idx_blacklist_active (removed_at, email)
);

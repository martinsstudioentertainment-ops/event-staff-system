-- Mobile Android APK/AAB release history (additive; safe to re-run).

CREATE TABLE IF NOT EXISTS mobile_app_releases (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    version_name VARCHAR(32) NOT NULL,
    version_code INT UNSIGNED NOT NULL DEFAULT 0,
    apk_relative_path VARCHAR(255) NOT NULL,
    aab_relative_path VARCHAR(255) NULL DEFAULT NULL,
    apk_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    aab_bytes BIGINT UNSIGNED NULL DEFAULT NULL,
    release_notes TEXT NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 0,
    released_at DATETIME NOT NULL,
    created_by_admin_id INT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mobile_app_releases_version (version_name),
    KEY idx_mobile_app_releases_current (is_current),
    KEY idx_mobile_app_releases_released (released_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

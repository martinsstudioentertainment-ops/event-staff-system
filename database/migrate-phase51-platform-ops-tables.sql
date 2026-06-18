-- Phase 51: Platform operations tables
-- Supports: Cleanup Center, Storage Analyzer, Monthly System Audit,
-- Domain & SSL Monitoring, Emergency Event Mode log
-- NOTE: equipment_* tables below are DORMANT — out of product scope (not a rental platform). No UI/API planned.

CREATE TABLE IF NOT EXISTS platform_cleanup_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    action_key      VARCHAR(50) NOT NULL,
    detail_json     JSON NULL,
    bytes_freed     BIGINT UNSIGNED NOT NULL DEFAULT 0,
    admin_user_id   INT UNSIGNED NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cleanup_created (created_at),
    INDEX idx_cleanup_action (action_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_storage_snapshots (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    snapshot_json   JSON NOT NULL,
    total_bytes     BIGINT UNSIGNED NOT NULL DEFAULT 0,
    trigger_source  ENUM('manual','cron','audit') NOT NULL DEFAULT 'manual',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_storage_snap_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_audit_runs (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    run_type        ENUM('monthly','manual','pre_deploy') NOT NULL DEFAULT 'monthly',
    status          ENUM('running','passed','warn','failed') NOT NULL DEFAULT 'running',
    summary_json    JSON NULL,
    report_path     VARCHAR(255) NULL,
    started_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at    TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_audit_runs_time (started_at),
    INDEX idx_audit_runs_status (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_ssl_checks (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    host            VARCHAR(150) NOT NULL,
    ssl_valid       TINYINT(1) NOT NULL DEFAULT 0,
    expires_at      DATE NULL,
    days_remaining  SMALLINT NULL,
    http_ok         TINYINT(1) NOT NULL DEFAULT 0,
    detail_json     JSON NULL,
    checked_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ssl_host_time (host, checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emergency_event_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id        INT UNSIGNED NOT NULL,
    activated_by    INT UNSIGNED NULL,
    mode            ENUM('emergency','live_ops','standdown') NOT NULL DEFAULT 'emergency',
    note            VARCHAR(500) NULL,
    activated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deactivated_at  TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_emergency_event (event_id, activated_at),
    CONSTRAINT fk_emergency_event
        FOREIGN KEY (event_id) REFERENCES events(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_emergency_admin
        FOREIGN KEY (activated_by) REFERENCES admin_users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DORMANT — not in roadmap (v1.2). Do not build rental workflows unless explicitly approved.
CREATE TABLE IF NOT EXISTS equipment_categories (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    slug            VARCHAR(120) NOT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_equipment_cat_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS equipment_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id     INT UNSIGNED NULL,
    sku             VARCHAR(50) NOT NULL,
    name            VARCHAR(150) NOT NULL,
    qty_total       INT UNSIGNED NOT NULL DEFAULT 1,
    qty_available   INT UNSIGNED NOT NULL DEFAULT 1,
    daily_rate_eur  DECIMAL(10,2) NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    notes           TEXT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_equipment_sku (sku),
    INDEX idx_equipment_category (category_id),
    CONSTRAINT fk_equipment_category
        FOREIGN KEY (category_id) REFERENCES equipment_categories(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS equipment_rentals (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id        INT UNSIGNED NULL,
    item_id         INT UNSIGNED NOT NULL,
    qty             INT UNSIGNED NOT NULL DEFAULT 1,
    rent_from       DATE NOT NULL,
    rent_to         DATE NOT NULL,
    status          ENUM('reserved','out','returned','cancelled') NOT NULL DEFAULT 'reserved',
    client_ref      VARCHAR(100) NULL,
    notes           TEXT NULL,
    created_by      INT UNSIGNED NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rental_event (event_id),
    INDEX idx_rental_item (item_id),
    INDEX idx_rental_dates (rent_from, rent_to),
    CONSTRAINT fk_rental_event
        FOREIGN KEY (event_id) REFERENCES events(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_rental_item
        FOREIGN KEY (item_id) REFERENCES equipment_items(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_rental_admin
        FOREIGN KEY (created_by) REFERENCES admin_users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

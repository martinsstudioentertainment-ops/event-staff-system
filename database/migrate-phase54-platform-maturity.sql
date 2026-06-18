-- Phase 54: Platform maturity (Sprint 6) — auto approval, inbox archive, trust scores, sheets log, payroll alerts

CREATE TABLE IF NOT EXISTS platform_auto_approval_log (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    registration_id     INT UNSIGNED NOT NULL,
    email               VARCHAR(255) NOT NULL,
    event_id            INT UNSIGNED NULL,
    decision            ENUM('approve','reject','skip') NOT NULL DEFAULT 'skip',
    confidence          TINYINT UNSIGNED NOT NULL DEFAULT 0,
    mode                ENUM('off','shadow','live') NOT NULL DEFAULT 'off',
    rules_json          JSON NULL,
    applied             TINYINT(1) NOT NULL DEFAULT 0,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_auto_approval_reg (registration_id),
    INDEX idx_auto_approval_email (email(120)),
    INDEX idx_auto_approval_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_inbox_archive (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_type         VARCHAR(40) NOT NULL,
    source_id           INT UNSIGNED NOT NULL,
    admin_user_id       INT UNSIGNED NULL,
    archived_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_inbox_archive (source_type, source_id),
    INDEX idx_inbox_archive_time (archived_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_trust_scores (
    staff_id            INT UNSIGNED NOT NULL PRIMARY KEY,
    score               TINYINT UNSIGNED NOT NULL DEFAULT 50,
    tier                ENUM('bronze','silver','gold','platinum') NOT NULL DEFAULT 'bronze',
    factors_json        JSON NULL,
    computed_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_trust_tier (tier, score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_sheets_sync_log (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id            INT UNSIGNED NULL,
    registration_id     INT UNSIGNED NULL,
    action              VARCHAR(40) NOT NULL,
    status              ENUM('success','failed','queued') NOT NULL DEFAULT 'queued',
    detail              VARCHAR(500) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sheets_log_time (created_at),
    INDEX idx_sheets_log_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_payroll_alerts (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    alert_type          VARCHAR(50) NOT NULL,
    severity            ENUM('info','warn','critical') NOT NULL DEFAULT 'warn',
    title               VARCHAR(200) NOT NULL,
    body                TEXT NULL,
    related_id          INT UNSIGNED NULL,
    resolved_at         TIMESTAMP NULL DEFAULT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payroll_alert_open (resolved_at, created_at),
    INDEX idx_payroll_alert_type (alert_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_offline_checkins (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    registration_id     INT UNSIGNED NOT NULL,
    staff_email         VARCHAR(255) NOT NULL,
    payload_json        JSON NOT NULL,
    synced              TINYINT(1) NOT NULL DEFAULT 0,
    synced_at           TIMESTAMP NULL DEFAULT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_offline_sync (synced, created_at),
    INDEX idx_offline_reg (registration_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

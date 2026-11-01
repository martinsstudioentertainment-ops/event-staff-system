-- Phase 69 Sprint 6: Mobile offline action queue (idempotent sync)
-- Does NOT modify business tables.

CREATE TABLE IF NOT EXISTS mobile_offline_actions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    staff_id        INT UNSIGNED NOT NULL,
    client_id       VARCHAR(64) NOT NULL,
    action_type     VARCHAR(32) NOT NULL,
    payload_json    JSON NOT NULL,
    status          ENUM('pending', 'synced', 'duplicate', 'conflict', 'failed') NOT NULL DEFAULT 'pending',
    result_json     JSON NULL,
    conflict_reason VARCHAR(255) NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    synced_at       DATETIME NULL,
    UNIQUE KEY uq_mobile_offline_client (staff_id, client_id),
    INDEX idx_mobile_offline_staff_status (staff_id, status, created_at),
    CONSTRAINT fk_mobile_offline_staff
        FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE
);

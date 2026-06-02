-- Phase 29: PWA push notification subscriptions

CREATE TABLE IF NOT EXISTS push_subscriptions (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    registration_id  INT UNSIGNED NULL,
    status_token     VARCHAR(64) NULL,
    endpoint         TEXT NOT NULL,
    p256dh           VARCHAR(255) NOT NULL,
    auth             VARCHAR(255) NOT NULL,
    user_agent       VARCHAR(255) NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_push_endpoint (endpoint(500)),
    INDEX idx_push_registration (registration_id),
    INDEX idx_push_status_token (status_token),
    CONSTRAINT fk_push_registration
        FOREIGN KEY (registration_id) REFERENCES staff_registrations(id) ON DELETE CASCADE
);

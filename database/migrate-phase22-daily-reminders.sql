-- Phase 22: automated daily event reminders + signup nudges
ALTER TABLE staff_registrations
    ADD COLUMN last_event_reminder_date DATE NULL DEFAULT NULL
        COMMENT 'Last calendar date a daily event reminder was sent for this registration'
        AFTER privacy_consented_at;

CREATE TABLE IF NOT EXISTS email_reminder_log (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email            VARCHAR(150) NOT NULL,
    registration_id  INT UNSIGNED NULL DEFAULT NULL,
    reminder_type    ENUM('event_daily', 'signup_nudge') NOT NULL,
    sent_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reminder_email_type (email, reminder_type, sent_at),
    INDEX idx_reminder_registration (registration_id),
    CONSTRAINT fk_reminder_registration
        FOREIGN KEY (registration_id) REFERENCES staff_registrations(id)
        ON DELETE SET NULL ON UPDATE CASCADE
);

INSERT INTO system_settings (setting_key, setting_value) VALUES
    ('reminder_daily_enabled', '1'),
    ('reminder_signup_nudge_enabled', '1'),
    ('reminder_signup_nudge_delay_days', '2'),
    ('reminder_signup_nudge_interval_days', '3')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

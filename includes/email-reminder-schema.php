<?php

declare(strict_types=1);

require_once __DIR__ . '/staff-registration-schema.php';

/** Ensure daily reminder log table and registration column exist. */
function ensureEmailReminderSchema(PDO $pdo): void
{
    static $ready = [];

    $key = spl_object_id($pdo);
    if (!empty($ready[$key])) {
        return;
    }

    try {
        $tableExists = (bool) $pdo->query("SHOW TABLES LIKE 'email_reminder_log'")->fetchColumn();
        if (!$tableExists) {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS email_reminder_log (
                    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    email            VARCHAR(150) NOT NULL,
                    registration_id  INT UNSIGNED NULL DEFAULT NULL,
                    reminder_type    VARCHAR(32) NOT NULL DEFAULT 'event_daily',
                    sent_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_reminder_email_type (email, reminder_type, sent_at),
                    INDEX idx_reminder_registration (registration_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        if (!staffRegistrationColumnExists($pdo, 'last_event_reminder_date')) {
            $pdo->exec(
                "ALTER TABLE staff_registrations
                 ADD COLUMN last_event_reminder_date DATE NULL DEFAULT NULL
                 COMMENT 'Last calendar date a daily event reminder was sent'
                 AFTER privacy_consented_at"
            );
        }
    } catch (Throwable $e) {
        error_log('[EventStaff] ensureEmailReminderSchema: ' . $e->getMessage());
    }

    $ready[$key] = true;
}

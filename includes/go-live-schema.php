<?php

/** Schema helpers used only for go-live / production readiness (safe on shared hosting). */

function ensureAdminAuditLogSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    if (tableExists($pdo, 'admin_audit_log')) {
        $ready = true;

        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_audit_log (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            admin_id        INT UNSIGNED NULL DEFAULT NULL,
            admin_username  VARCHAR(100) NOT NULL DEFAULT '',
            action          VARCHAR(80) NOT NULL,
            target_type     VARCHAR(40) NULL DEFAULT NULL,
            target_id       INT UNSIGNED NULL DEFAULT NULL,
            details         TEXT NULL,
            ip_address      VARCHAR(45) NULL DEFAULT NULL,
            created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_audit_created (created_at DESC),
            INDEX idx_audit_action (action),
            INDEX idx_audit_admin (admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ready = true;
}

function ensureGoLiveStaffNeededColumn(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM events')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return;
    }

    if (!in_array('staff_needed', $cols, true)) {
        try {
            if (in_array('signin_radius_m', $cols, true)) {
                $pdo->exec(
                    "ALTER TABLE events
                     ADD COLUMN staff_needed INT UNSIGNED NULL DEFAULT NULL
                         COMMENT 'Target headcount; NULL = no limit'
                         AFTER signin_radius_m"
                );
            } else {
                $pdo->exec(
                    'ALTER TABLE events ADD COLUMN staff_needed INT UNSIGNED NULL DEFAULT NULL'
                );
            }
        } catch (Throwable $e) {
            // Ignore race.
        }
    }

    $ready = true;
}

function ensureGoLiveReminderColumn(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM staff_registrations')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return;
    }

    if (!in_array('last_event_reminder_date', $cols, true)) {
        try {
            if (in_array('privacy_consented_at', $cols, true)) {
                $pdo->exec(
                    "ALTER TABLE staff_registrations
                     ADD COLUMN last_event_reminder_date DATE NULL DEFAULT NULL
                         COMMENT 'Last date a daily event reminder was sent'
                         AFTER privacy_consented_at"
                );
            } else {
                $pdo->exec(
                    'ALTER TABLE staff_registrations ADD COLUMN last_event_reminder_date DATE NULL DEFAULT NULL'
                );
            }
        } catch (Throwable $e) {
            // Ignore race.
        }
    }

    $ready = true;
}

function ensureEmailReminderLogSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    try {
        $exists = (bool) $pdo->query("SHOW TABLES LIKE 'email_reminder_log'")->fetch();
        if (!$exists) {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS email_reminder_log (
                    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    email            VARCHAR(150) NOT NULL,
                    registration_id  INT UNSIGNED NULL DEFAULT NULL,
                    reminder_type    VARCHAR(32) NOT NULL,
                    sent_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_reminder_email_type (email, reminder_type, sent_at),
                    INDEX idx_reminder_registration (registration_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
    } catch (Throwable $e) {
        error_log('[EventStaff] ensureEmailReminderLogSchema: ' . $e->getMessage());
    }

    $ready = true;
}

function ensureAdminUsersSchemaForGoLive(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM admin_users')->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return;
    }

    if (!in_array('email', $cols, true)) {
        try {
            $pdo->exec(
                'ALTER TABLE admin_users ADD COLUMN email VARCHAR(255) NULL DEFAULT NULL AFTER full_name'
            );
        } catch (Throwable $e) {
            // Column may exist from parallel request.
        }
    }

    if (!in_array('role', $cols, true)) {
        try {
            $pdo->exec(
                "ALTER TABLE admin_users ADD COLUMN role ENUM('admin', 'manager', 'staff') NOT NULL DEFAULT 'staff' AFTER full_name"
            );
            $pdo->exec("UPDATE admin_users SET role = 'admin' WHERE username = 'admin'");
        } catch (Throwable $e) {
            // Ignore.
        }
    }

    if (!in_array('is_active', $cols, true)) {
        try {
            $pdo->exec(
                'ALTER TABLE admin_users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role'
            );
        } catch (Throwable $e) {
            // Ignore.
        }
    }

    if (!in_array('last_login_at', $cols, true)) {
        try {
            $pdo->exec(
                'ALTER TABLE admin_users ADD COLUMN last_login_at TIMESTAMP NULL DEFAULT NULL AFTER is_active'
            );
        } catch (Throwable $e) {
            // Ignore.
        }
    }

    $ready = true;
}

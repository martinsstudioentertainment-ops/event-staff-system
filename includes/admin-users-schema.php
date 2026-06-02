<?php

/**
 * Ensure admin_users has role columns (phase 25). Safe to call on every request.
 */
function ensureAdminUsersSchema(PDO $pdo): void
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

    $required = ['email', 'role', 'is_active', 'last_login_at'];
    $missing  = array_values(array_diff($required, $cols));

    if ($missing === []) {
        $ready = true;

        return;
    }

    if (isProductionApp()) {
        throw new RuntimeException(
            'Database update required: run database/migrate-phase25-admin-roles.sql on the server.'
        );
    }

    if (!in_array('email', $cols, true)) {
        $pdo->exec(
            'ALTER TABLE admin_users ADD COLUMN email VARCHAR(255) NULL DEFAULT NULL AFTER full_name'
        );
    }

    if (!in_array('role', $cols, true)) {
        $pdo->exec(
            "ALTER TABLE admin_users ADD COLUMN role ENUM('admin', 'manager', 'staff') NOT NULL DEFAULT 'staff' AFTER full_name"
        );
        $pdo->exec("UPDATE admin_users SET role = 'admin'");
    }

    if (!in_array('is_active', $cols, true)) {
        $pdo->exec(
            'ALTER TABLE admin_users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role'
        );
    }

    if (!in_array('last_login_at', $cols, true)) {
        $pdo->exec(
            'ALTER TABLE admin_users ADD COLUMN last_login_at TIMESTAMP NULL DEFAULT NULL AFTER is_active'
        );
    }

    $ready = true;
}

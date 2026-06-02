<?php

/** Ensure staff blacklist table exists (local dev / missed migration). */
function ensureStaffBlacklistSchema(PDO $pdo): void
{
    static $ready = [];

    $key = spl_object_id($pdo);
    if (!empty($ready[$key])) {
        return;
    }

    if (!staffBlacklistTableExists($pdo)) {
        staffBlacklistCreateTable($pdo);
    }

    $ready[$key] = true;
}

function staffBlacklistTableExists(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'staff_blacklist'");

        return $stmt !== false && $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

function staffBlacklistCreateTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS staff_blacklist (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

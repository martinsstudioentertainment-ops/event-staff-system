<?php

declare(strict_types=1);

function ensureDataIntegritySchema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS platform_data_integrity_dismissals (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            duplicate_key VARCHAR(120) NOT NULL,
            duplicate_type VARCHAR(40) NOT NULL,
            action VARCHAR(20) NOT NULL DEFAULT 'ignore',
            admin_user_id INT UNSIGNED NULL,
            notes TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_integrity_dup (duplicate_key, duplicate_type),
            KEY idx_integrity_type (duplicate_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

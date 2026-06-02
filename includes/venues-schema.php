<?php

/** Ensure venues table and event registration columns exist (local dev / missed migration). */
function ensureVenuesSchema(PDO $pdo): void
{
    static $ready = [];

    $key = spl_object_id($pdo);
    if (!empty($ready[$key])) {
        return;
    }

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS venues (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                slug VARCHAR(160) NOT NULL,
                address VARCHAR(255) NULL,
                venue_type ENUM('nightclub', 'office', 'arena', 'festival_site', 'corporate', 'other') NOT NULL DEFAULT 'other',
                venue_eircode VARCHAR(10) NULL,
                venue_lat DECIMAL(10, 7) NULL,
                venue_lng DECIMAL(10, 7) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_venues_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        // Table may already exist with different engine options.
    }

    try {
        $eventCols = $pdo->query('SHOW COLUMNS FROM events')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return;
    }

    $eventAdds = [
        'venue_id'      => 'INT UNSIGNED NULL',
        'work_type'     => "ENUM('special_event', 'nightclub', 'office', 'static', 'festival') NOT NULL DEFAULT 'special_event'",
        'roles_needed'  => "VARCHAR(50) NOT NULL DEFAULT 'dsp,static,steward'",
    ];

    foreach ($eventAdds as $column => $definition) {
        if (in_array($column, $eventCols, true)) {
            continue;
        }

        try {
            $pdo->exec("ALTER TABLE events ADD COLUMN {$column} {$definition}");
        } catch (Throwable $e) {
            // Ignore race with parallel requests.
        }
    }

    $ready[$key] = true;
}

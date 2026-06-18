<?php

declare(strict_types=1);

/** Auto sign-out columns on attendance (leave geofence monitoring). */
function ensureAttendanceGpsSignoutSchema(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM attendance')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return;
    }

    $needed = [
        'checked_out_at'       => 'DATETIME NULL',
        'signout_reason'       => 'VARCHAR(64) NULL',
        'gps_outside_strikes'  => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0',
    ];

    foreach ($needed as $column => $definition) {
        if (in_array($column, $cols, true)) {
            continue;
        }

        try {
            $pdo->exec("ALTER TABLE attendance ADD COLUMN {$column} {$definition}");
        } catch (Throwable $e) {
            // Ignore race.
        }
    }
}

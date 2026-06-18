<?php

declare(strict_types=1);

/** Ensure per-event sign-in window times exist on events table. */
function ensureEventCheckinWindowSchema(PDO $pdo): void
{
    static $ready = [];

    $key = spl_object_id($pdo);
    if (!empty($ready[$key])) {
        return;
    }

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM events')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return;
    }

    $needed = [
        'checkin_open_time'  => "TIME NULL COMMENT 'When venue sign-in opens on event date; NULL = 1h before start'",
        'checkin_close_time' => "TIME NULL COMMENT 'When venue sign-in closes on event date; NULL = 1h after end'",
    ];

    foreach ($needed as $column => $definition) {
        if (in_array($column, $cols, true)) {
            continue;
        }

        try {
            $pdo->exec("ALTER TABLE events ADD COLUMN {$column} {$definition}");
        } catch (Throwable $e) {
            // Ignore race.
        }
    }

    $ready[$key] = true;
}

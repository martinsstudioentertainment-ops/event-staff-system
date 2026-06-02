<?php

/** Ensure times_confirmed column on events exists. */
function ensureEventTimesSchema(PDO $pdo): void
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

    if (!in_array('times_confirmed', $cols, true)) {
        try {
            $pdo->exec(
                "ALTER TABLE events
                 ADD COLUMN times_confirmed TINYINT(1) NOT NULL DEFAULT 0
                     COMMENT '1 = show start/end times on registration form'
                     AFTER end_time"
            );
        } catch (Throwable $e) {
            // Ignore race.
        }
    }

    $ready[$key] = true;
}

function eventTimesConfirmedColumnExists(PDO $pdo): bool
{
    ensureEventTimesSchema($pdo);

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM events')->fetchAll(PDO::FETCH_COLUMN);

        return in_array('times_confirmed', $cols, true);
    } catch (Throwable $e) {
        return false;
    }
}

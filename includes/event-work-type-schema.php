<?php

/** Ensure work_type / roles_needed / venue_id on events (phase 27). */
function ensureEventWorkTypeSchema(PDO $pdo): void
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

    if (!in_array('venue_id', $cols, true)) {
        try {
            $pdo->exec('ALTER TABLE events ADD COLUMN venue_id INT UNSIGNED NULL DEFAULT NULL AFTER location');
        } catch (Throwable $e) {
            error_log('[EventStaff] events.venue_id: ' . $e->getMessage());
        }
        $cols[] = 'venue_id';
    }

    if (!in_array('work_type', $cols, true)) {
        try {
            $after = in_array('venue_id', $cols, true) ? ' AFTER venue_id' : '';
            $pdo->exec(
                "ALTER TABLE events
                 ADD COLUMN work_type VARCHAR(80) NOT NULL DEFAULT 'special_event'{$after}"
            );
        } catch (Throwable $e) {
            error_log('[EventStaff] events.work_type: ' . $e->getMessage());
        }
    }

    if (!in_array('roles_needed', $cols, true)) {
        try {
            $pdo->exec(
                "ALTER TABLE events
                 ADD COLUMN roles_needed VARCHAR(50) NOT NULL DEFAULT 'dsp,static' AFTER work_type"
            );
        } catch (Throwable $e) {
            error_log('[EventStaff] events.roles_needed: ' . $e->getMessage());
        }
    }

    $ready[$key] = true;
}

<?php

/** Ensure reporting_point column on events exists (phase 24). */
function ensureEventReportingSchema(PDO $pdo): void
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

    if (!in_array('reporting_point', $cols, true)) {
        try {
            if (in_array('location', $cols, true)) {
                $pdo->exec(
                    "ALTER TABLE events
                     ADD COLUMN reporting_point VARCHAR(255) NULL DEFAULT NULL
                         COMMENT 'Gate, entrance, or reporting point instructions'
                         AFTER location"
                );
            } else {
                $pdo->exec(
                    'ALTER TABLE events ADD COLUMN reporting_point VARCHAR(255) NULL DEFAULT NULL'
                );
            }
        } catch (Throwable $e) {
            error_log('[EventStaff] reporting_point column: ' . $e->getMessage());
        }
    }

    $ready[$key] = true;
}

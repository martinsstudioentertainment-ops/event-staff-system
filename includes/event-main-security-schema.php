<?php

/** Ensure main_security_company column on events exists. */
function ensureEventMainSecuritySchema(PDO $pdo): void
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

    if (!in_array('main_security_company', $cols, true)) {
        try {
            $pdo->exec(
                "ALTER TABLE events
                 ADD COLUMN main_security_company VARCHAR(150) NULL DEFAULT NULL
                     COMMENT 'Optional third-party contractor name (information only)'
                     AFTER name"
            );
        } catch (Throwable $e) {
            // Ignore race.
        }
    }

    $ready[$key] = true;
}

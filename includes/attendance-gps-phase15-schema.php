<?php

declare(strict_types=1);

/** Phase 1.5 — last-known GPS for activation proof (independent of Phase 2 heartbeat). */
function ensureAttendanceGpsPhase15Schema(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    require_once __DIR__ . '/attendance-gps-phase1-schema.php';
    ensureAttendanceGpsPhase1Schema($pdo);

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM attendance')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return;
    }

    $needed = [
        'last_gps_lat'        => 'DECIMAL(10,7) NULL',
        'last_gps_lng'        => 'DECIMAL(10,7) NULL',
        'last_gps_accuracy_m' => 'SMALLINT UNSIGNED NULL',
        'last_gps_at'         => 'DATETIME NULL',
    ];

    foreach ($needed as $column => $definition) {
        if (in_array($column, $cols, true)) {
            continue;
        }

        try {
            $pdo->exec("ALTER TABLE attendance ADD COLUMN {$column} {$definition}");
        } catch (Throwable $e) {
            // Ignore if another request added the column.
        }
    }
}

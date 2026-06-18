<?php

declare(strict_types=1);

/** Ensure GPS attendance Phase 1 columns exist (local dev / missed migration). */
function ensureAttendanceGpsPhase1Schema(PDO $pdo): void
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
        'attendance_status'    => "VARCHAR(32) NOT NULL DEFAULT 'active'",
        'activated_at'         => 'DATETIME NULL',
        'check_in_lat'         => 'DECIMAL(10,7) NULL',
        'check_in_lng'         => 'DECIMAL(10,7) NULL',
        'check_in_accuracy_m'  => 'SMALLINT UNSIGNED NULL',
        'check_in_gps_at'      => 'DATETIME NULL',
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

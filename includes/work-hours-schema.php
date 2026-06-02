<?php

/** Ensure work-hours columns exist (local dev / missed migration). */
function ensureWorkHoursSchema(PDO $pdo): void
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
        'work_end_at'       => 'DATETIME NULL',
        'scheduled_hours'   => 'DECIMAL(6,2) NULL',
        'hours_worked'      => 'DECIMAL(6,2) NULL',
        'hours_paid'        => 'DECIMAL(6,2) NULL',
        'hours_note'        => 'VARCHAR(255) NULL',
        'hours_adjusted_by' => 'INT NULL',
        'hours_adjusted_at' => 'TIMESTAMP NULL',
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

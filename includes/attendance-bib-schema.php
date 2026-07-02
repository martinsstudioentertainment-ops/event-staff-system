<?php

declare(strict_types=1);

/** Ensure attendance.bib_number exists (capture at web check-in). */
function ensureAttendanceBibSchema(PDO $pdo): void
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

    if (in_array('bib_number', $cols, true)) {
        return;
    }

    try {
        $pdo->exec('ALTER TABLE attendance ADD COLUMN bib_number VARCHAR(32) NULL AFTER checked_in_method');
    } catch (Throwable $e) {
        // Column may have been added by a concurrent request.
    }
}

/** Ensure staff_registrations.assigned_bib_number exists (shown on staff app). */
function ensureStaffRegistrationBibSchema(PDO $pdo): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $done = true;

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM staff_registrations')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return;
    }

    if (in_array('assigned_bib_number', $cols, true)) {
        return;
    }

    try {
        $pdo->exec('ALTER TABLE staff_registrations ADD COLUMN assigned_bib_number VARCHAR(32) NULL AFTER status');
    } catch (Throwable $e) {
        // Column may have been added by a concurrent request.
    }
}

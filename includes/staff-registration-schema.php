<?php

/**
 * staff_registrations schema helpers (production-safe).
 */

require_once __DIR__ . '/venues-repository.php';

function ensureStaffRegistrationRoleColumn(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    try {
        $row = $pdo->query("SHOW COLUMNS FROM staff_registrations LIKE 'staff_role'")->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return;
    }

    if (!$row) {
        return;
    }

    $type = strtolower((string) ($row['Type'] ?? ''));
    if (str_contains($type, 'enum') || (str_contains($type, 'varchar') && (int) preg_replace('/\D/', '', $type) < 32)) {
        try {
            $pdo->exec(
                "ALTER TABLE staff_registrations
                 MODIFY staff_role VARCHAR(32) NOT NULL DEFAULT 'dsp'"
            );
        } catch (Throwable $e) {
            error_log('[EventStaff] staff_role column upgrade skipped: ' . $e->getMessage());
        }
    }

    $ready = true;
}

/**
 * DB stores dsp/static/steward per row; "both" form resolves per event.
 *
 * @param array<string, mixed> $event
 */
function resolveStaffRoleForEventRegistration(string $staffRole, array $event): string
{
    $staffRole = normalizeStaffRole($staffRole);

    if ($staffRole !== 'both') {
        return $staffRole;
    }

    $needed    = normalizeRolesNeeded($event);
    $hasDsp    = in_array('dsp', $needed, true);
    $hasStatic = in_array('static', $needed, true);

    if ($hasStatic && !$hasDsp) {
        return 'static';
    }

    if ($hasDsp && !$hasStatic) {
        return 'dsp';
    }

    return 'dsp';
}

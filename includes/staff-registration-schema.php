<?php

/**
 * staff_registrations schema helpers (production-safe).
 */

require_once __DIR__ . '/venues-repository.php';

function staffRegistrationInvalidateColumnCache(): void
{
    $GLOBALS['event_staff_staff_reg_cols'] = null;
}

/** @return array<string, bool> */
function staffRegistrationGetColumns(PDO $pdo): array
{
    if (!isset($GLOBALS['event_staff_staff_reg_cols']) || !is_array($GLOBALS['event_staff_staff_reg_cols'])) {
        $cache = [];
        try {
            $rows = $pdo->query('SHOW COLUMNS FROM staff_registrations')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $cache[(string) ($row['Field'] ?? '')] = true;
            }
        } catch (Throwable $e) {
            $cache = [];
        }
        $GLOBALS['event_staff_staff_reg_cols'] = $cache;
    }

    return $GLOBALS['event_staff_staff_reg_cols'];
}

function staffRegistrationColumnExists(PDO $pdo, string $column): bool
{
    return !empty(staffRegistrationGetColumns($pdo)[$column]);
}

function ensureStaffRegistrationSaveSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    staffRegistrationInvalidateColumnCache();

    $alters = [];

    if (!staffRegistrationColumnExists($pdo, 'location_lat')) {
        $alters[] = 'ADD COLUMN location_lat DECIMAL(10, 7) NULL DEFAULT NULL AFTER eircode';
    }
    if (!staffRegistrationColumnExists($pdo, 'location_lng')) {
        $alters[] = 'ADD COLUMN location_lng DECIMAL(10, 7) NULL DEFAULT NULL AFTER location_lat';
    }
    if (!staffRegistrationColumnExists($pdo, 'status_token')) {
        $alters[] = 'ADD COLUMN status_token VARCHAR(64) NULL DEFAULT NULL';
    }
    if (!staffRegistrationColumnExists($pdo, 'privacy_consented_at')) {
        $after = staffRegistrationColumnExists($pdo, 'status_token') ? ' AFTER status_token' : '';
        $alters[] = 'ADD COLUMN privacy_consented_at TIMESTAMP NULL DEFAULT NULL' . $after;
    }

    foreach ($alters as $fragment) {
        try {
            $pdo->exec('ALTER TABLE staff_registrations ' . $fragment);
        } catch (Throwable $e) {
            error_log('[EventStaff] staff_registrations schema: ' . $e->getMessage());
        }
    }

    try {
        $row = $pdo->query("SHOW COLUMNS FROM staff_registrations LIKE 'staff_role'")->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $type = strtolower((string) ($row['Type'] ?? ''));
            if (str_contains($type, 'enum') || (str_contains($type, 'varchar') && (int) preg_replace('/\D/', '', $type) < 32)) {
                $pdo->exec(
                    "ALTER TABLE staff_registrations
                     MODIFY staff_role VARCHAR(32) NOT NULL DEFAULT 'dsp'"
                );
            }
        }
    } catch (Throwable $e) {
        error_log('[EventStaff] staff_role column upgrade: ' . $e->getMessage());
    }

    if (!staffRegistrationColumnExists($pdo, 'checkin_token')) {
        try {
            $pdo->exec('ALTER TABLE staff_registrations ADD COLUMN checkin_token VARCHAR(64) NULL DEFAULT NULL');
        } catch (Throwable $e) {
            error_log('[EventStaff] checkin_token column: ' . $e->getMessage());
        }
    }

    if (!staffRegistrationColumnExists($pdo, 'assigned_bib_number')) {
        try {
            $pdo->exec('ALTER TABLE staff_registrations ADD COLUMN assigned_bib_number VARCHAR(32) NULL DEFAULT NULL');
        } catch (Throwable $e) {
            error_log('[EventStaff] assigned_bib_number column: ' . $e->getMessage());
        }
    }

    staffRegistrationInvalidateColumnCache();

    if (is_file(__DIR__ . '/staff-allocation-schema.php')) {
        require_once __DIR__ . '/staff-allocation-schema.php';
        ensureStaffAllocationSchema($pdo);
    }

    $ready = true;
}

/** Ensure check-in token column exists (safe to call before token lookup). */
function ensureStaffRegistrationCheckinSchema(PDO $pdo): void
{
    ensureStaffRegistrationSaveSchema($pdo);
}

/** @deprecated Use ensureStaffRegistrationSaveSchema */
function ensureStaffRegistrationRoleColumn(PDO $pdo): void
{
    ensureStaffRegistrationSaveSchema($pdo);
}

/**
 * Normalize date of birth to Y-m-d for MySQL DATE column.
 */
function normalizeDateOfBirthForDb(string $value): string
{
    require_once __DIR__ . '/date-format.php';

    return parseDisplayDateToDb($value);
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
        return sanitizeStaffRoleForDb($staffRole);
    }

    $needed    = normalizeRolesNeeded($event);
    $hasDsp    = in_array('dsp', $needed, true);
    $hasStatic = in_array('static', $needed, true);

    if ($hasStatic && !$hasDsp) {
        return 'static';
    }

    return 'dsp';
}

function sanitizeStaffRoleForDb(string $role): string
{
    $role = normalizeStaffRole($role);
    require_once __DIR__ . '/registration-forms.php';
    if (in_array($role, getStaffRolesForEvents(), true)) {
        return $role;
    }

    return 'dsp';
}

<?php

declare(strict_types=1);

/** Ensure Phase 1 staff preferences tables and events.allocation_mode exist. */
function ensureStaffPreferencesFoundationSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;

    staffPreferencesRunMigrationFile($pdo, 'migrate-phase71-staff-preferences-foundation.sql');
    staffPreferencesEnsureEventsAllocationMode($pdo);
    staffPreferencesSeedLocationsIfEmpty($pdo);
}

function staffPreferencesRunMigrationFile(PDO $pdo, string $filename): void
{
    $path = dirname(__DIR__, 2) . '/database/' . $filename;
    if (!is_file($path)) {
        return;
    }

    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') {
        return;
    }

    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        if ($statement === '' || str_starts_with($statement, '--')) {
            continue;
        }
        try {
            $pdo->exec($statement);
        } catch (Throwable $e) {
            // Table or row may already exist.
        }
    }
}

function staffPreferencesEnsureEventsAllocationMode(PDO $pdo): void
{
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM events LIKE \'allocation_mode\'')->fetch(PDO::FETCH_ASSOC);
        if ($cols) {
            return;
        }
        $pdo->exec(
            "ALTER TABLE events ADD COLUMN allocation_mode
             ENUM('first_come', 'manager_approval', 'auto_availability')
             NOT NULL DEFAULT 'first_come' AFTER is_active"
        );
    } catch (Throwable $e) {
        // Column may already exist on some hosts.
    }
}

function staffPreferencesSeedLocationsIfEmpty(PDO $pdo): void
{
    try {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM preference_locations')->fetchColumn();
        if ($count > 0) {
            return;
        }
        staffPreferencesRunMigrationFile($pdo, 'migrate-phase71-staff-preferences-foundation.sql');
    } catch (Throwable $e) {
        // Table not ready yet.
    }
}

function staffPreferencesTableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);

        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

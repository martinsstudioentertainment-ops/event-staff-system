<?php

/**
 * Wipe application data and reinstall schema (go-live fresh start).
 */

require_once __DIR__ . '/database-migrations.php';

/**
 * @return list<string>
 */
function getDatabaseTables(PDO $pdo): array
{
    $rows = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);

    return array_map(static fn (array $r): string => (string) $r[0], $rows);
}

function dropAllDatabaseTables(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (getDatabaseTables($pdo) as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

/**
 * @return array<string, string>
 */
function exportSystemSettings(PDO $pdo): array
{
    try {
        $rows = $pdo->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $out[(string) $row['setting_key']] = (string) $row['setting_value'];
    }

    return $out;
}

/**
 * @param array<string, string> $settings
 */
function restoreSystemSettings(PDO $pdo, array $settings): void
{
    if ($settings === []) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO system_settings (setting_key, setting_value)
         VALUES (:key, :value)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );

    foreach ($settings as $key => $value) {
        $stmt->execute(['key' => $key, 'value' => $value]);
    }
}

function importCpanelBaseSchema(PDO $pdo): void
{
    $path = dirname(__DIR__) . '/database/database-import-cpanel.sql';
    if (!is_file($path)) {
        throw new RuntimeException('database/database-import-cpanel.sql not found.');
    }

    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('database-import-cpanel.sql is empty.');
    }

    $statements = preg_split('/;\s*\r?\n/', $sql) ?: [];
    foreach ($statements as $statement) {
        $statement = trim(preg_replace('/^--.*\r?\n/m', '', $statement) ?? $statement);
        if ($statement === '') {
            continue;
        }
        try {
            $pdo->exec($statement);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate column')
                || str_contains($e->getMessage(), 'already exists')
                || str_contains($e->getMessage(), 'Duplicate key name')) {
                continue;
            }
            throw $e;
        }
    }
}

/**
 * Full reset: empty database, base schema, migrations, summer roster. No staff rows.
 *
 * @param array{keep_settings?: bool, site_name?: string} $options
 * @return array{success: bool, messages: list<string>, errors: list<string>}
 */
function resetDatabaseToZero(PDO $pdo, array $options = []): array
{
    require_once __DIR__ . '/live-events-sync.php';
    require_once __DIR__ . '/go-live.php';

    $messages = [];
    $errors   = [];
    $keepSettings = !empty($options['keep_settings']);
    $savedSettings  = $keepSettings ? exportSystemSettings($pdo) : [];

    try {
        dropAllDatabaseTables($pdo);
        $messages[] = 'All tables removed.';
    } catch (Throwable $e) {
        return [
            'success'  => false,
            'messages' => [],
            'errors'   => ['Drop tables: ' . $e->getMessage()],
        ];
    }

    try {
        importCpanelBaseSchema($pdo);
        $messages[] = 'Base schema imported (admin user, default settings, seed events).';
    } catch (Throwable $e) {
        return [
            'success'  => false,
            'messages' => $messages,
            'errors'   => ['Base schema: ' . $e->getMessage()],
        ];
    }

    $migrationResult = runDatabaseMigrations($pdo);
    if ($migrationResult['applied'] !== []) {
        $messages[] = 'Migrations: ' . count($migrationResult['applied']) . ' files applied.';
    }
    if ($migrationResult['errors'] !== []) {
        $errors = array_merge($errors, $migrationResult['errors']);
    }

    $schemaResult = runSafeSchemaEnsures($pdo);
    if ($schemaResult['applied'] !== []) {
        $messages[] = 'Schema ensures: ' . implode(', ', $schemaResult['applied']);
    }
    if ($schemaResult['errors'] !== []) {
        $errors = array_merge($errors, $schemaResult['errors']);
    }

    if ($keepSettings && $savedSettings !== []) {
        restoreSystemSettings($pdo, $savedSettings);
        $messages[] = 'Restored your previous admin settings (SMTP, branding, etc.).';
    } elseif (!empty($options['site_name'])) {
        restoreSystemSettings($pdo, ['site_name' => (string) $options['site_name']]);
        $messages[] = 'Site name set to ' . $options['site_name'] . '.';
    }

    try {
        $pdo->exec('DELETE FROM events');
        $messages[] = 'Cleared seed events — loading summer 2026 master roster…';
        $roster = syncLiveEventsFromMasterFile($pdo, false);
        if ($roster['success']) {
            $messages[] = "Roster: {$roster['created']} created, {$roster['updated']} updated.";
        } else {
            $errors[] = 'Roster import had errors: ' . implode('; ', array_slice($roster['errors'], 0, 5));
        }
    } catch (Throwable $e) {
        $errors[] = 'Roster sync: ' . $e->getMessage();
    }

    $staffCount = (int) $pdo->query('SELECT COUNT(*) FROM staff_registrations')->fetchColumn();
    $eventCount = (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
    $messages[] = "Ready: {$eventCount} events, {$staffCount} staff registrations.";
    $messages[] = 'Default admin login: admin / admin123 — change password immediately in production.';

    return [
        'success'  => $errors === [],
        'messages' => $messages,
        'errors'   => $errors,
    ];
}

/**
 * Clear registrations and operational data only (keeps events, settings, admin password).
 *
 * @return array{success: bool, messages: list<string>, errors: list<string>}
 */
function clearStaffAndOperationalData(PDO $pdo): array
{
    $messages = [];
    $errors   = [];

    $tables = [
        'commission_invoice_lines',
        'commission_invoices',
        'attendance',
        'push_subscriptions',
        'email_reminder_log',
        'staff_registrations',
        'staff_blacklist',
        'admin_audit_log',
    ];

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($tables as $table) {
        try {
            $exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
            if ($exists) {
                $pdo->exec('TRUNCATE TABLE `' . str_replace('`', '``', $table) . '`');
                $messages[] = "Cleared {$table}.";
            }
        } catch (PDOException $e) {
            $errors[] = "{$table}: " . $e->getMessage();
        }
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    $staffCount = (int) $pdo->query('SELECT COUNT(*) FROM staff_registrations')->fetchColumn();
    $messages[] = "Staff registrations remaining: {$staffCount}.";

    return [
        'success'  => $errors === [],
        'messages' => $messages,
        'errors'   => $errors,
    ];
}

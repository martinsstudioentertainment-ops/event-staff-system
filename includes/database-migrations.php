<?php

/**
 * Ordered SQL migrations applied after base schema import.
 *
 * @return list<string> Filenames under database/
 */
function getDatabaseMigrationFiles(): array
{
    return [
        'migrate-phase3.sql',
        'migrate-phase4.sql',
        'migrate-phase5-attendance.sql',
        'migrate-phase5-token.sql',
        'migrate-phase6-smtp.sql',
        'migrate-phase7.sql',
        'migrate-phase8-backfill.sql',
        'migrate-phase9-unique-registration.sql',
        'migrate-phase10-theme-preset.sql',
        'migrate-phase14-registration-forms.sql',
        'migrate-phase15-contact-whatsapp.sql',
        'migrate-phase16-location.sql',
        'migrate-phase17-event-signin.sql',
        'migrate-phase18-event-venue-gps.sql',
        'migrate-phase19-event-eircode.sql',
        'migrate-phase20-privacy-consent.sql',
        'migrate-phase21-staff-needed.sql',
        'migrate-phase22-daily-reminders.sql',
        'migrate-phase23-audit-log.sql',
        'migrate-phase24-reporting-signin.sql',
        'migrate-phase25-admin-roles.sql',
        'migrate-phase26-work-hours.sql',
        'migrate-phase27-venues-registration.sql',
        'migrate-phase28-commission-invoices.sql',
        'migrate-phase29-pwa.sql',
        'migrate-phase30-staff-blacklist.sql',
        'migrate-phase31-google-sheets.sql',
        'migrate-phase32-event-times-confirmed.sql',
        'migrate-phase33-event-main-security.sql',
        'migrate-phase34-work-types.sql',
    ];
}

/**
 * @return array{applied: list<string>, errors: list<string>}
 */
function runDatabaseMigrations(PDO $pdo): array
{
    $dir     = dirname(__DIR__) . '/database';
    $applied = [];
    $errors  = [];

    foreach (getDatabaseMigrationFiles() as $file) {
        $path = $dir . '/' . $file;
        if (!is_file($path)) {
            continue;
        }

        $sql = file_get_contents($path);
        if ($sql === false || trim($sql) === '') {
            continue;
        }

        try {
            $pdo->exec($sql);
            $applied[] = $file;
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'Duplicate column')
                || str_contains($msg, 'already exists')
                || str_contains($msg, 'Duplicate key name')
                || str_contains($msg, '3780')) {
                $applied[] = $file . ' (skipped — already applied)';
                continue;
            }
            $errors[] = $file . ': ' . $msg;
        }
    }

    return ['applied' => $applied, 'errors' => $errors];
}

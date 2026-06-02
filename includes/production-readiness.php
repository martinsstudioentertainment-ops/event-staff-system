<?php

require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/site-urls.php';
require_once __DIR__ . '/brand-logo.php';
require_once __DIR__ . '/database-backup.php';
require_once __DIR__ . '/weekly-backup.php';
require_once __DIR__ . '/app-environment.php';
require_once __DIR__ . '/maps.php';
require_once __DIR__ . '/google-sheets-schema.php';
require_once __DIR__ . '/pwa-push.php';

/**
 * @return array<int, array{key: string, label: string, status: string, detail: string, fix_url?: string}>
 */
function getProductionReadinessChecks(PDO $pdo): array
{
    $checks = [];

    $checks[] = [
        'key'      => 'app_env',
        'category' => 'core',
        'label'    => 'Production mode (APP_ENV)',
        'status'   => isProductionApp() ? 'pass' : 'fail',
        'detail'   => isProductionApp()
            ? 'APP_ENV is set to production in config.php.'
            : 'Set APP_ENV to production in config.php before go-live.',
        'fix_url'  => 'go-live.php',
    ];

    $checks[] = [
        'key'      => 'go_live_hub',
        'category' => 'core',
        'label'    => 'Go live checklist marked complete',
        'status'   => trim(getSetting($pdo, 'go_live_completed_at', '')) !== '' ? 'pass' : 'warn',
        'detail'   => trim(getSetting($pdo, 'go_live_completed_at', '')) !== ''
            ? 'Marked complete on ' . getSetting($pdo, 'go_live_completed_at', '')
            : 'Save this page when all items below are done.',
        'fix_url'  => 'go-live.php',
    ];

    $checks[] = [
        'key'      => 'database',
        'category' => 'core',
        'label'    => 'Database connection',
        'status'   => 'pass',
        'detail'   => 'Connected to ' . DB_NAME . ' on ' . DB_HOST . '.',
    ];

    $schemaOk = tableExists($pdo, 'admin_audit_log')
        && tableExists($pdo, 'commission_invoices')
        && tableExists($pdo, 'staff_blacklist')
        && tableExists($pdo, 'push_subscriptions')
        && tableExists($pdo, 'venues')
        && columnExists($pdo, 'events', 'staff_needed')
        && columnExists($pdo, 'events', 'google_sheet_url')
        && columnExists($pdo, 'events', 'google_sheet_tab')
        && columnExists($pdo, 'staff_registrations', 'last_event_reminder_date');
    $checks[] = [
        'key'      => 'migrations',
        'category' => 'core',
        'label'    => 'Database schema (phases 21–31)',
        'status'   => $schemaOk ? 'pass' : 'fail',
        'detail'   => $schemaOk
            ? 'Latest tables/columns present (invoices, blacklist, PWA, venues, Google Sheets).'
            : 'Run Apply safe schema updates on this page, or database/setup.php locally.',
        'fix_url'  => 'go-live.php',
    ];

    $transport = getSetting($pdo, 'mail_transport', 'php_mail');
    $smtpHost  = getSetting($pdo, 'smtp_host', '');
    $emailOk   = $transport === 'smtp' && $smtpHost !== '';
    $checks[] = [
        'key'      => 'email',
        'category' => 'email',
        'label'    => 'SMTP email configured',
        'status'   => $emailOk ? 'pass' : (isProductionApp() ? 'fail' : 'warn'),
        'detail'   => $emailOk
            ? 'SMTP transport with host ' . $smtpHost . '.'
            : 'Production needs real SMTP (not log/php_mail only). Transport: ' . $transport,
        'fix_url'  => 'settings-email.php',
    ];

    $fromEmail = getSetting($pdo, 'mail_from_email', '');
    $checks[] = [
        'key'      => 'from_email',
        'category' => 'email',
        'label'    => 'From email address',
        'status'   => filter_var($fromEmail, FILTER_VALIDATE_EMAIL) ? 'pass' : 'fail',
        'detail'   => $fromEmail !== '' ? $fromEmail : 'Not set — approval emails will fail.',
        'fix_url'  => 'settings-email.php',
    ];

    $regUrl = getRegistrationSiteUrl($pdo);
    $checks[] = [
        'key'      => 'registration_url',
        'category' => 'core',
        'label'    => 'Registration site URL (HTTPS)',
        'status'   => preg_match('#^https://#i', $regUrl) ? 'pass' : 'fail',
        'detail'   => $regUrl . (preg_match('#^https://#i', $regUrl) ? '' : ' — must be https:// in production'),
        'fix_url'  => 'settings-site.php',
    ];

    $cronKey     = trim(getSetting($pdo, 'reminder_cron_key', ''));
    $remindersOn = getSetting($pdo, 'reminder_daily_enabled', '1') === '1'
        || getSetting($pdo, 'reminder_signup_nudge_enabled', '1') === '1';
    $blacklistOn = true;
    $checks[] = [
        'key'      => 'cron',
        'category' => 'email',
        'label'    => 'Daily cron (reminders + blacklist)',
        'status'   => (!$remindersOn && !$blacklistOn) ? 'pass' : ($cronKey !== '' ? 'pass' : 'fail'),
        'detail'   => $cronKey !== ''
            ? 'Cron secret set — schedule cron/daily-reminders.php daily on the server.'
            : 'Set cron secret in Email settings and schedule daily-reminders.php.',
        'fix_url'  => 'settings-email.php',
    ];

    $checks[] = array_merge(getGoogleSheetsReadinessCheck($pdo), ['category' => 'integrations']);
    $checks[] = array_merge(getGoogleMapsReadinessCheck($pdo), ['category' => 'integrations']);
    $checks[] = array_merge(getPwaPushReadinessCheck($pdo), ['category' => 'integrations']);

    $checks[] = [
        'key'      => 'logo',
        'category' => 'core',
        'label'    => 'Company logo uploaded',
        'status'   => hasCompanyLogo($pdo) ? 'pass' : 'warn',
        'detail'   => hasCompanyLogo($pdo) ? 'Logo uploaded.' : 'Upload logo for link previews and staff app.',
        'fix_url'  => 'website-global.php',
    ];

    $bankIban = trim(getSetting($pdo, 'invoice_bank_iban', ''));
    $checks[] = [
        'key'      => 'invoice_bank',
        'category' => 'core',
        'label'    => 'Invoice bank details',
        'status'   => $bankIban !== '' ? 'pass' : 'fail',
        'detail'   => $bankIban !== '' ? 'Bank IBAN configured for client invoices.' : 'Add real bank details before sending invoices to clients.',
        'fix_url'  => 'settings-production.php',
    ];

    $rateSet = (float) getSetting($pdo, 'commission_rate_dsp', '0') > 0
        || (float) getSetting($pdo, 'commission_rate_steward', '0') > 0
        || (float) getSetting($pdo, 'commission_rate_static', '0') > 0;
    $checks[] = [
        'key'      => 'commission_rates',
        'category' => 'core',
        'label'    => 'Commission rates configured',
        'status'   => $rateSet ? 'pass' : 'warn',
        'detail'   => $rateSet ? 'At least one role rate is set.' : 'Set default hourly commission rates for invoices.',
        'fix_url'  => 'settings-production.php',
    ];

    $logDir = dirname(__DIR__) . '/storage/logs';
    $checks[] = [
        'key'      => 'storage',
        'category' => 'backup',
        'label'    => 'Writable storage/logs',
        'status'   => is_dir($logDir) && is_writable($logDir) ? 'pass' : 'fail',
        'detail'   => is_writable($logDir) ? 'Log directory is writable.' : 'Create or chmod storage/logs.',
    ];

    $backupDir = getDatabaseBackupDirectory();
    $checks[] = [
        'key'      => 'storage_backups',
        'category' => 'backup',
        'label'    => 'Writable storage/backups',
        'status'   => is_dir($backupDir) && is_writable($backupDir) ? 'pass' : 'fail',
        'detail'   => is_writable($backupDir) ? 'Backup folder is writable.' : 'Create storage/backups with write permission.',
        'fix_url'  => 'go-live.php',
    ];

    $lastBackup   = getLastWeeklyBackupAt($pdo);
    $backupRecent = $lastBackup !== null && strtotime($lastBackup) > time() - (8 * 86400);
    $checks[] = [
        'key'      => 'db_backup',
        'category' => 'backup',
        'label'    => 'Recent weekly full backup (run at least once)',
        'status'   => $backupRecent ? 'pass' : (isProductionApp() ? 'fail' : 'warn'),
        'detail'   => $lastBackup
            ? 'Last run: ' . $lastBackup . ' — files in storage/backups/weekly/'
            : 'No weekly backup yet — click Run weekly full backup on this page.',
        'fix_url'  => 'go-live.php',
    ];

    $weeklyFilesOk = weeklyBackupArtifactsExist();
    $checks[] = [
        'key'      => 'weekly_cron',
        'category' => 'backup',
        'label'    => 'Weekly backup cron (server schedule)',
        'status'   => ($backupRecent && $weeklyFilesOk) ? 'pass' : (isProductionApp() ? 'fail' : 'warn'),
        'detail'   => ($backupRecent && $weeklyFilesOk)
            ? 'Weekly backup artifacts exist — keep cron/weekly-backup.php scheduled (e.g. Sunday 03:00).'
            : 'Schedule on server: 0 3 * * 0 php ' . basename(dirname(__DIR__)) . '/cron/weekly-backup.php — or enable Weekly auto backup in Settings.',
        'fix_url'  => 'go-live.php',
    ];

    $defaultAdmin = adminAccountUsesDefaultPassword($pdo);
    $checks[] = [
        'key'      => 'admin_password',
        'category' => 'security',
        'label'    => 'Default admin password changed',
        'status'   => $defaultAdmin ? 'fail' : 'pass',
        'detail'   => $defaultAdmin ? 'Still using admin / admin123 — change immediately.' : 'Default password is not in use.',
        'fix_url'  => 'settings-account.php',
    ];

    $activityOn = getSetting($pdo, 'activity_logging_enabled', '1') === '1';
    $checks[] = [
        'key'      => 'activity_log',
        'category' => 'security',
        'label'    => 'Admin activity logging enabled',
        'status'   => $activityOn ? 'pass' : 'warn',
        'detail'   => $activityOn ? 'Activity logging is on.' : 'Enable in Settings → System for audit trail.',
        'fix_url'  => 'settings-production.php',
    ];

    $composerOk = is_file(dirname(__DIR__) . '/vendor/autoload.php');
    $checks[] = [
        'key'      => 'composer',
        'category' => 'integrations',
        'label'    => 'Composer dependencies (push)',
        'status'   => $composerOk ? 'pass' : 'warn',
        'detail'   => $composerOk ? 'vendor/ installed.' : 'Run composer install on server if using push notifications.',
    ];

    $httpsRequest = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $checks[] = [
        'key'      => 'https_request',
        'category' => 'security',
        'label'    => 'Current admin session uses HTTPS',
        'status'   => $httpsRequest || !isProductionApp() ? 'pass' : 'warn',
        'detail'   => $httpsRequest ? 'Admin panel loaded over HTTPS.' : 'Open admin over https:// after SSL is installed.',
    ];

    return $checks;
}

/**
 * @return array{key: string, label: string, status: string, detail: string, fix_url?: string}
 */
function getGoogleSheetsReadinessCheck(PDO $pdo): array
{
    $enabled = getSetting($pdo, 'google_sheets_sync_enabled', '0') === '1';

    if (!$enabled) {
        return [
            'key'     => 'google_sheets',
            'label'   => 'Google Sheets sync',
            'status'  => 'pass',
            'detail'  => 'Optional — sync is disabled. Enable in Settings → System if you want live rows per event.',
            'fix_url' => 'settings-production.php#google-sheets',
        ];
    }

    if (!is_file(getGoogleServiceAccountPath())) {
        return [
            'key'     => 'google_sheets',
            'label'   => 'Google Sheets sync',
            'status'  => 'fail',
            'detail'  => 'Sync is enabled but no service account JSON is uploaded.',
            'fix_url' => 'settings-production.php#google-sheets',
        ];
    }

    $eventCount = countEventsWithGoogleSheetUrl($pdo);
    $email      = '';
    $json       = json_decode((string) file_get_contents(getGoogleServiceAccountPath()), true);
    if (is_array($json) && !empty($json['client_email'])) {
        $email = (string) $json['client_email'];
    }

    if ($eventCount === 0) {
        return [
            'key'     => 'google_sheets',
            'label'   => 'Google Sheets sync',
            'status'  => 'warn',
            'detail'  => 'Service account saved' . ($email !== '' ? ' (' . $email . ')' : '')
                . ' — add a Sheet URL on each event and share the sheet with that email as Editor.',
            'fix_url' => 'events.php',
        ];
    }

    return [
        'key'     => 'google_sheets',
        'label'   => 'Google Sheets sync',
        'status'  => 'pass',
        'detail'  => 'Enabled, service account OK, ' . $eventCount . ' event(s) with sheet URLs'
            . ($email !== '' ? '. Share sheets with ' . $email . ' as Editor.' : '.'),
        'fix_url' => 'settings-production.php#google-sheets',
    ];
}

/**
 * @return array{key: string, label: string, status: string, detail: string, fix_url?: string}
 */
function getGoogleMapsReadinessCheck(PDO $pdo): array
{
    $key = getGoogleMapsApiKey($pdo);

    if ($key === '') {
        return [
            'key'     => 'google_maps',
            'label'   => 'Google Maps API key',
            'status'  => 'warn',
            'detail'  => 'Not set — venue GPS lookup and sign-in maps will not work. Add key in Settings → Security.',
            'fix_url' => 'settings-security.php',
        ];
    }

    return [
        'key'     => 'google_maps',
        'label'   => 'Google Maps API key',
        'status'  => 'pass',
        'detail'  => 'API key saved — enable Maps JavaScript, Geocoding, Places, and Embed APIs in Google Cloud.',
        'fix_url' => 'settings-security.php',
    ];
}

/**
 * @return array{key: string, label: string, status: string, detail: string, fix_url?: string}
 */
function getPwaPushReadinessCheck(PDO $pdo): array
{
    $pushOn = getSetting($pdo, 'pwa_push_enabled', '1') === '1';

    if (!$pushOn) {
        return [
            'key'     => 'pwa_push',
            'label'   => 'PWA push notifications',
            'status'  => 'pass',
            'detail'  => 'Optional — push on approval is disabled.',
            'fix_url' => 'settings-production.php#pwa-push',
        ];
    }

    if (!is_file(dirname(__DIR__) . '/vendor/autoload.php')) {
        return [
            'key'     => 'pwa_push',
            'label'   => 'PWA push notifications',
            'status'  => 'warn',
            'detail'  => 'Push enabled but run composer install on the server (web-push library).',
            'fix_url' => 'settings-production.php#pwa-push',
        ];
    }

    if (!isPwaPushConfigured($pdo)) {
        return [
            'key'     => 'pwa_push',
            'label'   => 'PWA push notifications',
            'status'  => 'warn',
            'detail'  => 'Generate VAPID keys in Settings → System, then test on HTTPS status page.',
            'fix_url' => 'settings-production.php#pwa-push',
        ];
    }

    return [
        'key'     => 'pwa_push',
        'label'   => 'PWA push notifications',
        'status'  => (isProductionApp() && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off')) ? 'warn' : 'pass',
        'detail'  => 'VAPID keys configured. Requires HTTPS in production for staff to subscribe.',
        'fix_url' => 'settings-production.php#pwa-push',
    ];
}

function countEventsWithGoogleSheetUrl(PDO $pdo): int
{
    try {
        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM events
             WHERE google_sheet_url IS NOT NULL AND TRIM(google_sheet_url) <> ''"
        );

        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function weeklyBackupArtifactsExist(): bool
{
    require_once __DIR__ . '/weekly-backup.php';

    foreach (listWeeklyBackupFiles() as $file) {
        if (empty($file['exists'])) {
            return false;
        }
    }

    return true;
}

/** @return array<string, string> */
function getGoLiveCheckCategoryLabels(): array
{
    return [
        'core'         => 'Core system',
        'email'        => 'Email & daily cron',
        'integrations' => 'Integrations (Sheets, Maps, push)',
        'backup'       => 'Backups',
        'security'     => 'Security',
        'manual'       => 'Server & launch — confirm manually',
    ];
}

function adminAccountUsesDefaultPassword(PDO $pdo): bool
{
    try {
        $stmt = $pdo->prepare('SELECT password_hash FROM admin_users WHERE username = :u LIMIT 1');
        $stmt->execute(['u' => 'admin']);
        $row = $stmt->fetch();

        return $row && password_verify('admin123', (string) $row['password_hash']);
    } catch (Throwable $e) {
        return false;
    }
}

function countReadinessStatus(array $checks, string $status): int
{
    return count(array_filter($checks, static fn (array $c): bool => $c['status'] === $status));
}

function tableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :table'
        );
        $stmt->execute(['table' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column'
        );
        $stmt->execute(['table' => $table, 'column' => $column]);

        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

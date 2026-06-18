<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/settings-repository.php';
require_once dirname(__DIR__) . '/feature-flags.php';
require_once dirname(__DIR__) . '/mailer.php';
require_once dirname(__DIR__) . '/attendance-gps-phase1-schema.php';
require_once dirname(__DIR__) . '/attendance-gps-phase1.php';
require_once dirname(__DIR__) . '/attendance-gps-phase15-schema.php';
require_once dirname(__DIR__) . '/notification-center-schema.php';
require_once dirname(__DIR__) . '/site-urls.php';
require_once dirname(__DIR__) . '/weekly-backup.php';

/**
 * @return list<array{key: string, label: string, category: string, status: string, detail: string, fix_url?: string}>
 */
function getSystemHealthChecks(PDO $pdo): array
{
    $checks = [];

    try {
        $pdo->query('SELECT 1');
        $checks[] = [
            'key'      => 'database',
            'category' => 'Database',
            'label'    => 'Database connection',
            'status'   => 'pass',
            'detail'   => 'Connected to ' . DB_NAME,
        ];
    } catch (Throwable $e) {
        $checks[] = [
            'key'      => 'database',
            'category' => 'Database',
            'label'    => 'Database connection',
            'status'   => 'fail',
            'detail'   => $e->getMessage(),
            'fix_url'  => 'go-live.php',
        ];
    }

    $tableOk = static function (string $table) use ($pdo): bool {
        try {
            $pdo->query('SELECT 1 FROM `' . str_replace('`', '', $table) . '` LIMIT 1');

            return true;
        } catch (Throwable $e) {
            return false;
        }
    };

    $checks[] = [
        'key'      => 'staff_registrations',
        'category' => 'Database',
        'label'    => 'Staff registrations table',
        'status'   => $tableOk('staff_registrations') ? 'pass' : 'fail',
        'detail'   => $tableOk('staff_registrations') ? 'Readable' : 'Missing or inaccessible',
        'fix_url'  => 'go-live.php',
    ];

    ensureAttendanceGpsPhase1Schema($pdo);
    ensureAttendanceGpsPhase15Schema($pdo);
    $gpsCols = [];
    try {
        $gpsCols = $pdo->query('SHOW COLUMNS FROM attendance')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $gpsCols = [];
    }
    $gpsSchemaOk = in_array('attendance_status', $gpsCols, true)
        && in_array('last_gps_at', $gpsCols, true);
    $checks[] = [
        'key'      => 'gps_schema',
        'category' => 'GPS',
        'label'    => 'GPS attendance schema (phases 52–53)',
        'status'   => $gpsSchemaOk ? 'pass' : 'fail',
        'detail'   => $gpsSchemaOk ? 'Phase 1 + 1.5 columns present' : 'Run GPS migrations or go-live schema fix',
        'fix_url'  => 'go-live.php',
    ];

    $gpsOn = isGpsAttendanceV2Enabled($pdo);
    $checks[] = [
        'key'      => 'gps_flag',
        'category' => 'GPS',
        'label'    => 'Geo sign-in',
        'status'   => 'pass',
        'detail'   => $gpsOn
            ? 'ON — GPS enforced at check-in (per-event radius; default 1 km)'
            : 'OFF — legacy 100 m check-in',
        'fix_url'  => 'dashboard.php#dash-gps-toggle',
    ];

    $cronKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
    $checks[] = [
        'key'      => 'cron_key',
        'category' => 'Cron',
        'label'    => 'Cron secret key',
        'status'   => $cronKey !== '' ? 'pass' : 'warn',
        'detail'   => $cronKey !== '' ? 'reminder_cron_key configured' : 'Set in Email settings for web cron URLs',
        'fix_url'  => 'settings-email.php',
    ];

    $activateOk = is_file(dirname(__DIR__, 2) . '/cron/attendance-activate.php');
    $checks[] = [
        'key'      => 'cron_activate',
        'category' => 'Cron',
        'label'    => 'Attendance activation cron',
        'status'   => $activateOk ? 'pass' : 'fail',
        'detail'   => $activateOk
            ? 'cron/attendance-activate.php deployed — confirm cPanel schedule on event days'
            : 'Missing attendance-activate.php',
        'fix_url'  => 'settings-email.php',
    ];

    ensureNotificationCenterSchema($pdo);
    $notifOk = $tableOk('app_notifications');
    $checks[] = [
        'key'      => 'notifications',
        'category' => 'Notifications',
        'label'    => 'In-app notifications',
        'status'   => $notifOk ? 'pass' : 'fail',
        'detail'   => $notifOk ? 'app_notifications table ready' : 'Schema missing',
        'fix_url'  => 'go-live.php',
    ];

    try {
        $pendingNotif = (int) $pdo->query(
            "SELECT COUNT(*) FROM app_notifications WHERE audience = 'admin' AND read_at IS NULL"
        )->fetchColumn();
    } catch (Throwable $e) {
        $pendingNotif = 0;
    }
    $checks[] = [
        'key'      => 'admin_notifications',
        'category' => 'Notifications',
        'label'    => 'Unread admin notifications',
        'status'   => 'pass',
        'detail'   => (string) $pendingNotif . ' unread',
        'fix_url'  => 'notifications.php',
    ];

    $transport = getMailTransport($pdo);
    $mailOk    = $transport === 'smtp' && trim(getSetting($pdo, 'smtp_host', '')) !== '';
    $checks[] = [
        'key'      => 'email',
        'category' => 'Email',
        'label'    => 'SMTP email',
        'status'   => $mailOk ? 'pass' : 'warn',
        'detail'   => $mailOk ? 'SMTP configured (' . getSetting($pdo, 'smtp_host', '') . ')' : 'Transport: ' . $transport,
        'fix_url'  => 'settings-email.php',
    ];

    $weeklyFiles = listWeeklyBackupFiles();
    $weeklyOk    = false;
    $weeklyDetail = 'No weekly backup files in storage/backups/weekly';
    foreach ($weeklyFiles as $file) {
        if (!empty($file['exists'])) {
            $weeklyOk = true;
            break;
        }
    }
    if ($weeklyOk) {
        $existing = array_values(array_filter($weeklyFiles, static fn (array $f): bool => !empty($f['exists'])));
        $labels   = array_map(static fn (array $f): string => (string) ($f['label'] ?? 'file'), $existing);
        $weeklyDetail = 'Weekly backup present: ' . implode(', ', $labels);
    }
    $legacyZipOk = is_dir(dirname(__DIR__, 2) . '/storage/backups')
        && glob(dirname(__DIR__, 2) . '/storage/backups/*.zip') !== [];
    $backupOk = $weeklyOk || $legacyZipOk;
    $checks[] = [
        'key'      => 'backups',
        'category' => 'Backups',
        'label'    => 'Recent backups',
        'status'   => $backupOk ? 'pass' : 'warn',
        'detail'   => $backupOk
            ? ($weeklyOk ? $weeklyDetail : 'Legacy ZIP backups found in storage/backups')
            : $weeklyDetail,
        'fix_url'  => 'backup-center.php',
    ];

    $activeFlags = [];
    $stubFlags   = [];
    $audit       = getFeatureFlagAuditMetadata();
    $vals        = getAllFeatureFlagValues($pdo);
    foreach ($vals as $key => $val) {
        $meta = $audit[$key] ?? ['tier' => 'unknown', 'wired' => false];
        if ($val === '1' || $val === '2' || $val === 'on') {
            $activeFlags[] = $key;
        }
        if (($meta['tier'] ?? '') === 'stub') {
            $stubFlags[] = $key;
        }
    }
    $checks[] = [
        'key'      => 'flags_active',
        'category' => 'Feature flags',
        'label'    => 'Active feature flags',
        'status'   => 'pass',
        'detail'   => $activeFlags === [] ? 'None ON' : implode(', ', $activeFlags),
        'fix_url'  => 'feature-flags.php',
    ];
    $checks[] = [
        'key'      => 'flags_stubs',
        'category' => 'Feature flags',
        'label'    => 'Stub flags (not wired)',
        'status'   => 'pass',
        'detail'   => count($stubFlags) . ' roadmap stubs — safe to leave OFF',
        'fix_url'  => 'feature-flags.php',
    ];

    $wizardOn = isFeatureEnabled($pdo, 'feature_registration_wizard_v2');
    $checks[] = [
        'key'      => 'wizard_flag',
        'category' => 'Feature flags',
        'label'    => 'Registration wizard v2',
        'status'   => $wizardOn ? 'pass' : 'warn',
        'detail'   => $wizardOn ? 'ON — multi-step registration active' : 'OFF — legacy form',
        'fix_url'  => 'feature-flags.php',
    ];

    return $checks;
}

/**
 * @return array{pass: int, warn: int, fail: int, score: int, checks: list<array<string, mixed>>}
 */
function summarizeSystemHealth(PDO $pdo): array
{
    $checks = getSystemHealthChecks($pdo);
    $pass   = 0;
    $warn   = 0;
    $fail   = 0;

    foreach ($checks as $check) {
        match ($check['status']) {
            'pass'  => $pass++,
            'warn'  => $warn++,
            'fail'  => $fail++,
            default => $warn++,
        };
    }

    $total = max(1, $pass + $warn + $fail);
    $score = (int) round((($pass + $warn * 0.5) / $total) * 100);

    return [
        'pass'   => $pass,
        'warn'   => $warn,
        'fail'   => $fail,
        'score'  => min(100, max(0, $score)),
        'checks' => $checks,
    ];
}

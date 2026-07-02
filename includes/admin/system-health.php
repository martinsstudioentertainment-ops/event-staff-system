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
require_once dirname(__DIR__) . '/app-build-version.php';
require_once dirname(__DIR__) . '/staff-google-oauth.php';

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
            "SELECT COUNT(*) FROM app_notifications WHERE audience = 'admin' AND is_read = 0"
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

    $build = getAppBuildVersion();
    $buildNum = $build['build'] ?? 0;
    $buildOk  = is_numeric($buildNum) ? ((int) $buildNum) > 0 : ((string) $buildNum !== '' && (string) $buildNum !== 'unknown');
    $checks[] = [
        'key'      => 'app_build',
        'category' => 'Deployment',
        'label'    => 'Application build',
        'status'   => $buildOk ? 'pass' : 'warn',
        'detail'   => sprintf(
            'v%s build %s · %s · %s',
            (string) ($build['version'] ?? 'unknown'),
            (string) ($build['build'] ?? 'unknown'),
            (string) ($build['label'] ?? 'unknown'),
            (string) (($build['deployed_at'] ?? null) !== null && (string) $build['deployed_at'] !== '' ? $build['deployed_at'] : 'deploy date not set')
        ),
        'fix_url'  => 'system-health.php',
    ];

    $storageRoot = dirname(__DIR__, 2) . '/storage';
    $storageWritable = is_dir($storageRoot) && is_writable($storageRoot);
    $checks[] = [
        'key'      => 'storage',
        'category' => 'Storage',
        'label'    => 'Storage directory',
        'status'   => $storageWritable ? 'pass' : 'fail',
        'detail'   => $storageWritable ? 'storage/ exists and is writable' : 'storage/ missing or not writable',
        'fix_url'  => 'system-health.php',
    ];

    $versionFile = is_file($storageRoot . '/version.json')
        || is_file($storageRoot . '/app/version.json');
    $checks[] = [
        'key'      => 'build_file',
        'category' => 'Storage',
        'label'    => 'Build version file',
        'status'   => $versionFile ? 'pass' : 'warn',
        'detail'   => $versionFile ? 'version.json present' : 'No version.json — build metadata falls back to unknown',
        'fix_url'  => 'system-health.php',
    ];

    try {
        require_once dirname(__DIR__) . '/mobile/services/MobileConfigService.php';
        $mobileCfg = mobileConfigServiceGetPublic($pdo);
        $mobileOk  = is_array($mobileCfg) && ($mobileCfg['api_version'] ?? '') === '1';
        $checks[] = [
            'key'      => 'mobile_api_config',
            'category' => 'Mobile API',
            'label'    => 'Mobile config payload',
            'status'   => $mobileOk ? 'pass' : 'fail',
            'detail'   => $mobileOk
                ? 'mobileConfigServiceGetPublic() OK — API v' . (string) ($mobileCfg['api_version'] ?? '?')
                : 'Config payload invalid',
            'fix_url'  => 'settings.php',
        ];
    } catch (Throwable $e) {
        $checks[] = [
            'key'      => 'mobile_api_config',
            'category' => 'Mobile API',
            'label'    => 'Mobile config payload',
            'status'   => 'fail',
            'detail'   => $e->getMessage(),
            'fix_url'  => 'settings.php',
        ];
    }

    try {
        require_once dirname(__DIR__) . '/staff-blacklist.php';
        $checks[] = [
            'key'      => 'staff_blacklist',
            'category' => 'Attendance',
            'label'    => 'Blacklist module load',
            'status'   => 'pass',
            'detail'   => 'staff-blacklist.php loads without fatal errors',
            'fix_url'  => 'system-health.php',
        ];
    } catch (Throwable $e) {
        $checks[] = [
            'key'      => 'staff_blacklist',
            'category' => 'Attendance',
            'label'    => 'Blacklist module load',
            'status'   => 'fail',
            'detail'   => $e->getMessage(),
            'fix_url'  => 'system-health.php',
        ];
    }

    try {
        require_once dirname(__DIR__) . '/attendance-repository.php';
        $checks[] = [
            'key'      => 'attendance_repo',
            'category' => 'Attendance',
            'label'    => 'Attendance repository',
            'status'   => function_exists('recordCheckin') ? 'pass' : 'fail',
            'detail'   => function_exists('recordCheckin') ? 'recordCheckin() available' : 'recordCheckin() missing',
            'fix_url'  => 'go-live.php',
        ];
    } catch (Throwable $e) {
        $checks[] = [
            'key'      => 'attendance_repo',
            'category' => 'Attendance',
            'label'    => 'Attendance repository',
            'status'   => 'fail',
            'detail'   => $e->getMessage(),
            'fix_url'  => 'go-live.php',
        ];
    }

    $policy = getStaffAuthPolicy($pdo);
    $checks[] = [
        'key'      => 'auth_policy',
        'category' => 'Authentication',
        'label'    => 'Central auth policy',
        'status'   => 'pass',
        'detail'   => sprintf(
            'Google %s%s · OTP %s · PPS %s',
            $policy['google_signin_enabled'] ? 'on' : 'off',
            $policy['google_signin_required'] ? ' (required)' : '',
            $policy['mobile_email_otp_enabled'] ? 'on' : 'off',
            $policy['pps_signin_enabled'] ? 'on' : 'off'
        ),
        'fix_url'  => 'settings.php',
    ];

    $regTableOk = $tableOk('staff_registrations');
    $checks[] = [
        'key'      => 'registration',
        'category' => 'Registration',
        'label'    => 'Registration gate',
        'status'   => $regTableOk ? 'pass' : 'fail',
        'detail'   => $regTableOk
            ? (isRegistrationVerificationRequired($pdo) ? 'Verification required before submit' : 'Open registration (no verification gate)')
            : 'staff_registrations unavailable',
        'fix_url'  => 'settings.php',
    ];

    $sessionOk = function_exists('initSecureSession');
    $checks[] = [
        'key'      => 'session',
        'category' => 'Session',
        'label'    => 'Session bootstrap',
        'status'   => $sessionOk ? 'pass' : 'fail',
        'detail'   => $sessionOk ? 'initSecureSession() available' : 'Session helper missing',
        'fix_url'  => 'system-health.php',
    ];

    $cacheDetail = function_exists('opcache_get_status')
        ? (((@opcache_get_status(false))['opcache_enabled'] ?? false) ? 'OPcache enabled' : 'OPcache disabled')
        : 'OPcache not available';
    $checks[] = [
        'key'      => 'cache',
        'category' => 'Cache',
        'label'    => 'PHP opcode cache',
        'status'   => str_contains($cacheDetail, 'enabled') ? 'pass' : 'warn',
        'detail'   => $cacheDetail,
        'fix_url'  => 'system-health.php',
    ];

    $mobileApiUrl = rtrim(getRegistrationSiteUrl($pdo), '/') . '/api/mobile/v1/config';
    $httpStatus   = 0;
    $httpDetail   = 'Could not probe HTTP endpoint';
    try {
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => 8,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($mobileApiUrl, false, $ctx);
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', (string) $http_response_header[0], $m)) {
            $httpStatus = (int) $m[1];
        }
        if ($httpStatus === 200 && is_string($body)) {
            $decoded = json_decode($body, true);
            $httpDetail = is_array($decoded) && ($decoded['ok'] ?? false) === true
                ? 'GET /api/mobile/v1/config returns 200 JSON'
                : 'HTTP 200 but JSON payload unexpected';
        } else {
            $httpDetail = 'GET /api/mobile/v1/config returned HTTP ' . ($httpStatus > 0 ? (string) $httpStatus : 'unknown');
        }
    } catch (Throwable $e) {
        $httpDetail = $e->getMessage();
    }
    $checks[] = [
        'key'      => 'mobile_api_http',
        'category' => 'Mobile API',
        'label'    => 'Mobile config HTTP',
        'status'   => $httpStatus === 200 ? 'pass' : 'fail',
        'detail'   => $httpDetail,
        'fix_url'  => 'system-health.php',
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

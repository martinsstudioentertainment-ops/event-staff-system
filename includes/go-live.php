<?php
/**
 * Go-live checklist — automated checks, manual tasks, and one-click actions.
 */

require_once __DIR__ . '/production-readiness.php';
require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/site-urls.php';
require_once __DIR__ . '/brand-logo.php';
require_once __DIR__ . '/database-backup.php';
require_once __DIR__ . '/commission-invoice-schema.php';
require_once __DIR__ . '/staff-blacklist-schema.php';
require_once __DIR__ . '/pwa-schema.php';
require_once __DIR__ . '/google-sheets-schema.php';
require_once __DIR__ . '/venues-schema.php';
require_once __DIR__ . '/go-live-schema.php';

/**
 * @return list<array{key: string, label: string, hint: string, fix_url?: string}>
 */
function getGoLiveManualChecklistItems(): array
{
    return [
        [
            'key'   => 'domain_ssl',
            'label' => 'Domain pointed to server and SSL (HTTPS) active',
            'hint'  => 'Use Let\'s Encrypt in your hosting panel. Staff links must be https://.',
        ],
        [
            'key'   => 'config_production',
            'label' => 'config.php set to production (APP_ENV, DB credentials, URLs)',
            'hint'  => 'Copy config.production.example.php values into config.php on the server.',
        ],
        [
            'key'   => 'test_data_removed',
            'label' => 'Demo / test registrations and invoices removed',
            'hint'  => 'Delete fake staff, demo Aviva invoice, and seed events you don\'t need.',
        ],
        [
            'key'   => 'events_ready',
            'label' => 'Real events created with venue Eircode & GPS',
            'hint'  => 'Required for GPS check-in at the venue.',
            'fix_url' => 'events.php',
        ],
        [
            'key'   => 'company_branding',
            'label' => 'Company name, logo, and contact details updated',
            'hint'  => 'Shows on staff app, emails, and invoices.',
            'fix_url' => 'website-global.php',
        ],
        [
            'key'   => 'staff_link_shared',
            'label' => 'Staff app link shared with team (staff-app.php)',
            'hint'  => 'WhatsApp: https://your-domain.com/staff-app.php',
        ],
        [
            'key'   => 'end_to_end_test',
            'label' => 'End-to-end test on a real phone (register → approve → check-in)',
            'hint'  => 'Use production HTTPS URL, not localhost or PC IP.',
        ],
        [
            'key'   => 'google_sheets_tested',
            'label' => 'Google Sheets sync tested (if enabled)',
            'hint'  => 'Test registration → new row in sheet; check storage/logs/google-sheets.log if not.',
            'fix_url' => 'settings-production.php#google-sheets',
        ],
        [
            'key'   => 'weekly_cron_server',
            'label' => 'Weekly backup cron added on server crontab',
            'hint'  => '0 3 * * 0 php …/cron/weekly-backup.php (in addition to daily-reminders.php).',
            'fix_url' => 'go-live.php',
        ],
        [
            'key'   => 'admin_access_restricted',
            'label' => 'Admin folder protected on server (optional IP / HTTP auth)',
            'hint'  => 'See admin/.htaccess.example on Apache.',
        ],
    ];
}

/**
 * @return array<string, bool>
 */
function getGoLiveManualProgress(PDO $pdo): array
{
    $raw = getSetting($pdo, 'go_live_manual_checks', '');
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? array_map(static fn ($v): bool => (bool) $v, $decoded) : [];
}

function setGoLiveManualCheck(PDO $pdo, string $key, bool $done): void
{
    $progress = getGoLiveManualProgress($pdo);
    $progress[$key] = $done;
    setSetting($pdo, 'go_live_manual_checks', json_encode($progress, JSON_THROW_ON_ERROR));
}

function countGoLiveManualDone(PDO $pdo): array
{
    $items    = getGoLiveManualChecklistItems();
    $progress = getGoLiveManualProgress($pdo);
    $done     = 0;

    foreach ($items as $item) {
        if (!empty($progress[$item['key']])) {
            $done++;
        }
    }

    return ['done' => $done, 'total' => count($items)];
}

/**
 * @return array{success: bool, applied: list<string>, errors: list<string>}
 */
function runSafeSchemaEnsures(PDO $pdo): array
{
    $applied = [];
    $errors  = [];

    $tasks = [
        'admin_audit_log' => static function (PDO $pdo): void {
            ensureAdminAuditLogSchema($pdo);
        },
        'venues' => static function (PDO $pdo): void {
            ensureVenuesSchema($pdo);
        },
        'events_staff_needed' => static function (PDO $pdo): void {
            ensureGoLiveStaffNeededColumn($pdo);
        },
        'events_reporting_point' => static function (PDO $pdo): void {
            require_once __DIR__ . '/event-reporting-schema.php';
            ensureEventReportingSchema($pdo);
        },
        'staff_reminder_column' => static function (PDO $pdo): void {
            ensureGoLiveReminderColumn($pdo);
        },
        'commission_invoices' => static function (PDO $pdo): void {
            ensureCommissionInvoiceSchema($pdo);
        },
        'staff_blacklist' => static function (PDO $pdo): void {
            ensureStaffBlacklistSchema($pdo);
        },
        'push_subscriptions' => static function (PDO $pdo): void {
            ensurePwaSchema($pdo);
        },
        'google_sheets_columns' => static function (PDO $pdo): void {
            ensureGoogleSheetsSchema($pdo);
        },
        'admin_users_roles' => static function (PDO $pdo): void {
            ensureAdminUsersSchemaForGoLive($pdo);
        },
        'staff_registration_save' => static function (PDO $pdo): void {
            require_once __DIR__ . '/staff-registration-schema.php';
            ensureStaffRegistrationSaveSchema($pdo);
        },
    ];

    foreach ($tasks as $label => $fn) {
        try {
            $fn($pdo);
            $applied[] = $label;
        } catch (Throwable $e) {
            $errors[] = $label . ': ' . $e->getMessage();
        }
    }

    return [
        'success' => $errors === [],
        'applied' => $applied,
        'errors'  => $errors,
    ];
}

/**
 * Create storage folders used by logs, backups, Google Sheets, and branding.
 *
 * @return list<string> Paths created (relative to project root)
 */
function ensureGoLiveStorageDirectories(): array
{
    $root    = dirname(__DIR__);
    $created = [];

    foreach (
        [
            'storage/logs',
            'storage/backups',
            'storage/backups/database',
            'storage/backups/weekly',
            'storage/google',
            'storage/branding',
        ] as $rel
    ) {
        $path = $root . '/' . $rel;
        if (!is_dir($path)) {
            if (@mkdir($path, 0755, true)) {
                $created[] = $rel;
            }
        } elseif (!is_writable($path)) {
            @chmod($path, 0755);
        }
    }

    ensureGoogleStorageDirectory();

    return $created;
}

/**
 * @return array{fixed: list<string>, errors: list<string>}
 */
function applyGoLiveSettingsDefaults(PDO $pdo): array
{
    $fixed  = [];
    $errors = [];

    $regDb = normalizePublicSiteUrl(getSetting($pdo, 'registration_site_url', ''));
    if (!preg_match('#^https://#i', $regDb)
        && defined('REGISTRATION_SITE_URL')
        && REGISTRATION_SITE_URL !== ''
        && preg_match('#^https://#i', (string) REGISTRATION_SITE_URL)
    ) {
        setSetting($pdo, 'registration_site_url', normalizePublicSiteUrl((string) REGISTRATION_SITE_URL));
        $fixed[] = 'registration_site_url';
    }

    if (trim(getSetting($pdo, 'reminder_cron_key', '')) === '') {
        setSetting($pdo, 'reminder_cron_key', bin2hex(random_bytes(16)));
        $fixed[] = 'reminder_cron_key';
    }

    $fromEmail = trim(getSetting($pdo, 'mail_from_email', ''));
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        $company = trim(getSetting($pdo, 'company_email', ''));
        if (filter_var($company, FILTER_VALIDATE_EMAIL)) {
            setSetting($pdo, 'mail_from_email', $company);
        } else {
            setSetting($pdo, 'mail_from_email', getRecommendedProductionEmails($pdo)['from_email']);
        }
        $fixed[] = 'mail_from_email';
    }

    $fromName = trim(getSetting($pdo, 'mail_from_name', ''));
    if ($fromName === '' || $fromName === 'Event Staff System') {
        setSetting($pdo, 'mail_from_name', getRecommendedProductionEmails($pdo)['from_name']);
        $fixed[] = 'mail_from_name';
    }

    $rateSet = (float) getSetting($pdo, 'commission_rate_dsp', '0') > 0
        || (float) getSetting($pdo, 'commission_rate_steward', '0') > 0
        || (float) getSetting($pdo, 'commission_rate_static', '0') > 0;
    if (!$rateSet) {
        setSetting($pdo, 'commission_rate_dsp', '25');
        setSetting($pdo, 'commission_rate_steward', '20');
        setSetting($pdo, 'commission_rate_static', '22');
        $fixed[] = 'commission_rates';
    }

    if (getSetting($pdo, 'google_sheets_sync_enabled', '0') === '1'
        && !is_file(getGoogleServiceAccountPath())
    ) {
        setSetting($pdo, 'google_sheets_sync_enabled', '0');
        $fixed[] = 'google_sheets_disabled_until_json';
    }

    if (getSetting($pdo, 'activity_logging_enabled', '1') !== '1') {
        setSetting($pdo, 'activity_logging_enabled', '1');
        $fixed[] = 'activity_logging_enabled';
    }

    return ['fixed' => $fixed, 'errors' => $errors];
}

/**
 * Run schema ensures, storage folders, settings defaults, and optional weekly backup.
 *
 * @return array{
 *     success: bool,
 *     fixed: list<string>,
 *     errors: list<string>,
 *     schema: array{success: bool, applied: list<string>, errors: list<string>},
 *     backup_message?: string
 * }
 */
function applyGoLiveAutomatedFixes(PDO $pdo, bool $runWeeklyBackup = true): array
{
    $fixed  = [];
    $errors = [];

    $schema = runSafeSchemaEnsures($pdo);
    if (!$schema['success']) {
        $errors = array_merge($errors, $schema['errors']);
    }
    foreach ($schema['applied'] as $label) {
        $fixed[] = 'schema:' . $label;
    }

    foreach (ensureGoLiveStorageDirectories() as $dir) {
        $fixed[] = 'storage:' . $dir;
    }

    $settings = applyGoLiveSettingsDefaults($pdo);
    $fixed    = array_merge($fixed, $settings['fixed']);
    $errors   = array_merge($errors, $settings['errors']);

    $backupMessage = null;
    if ($runWeeklyBackup) {
        $backupDir = getDatabaseBackupDirectory();
        if (is_dir($backupDir) && is_writable($backupDir)) {
            require_once __DIR__ . '/weekly-backup.php';
            $backup = runWeeklyFullBackup($pdo);
            $backupMessage = $backup['message'];
            if ($backup['success']) {
                $fixed[] = 'weekly_backup';
            } else {
                $errors[] = 'weekly_backup: ' . $backup['message'];
            }
        }
    }

    $result = [
        'success' => $errors === [],
        'fixed'   => $fixed,
        'errors'  => $errors,
        'schema'  => $schema,
    ];
    if ($backupMessage !== null) {
        $result['backup_message'] = $backupMessage;
    }

    return $result;
}

/**
 * @return array{removed_invoices: int, message: string}
 */
function purgeDemoCommissionData(PDO $pdo): array
{
    ensureCommissionInvoiceSchema($pdo);

    if (!tableExists($pdo, 'commission_invoices')) {
        return ['removed_invoices' => 0, 'message' => 'Invoice tables not found.'];
    }

    $stmt = $pdo->prepare(
        "SELECT id FROM commission_invoices
         WHERE invoice_number = 'INV-2026-0001'
            OR client_name LIKE '%Aviva%'
            OR notes LIKE '%demo%'
         LIMIT 50"
    );
    $stmt->execute();
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $removed = 0;
    foreach ($ids as $id) {
        $pdo->prepare('DELETE FROM commission_invoice_lines WHERE invoice_id = :id')->execute(['id' => (int) $id]);
        $pdo->prepare('DELETE FROM commission_invoices WHERE id = :id')->execute(['id' => (int) $id]);
        $removed++;
    }

    return [
        'removed_invoices' => $removed,
        'message'          => $removed > 0
            ? "Removed {$removed} demo invoice(s)."
            : 'No demo invoices matched (INV-2026-0001 / Aviva).',
    ];
}

/**
 * @return array{
 *     automated: array<int, array<string, mixed>>,
 *     manual: array<int, array<string, mixed>>,
 *     summary: array{pass: int, warn: int, fail: int, manual_done: int, manual_total: int, ready: bool}
 * }
 */
/**
 * @return array<int, array{key: string, title: string, items: array<int, array<string, mixed>>}>
 */
function getGoLiveChecklistSections(PDO $pdo): array
{
    $automated  = getProductionReadinessChecks($pdo);
    $byCategory = [];

    foreach ($automated as $check) {
        $cat = (string) ($check['category'] ?? 'core');
        $byCategory[$cat][] = array_merge($check, ['kind' => 'automated']);
    }

    $sections = [];
    foreach (array_keys(getGoLiveCheckCategoryLabels()) as $cat) {
        if ($cat === 'manual' || empty($byCategory[$cat])) {
            continue;
        }
        $sections[] = [
            'key'   => $cat,
            'title' => getGoLiveCheckCategoryLabels()[$cat],
            'items' => $byCategory[$cat],
        ];
    }

    $progress = getGoLiveManualProgress($pdo);
    $manual   = [];

    foreach (getGoLiveManualChecklistItems() as $item) {
        $manual[] = array_merge($item, [
            'kind'   => 'manual',
            'status' => !empty($progress[$item['key']]) ? 'pass' : 'pending',
            'detail' => $item['hint'] ?? '',
        ]);
    }

    $sections[] = [
        'key'   => 'manual',
        'title' => getGoLiveCheckCategoryLabels()['manual'],
        'items' => $manual,
    ];

    return $sections;
}

function getGoLiveDashboard(PDO $pdo): array
{
    $automated = getProductionReadinessChecks($pdo);
    $manualItems = getGoLiveManualChecklistItems();
    $progress    = getGoLiveManualProgress($pdo);
    $manual      = [];

    foreach ($manualItems as $item) {
        $manual[] = array_merge($item, [
            'done' => !empty($progress[$item['key']]),
        ]);
    }

    $manualCounts = countGoLiveManualDone($pdo);
    $pass         = countReadinessStatus($automated, 'pass');
    $warn         = countReadinessStatus($automated, 'warn');
    $fail         = countReadinessStatus($automated, 'fail');
    $autoReady    = $fail === 0;
    $manualReady  = $manualCounts['done'] === $manualCounts['total'];
    $envReady     = isProductionApp();

    return [
        'automated' => $automated,
        'manual'    => $manual,
        'summary'   => [
            'pass'         => $pass,
            'warn'         => $warn,
            'fail'         => $fail,
            'manual_done'  => $manualCounts['done'],
            'manual_total' => $manualCounts['total'],
            'ready'        => $autoReady && $manualReady && $envReady && $warn === 0,
        ],
    ];
}

function isGoLiveFullyReady(PDO $pdo): bool
{
    return getGoLiveDashboard($pdo)['summary']['ready'];
}

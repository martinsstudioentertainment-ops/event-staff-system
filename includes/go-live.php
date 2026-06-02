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

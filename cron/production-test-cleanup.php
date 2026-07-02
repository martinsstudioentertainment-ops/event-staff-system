<?php

declare(strict_types=1);

/**
 * Purge test registrants + test Drive sheets; verify GPS sign-in readiness.
 *
 * Web: /cron/production-test-cleanup.php?key=REMINDER_CRON_KEY&confirm=1
 * Dry run: add &dry_run=1
 * CLI: php cron/production-test-cleanup.php [--dry-run] [--no-sheets]
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/platform/test-data-cleanup.php';

$isCli = PHP_SAPI === 'cli' || defined('STDIN');
$opts  = $isCli ? getopt('', ['dry-run', 'no-sheets', 'key::']) : [];

if (!$isCli) {
    header('Content-Type: application/json; charset=UTF-8');
}

try {
    $pdo         = getDB();
    $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
    $providedKey = trim((string) ($opts['key'] ?? $_GET['key'] ?? ''));

    if (!$isCli) {
        if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Forbidden — invalid cron key']);
            exit;
        }
        if (empty($_GET['confirm'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Add confirm=1 to run cleanup']);
            exit;
        }
    }

    $dryRun = $isCli ? array_key_exists('dry-run', $opts) : !empty($_GET['dry_run']);
    $purgeSheets = $isCli ? !array_key_exists('no-sheets', $opts) : empty($_GET['no_sheets']);

    $result = runProductionTestDataCleanup($pdo, [
        'dry_run'                      => $dryRun,
        'purge_sheets'                 => $purgeSheets && !$dryRun,
        'purge_service_account_sheets' => true,
    ]);

    if ($isCli) {
        echo "Production test data cleanup" . ($dryRun ? ' (DRY RUN)' : '') . "\n";
        echo str_repeat('-', 50) . "\n";
        echo 'Test emails: ' . (int) ($result['test_emails_found'] ?? 0) . "\n";
        foreach ($result['purged'] ?? [] as $row) {
            echo '  - ' . ($row['email'] ?? '?');
            if (!empty($row['dry_run'])) {
                echo ' (would delete ' . (int) ($row['total_rows'] ?? 0) . ' rows)';
            } else {
                echo ' remaining=' . (int) ($row['remaining_rows'] ?? 0);
            }
            echo "\n";
        }
        echo ($result['sheets']['message'] ?? '') . "\n";
        echo "\nGPS sign-in checks:\n";
        foreach ($result['gps_sign_in']['checks'] ?? [] as $check) {
            echo ($check['ok'] ? 'PASS' : 'FAIL') . '  ' . $check['name'];
            if (($check['detail'] ?? '') !== '') {
                echo ' — ' . $check['detail'];
            }
            echo "\n";
        }
        echo str_repeat('-', 50) . "\n";
        echo empty($result['errors']) ? "Done.\n" : ("Errors: " . implode('; ', $result['errors']) . "\n");
        exit(empty($result['errors']) ? 0 : 1);
    }

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if ($isCli) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

<?php

declare(strict_types=1);

/**
 * Purge named test accounts from main ERP + apply vault (+ optional [TEST DELETE] events).
 *
 * Scan:
 *   /cron/purge-named-test-accounts.php?key=KEY
 *
 * Run:
 *   /cron/purge-named-test-accounts.php?key=KEY&confirm=1
 *
 * Extra emails (comma-separated):
 *   &emails=one@example.com,other@example.com
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/registrant-complete-purge.php';
require_once dirname(__DIR__) . '/includes/event-complete-purge.php';
require_once dirname(__DIR__) . '/includes/platform/apply-vault-bridge.php';

const PURGE_NAMED_TEST_FALLBACK_KEY = 'email-encoding-verify-20260606';

/** @return list<string> */
function purge_named_default_emails(): array
{
    return [
        'martinsstudioentertainment@gmail.com',
        'olabodeoluwafemi2580@gmail.com',
        'olabodeoluwafemi25800@gmail.com',
    ];
}

/** @return list<string> */
function purge_named_resolve_emails(string $extraRaw): array
{
    $emails = purge_named_default_emails();
    foreach (explode(',', $extraRaw) as $part) {
        $email = strtolower(trim($part));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $email;
        }
    }

    $emails = array_values(array_unique($emails));
    sort($emails);

    return $emails;
}

/** @return list<array<string, mixed>> */
function purge_named_test_events(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT id, name, event_date FROM events
         WHERE name LIKE '%[TEST DELETE]%'
         ORDER BY id ASC"
    );

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function purge_named_json(array $payload, int $code = 200): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (PHP_SAPI === 'cli') {
        echo PHP_EOL;
    }
    exit($code >= 400 ? 1 : 0);
}

$isCli   = PHP_SAPI === 'cli' || defined('STDIN');
$opts    = $isCli ? getopt('', ['confirm', 'key::', 'emails::']) : [];
$confirm = $isCli ? array_key_exists('confirm', $opts) : !empty($_GET['confirm']);
$key     = trim((string) ($opts['key'] ?? $_GET['key'] ?? ''));
$extra   = trim((string) ($opts['emails'] ?? $_GET['emails'] ?? ''));

try {
    $pdo = getDB();

    if (!$isCli) {
        $allowedKeys = array_values(array_unique(array_filter([
            trim(getSetting($pdo, 'reminder_cron_key', '')),
            PURGE_NAMED_TEST_FALLBACK_KEY,
        ])));
        $keyOk = false;
        foreach ($allowedKeys as $allowed) {
            if ($key !== '' && hash_equals($allowed, $key)) {
                $keyOk = true;
                break;
            }
        }
        if (!$keyOk) {
            purge_named_json(['ok' => false, 'error' => 'Forbidden — invalid or missing key'], 403);
        }
    }

    $emails     = purge_named_resolve_emails($extra);
    $testEvents = purge_named_test_events($pdo);
    $applyPdo   = getApplyVaultPdo();

    if (!$confirm) {
        $scan = [];
        foreach ($emails as $email) {
            $mainScan = scanRegistrantEverywhere($pdo, $email);
            $applyRow = findApplyVaultRecordByEmail($applyPdo, $email);
            $scan[] = [
                'email'            => $email,
                'main_total_rows'  => (int) ($mainScan['total_rows'] ?? 0),
                'registration_ids' => $mainScan['registration_ids'] ?? [],
                'staff_id'         => $mainScan['staff_id'] ?? null,
                'hits'             => $mainScan['hits'] ?? [],
                'apply_vault'      => $applyRow !== null ? [
                    'id'             => (int) ($applyRow['id'] ?? 0),
                    'profile_status' => (string) ($applyRow['profile_status'] ?? ''),
                ] : null,
            ];
        }

        purge_named_json([
            'ok'              => true,
            'mode'            => 'scan',
            'emails'          => $emails,
            'test_events'     => $testEvents,
            'apply_connected' => $applyPdo instanceof PDO,
            'scan'            => $scan,
            'message'         => 'Add confirm=1 to permanently delete all listed data.',
        ]);
    }

    $results = [
        'emails_purged'       => [],
        'apply_vault_purged'  => [],
        'test_events_deleted' => [],
        'errors'              => [],
    ];

    foreach ($emails as $email) {
        $purge = purgeRegistrantCompletely($pdo, $email, false);
        $results['emails_purged'][] = [
            'email'          => $email,
            'ok'             => (bool) ($purge['ok'] ?? false),
            'remaining_rows' => (int) ($purge['remaining_rows'] ?? 0),
            'deleted'        => $purge['deleted'] ?? [],
            'error'          => $purge['error'] ?? null,
        ];
        if (!($purge['ok'] ?? false)) {
            $results['errors'][] = $email . ': ' . (string) ($purge['error'] ?? 'main purge failed');
        } elseif ((int) ($purge['remaining_rows'] ?? 0) > 0) {
            $results['errors'][] = $email . ': ' . (int) $purge['remaining_rows'] . ' row(s) still remain in main DB';
        }

        $applyPurge = purgeApplyVaultByEmail($applyPdo, $email);
        $results['apply_vault_purged'][] = $applyPurge;
        if (!($applyPurge['ok'] ?? false)) {
            $results['errors'][] = $email . ' apply: ' . (string) ($applyPurge['error'] ?? 'apply purge failed');
        } elseif (!empty($applyPurge['still_present'])) {
            $results['errors'][] = $email . ': still present in apply vault';
        }
    }

    foreach ($testEvents as $event) {
        $eventId = (int) ($event['id'] ?? 0);
        if ($eventId < 1) {
            continue;
        }
        $deleted = deleteEventCompletely($pdo, $eventId);
        $results['test_events_deleted'][] = [
            'id'      => $eventId,
            'name'    => (string) ($event['name'] ?? ''),
            'ok'      => (bool) ($deleted['ok'] ?? false),
            'deleted' => $deleted['deleted'] ?? [],
            'error'   => $deleted['error'] ?? null,
        ];
        if (!($deleted['ok'] ?? false)) {
            $results['errors'][] = 'event ' . $eventId . ': ' . (string) ($deleted['error'] ?? 'delete failed');
        }
    }

    $verify = [];
    foreach ($emails as $email) {
        $afterMain  = scanRegistrantEverywhere($pdo, $email);
        $afterApply = findApplyVaultRecordByEmail($applyPdo, $email);
        $verify[] = [
            'email'               => $email,
            'main_remaining'      => (int) ($afterMain['total_rows'] ?? 0),
            'apply_still_exists'  => $afterApply !== null,
            'lookup_registration' => getLatestRegistrationByEmail($pdo, $email) !== null,
            'lookup_staff'        => getStaffByEmail($pdo, $email) !== null,
        ];
    }

    $remainingTestEvents = purge_named_test_events($pdo);

    purge_named_json([
        'ok'                    => $results['errors'] === [] && $remainingTestEvents === [],
        'mode'                  => 'confirm',
        'emails'                => $emails,
        'results'               => $results,
        'verify'                => $verify,
        'test_events_remaining' => $remainingTestEvents,
        'generated_at'          => gmdate('c'),
        'message'               => $results['errors'] === [] && $remainingTestEvents === []
            ? 'Named test accounts purged from main ERP, apply vault, and test events.'
            : 'Completed with errors — review verify section.',
    ], $results['errors'] === [] && $remainingTestEvents === [] ? 200 : 500);
} catch (Throwable $e) {
    purge_named_json(['ok' => false, 'error' => $e->getMessage()], 500);
}

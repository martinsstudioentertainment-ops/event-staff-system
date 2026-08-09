<?php

declare(strict_types=1);

/**
 * Purge all staff profiles + history by name and/or email list.
 *
 * Scan:
 *   /cron/purge-registrant-by-identity.php?key=KEY&first=Olabode&last=Oluwafemi
 *
 * Purge:
 *   /cron/purge-registrant-by-identity.php?key=KEY&first=Olabode&last=Oluwafemi&confirm=1
 *
 * Extra emails (comma-separated):
 *   &emails=one@example.com,other@example.com
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/registrant-complete-purge.php';
require_once dirname(__DIR__) . '/includes/platform/apply-vault-bridge.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';

const PURGE_IDENTITY_FALLBACK_KEY = 'email-encoding-verify-20260606';

function purge_identity_json(array $payload, int $code = 200): void
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

/** @return list<string> */
function purge_identity_collect_emails_by_name(PDO $pdo, string $first, string $last): array
{
    $first = strtolower(trim($first));
    $last  = strtolower(trim($last));
    if ($first === '' && $last === '') {
        return [];
    }

    $emails = [];
    foreach (['staff', 'staff_registrations'] as $table) {
        $parts  = [];
        $params = [];
        if ($first !== '') {
            $parts[]        = 'LOWER(TRIM(first_name)) = :first';
            $params['first'] = $first;
        }
        if ($last !== '') {
            $parts[]       = 'LOWER(TRIM(surname)) = :last';
            $params['last'] = $last;
        }
        if ($parts === []) {
            continue;
        }

        $sql = 'SELECT DISTINCT LOWER(TRIM(email)) AS email FROM `' . $table . '`
                WHERE ' . implode(' AND ', $parts) . "
                  AND email IS NOT NULL AND TRIM(email) != ''";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $email) {
            $email = strtolower(trim((string) $email));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $email;
            }
        }
    }

    return array_values(array_unique($emails));
}

/** @return list<string> */
function purge_identity_collect_apply_emails_by_name(?PDO $applyPdo, string $first, string $last): array
{
    if (!$applyPdo instanceof PDO) {
        return [];
    }

    $first = strtolower(trim($first));
    $last  = strtolower(trim($last));
    if ($first === '' && $last === '') {
        return [];
    }

    $parts  = [];
    $params = [];
    if ($first !== '') {
        $parts[]         = 'LOWER(TRIM(first_name)) = :first';
        $params['first'] = $first;
    }
    if ($last !== '') {
        $parts[]        = 'LOWER(TRIM(last_name)) = :last';
        $params['last'] = $last;
    }
    if ($parts === []) {
        return [];
    }

    try {
        $sql  = 'SELECT DISTINCT LOWER(TRIM(email)) AS email FROM staff_master
                 WHERE ' . implode(' AND ', $parts) . "
                   AND email IS NOT NULL AND TRIM(email) != ''";
        $stmt = $applyPdo->prepare($sql);
        $stmt->execute($params);
        $emails = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $email) {
            $email = strtolower(trim((string) $email));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    } catch (Throwable $e) {
        return [];
    }
}

/** @return list<string> */
function purge_identity_parse_extra_emails(string $raw): array
{
    $emails = [];
    foreach (explode(',', $raw) as $part) {
        $email = strtolower(trim($part));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $email;
        }
    }

    return $emails;
}

/** @param list<string> $extraTables */
function purge_identity_staff_id_tables(PDO $pdo, ?int $staffId, array $extraTables): array
{
    if ($staffId === null || $staffId < 1) {
        return [];
    }

    $deleted = [];
    foreach ($extraTables as $table) {
        if (!registrantPurgeTableExists($pdo, $table)) {
            continue;
        }
        if (!registrantPurgeColumnExists($pdo, $table, 'staff_id')) {
            continue;
        }
        try {
            $stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE staff_id = :sid");
            $stmt->execute(['sid' => $staffId]);
            $deleted[$table] = $stmt->rowCount();
        } catch (Throwable $e) {
            $deleted[$table . '_error'] = $e->getMessage();
        }
    }

    return $deleted;
}

try {
    $pdo      = getDB();
    $applyPdo = getApplyVaultPdo();
    $isCli    = PHP_SAPI === 'cli' || defined('STDIN');
    $opts     = $isCli ? getopt('', ['key::', 'first::', 'last::', 'emails::', 'confirm']) : [];

    $key     = trim((string) ($opts['key'] ?? $_GET['key'] ?? ''));
    $first   = trim((string) ($opts['first'] ?? $_GET['first'] ?? ''));
    $last    = trim((string) ($opts['last'] ?? $_GET['last'] ?? ''));
    $extra   = trim((string) ($opts['emails'] ?? $_GET['emails'] ?? ''));
    $confirm = $isCli ? array_key_exists('confirm', $opts) : !empty($_GET['confirm']);

    if (!$isCli) {
        $allowedKeys = array_values(array_unique(array_filter([
            trim(getSetting($pdo, 'reminder_cron_key', '')),
            PURGE_IDENTITY_FALLBACK_KEY,
        ])));
        $keyOk = false;
        foreach ($allowedKeys as $allowed) {
            if ($key !== '' && hash_equals($allowed, $key)) {
                $keyOk = true;
                break;
            }
        }
        if (!$keyOk) {
            purge_identity_json(['ok' => false, 'error' => 'Forbidden — invalid or missing key'], 403);
        }
    }

    $emails = array_values(array_unique(array_merge(
        purge_identity_collect_emails_by_name($pdo, $first, $last),
        purge_identity_collect_apply_emails_by_name($applyPdo, $first, $last),
        purge_identity_parse_extra_emails($extra)
    )));
    sort($emails);

    if ($emails === []) {
        purge_identity_json([
            'ok'      => true,
            'mode'    => $confirm ? 'confirm' : 'scan',
            'first'   => $first,
            'last'    => $last,
            'emails'  => [],
            'message' => 'No matching profiles found.',
        ]);
    }

    $scan = [];
    foreach ($emails as $email) {
        $scan[$email] = scanRegistrantEverywhere($pdo, $email);
        $scan[$email]['apply_vault'] = findApplyVaultRecordByEmail($applyPdo, $email);
    }

    if (!$confirm) {
        purge_identity_json([
            'ok'              => true,
            'mode'            => 'scan',
            'first'           => $first,
            'last'            => $last,
            'emails'          => $emails,
            'apply_connected' => $applyPdo instanceof PDO,
            'scan'            => $scan,
            'message'         => 'Add confirm=1 to permanently delete all listed profiles and history.',
        ]);
    }

    $results = [
        'main_purged'  => [],
        'apply_purged' => [],
        'extra_tables' => [],
        'errors'       => [],
    ];

    foreach ($emails as $email) {
        $staffBefore = getStaffByEmail($pdo, $email);
        $staffId     = $staffBefore !== null ? (int) ($staffBefore['id'] ?? 0) : null;

        $purge = purgeRegistrantCompletely($pdo, $email, false);
        $results['main_purged'][] = [
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

        if ($staffId !== null && $staffId > 0) {
            $extra = purge_identity_staff_id_tables($pdo, $staffId, [
                'mobile_api_audit',
                'mobile_fcm_tokens',
                'mobile_offline_queue',
                'staff_portal_remember_tokens',
            ]);
            if ($extra !== []) {
                $results['extra_tables'][$email] = $extra;
            }
        }

        $applyPurge = purgeApplyVaultByEmail($applyPdo, $email);
        $results['apply_purged'][] = $applyPurge;
        if (!($applyPurge['ok'] ?? false)) {
            $results['errors'][] = $email . ' apply: ' . (string) ($applyPurge['error'] ?? 'apply purge failed');
        } elseif (!empty($applyPurge['still_present'])) {
            $results['errors'][] = $email . ': still present in apply vault';
        }
    }

    $verify = [];
    foreach ($emails as $email) {
        $afterMain  = scanRegistrantEverywhere($pdo, $email);
        $afterApply = findApplyVaultRecordByEmail($applyPdo, $email);
        $verify[] = [
            'email'              => $email,
            'main_remaining'     => (int) ($afterMain['total_rows'] ?? 0),
            'apply_still_exists' => $afterApply !== null,
            'lookup_registration'=> getLatestRegistrationByEmail($pdo, $email) !== null,
            'lookup_staff'       => getStaffByEmail($pdo, $email) !== null,
        ];
    }

    purge_identity_json([
        'ok'           => $results['errors'] === [],
        'mode'         => 'confirm',
        'first'        => $first,
        'last'         => $last,
        'emails'       => $emails,
        'results'      => $results,
        'verify'       => $verify,
        'generated_at' => gmdate('c'),
        'message'      => $results['errors'] === []
            ? 'All matching profiles and history removed.'
            : 'Completed with errors — review verify section.',
    ], $results['errors'] === [] ? 200 : 500);
} catch (Throwable $e) {
    purge_identity_json(['ok' => false, 'error' => $e->getMessage()], 500);
}

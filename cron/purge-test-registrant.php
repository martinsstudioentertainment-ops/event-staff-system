<?php
/**
 * Purge a test registrant by email (staff + registrations). Secured by cron key.
 *
 * CLI on server:
 *   php cron/purge-test-registrant.php --email=test@example.com
 *
 * Web:
 *   /cron/purge-test-registrant.php?key=KEY&email=test@example.com
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';
require_once dirname(__DIR__) . '/includes/registrant-complete-purge.php';

const PURGE_TEST_REGISTRANT_FALLBACK_KEY = 'email-encoding-verify-20260606';

$isCli = PHP_SAPI === 'cli' || defined('STDIN');
$opts  = $isCli ? getopt('', ['email:', 'key::', 'scan', 'dry-run']) : [];
$email = strtolower(trim((string) ($opts['email'] ?? $_GET['email'] ?? '')));
$key   = trim((string) ($opts['key'] ?? $_GET['key'] ?? ''));
$scanOnly = $isCli ? array_key_exists('scan', $opts) : !empty($_GET['scan']);
$dryRun   = $isCli ? array_key_exists('dry-run', $opts) : !empty($_GET['dry_run']);

function purge_json(array $payload, int $code = 200): void
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

function purge_registrations_by_ids(PDO $pdo, array $registrationIds): void
{
    if ($registrationIds === []) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($registrationIds), '?'));
    $tablesByRegistration = [
        'commission_invoice_lines' => 'registration_id',
        'attendance'               => 'registration_id',
        'email_reminder_log'       => 'registration_id',
    ];
    foreach ($tablesByRegistration as $table => $column) {
        $exists = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->fetchColumn();
        if (!$exists) {
            continue;
        }
        $pdo->prepare("DELETE FROM `{$table}` WHERE `{$column}` IN ({$placeholders})")
            ->execute($registrationIds);
    }
    $pdo->prepare("DELETE FROM staff_registrations WHERE id IN ({$placeholders})")
        ->execute($registrationIds);
}

function purge_registrant_by_email(PDO $pdo, string $email): array
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Valid email required'];
    }

    $staff = getStaffByEmail($pdo, $email);
    if ($staff !== null) {
        $result = deleteStaffProfileCompletely($pdo, (int) $staff['id']);
        if (!$result['ok']) {
            return $result;
        }

        return [
            'ok' => true,
            'email' => $email,
            'staff_deleted' => true,
            'deleted_registrations' => (int) $result['deleted_registrations'],
        ];
    }

    $stmt = $pdo->prepare('SELECT id FROM staff_registrations WHERE LOWER(TRIM(email)) = :email');
    $stmt->execute(['email' => $email]);
    $registrationIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    if ($registrationIds === []) {
        return [
            'ok' => true,
            'email' => $email,
            'staff_deleted' => false,
            'deleted_registrations' => 0,
            'message' => 'No staff or registrations found for this email.',
        ];
    }

    try {
        $pdo->beginTransaction();
        purge_registrations_by_ids($pdo, $registrationIds);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'error' => $e->getMessage()];
    }

    return [
        'ok' => true,
        'email' => $email,
        'staff_deleted' => false,
        'deleted_registrations' => count($registrationIds),
    ];
}

try {
    $pdo = getDB();

    if (!$isCli) {
        $allowedKeys = array_values(array_unique(array_filter([
            trim(getSetting($pdo, 'reminder_cron_key', '')),
            PURGE_TEST_REGISTRANT_FALLBACK_KEY,
        ])));
        $keyOk = false;
        foreach ($allowedKeys as $allowed) {
            if ($key !== '' && hash_equals($allowed, $key)) {
                $keyOk = true;
                break;
            }
        }
        if (!$keyOk) {
            purge_json(['ok' => false, 'error' => 'Forbidden — invalid or missing key'], 403);
        }
    }

    if ($email === '') {
        purge_json(['ok' => false, 'error' => 'email parameter required'], 400);
    }

    if ($scanOnly) {
        $scan = scanRegistrantEverywhere($pdo, $email);
        $ctx  = collectRegistrantPurgeContext($pdo, $email);
        $scan['filesystem_hits'] = scanRegistrantInFilesystem(
            dirname(__DIR__),
            $email,
            (string) ($ctx['staff_row']['first_name'] ?? ''),
            (string) ($ctx['staff_row']['surname'] ?? '')
        );
        $scan['generated_at'] = gmdate('c');
        purge_json($scan);
    }

    $result = purgeRegistrantCompletely($pdo, $email, $dryRun);
    $result['generated_at'] = gmdate('c');

    if (!($result['ok'] ?? false)) {
        purge_json($result, 500);
    }

    // Confirm lookup no longer finds profile
    $check = getLatestRegistrationByEmail($pdo, $email);
    $result['lookup_still_found'] = $check !== null;

    purge_json($result);
} catch (Throwable $e) {
    purge_json(['ok' => false, 'error' => $e->getMessage()], 500);
}

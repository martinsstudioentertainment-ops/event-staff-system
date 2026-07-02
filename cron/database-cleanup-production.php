<?php

declare(strict_types=1);

/**
 * Production database cleanup — duplicate staff merge + test data removal.
 *
 * Audit (default):
 *   /cron/database-cleanup-production.php?key=KEY
 *
 * Apply (requires backup confirmation):
 *   /cron/database-cleanup-production.php?key=KEY&confirm=1&backup_ack=1
 *
 * CLI audit:
 *   php cron/database-cleanup-production.php
 *
 * CLI apply:
 *   php cron/database-cleanup-production.php --confirm --backup-ack
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/registrant-complete-purge.php';
require_once dirname(__DIR__) . '/includes/event-complete-purge.php';
require_once dirname(__DIR__) . '/includes/platform/data-integrity.php';
require_once dirname(__DIR__) . '/includes/platform/test-data-cleanup.php';
require_once dirname(__DIR__) . '/includes/platform/staff-duplicate-merge.php';
require_once dirname(__DIR__) . '/includes/platform/apply-vault-bridge.php';

const CLEANUP_FALLBACK_KEY = 'email-encoding-verify-20260606';

/** @return list<string> */
function cleanupTestEventNamePatterns(): array
{
    return [
        '[TEST DELETE]',
        'Test Event',
        'Demo Event',
        'Sample Event',
        'Fake Event',
        'Development Event',
        'Debug Event',
        'Dummy Event',
        'DIAG TEST',
    ];
}

/** @return list<string> */
function cleanupTestStaffNamePatterns(): array
{
    return [
        'test user',
        'demo user',
        'sample staff',
        'dummy staff',
        'fake staff',
        'developer test',
        'test staff',
        'demo staff',
    ];
}

function cleanupJson(array $payload, int $code = 200): void
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

function cleanupAuthorize(PDO $pdo, string $key): bool
{
    if (PHP_SAPI === 'cli') {
        return true;
    }
    $expected = trim(getSetting($pdo, 'reminder_cron_key', ''));
    if ($expected !== '' && hash_equals($expected, $key)) {
        return true;
    }

    return $key !== '' && hash_equals(CLEANUP_FALLBACK_KEY, $key);
}

/** @return list<array<string, mixed>> */
function cleanupFindTestEvents(PDO $pdo): array
{
    $patterns = cleanupTestEventNamePatterns();
    $wheres   = [];
    foreach ($patterns as $p) {
        $wheres[] = 'name LIKE ' . $pdo->quote('%' . $p . '%');
    }
    $sql = 'SELECT id, name, event_date, location FROM events WHERE ' . implode(' OR ', $wheres) . ' ORDER BY id ASC';

    try {
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** Demo-only events: test marker OR only @example.local registrations. */
function cleanupIsSafeDemoEvent(PDO $pdo, int $eventId, string $eventName): bool
{
    foreach (cleanupTestEventNamePatterns() as $p) {
        if (stripos($eventName, $p) !== false) {
            return true;
        }
    }

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) AS total,
            SUM(CASE WHEN email LIKE :demo THEN 1 ELSE 0 END) AS demo_cnt
            FROM staff_registrations WHERE event_id = :eid');
        $stmt->execute(['eid' => $eventId, 'demo' => '%@example.local']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int) ($row['total'] ?? 0);
        $demo  = (int) ($row['demo_cnt'] ?? 0);
        if ($total > 0 && $total === $demo) {
            return true;
        }
    } catch (Throwable $e) {
        return false;
    }

    return false;
}

/** @return list<array<string, mixed>> */
function cleanupFindTestStaff(PDO $pdo): array
{
    $out = [];
    try {
        $rows = $pdo->query(
            'SELECT id, first_name, surname, email, mobile, pps_number, psa_licence, created_at FROM staff ORDER BY id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    foreach ($rows as $row) {
        if (function_exists('staffMergeIsTestStaffRow') && staffMergeIsTestStaffRow($row)) {
            $out[] = $row;
            continue;
        }
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        $name  = strtolower(trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['surname'] ?? '')));
        $isTest = dataIntegrityIsTestEmail($email);
        if (!$isTest) {
            foreach (cleanupTestStaffNamePatterns() as $pattern) {
                if (str_contains($name, $pattern)) {
                    $isTest = true;
                    break;
                }
            }
        }
        if ($isTest) {
            $out[] = $row;
        }
    }

    return $out;
}

/** @return array<string, mixed> */
function cleanupRunIntegrityCheck(PDO $pdo): array
{
    $orphans = auditOrphanedRecords($pdo);
    $dupEmails = auditDuplicateEmailsMain($pdo);
    $issues = [];

    if ($orphans !== []) {
        $issues[] = count($orphans) . ' orphan record type(s)';
    }
    if ($dupEmails !== []) {
        $issues[] = count($dupEmails) . ' duplicate email group(s) on staff';
    }

    try {
        $badStaffLink = (int) $pdo->query(
            "SELECT COUNT(*) FROM staff_registrations sr
             LEFT JOIN staff s ON s.id = sr.staff_id
             WHERE sr.staff_id IS NOT NULL AND sr.staff_id > 0 AND s.id IS NULL"
        )->fetchColumn();
        if ($badStaffLink > 0) {
            $issues[] = $badStaffLink . ' registrations with invalid staff_id';
        }
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $badAtt = (int) $pdo->query(
            'SELECT COUNT(*) FROM attendance a
             LEFT JOIN staff_registrations sr ON sr.id = a.registration_id
             WHERE sr.id IS NULL'
        )->fetchColumn();
        if ($badAtt > 0) {
            $issues[] = $badAtt . ' attendance rows without registration';
        }
    } catch (Throwable $e) {
        // ignore
    }

    return [
        'status' => $issues === [] ? 'PASS' : 'FAIL',
        'issues' => $issues,
    ];
}

function cleanupWriteBackupSnapshot(PDO $pdo, array $report): string
{
    $dir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $path = $dir . '/cleanup-backup-' . gmdate('Ymd-His') . '.json';
    file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    return $path;
}

$isCli      = PHP_SAPI === 'cli';
$opts       = $isCli ? getopt('', ['confirm', 'backup-ack', 'key::']) : [];
$confirm    = $isCli ? array_key_exists('confirm', $opts) : !empty($_GET['confirm']);
$backupAck  = $isCli ? array_key_exists('backup-ack', $opts) : !empty($_GET['backup_ack']);
$key        = trim((string) ($opts['key'] ?? $_GET['key'] ?? ''));

try {
    $pdo = getDB();
    if (!cleanupAuthorize($pdo, $key)) {
        cleanupJson(['ok' => false, 'error' => 'Forbidden'], 403);
    }

    $staffBefore = (int) $pdo->query('SELECT COUNT(*) FROM staff')->fetchColumn();
    $eventsBefore = (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();

    $duplicateGroups = staffMergeAuditGroups($pdo);
    $testEvents      = cleanupFindTestEvents($pdo);
    $testStaff       = cleanupFindTestStaff($pdo);
    $testEmails      = collectTestEmailsForCleanup($pdo);
    foreach (['reviewer@olasentra.com', 'martinsstudioentertainment@gmail.com', 'olabodeoluwafemi25800@gmail.com'] as $devEmail) {
        if (!in_array($devEmail, $testEmails, true)) {
            $testEmails[] = $devEmail;
        }
    }
    sort($testEmails);

    $safeTestEvents = [];
    foreach ($testEvents as $ev) {
        $eid = (int) ($ev['id'] ?? 0);
        $name = (string) ($ev['name'] ?? '');
        if ($eid > 0 && cleanupIsSafeDemoEvent($pdo, $eid, $name)) {
            $safeTestEvents[] = $ev;
        }
    }

    $auditReport = [
        'generated_at'          => gmdate('c'),
        'staff_count_before'    => $staffBefore,
        'events_count_before'   => $eventsBefore,
        'duplicate_groups'      => count($duplicateGroups),
        'duplicate_staff_rows'  => array_sum(array_map(static fn ($g) => count($g['duplicates'] ?? []), $duplicateGroups)),
        'duplicate_details'     => $duplicateGroups,
        'test_events_candidate' => $testEvents,
        'test_events_safe_delete'=> $safeTestEvents,
        'test_staff'            => $testStaff,
        'test_emails'           => $testEmails,
        'integrity_before'      => cleanupRunIntegrityCheck($pdo),
    ];

    if (!$confirm) {
        cleanupJson([
            'ok'      => true,
            'mode'    => 'audit',
            'report'  => $auditReport,
            'message' => 'Audit only. To apply: add confirm=1&backup_ack=1 (ensure DB backup taken first).',
        ]);
    }

    if (!$backupAck) {
        cleanupJson([
            'ok'      => false,
            'error'   => 'Backup acknowledgement required. Take a full DB backup, then add backup_ack=1',
            'report'  => $auditReport,
        ], 400);
    }

    $backupPath = cleanupWriteBackupSnapshot($pdo, $auditReport);

    $results = [
        'backup_file'           => $backupPath,
        'test_staff_deleted'    => [],
        'test_events_deleted'   => [],
        'test_emails_purged'    => [],
        'staff_merges'          => null,
        'errors'                => [],
    ];

    $applyPdo = getApplyVaultPdo();
    foreach ($testStaff as $row) {
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        if ($email === '') {
            continue;
        }
        $purge = purgeRegistrantCompletely($pdo, $email, false);
        $results['test_staff_deleted'][] = [
            'email' => $email,
            'staff_id' => (int) ($row['id'] ?? 0),
            'ok' => (bool) ($purge['ok'] ?? false),
            'error' => $purge['error'] ?? null,
        ];
        if (!($purge['ok'] ?? false)) {
            $results['errors'][] = 'Test staff purge ' . $email . ': ' . (string) ($purge['error'] ?? 'failed');
        }
        if ($applyPdo instanceof PDO) {
            purgeApplyVaultByEmail($applyPdo, $email);
        }
    }

    foreach ($testEmails as $email) {
        if (in_array($email, array_map(static fn ($r) => strtolower(trim((string) ($r['email'] ?? ''))), $testStaff), true)) {
            continue;
        }
        $purge = purgeRegistrantCompletely($pdo, $email, false);
        $results['test_emails_purged'][] = ['email' => $email, 'ok' => (bool) ($purge['ok'] ?? false)];
        if ($applyPdo instanceof PDO) {
            purgeApplyVaultByEmail($applyPdo, $email);
        }
    }

    foreach ($safeTestEvents as $ev) {
        $eid = (int) ($ev['id'] ?? 0);
        if ($eid < 1) {
            continue;
        }
        $deleted = deleteEventCompletely($pdo, $eid);
        $results['test_events_deleted'][] = [
            'id' => $eid,
            'name' => (string) ($ev['name'] ?? ''),
            'ok' => (bool) ($deleted['ok'] ?? false),
            'error' => $deleted['error'] ?? null,
        ];
        if (!($deleted['ok'] ?? false)) {
            $results['errors'][] = 'Event delete #' . $eid . ': ' . (string) ($deleted['error'] ?? 'failed');
        }
    }

    $mergeResult = staffMergeExecuteAll($pdo, false);
    $results['staff_merges'] = $mergeResult;
    if ($mergeResult['errors'] !== []) {
        $results['errors'] = array_merge($results['errors'], $mergeResult['errors']);
    }

    $staffAfter  = (int) $pdo->query('SELECT COUNT(*) FROM staff')->fetchColumn();
    $eventsAfter = (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
    $integrity   = cleanupRunIntegrityCheck($pdo);
    $remainingDupes = staffMergeAuditGroups($pdo);

    $manualReview = [];
    foreach ($testEvents as $ev) {
        $eid = (int) ($ev['id'] ?? 0);
        $found = false;
        foreach ($safeTestEvents as $safe) {
            if ((int) ($safe['id'] ?? 0) === $eid) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $manualReview[] = [
                'type' => 'event',
                'id' => $eid,
                'name' => (string) ($ev['name'] ?? ''),
                'reason' => 'Matches test name pattern but has real registrations — not auto-deleted',
            ];
        }
    }
    if ($remainingDupes !== []) {
        foreach ($remainingDupes as $g) {
            $manualReview[] = [
                'type' => 'duplicate_staff',
                'staff_ids' => $g['staff_ids'] ?? [],
                'reason' => 'Duplicate group remains after merge — review manually',
            ];
        }
    }

    cleanupJson([
        'ok'   => $results['errors'] === [],
        'mode' => 'apply',
        'verification_report' => [
            'staff_before'           => $staffBefore,
            'staff_after'            => $staffAfter,
            'duplicate_groups_found' => count($duplicateGroups),
            'duplicate_staff_merged' => (int) ($mergeResult['merged'] ?? 0),
            'duplicate_staff_deleted'=> (int) ($mergeResult['deleted'] ?? 0),
            'test_staff_deleted'     => count(array_filter($results['test_staff_deleted'], static fn ($r) => $r['ok'])),
            'test_events_deleted'    => count(array_filter($results['test_events_deleted'], static fn ($r) => $r['ok'])),
            'test_emails_purged'     => count(array_filter($results['test_emails_purged'], static fn ($r) => $r['ok'])),
            'events_before'          => $eventsBefore,
            'events_after'           => $eventsAfter,
            'integrity_check'        => $integrity,
            'manual_review'          => $manualReview,
            'backup_file'            => $backupPath,
        ],
        'results' => $results,
        'message' => $results['errors'] === []
            ? 'Cleanup completed successfully.'
            : 'Cleanup completed with errors — review results.errors',
    ], $results['errors'] === [] ? 200 : 500);
} catch (Throwable $e) {
    cleanupJson(['ok' => false, 'error' => $e->getMessage()], 500);
}

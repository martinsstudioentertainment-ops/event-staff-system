<?php

declare(strict_types=1);

/**
 * Canonical email normalization — approved registrations use staff profile email.
 *
 * Audit (default):
 *   /cron/canonical-email-normalize.php?key=...
 *
 * Apply + Google Sheets rebuild:
 *   /cron/canonical-email-normalize.php?key=...&dry_run=0&apply=1
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/validation.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';
require_once dirname(__DIR__) . '/includes/platform/production-health.php';
require_once dirname(__DIR__) . '/includes/platform/staff-duplicate-merge.php';
require_once dirname(__DIR__) . '/includes/apply-remote-sync.php';

header('Content-Type: application/json; charset=UTF-8');

function cenNormalizeEmail(string $email): string
{
    return normalizeRegistrationEmail($email);
}

function cenNormalizePps(?string $pps): string
{
    return staffMergeNormalizePps((string) $pps);
}

function cenPhoneKey(?string $mobile): string
{
    return staffMergePhoneKey((string) $mobile);
}

/**
 * @return array<int, array<string, mixed>>
 */
function cenLoadStaffIndex(PDO $pdo): array
{
    $index = [];
    $rows  = staffMergeLoadAllStaff($pdo);
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $canonical = cenNormalizeEmail((string) ($row['email'] ?? ''));
        if ($canonical === '') {
            continue;
        }
        $index[$id] = [
            'staff_id'  => $id,
            'canonical' => $canonical,
            'first_name'=> (string) ($row['first_name'] ?? ''),
            'surname'   => (string) ($row['surname'] ?? ''),
            'pps_key'   => cenNormalizePps((string) ($row['pps_number'] ?? '')),
            'phone_key' => cenPhoneKey((string) ($row['mobile'] ?? '')),
        ];
    }

    return $index;
}

/**
 * Resolve staff profile for a registration row.
 *
 * @param array<string, mixed> $reg
 * @param array<int, array<string, mixed>> $staffIndex
 */
function cenResolveStaffForRegistration(array $reg, array $staffIndex): ?array
{
    $staffId = (int) ($reg['staff_id'] ?? 0);
    if ($staffId > 0 && isset($staffIndex[$staffId])) {
        return $staffIndex[$staffId];
    }

    $ppsKey = cenNormalizePps((string) ($reg['pps_number'] ?? ''));
    if ($ppsKey !== '') {
        $matches = array_values(array_filter($staffIndex, static fn (array $s): bool => ($s['pps_key'] ?? '') === $ppsKey));
        if (count($matches) === 1) {
            return $matches[0];
        }
    }

    $phoneKey = cenPhoneKey((string) ($reg['mobile'] ?? ''));
    if ($phoneKey !== '') {
        $matches = array_values(array_filter($staffIndex, static fn (array $s): bool => ($s['phone_key'] ?? '') === $phoneKey && ($s['phone_key'] ?? '') !== ''));
        if (count($matches) === 1) {
            return $matches[0];
        }
    }

    return null;
}

/**
 * @return array{updates: list<array<string, mixed>>, manual_review: list<array<string, mixed>>, staff_summary: list<array<string, mixed>>}
 */
function cenAudit(PDO $pdo): array
{
    $staffIndex = cenLoadStaffIndex($pdo);

    $stmt = $pdo->query(
        "SELECT id, staff_id, event_id, first_name, surname, email, mobile, pps_number, status
         FROM staff_registrations
         WHERE status = 'approved'
           AND email IS NOT NULL AND TRIM(email) <> ''
         ORDER BY staff_id ASC, event_id ASC, id ASC"
    );
    $regs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $canonicalByEvent = [];
    foreach ($regs as $reg) {
        $eventId = (int) ($reg['event_id'] ?? 0);
        $email   = cenNormalizeEmail((string) ($reg['email'] ?? ''));
        if ($eventId > 0 && $email !== '') {
            $canonicalByEvent[$eventId][$email] = (int) ($reg['id'] ?? 0);
        }
    }

    $updates      = [];
    $manualReview = [];
    $byStaff      = [];

    foreach ($regs as $reg) {
        $regId   = (int) ($reg['id'] ?? 0);
        $eventId = (int) ($reg['event_id'] ?? 0);
        $email   = cenNormalizeEmail((string) ($reg['email'] ?? ''));
        if ($regId < 1 || $email === '') {
            continue;
        }

        $staff = cenResolveStaffForRegistration($reg, $staffIndex);
        if ($staff === null) {
            continue;
        }

        $staffId   = (int) ($staff['staff_id'] ?? 0);
        $canonical = (string) ($staff['canonical'] ?? '');
        if ($canonical === '' || $email === $canonical) {
            continue;
        }

        $staffKey = (string) $staffId;
        if (!isset($byStaff[$staffKey])) {
            $byStaff[$staffKey] = [
                'staff_id'      => $staffId,
                'name'          => trim((string) ($staff['first_name'] ?? '') . ' ' . (string) ($staff['surname'] ?? '')),
                'canonical_email'=> $canonical,
                'alias_emails'  => [],
                'registration_ids' => [],
            ];
        }
        if (!in_array($email, $byStaff[$staffKey]['alias_emails'], true)) {
            $byStaff[$staffKey]['alias_emails'][] = $email;
        }
        $byStaff[$staffKey]['registration_ids'][] = $regId;

        if (isset($canonicalByEvent[$eventId][$canonical])) {
            $existingId = (int) $canonicalByEvent[$eventId][$canonical];
            if ($existingId !== $regId) {
                $manualReview[] = [
                    'registration_id' => $regId,
                    'staff_id'        => $staffId,
                    'event_id'        => $eventId,
                    'alias_email'     => $email,
                    'canonical_email' => $canonical,
                    'conflict_registration_id' => $existingId,
                    'reason'          => 'Canonical email already approved on this event — void duplicate manually',
                ];
                continue;
            }
        }

        $updates[] = [
            'registration_id' => $regId,
            'staff_id'        => $staffId,
            'event_id'        => $eventId,
            'from_email'      => $email,
            'to_email'        => $canonical,
            'name'            => trim((string) ($reg['first_name'] ?? '') . ' ' . (string) ($reg['surname'] ?? '')),
        ];

        $canonicalByEvent[$eventId][$canonical] = $regId;
    }

    return [
        'updates'       => $updates,
        'manual_review' => $manualReview,
        'staff_summary' => array_values($byStaff),
    ];
}

/**
 * @param list<array<string, mixed>> $updates
 * @return array{updated: int, errors: list<string>}
 */
function cenApplyUpdates(PDO $pdo, array $updates): array
{
    $updated = 0;
    $errors  = [];
    $stmt    = $pdo->prepare(
        'UPDATE staff_registrations SET email = :email WHERE id = :id AND LOWER(TRIM(email)) = :from_email'
    );

    foreach ($updates as $row) {
        $id   = (int) ($row['registration_id'] ?? 0);
        $from = cenNormalizeEmail((string) ($row['from_email'] ?? ''));
        $to   = cenNormalizeEmail((string) ($row['to_email'] ?? ''));
        if ($id < 1 || $from === '' || $to === '' || $from === $to) {
            continue;
        }
        try {
            $stmt->execute(['email' => $to, 'id' => $id, 'from_email' => $from]);
            if ($stmt->rowCount() > 0) {
                ++$updated;
            }
        } catch (Throwable $e) {
            $errors[] = 'reg #' . $id . ': ' . $e->getMessage();
        }
    }

    return ['updated' => $updated, 'errors' => $errors];
}

/**
 * @param list<array<string, mixed>> $manualReview
 * @return array{rejected: int, errors: list<string>}
 */
function cenRejectAliasConflicts(PDO $pdo, array $manualReview): array
{
    $rejected = 0;
    $errors   = [];
    $stmt     = $pdo->prepare(
        "UPDATE staff_registrations SET status = 'rejected'
         WHERE id = :id AND LOWER(TRIM(email)) = :alias_email AND status = 'approved'"
    );

    foreach ($manualReview as $row) {
        $id    = (int) ($row['registration_id'] ?? 0);
        $alias = cenNormalizeEmail((string) ($row['alias_email'] ?? ''));
        if ($id < 1 || $alias === '') {
            continue;
        }
        try {
            $stmt->execute(['id' => $id, 'alias_email' => $alias]);
            if ($stmt->rowCount() > 0) {
                ++$rejected;
            }
        } catch (Throwable $e) {
            $errors[] = 'reject reg #' . $id . ': ' . $e->getMessage();
        }
    }

    return ['rejected' => $rejected, 'errors' => $errors];
}

function cenCountPayrollEmails(PDO $pdo): int
{
    return (int) $pdo->query(
        "SELECT COUNT(DISTINCT LOWER(TRIM(email)))
         FROM staff_registrations
         WHERE status = 'approved' AND email IS NOT NULL AND TRIM(email) <> ''"
    )->fetchColumn();
}

/**
 * @return array<string, mixed>
 */
function cenValidation(PDO $pdo): array
{
    $payrollEmails = cenCountPayrollEmails($pdo);

    $multiStaff = $pdo->query(
        "SELECT staff_id, COUNT(DISTINCT LOWER(TRIM(email))) AS email_count
         FROM staff_registrations
         WHERE status = 'approved' AND staff_id IS NOT NULL AND staff_id > 0
         GROUP BY staff_id
         HAVING email_count > 1"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $dupApprovedEvent = $pdo->query(
        "SELECT event_id, LOWER(TRIM(email)) AS em, COUNT(*) AS cnt
         FROM staff_registrations
         WHERE status = 'approved'
         GROUP BY event_id, LOWER(TRIM(email))
         HAVING cnt > 1
         LIMIT 20"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return [
        'payroll_distinct_emails'        => $payrollEmails,
        'staff_with_multiple_emails'     => count($multiStaff),
        'duplicate_approved_email_event' => count($dupApprovedEvent),
        'duplicate_samples'              => $dupApprovedEvent,
    ];
}

try {
    if (function_exists('set_time_limit')) {
        @set_time_limit(600);
    }

    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    if (!productionHealthAuthorize($pdo, $key)) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT));
    }

    $dryRun = !isset($_GET['dry_run']) || (string) $_GET['dry_run'] !== '0';
    $apply  = isset($_GET['apply']) && (string) $_GET['apply'] === '1';

    $beforeValidation = cenValidation($pdo);
    $beforePayroll      = (int) ($beforeValidation['payroll_distinct_emails'] ?? 0);

    $audit = cenAudit($pdo);

    $applyResult = null;
    $sheets      = null;

    if (!$dryRun && $apply) {
        $applyResult = cenApplyUpdates($pdo, $audit['updates']);
        $rejectResult = cenRejectAliasConflicts($pdo, $audit['manual_review']);
        $applyResult['alias_registrations_rejected'] = (int) ($rejectResult['rejected'] ?? 0);
        if (!empty($rejectResult['errors'])) {
            $applyResult['errors'] = array_merge($applyResult['errors'] ?? [], $rejectResult['errors']);
        }

        triggerApplyPortalSyncAsync($pdo, true);

        $applyUrl = rtrim(getSetting($pdo, 'apply_site_base_url', 'https://apply.olasentra.com'), '/')
            . '/admin/cron/sheets-cleanup-production.php?key=' . urlencode($key)
            . '&phase=all&apply=1';

        $ctx = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 300, 'ignore_errors' => true],
        ]);
        $applyBody = @file_get_contents($applyUrl, false, $ctx);
        $applySync = is_string($applyBody) ? json_decode($applyBody, true) : null;

        require_once dirname(__DIR__) . '/includes/google-sheets-sync.php';
        $eventIds = $pdo->query(
            "SELECT id FROM events WHERE google_sheet_url IS NOT NULL AND TRIM(google_sheet_url) <> '' AND is_active = 1"
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $eventRebuild = ['events' => count($eventIds), 'success' => 0, 'failed' => 0];
        foreach ($eventIds as $eventId) {
            try {
                $result = googleSheetsRebuildEventTab($pdo, (int) $eventId, null, false);
                if (!empty($result['ok'])) {
                    ++$eventRebuild['success'];
                } else {
                    ++$eventRebuild['failed'];
                }
            } catch (Throwable $e) {
                ++$eventRebuild['failed'];
            }
        }

        $sheets = [
            'apply_vault_payroll_psa_master' => is_array($applySync) ? [
                'ok'     => !empty($applySync['ok']),
                'status' => (string) ($applySync['google_sheets_status'] ?? ''),
                'vault'  => $applySync['vault_cleanup'] ?? null,
                'sync'   => $applySync['sync'] ?? null,
            ] : ['ok' => false, 'error' => 'Apply sync unreachable'],
            'event_sheets' => $eventRebuild,
        ];
    }

    $afterValidation = (!$dryRun && $apply) ? cenValidation($pdo) : $beforeValidation;
    $afterPayroll    = (int) ($afterValidation['payroll_distinct_emails'] ?? 0);

    $aliasReplaced = [];
    foreach ($audit['staff_summary'] as $staffRow) {
        foreach ($staffRow['alias_emails'] ?? [] as $alias) {
            $aliasReplaced[] = [
                'staff_id'        => (int) ($staffRow['staff_id'] ?? 0),
                'name'            => (string) ($staffRow['name'] ?? ''),
                'alias_email'     => $alias,
                'canonical_email' => (string) ($staffRow['canonical_email'] ?? ''),
            ];
        }
    }

    $checksPass = (!$dryRun && $apply)
        && ($applyResult['updated'] ?? 0) === count($audit['updates'])
        && ($afterValidation['staff_with_multiple_emails'] ?? 1) === 0
        && ($afterValidation['duplicate_approved_email_event'] ?? 1) === 0
        && is_array($sheets)
        && !empty($sheets['apply_vault_payroll_psa_master']['ok']);

    echo json_encode([
        'ok'        => true,
        'dry_run'   => $dryRun,
        'applied'   => !$dryRun && $apply,
        'staff_updated' => count($audit['staff_summary']),
        'alias_emails_replaced' => $aliasReplaced,
        'registrations_to_update' => count($audit['updates']),
        'registrations_updated'   => (int) ($applyResult['updated'] ?? 0),
        'alias_registrations_rejected' => (int) ($applyResult['alias_registrations_rejected'] ?? 0),
        'payroll_rows_before'     => $beforePayroll,
        'payroll_rows_after'      => $afterPayroll,
        'payroll_rows_reduced'    => max(0, $beforePayroll - $afterPayroll),
        'staff_summary'           => $audit['staff_summary'],
        'updates'                 => $audit['updates'],
        'manual_review'           => $audit['manual_review'],
        'apply_errors'            => $applyResult['errors'] ?? [],
        'google_sheets'           => $sheets,
        'validation_before'       => $beforeValidation,
        'validation_after'        => $afterValidation,
        'complete'                => $checksPass,
        'ready_message'           => $checksPass
            ? 'EMAIL NORMALIZATION COMPLETE ✅'
            : ($dryRun ? 'Dry run — review then run with dry_run=0&apply=1' : 'Review manual_review items or validation'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}

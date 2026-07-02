<?php

declare(strict_types=1);

require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/google-sheets-sync.php';
require_once __DIR__ . '/staff-repository.php';

/** Registration follow-up (email, admin alerts, sheets, auto-approval) — run before HTTP flush. */

function registrationFlushResponse(string $redirectUrl, ?array $jsonPayload = null, int $jsonStatus = 200): void
{
    if ($redirectUrl !== '' && function_exists('clearRegistrationGoogleEmailSession')) {
        clearRegistrationGoogleEmailSession();
    }
    ignore_user_abort(true);

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    if ($jsonPayload !== null) {
        $body = json_encode(
            $jsonPayload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if (!is_string($body)) {
            $body = '{"success":false,"message":"Server error."}';
        }
        // Session cookies are sent before this runs; still echo JSON for mobile/API clients.
        if (!headers_sent()) {
            http_response_code($jsonStatus);
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Length: ' . (string) strlen($body));
            header('Connection: close');
        }
        echo $body;
    } else {
        if (headers_sent()) {
            return;
        }
        http_response_code(302);
        header('Location: ' . $redirectUrl);
        header('Content-Length: 0');
        header('Connection: close');
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    @flush();

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}

/**
 * @param int[] $ids
 * @param int[] $newEventIds
 */
function runRegistrationPostSaveSafely(PDO $pdo, array $data, array $ids, array $newEventIds, string $email): void
{
    try {
        runRegistrationPostSaveJobs($pdo, $data, $ids, $newEventIds, $email);
    } catch (Throwable $e) {
        error_log('[EventStaff] Registration post-save failed: ' . $e->getMessage());
    }
}

/**
 * @param list<array<string, mixed>> $duplicates
 */
function buildRegistrationSuccessMessage(int $count, int $autoApproved, array $duplicates): string
{
    if ($autoApproved > 0) {
        $message = $count === 1
            ? 'Registration submitted successfully for 1 event! Your application has been approved.'
            : 'Registration submitted successfully for ' . $count . ' events! Your applications have been approved.';
    } else {
        $message = $count === 1
            ? 'Registration submitted successfully for 1 event! Your application is pending approval.'
            : 'Registration submitted successfully for ' . $count . ' events! Your applications are pending approval.';
    }

    if ($duplicates !== []) {
        $message .= ' Already registered (skipped): ' . implode(', ', array_map(
            static fn(array $row): string => formatEventLabel($row),
            $duplicates
        )) . '.';
    }

    return $message;
}

/**
 * @param int[] $ids
 * @param int[] $newEventIds
 * @return array{approved: int, rejected: int}
 */
function runRegistrationPostSaveJobs(PDO $pdo, array $data, array $ids, array $newEventIds, string $email): array
{
    static $completedRuns = [];

    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return ['approved' => 0, 'rejected' => 0];
    }

    sort($ids);
    $runKey = hash('sha256', implode(',', $ids) . '|' . strtolower(trim($email)));
    if (isset($completedRuns[$runKey])) {
        return $completedRuns[$runKey];
    }

    $stats = ['approved' => 0, 'rejected' => 0];

    try {
        notifyStaffRegistrationSubmitted($pdo, $data, $newEventIds, $ids);
    } catch (Throwable $e) {
        error_log('[EventStaff] Registration email failed: ' . $e->getMessage());
    }

    try {
        require_once __DIR__ . '/notification-center.php';
        $staffName = trim((string) ($data['first_name'] ?? '') . ' ' . (string) ($data['surname'] ?? ''));
        foreach ($ids as $regId) {
            $row = getStaffRegistrationById($pdo, (int) $regId);
            if ($row === null) {
                continue;
            }
            notifyAdminNewRegistration(
                $pdo,
                $staffName !== '' ? $staffName : 'New applicant',
                $email,
                (int) $regId,
                formatEventLabel($row)
            );
        }
    } catch (Throwable $e) {
        error_log('[EventStaff] Admin notification failed: ' . $e->getMessage());
    }

    try {
        $sheetStats = syncRegistrationsToGoogleSheets($pdo, $ids);
        if (($sheetStats['failed'] ?? 0) > 0) {
            error_log('[EventStaff] Google Sheets sync failed for ' . (int) $sheetStats['failed'] . ' registration(s).');
        }
        require_once __DIR__ . '/google-sheets-auto-worker.php';
        googleSheetsRunAutoWorkerInline($pdo, 2);
    } catch (Throwable $e) {
        error_log('[EventStaff] Google Sheets sync error: ' . $e->getMessage());
    }

    try {
        require_once __DIR__ . '/platform/auto-approval-engine.php';
        $autoStats = processAutoApprovalForRegistrations($pdo, $ids);
        $stats['approved'] = (int) ($autoStats['approved'] ?? 0);
        $stats['rejected'] = (int) ($autoStats['rejected'] ?? 0);
    } catch (Throwable $e) {
        error_log('[EventStaff] Auto approval: ' . $e->getMessage());
    }

    $completedRuns[$runKey] = $stats;

    return $stats;
}

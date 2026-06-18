<?php

declare(strict_types=1);

require_once __DIR__ . '/registration-post-save.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/google-sheets-sync.php';
require_once __DIR__ . '/apply-remote-sync.php';
require_once __DIR__ . '/event-staff-alerts.php';

/** @alias for registrationFlushResponse */
function flushHttpResponse(string $redirectUrl, ?array $jsonPayload = null, int $jsonStatus = 200): void
{
    registrationFlushResponse($redirectUrl, $jsonPayload, $jsonStatus);
}

function runSingleStatusChangePostJobs(PDO $pdo, int $registrationId, string $status): void
{
    if ($registrationId < 1) {
        return;
    }

    try {
        notifyStaffStatusChange($pdo, $registrationId, $status);
    } catch (Throwable $e) {
        error_log('[EventStaff] status notify id=' . $registrationId . ': ' . $e->getMessage());
    }

    try {
        syncRegistrationToGoogleSheetWithOutcome($pdo, $registrationId);
    } catch (Throwable $e) {
        error_log('[EventStaff] status sheet sync id=' . $registrationId . ': ' . $e->getMessage());
    }

    if (in_array($status, ['approved', 'rejected'], true)) {
        try {
            triggerApplyPortalSyncAsync($pdo, true);
        } catch (Throwable $e) {
            error_log('[EventStaff] status apply sync id=' . $registrationId . ': ' . $e->getMessage());
        }
    }

    if ($status === 'rejected') {
        $regRow = getStaffRegistrationById($pdo, $registrationId);
        $eventIdForSlot = (int) ($regRow['event_id'] ?? 0);
        if ($eventIdForSlot > 0) {
            try {
                notifyRegisteredStaffOpenShiftSlot($pdo, $eventIdForSlot, 'A staff place opened up');
            } catch (Throwable $e) {
                error_log('[EventStaff] open shift notify id=' . $registrationId . ': ' . $e->getMessage());
            }
        }
    }
}

/**
 * @param int[] $registrationIds
 */
function runBulkStatusChangePostJobs(PDO $pdo, array $registrationIds, string $status, bool $skipOpenShiftAlerts = false): void
{
    $registrationIds = array_values(array_unique(array_filter(array_map('intval', $registrationIds), static fn (int $id): bool => $id > 0)));
    if ($registrationIds === []) {
        return;
    }

    foreach ($registrationIds as $registrationId) {
        try {
            syncRegistrationToGoogleSheetWithOutcome($pdo, $registrationId);
        } catch (Throwable $e) {
            error_log('[EventStaff] bulk sheet sync id=' . $registrationId . ': ' . $e->getMessage());
        }
    }

    if (in_array($status, ['approved', 'rejected'], true)) {
        try {
            notifyStaffStatusChanges($pdo, $registrationIds, $status);
        } catch (Throwable $e) {
            error_log('[EventStaff] bulk status notify: ' . $e->getMessage());
        }

        try {
            triggerApplyPortalSyncAsync($pdo, true);
        } catch (Throwable $e) {
            error_log('[EventStaff] bulk apply sync: ' . $e->getMessage());
        }
    }

    if ($status === 'rejected' && !$skipOpenShiftAlerts) {
        foreach ($registrationIds as $registrationId) {
            $regRow = getStaffRegistrationById($pdo, $registrationId);
            $eventIdForSlot = (int) ($regRow['event_id'] ?? 0);
            if ($eventIdForSlot < 1) {
                continue;
            }
            try {
                notifyRegisteredStaffOpenShiftSlot($pdo, $eventIdForSlot, 'A staff place opened up');
            } catch (Throwable $e) {
                error_log('[EventStaff] bulk open shift notify id=' . $registrationId . ': ' . $e->getMessage());
            }
        }
    }
}

/**
 * Sheet sync + cancellation emails/in-app after admin cancels an entire event.
 *
 * @param int[] $registrationIds
 */
function runEventCancellationPostJobs(PDO $pdo, array $registrationIds, string $reason): void
{
    $registrationIds = array_values(array_unique(array_filter(array_map('intval', $registrationIds), static fn (int $id): bool => $id > 0)));
    if ($registrationIds === []) {
        return;
    }

    foreach ($registrationIds as $registrationId) {
        try {
            syncRegistrationToGoogleSheetWithOutcome($pdo, $registrationId);
        } catch (Throwable $e) {
            error_log('[EventStaff] event cancel sheet sync id=' . $registrationId . ': ' . $e->getMessage());
        }
    }

    try {
        notifyStaffEventCancellations($pdo, $registrationIds, $reason);
    } catch (Throwable $e) {
        error_log('[EventStaff] event cancel notify: ' . $e->getMessage());
    }

    try {
        triggerApplyPortalSyncAsync($pdo, true);
    } catch (Throwable $e) {
        error_log('[EventStaff] event cancel apply sync: ' . $e->getMessage());
    }
}

function runProfileCompletionPostJobs(PDO $pdo, int $staffId): void
{
    if ($staffId < 1) {
        return;
    }

    try {
        require_once __DIR__ . '/staff-onboarding.php';
        autoApprovePendingRegistrationsForStaff($pdo, $staffId);
    } catch (Throwable $e) {
        error_log('[EventStaff] profile completion auto-approve staff=' . $staffId . ': ' . $e->getMessage());
    }

    try {
        triggerApplyPortalSyncAsync($pdo, true);
    } catch (Throwable $e) {
        error_log('[EventStaff] profile completion apply sync staff=' . $staffId . ': ' . $e->getMessage());
    }
}

function runStaffProfileSheetsPostJobs(PDO $pdo, int $staffId): void
{
    if ($staffId < 1) {
        return;
    }

    try {
        require_once __DIR__ . '/google-sheets-sync.php';
        syncStaffProfileToLinkedGoogleSheets($pdo, $staffId);
    } catch (Throwable $e) {
        error_log('[EventStaff] staff profile sheets sync staff=' . $staffId . ': ' . $e->getMessage());
    }

    try {
        triggerApplyPortalSyncAsync($pdo, true);
    } catch (Throwable $e) {
        error_log('[EventStaff] staff profile apply sync staff=' . $staffId . ': ' . $e->getMessage());
    }
}

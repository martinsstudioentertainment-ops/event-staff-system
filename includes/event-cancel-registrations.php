<?php

declare(strict_types=1);

require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/events-repository.php';
require_once __DIR__ . '/audit-log.php';
require_once __DIR__ . '/status-change-post-save.php';

/**
 * @return array{approved: int, pending: int, total: int}
 */
function countEventRegistrationsEligibleForCancel(PDO $pdo, int $eventId): array
{
    if ($eventId < 1) {
        return ['approved' => 0, 'pending' => 0, 'total' => 0];
    }

    $stmt = $pdo->prepare(
        "SELECT status, COUNT(*) AS cnt
         FROM staff_registrations
         WHERE event_id = :event_id AND status IN ('approved', 'pending')
         GROUP BY status"
    );
    $stmt->execute(['event_id' => $eventId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $approved = 0;
    $pending  = 0;
    foreach ($rows as $row) {
        $status = (string) ($row['status'] ?? '');
        $count  = (int) ($row['cnt'] ?? 0);
        if ($status === 'approved') {
            $approved = $count;
        } elseif ($status === 'pending') {
            $pending = $count;
        }
    }

    return [
        'approved' => $approved,
        'pending'  => $pending,
        'total'    => $approved + $pending,
    ];
}

/**
 * Deactivate event and reject all approved/pending registrations; notify staff by email + in-app.
 *
 * @return array{ok: bool, error?: string, updated?: int, deactivated?: bool, event_name?: string}
 */
function adminCancelAllEventRegistrations(PDO $pdo, int $eventId, string $reason): array
{
    if ($eventId < 1) {
        return ['ok' => false, 'error' => 'Invalid event.'];
    }

    $reason = trim($reason);
    if ($reason === '') {
        return ['ok' => false, 'error' => 'Please enter a reason (shown in the audit log).'];
    }

    $event = getEventById($pdo, $eventId);
    if ($event === null) {
        return ['ok' => false, 'error' => 'Event not found.'];
    }

    $stmt = $pdo->prepare(
        "SELECT id FROM staff_registrations
         WHERE event_id = :event_id AND status IN ('approved', 'pending')
         ORDER BY id ASC"
    );
    $stmt->execute(['event_id' => $eventId]);
    $ids = array_values(array_filter(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []), static fn (int $id): bool => $id > 0));

    $deactivated = setEventActive($pdo, $eventId, false);

    $updated   = 0;
    $notifyIds = [];
    foreach ($ids as $registrationId) {
        if (updateStaffStatus($pdo, $registrationId, 'rejected', true)) {
            $updated++;
            $notifyIds[] = $registrationId;
        }
    }

    logAdminAudit(
        $pdo,
        'event_cancel_all_registrations',
        'event',
        $eventId,
        sprintf(
            'Cancelled event shifts: %d registration(s) rejected. Reason: %s',
            $updated,
            $reason
        )
    );

    return [
        'ok'          => true,
        'updated'     => $updated,
        'deactivated' => $deactivated,
        'event_name'  => (string) ($event['name'] ?? ''),
        'notify_ids'  => $notifyIds,
    ];
}

<?php

require_once __DIR__ . '/settings-repository.php';

/**
 * Default headcount when an event has no Staff needed value (Admin → Events).
 */
function getDefaultEventStaffNeeded(PDO $pdo): ?int
{
    $raw = trim((string) getSetting($pdo, 'default_event_staff_needed', '30'));
    if ($raw === '' || !ctype_digit($raw)) {
        return null;
    }

    $value = (int) $raw;

    return $value > 0 ? $value : null;
}

/**
 * Resolved target headcount for display and capacity (DB value, else system default).
 */
function resolveEventStaffNeeded(array $event, ?PDO $pdo = null): ?int
{
    $raw = $event['staff_needed'] ?? null;
    if ($raw !== null && $raw !== '') {
        return max(0, (int) $raw);
    }

    if ($pdo instanceof PDO) {
        return getDefaultEventStaffNeeded($pdo);
    }

    return null;
}

function getEventApprovedRegistrationCount(PDO $pdo, int $eventId): int
{
    if ($eventId < 1) {
        return 0;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM staff_registrations WHERE event_id = :id AND status = 'approved'"
    );
    $stmt->execute(['id' => $eventId]);

    return (int) $stmt->fetchColumn();
}

/**
 * Approved + pending — used to hide an event from staff registration when slots are taken.
 */
function getEventFilledRegistrationCount(PDO $pdo, int $eventId): int
{
    if ($eventId < 1) {
        return 0;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM staff_registrations
         WHERE event_id = :id AND status IN ('approved', 'pending')"
    );
    $stmt->execute(['id' => $eventId]);

    return (int) $stmt->fetchColumn();
}

/**
 * @return array{needed: ?int, filled: int, approved: int, remaining: ?int, is_full: bool}
 */
function getEventCapacitySummary(PDO $pdo, array $event): array
{
    $eventId  = (int) ($event['id'] ?? 0);
    $needed   = resolveEventStaffNeeded($event, $pdo);
    $filled   = getEventFilledRegistrationCount($pdo, $eventId);
    $approved = getEventApprovedRegistrationCount($pdo, $eventId);

    if ($needed === null || $needed <= 0) {
        return [
            'needed'    => null,
            'filled'    => $filled,
            'approved'  => $approved,
            'remaining' => null,
            'is_full'   => false,
        ];
    }

    return [
        'needed'    => $needed,
        'filled'    => $filled,
        'approved'  => $approved,
        'remaining' => max(0, $needed - $filled),
        'is_full'   => $filled >= $needed,
    ];
}

function isEventStaffCapacityFull(PDO $pdo, array $event): bool
{
    return getEventCapacitySummary($pdo, $event)['is_full'];
}

/**
 * Staff-facing registration — hidden when date passed, inactive, or capacity full.
 */
function isEventAvailableForStaffRegistration(PDO $pdo, array $event): bool
{
    require_once __DIR__ . '/events-repository.php';

    return isEventOpenForRegistration($event) && !isEventStaffCapacityFull($pdo, $event);
}

function formatEventCapacityAdminLabel(PDO $pdo, array $event): string
{
    $summary = getEventCapacitySummary($pdo, $event);
    if ($summary['needed'] === null) {
        return '—';
    }

    $line = $summary['filled'] . ' / ' . $summary['needed'];
    if ($summary['approved'] !== $summary['filled']) {
        $line .= ' (' . $summary['approved'] . ' approved)';
    }

    return $line;
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/events-repository.php';
require_once __DIR__ . '/work-hours-repository.php';
require_once __DIR__ . '/attendance-repository.php';

/**
 * Events dropdown for attendance / work hours / manual sign-in.
 * Includes inactive events so past shifts remain selectable.
 *
 * @return array<int, array<string, mixed>>
 */
function getEventsForAttendanceFilter(PDO $pdo): array
{
    return $pdo->query(
        'SELECT id, name, event_date, is_active
         FROM events
         ORDER BY event_date DESC, name ASC'
    )->fetchAll() ?: [];
}

/**
 * @param array<string, mixed> $event
 */
function formatEventFilterOptionLabel(array $event): string
{
    $label = trim((string) ($event['name'] ?? '')) . ' — ' . formatEventDateLabel((string) ($event['event_date'] ?? ''));
    if ((int) ($event['is_active'] ?? 1) !== 1) {
        $label .= ' (inactive)';
    }

    return $label;
}

/**
 * @param array<string, mixed> $row
 */
function isAttendanceRosterCheckedIn(array $row): bool
{
    return resolveAttendanceBoardBucket($row) === 'checked_in';
}

/**
 * @param array<string, mixed> $row
 */
function isAttendanceMarkedNoShow(array $row): bool
{
    return resolveAttendanceBoardBucket($row) === 'no_show';
}

/**
 * @deprecated Use groupAttendanceBoardRows() for checked_in / awaiting / no_show.
 *
 * @param array<int, array<string, mixed>> $list
 * @return array<int, array{checked_in: array<int, array<string, mixed>>, waiting: array<int, array<string, mixed>>}>
 */
function groupAttendanceRosterRows(array $list): array
{
    $board = groupAttendanceBoardRows($list);

    return [
        [
            'checked_in' => $board['checked_in'],
            'waiting'    => array_merge($board['awaiting'], $board['no_show']),
        ],
    ];
}

/**
 * @param array<string, mixed> $row
 */
function formatAttendanceRosterHours(array $row): string
{
    if (resolveAttendanceBoardBucket($row) !== 'checked_in') {
        return '—';
    }

    $hoursWorked = isset($row['hours_worked']) && $row['hours_worked'] !== null
        ? (float) $row['hours_worked']
        : 0.0;
    $hoursPaid   = isset($row['hours_paid']) && $row['hours_paid'] !== null
        ? (float) $row['hours_paid']
        : 0.0;
    $scheduled   = isset($row['scheduled_hours']) && $row['scheduled_hours'] !== null
        ? (float) $row['scheduled_hours']
        : 0.0;

    if ($hoursPaid > 0) {
        $label = formatHoursDecimal($hoursPaid);
        if ($hoursWorked > 0 && $hoursPaid + 0.01 < $hoursWorked) {
            return $label . ' (adj)';
        }

        return $label;
    }

    if ($hoursWorked > 0) {
        return formatHoursDecimal($hoursWorked);
    }

    if ($scheduled > 0) {
        return formatHoursDecimal($scheduled);
    }

    return '—';
}

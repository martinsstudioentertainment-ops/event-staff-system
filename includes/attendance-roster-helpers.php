<?php

declare(strict_types=1);

require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/work-hours-repository.php';
require_once __DIR__ . '/attendance-repository.php';

/**
 * Events dropdown for attendance / scan check-in screens.
 *
 * @return array<int, array<string, mixed>>
 */
function getEventsForAttendanceFilter(PDO $pdo): array
{
    return getEventsForFilter($pdo);
}

/**
 * Split roster rows into checked-in vs waiting.
 *
 * @param array<int, array<string, mixed>> $list
 * @return array<int, array{checked_in: array<int, array<string, mixed>>, waiting: array<int, array<string, mixed>>}>
 */
function groupAttendanceRosterRows(array $list): array
{
    $checkedIn = [];
    $waiting   = [];
    $noShows   = [];

    foreach ($list as $row) {
        if (isAttendanceRosterCheckedIn($row)) {
            $checkedIn[] = $row;
        } elseif (isAttendanceMarkedNoShow($row)) {
            $noShows[] = $row;
        } else {
            $waiting[] = $row;
        }
    }

    return [
        [
            'checked_in' => $checkedIn,
            'waiting'    => $waiting,
            'no_show'    => $noShows,
        ],
    ];
}

/**
 * @param array<string, mixed> $row
 */
function formatAttendanceRosterHours(array $row): string
{
    if ((int) ($row['is_checked_in'] ?? 0) !== 1) {
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

<?php

/**
 * Human-readable staff pass / barcode reference (display only — check-in uses secure token).
 */
function formatStaffPassId(int $registrationId, ?string $eventDate = null): string
{
    $year = $eventDate ? date('Y', strtotime($eventDate)) : date('Y');

    return sprintf('EVT-%s-%06d', $year, $registrationId);
}

/**
 * @return array{dsp: int, static: int, steward: int, other: int, total: int}
 */
function countRolesInList(array $rows): array
{
    $counts = ['dsp' => 0, 'static' => 0, 'steward' => 0, 'other' => 0, 'total' => count($rows)];

    foreach ($rows as $row) {
        $role = (string) ($row['staff_role'] ?? '');
        if (isset($counts[$role])) {
            $counts[$role]++;
        } else {
            $counts['other']++;
        }
    }

    return $counts;
}

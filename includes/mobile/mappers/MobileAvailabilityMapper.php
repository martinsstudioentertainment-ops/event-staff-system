<?php

declare(strict_types=1);

/** @return list<string> */
function mobileAvailabilitySettableStatuses(): array
{
    return ['available', 'unavailable', 'preferred'];
}

/** @return list<string> */
function mobileAvailabilityLeaveTypes(): array
{
    return ['leave', 'holiday'];
}

/**
 * Map DB staff_availability.status to mobile API status.
 *
 * @return array{status: string, approval_status: string}
 */
function mobileAvailabilityFromDbStatus(string $dbStatus): array
{
    $dbStatus = strtolower(trim($dbStatus));

    return match ($dbStatus) {
        'available'          => ['status' => 'available', 'approval_status' => 'none'],
        'unavailable'        => ['status' => 'unavailable', 'approval_status' => 'none'],
        'preferred'          => ['status' => 'preferred', 'approval_status' => 'none'],
        'leave_requested'    => ['status' => 'leave', 'approval_status' => 'pending'],
        'holiday_requested'  => ['status' => 'holiday', 'approval_status' => 'pending'],
        'leave_approved'     => ['status' => 'leave', 'approval_status' => 'approved'],
        'holiday_approved'   => ['status' => 'holiday', 'approval_status' => 'approved'],
        default              => ['status' => 'available', 'approval_status' => 'none'],
    };
}

/**
 * Map mobile set-day status to DB value.
 */
function mobileAvailabilityToDbStatus(string $mobileStatus): ?string
{
    $mobileStatus = strtolower(trim($mobileStatus));

    return match ($mobileStatus) {
        'available'   => 'available',
        'unavailable' => 'unavailable',
        'preferred'   => 'preferred',
        default       => null,
    };
}

/**
 * Map leave request type to DB status.
 */
function mobileLeaveTypeToDbStatus(string $type): ?string
{
    $type = strtolower(trim($type));

    return match ($type) {
        'leave'   => 'leave_requested',
        'holiday' => 'holiday_requested',
        default   => null,
    };
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function mobileMapAvailabilityDay(array $row): array
{
    $mapped = mobileAvailabilityFromDbStatus((string) ($row['status'] ?? 'available'));

    return [
        'date'             => substr((string) ($row['avail_date'] ?? ''), 0, 10),
        'status'           => $mapped['status'],
        'approval_status'  => $mapped['approval_status'],
        'notes'            => (string) ($row['notes'] ?? ''),
        'admin_approved'   => (int) ($row['admin_approved'] ?? 0) === 1,
        'updated_at'       => (string) ($row['updated_at'] ?? $row['created_at'] ?? ''),
    ];
}

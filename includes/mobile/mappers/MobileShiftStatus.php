<?php

declare(strict_types=1);

require_once __DIR__ . '/../../staff-app-v3-data.php';
require_once __DIR__ . '/../../date-format.php';

/** @var list<string> */
const MOBILE_SHIFT_STATUSES = [
    'pending',
    'approved',
    'declined',
    'cancelled',
    'waitlist',
    'completed',
];

/**
 * Human-readable shift status definitions for mobile clients.
 *
 * @return array<string, array{label: string, description: string}>
 */
function mobileShiftStatusDefinitions(): array
{
    return [
        'pending'   => [
            'label'       => 'Pending',
            'description' => 'Registration submitted and awaiting admin approval.',
        ],
        'approved'  => [
            'label'       => 'Approved',
            'description' => 'Shift approved and scheduled; not yet completed.',
        ],
        'declined'  => [
            'label'       => 'Declined',
            'description' => 'Staff declined the shift or admin did not approve the registration.',
        ],
        'cancelled' => [
            'label'       => 'Cancelled',
            'description' => 'Shift or event is no longer active for this assignment.',
        ],
        'waitlist'  => [
            'label'       => 'Waitlist',
            'description' => 'Staff is on the waiting list for this event; no registration yet.',
        ],
        'completed' => [
            'label'       => 'Completed',
            'description' => 'Shift finished with recorded attendance or worked hours.',
        ],
    ];
}

/**
 * Resolve mobile shift_status from registration / waitlist row.
 *
 * @param array<string, mixed> $row
 */
function mobileResolveShiftStatus(array $row): string
{
    if (($row['record_type'] ?? '') === 'waitlist') {
        return 'waitlist';
    }

    $outcome = resolveStaffShiftOutcomeMeta($row);
    $code    = (string) ($outcome['code'] ?? '');

    if ($code === 'completed' || $code === 'checked_in') {
        $checkedOut = trim((string) ($row['checked_out_at'] ?? '')) !== '';
        $window     = getEventCheckinWindow($row);
        if ($code === 'completed' || $checkedOut || ($window['status'] ?? '') === 'after') {
            return 'completed';
        }
    }

    $shiftResponse = strtolower(trim((string) ($row['shift_response'] ?? '')));
    if ($shiftResponse === 'declined') {
        return 'declined';
    }

    $regStatus = strtolower(trim((string) ($row['status'] ?? 'pending')));

    if ($regStatus === 'pending') {
        return 'pending';
    }

    if ($regStatus === 'rejected') {
        return 'declined';
    }

    if ($regStatus === 'cancelled') {
        return 'cancelled';
    }

    if ($regStatus === 'approved') {
        if (isset($row['is_active']) && (int) $row['is_active'] === 0) {
            return 'cancelled';
        }
        if ($code === 'completed') {
            return 'completed';
        }

        return 'approved';
    }

    if ($regStatus === 'waitlist') {
        return 'waitlist';
    }

    return 'pending';
}

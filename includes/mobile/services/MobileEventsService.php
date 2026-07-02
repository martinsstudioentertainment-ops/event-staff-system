<?php

declare(strict_types=1);

require_once __DIR__ . '/../../events-repository.php';
require_once __DIR__ . '/../../event-capacity.php';
require_once __DIR__ . '/../../company.php';
require_once __DIR__ . '/../../staff-repository.php';
require_once __DIR__ . '/../../validation.php';
require_once __DIR__ . '/../../registration-forms.php';
require_once __DIR__ . '/../../registration-post-save.php';
require_once __DIR__ . '/../mobile-rate-limit.php';

function mobileEventsReadThrottle(int $staffId): void
{
    mobileThrottleOrFail('events_read_' . $staffId, 120, 60);
}

function mobileEventsWriteThrottle(int $staffId): void
{
    mobileThrottleOrFail('events_write_' . $staffId, 20, 60);
}

/**
 * @return array{ok: true, events: list, count: int}|array{ok: false, message: string, code: string, status: int}
 */
function mobileEventsServiceList(PDO $pdo, array $staff): array
{
    $staffId = (int) ($staff['id'] ?? 0);
    $email   = strtolower(trim((string) ($staff['email'] ?? '')));

    mobileEventsReadThrottle($staffId);

    if ($email === '') {
        return [
            'ok'      => false,
            'message' => 'Staff email is required.',
            'code'    => 'FORBIDDEN',
            'status'  => 403,
        ];
    }

    $companyName = getCompanyName($pdo);
    $events      = getEventsOpenForRegistration($pdo);
    $registered  = getRegisteredEventsSummaryByEmail($pdo, $email);
    $registeredByEventId = [];
    foreach ($registered as $row) {
        $eventId = (int) ($row['event_id'] ?? 0);
        if ($eventId > 0) {
            $registeredByEventId[$eventId] = $row;
        }
    }

    $payload = [];
    foreach ($events as $event) {
        $eventId   = (int) ($event['id'] ?? 0);
        $capacity  = getEventCapacitySummary($pdo, $event);
        $existing  = $registeredByEventId[$eventId] ?? null;
        $startTime = substr((string) ($event['start_time'] ?? $event['event_start_time'] ?? '09:00:00'), 0, 5);
        $endTime   = substr((string) ($event['end_time'] ?? $event['event_end_time'] ?? '17:00:00'), 0, 5);

        $payload[] = [
            'event_id'            => $eventId,
            'event_name'          => (string) ($event['name'] ?? 'Event'),
            'event_date'          => formatEventDateLabel((string) ($event['event_date'] ?? '')),
            'event_date_iso'      => normalizeEventDateYmd((string) ($event['event_date'] ?? '')),
            'venue_name'          => (string) ($event['venue_name'] ?? $event['location'] ?? '—'),
            'employer'            => trim((string) ($event['main_security_company'] ?? '')) !== ''
                ? (string) $event['main_security_company']
                : $companyName,
            'start_time'          => $startTime,
            'end_time'            => $endTime,
            'time_label'          => formatEventTimeRangeLabelForStaff($event),
            'available_spaces'    => $capacity['remaining'],
            'capacity_needed'     => $capacity['needed'],
            'capacity_filled'     => $capacity['filled'],
            'is_full'             => $capacity['is_full'],
            'registration_status' => $existing !== null ? (string) ($existing['status'] ?? 'none') : 'none',
            'registration_id'     => $existing !== null ? (int) ($existing['registration_id'] ?? 0) : null,
            'approval_status'     => $existing !== null
                ? mobileEventsApprovalLabel((string) ($existing['status'] ?? ''))
                : 'Not applied',
            'can_apply'           => $existing === null && !$capacity['is_full'],
        ];
    }

    return [
        'ok'     => true,
        'events' => $payload,
        'count'  => count($payload),
    ];
}

function mobileEventsApprovalLabel(string $status): string
{
    return match (strtolower(trim($status))) {
        'approved' => 'Approved',
        'pending'  => 'Pending approval',
        'rejected' => 'Rejected',
        'waitlist' => 'Waitlisted',
        default    => ucfirst(str_replace('_', ' ', $status)),
    };
}

/**
 * @return array{ok: true, message: string, registration_ids: list, count: int}|array{ok: false, message: string, code: string, status: int, details?: array}
 */
function mobileEventsServiceRegister(PDO $pdo, array $staff, array $body): array
{
    $staffId = (int) ($staff['id'] ?? 0);
    $email   = strtolower(trim((string) ($staff['email'] ?? '')));

    mobileEventsWriteThrottle($staffId);

    if ($staffId < 1 || $email === '') {
        return [
            'ok'      => false,
            'message' => 'Staff account is required.',
            'code'    => 'FORBIDDEN',
            'status'  => 403,
        ];
    }

    $eventIds = $body['event_ids'] ?? [];
    if (!is_array($eventIds)) {
        $eventIds = [];
    }
    $eventIds = array_values(array_unique(array_filter(array_map('intval', $eventIds), static fn(int $id): bool => $id > 0)));

    if ($eventIds === []) {
        return [
            'ok'      => false,
            'message' => 'Please select at least one event.',
            'code'    => 'VALIDATION_ERROR',
            'status'  => 422,
        ];
    }

    $fresh = getStaffById($pdo, $staffId);
    if ($fresh === null) {
        return [
            'ok'      => false,
            'message' => 'Staff not found.',
            'code'    => 'STAFF_NOT_FOUND',
            'status'  => 404,
        ];
    }

    foreach ($eventIds as $eventId) {
        $event = getEventById($pdo, $eventId);
        if ($event === null || !isEventAvailableForStaffRegistration($pdo, $event)) {
            return [
                'ok'      => false,
                'message' => 'One or more selected events are no longer available.',
                'code'    => 'EVENT_UNAVAILABLE',
                'status'  => 409,
            ];
        }
        if (registrationExistsForEmail($pdo, $email, $eventId)) {
            return [
                'ok'      => false,
                'message' => 'You are already registered for one of the selected events.',
                'code'    => 'ALREADY_REGISTERED',
                'status'  => 409,
            ];
        }
    }

    $staffRole = normalizeStaffRole((string) ($fresh['staff_role'] ?? ''), $pdo);
    $data      = [
        'email'           => $email,
        'first_name'      => (string) ($fresh['first_name'] ?? ''),
        'surname'         => (string) ($fresh['surname'] ?? ''),
        'full_address'    => (string) ($fresh['full_address'] ?? ''),
        'eircode'         => (string) ($fresh['eircode'] ?? ''),
        'mobile'          => (string) ($fresh['mobile'] ?? ''),
        'date_of_birth'   => (string) ($fresh['date_of_birth'] ?? ''),
        'gender'          => (string) ($fresh['gender'] ?? ''),
        'pps_number'      => (string) ($fresh['pps_number'] ?? ''),
        'bank_iban'       => (string) ($fresh['bank_iban'] ?? ''),
        'staff_role'      => $staffRole,
        'privacy_consent' => '1',
    ];

    try {
        $ids = saveRegistrations($pdo, $data, $eventIds, []);
    } catch (Throwable $e) {
        error_log('[MobileAPI] mobileEventsServiceRegister: ' . $e->getMessage());

        return [
            'ok'      => false,
            'message' => 'Could not complete registration. Please try again.',
            'code'    => 'REGISTER_FAILED',
            'status'  => 500,
        ];
    }

    runRegistrationPostSaveSafely($pdo, $data, $ids, $eventIds, $email);

    return [
        'ok'               => true,
        'message'          => count($ids) === 1
            ? 'Registration submitted for 1 event.'
            : 'Registration submitted for ' . count($ids) . ' events.',
        'registration_ids' => $ids,
        'count'            => count($ids),
    ];
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/../../staff-app-v3-data.php';
require_once __DIR__ . '/../../staff-portal-dashboard.php';
require_once __DIR__ . '/../../staff-profile-gate.php';
require_once __DIR__ . '/../../attendance-repository.php';
require_once __DIR__ . '/../../date-format.php';
require_once __DIR__ . '/../../maps.php';
require_once __DIR__ . '/MobileShiftStatus.php';

/**
 * @return array<string, mixed>
 */
function mobileMapShiftRow(PDO $pdo, array $row, array $staff, string $companyFallback = ''): array
{
    $outcome        = resolveStaffShiftOutcomeMeta($row);
    $registrationId = (int) ($row['id'] ?? $row['registration_id'] ?? 0);
    $shiftStatus    = mobileResolveShiftStatus($row);
    $definitions    = mobileShiftStatusDefinitions();
    $statusMeta     = $definitions[$shiftStatus] ?? ['label' => ucfirst($shiftStatus), 'description' => ''];

    $startTime = trim((string) ($row['event_start_time'] ?? $row['start_time'] ?? ''));
    $endTime   = trim((string) ($row['event_end_time'] ?? $row['end_time'] ?? ''));
    $venueLat  = normalizeCoordinate(isset($row['venue_lat']) ? (string) $row['venue_lat'] : null);
    $venueLng  = normalizeCoordinate(isset($row['venue_lng']) ? (string) $row['venue_lng'] : null);

    return [
        'registration_id'      => $registrationId > 0 ? $registrationId : null,
        'waitlist_id'          => ($row['record_type'] ?? '') === 'waitlist'
            ? (int) ($row['waitlist_id'] ?? 0) : null,
        'record_type'          => (string) (($row['record_type'] ?? '') === 'waitlist' ? 'waitlist' : 'registration'),
        'event_id'             => (int) ($row['event_id'] ?? 0),
        'event_name'           => (string) ($row['event_name'] ?? ''),
        'event_date'           => substr((string) ($row['event_date'] ?? ''), 0, 10),
        'venue'                => [
            'name'         => formatStaffStatusVenueLabel($row),
            'eircode'      => (string) ($row['venue_eircode'] ?? ''),
            'location_lat' => $venueLat,
            'location_lng' => $venueLng,
        ],
        'start_time'           => $startTime !== '' ? $startTime : null,
        'end_time'             => $endTime !== '' ? $endTime : null,
        'time_label'           => formatStaffV3ShiftTime($row),
        'status'               => (string) ($row['status'] ?? ''),
        'shift_status'         => $shiftStatus,
        'shift_status_label'   => (string) $statusMeta['label'],
        'shift_response'       => (string) ($row['shift_response'] ?? ''),
        'assigned_company'     => getStaffV3EmployerLabel($row, $companyFallback),
        'check_in_eligibility' => mobileShiftBuildCheckInEligibility($pdo, $staff, $row),
        'check_out_eligibility'=> mobileShiftBuildCheckOutEligibility($pdo, $staff, $row),
        'outcome'              => [
            'code'  => (string) ($outcome['code'] ?? ''),
            'label' => (string) ($outcome['label'] ?? ''),
            'tone'  => (string) ($outcome['tone'] ?? ''),
        ],
        'attendance'           => [
            'is_checked_in'     => (int) ($row['is_checked_in'] ?? 0) === 1
                || !empty($row['attendance_id'])
                || !empty($row['checked_in_at']),
            'checked_in_at'     => $row['checked_in_at'] ?? null,
            'checked_out_at'    => $row['checked_out_at'] ?? null,
            'attendance_status' => $row['attendance_status'] ?? null,
            'hours_worked'      => isset($row['hours_worked']) ? (float) $row['hours_worked'] : null,
        ],
    ];
}

/**
 * @param array<string, mixed> $staff
 * @param array<string, mixed> $row
 * @return array{allowed: bool, reason: ?string}
 */
function mobileShiftBuildCheckInEligibility(PDO $pdo, array $staff, array $row): array
{
    $result = ['allowed' => false, 'reason' => null];

    if (($row['record_type'] ?? '') === 'waitlist') {
        $result['reason'] = 'Waitlist entries cannot be checked in.';

        return $result;
    }

    if ((string) ($row['status'] ?? '') !== 'approved') {
        $result['reason'] = 'Shift is not approved.';

        return $result;
    }

    $regId = (int) ($row['id'] ?? $row['registration_id'] ?? 0);
    if ($regId < 1) {
        $result['reason'] = 'Invalid registration.';

        return $result;
    }

    if (hasCheckedIn($pdo, $regId) || !empty($row['checked_in_at'])) {
        $result['reason'] = 'Already checked in.';

        return $result;
    }

    if (staffNeedsProfileForm($pdo, $staff)) {
        $result['reason'] = 'Complete your profile before checking in.';

        return $result;
    }

    $eventDate = substr((string) ($row['event_date'] ?? ''), 0, 10);
    $today     = getOperationalTodayYmd($pdo);
    if ($eventDate !== $today) {
        $result['reason'] = $eventDate < $today
            ? 'Check-in is only available on the event day.'
            : 'Check-in is not open yet.';

        return $result;
    }

    $window = getEventCheckinWindow($row);
    if (empty($window['is_open'])) {
        $result['reason'] = formatCheckinWindowMessage($window);

        return $result;
    }

    $result['allowed'] = true;

    return $result;
}

/**
 * @param array<string, mixed> $staff
 * @param array<string, mixed> $row
 * @return array{allowed: bool, reason: ?string}
 */
function mobileShiftBuildCheckOutEligibility(PDO $pdo, array $staff, array $row): array
{
    unset($staff);
    $result = ['allowed' => false, 'reason' => null];

    if (($row['record_type'] ?? '') === 'waitlist') {
        $result['reason'] = 'Waitlist entries cannot be checked out.';

        return $result;
    }

    $regId = (int) ($row['id'] ?? $row['registration_id'] ?? 0);
    if ($regId < 1) {
        $result['reason'] = 'Invalid registration.';

        return $result;
    }

    $attendance = getAttendanceByRegistration($pdo, $regId);
    $checkedIn  = $attendance !== null
        || hasCheckedIn($pdo, $regId)
        || !empty($row['checked_in_at']);

    if (!$checkedIn) {
        $result['reason'] = 'Not checked in yet.';

        return $result;
    }

    $checkedOutAt = trim((string) ($attendance['checked_out_at'] ?? $row['checked_out_at'] ?? ''));
    if ($checkedOutAt !== '') {
        $result['reason'] = 'Already checked out.';

        return $result;
    }

    $attStatus = strtolower(trim((string) ($attendance['attendance_status'] ?? $row['attendance_status'] ?? '')));
    if ($attStatus !== '' && !in_array($attStatus, ['active', 'pre_checked_in'], true)) {
        $result['reason'] = 'Check-out is not available for this attendance state.';

        return $result;
    }

    $result['allowed'] = true;

    return $result;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function mobileFilterUpcomingShifts(PDO $pdo, array $rows, array $staff, string $companyFallback = '', int $limit = 10): array
{
    $today = getOperationalTodayYmd($pdo);
    $out   = [];

    foreach ($rows as $row) {
        if ((string) ($row['status'] ?? '') !== 'approved') {
            continue;
        }
        $eventDate = substr((string) ($row['event_date'] ?? ''), 0, 10);
        if ($eventDate === '' || $eventDate < $today) {
            continue;
        }
        $out[] = mobileMapShiftRow($pdo, $row, $staff, $companyFallback);
        if (count($out) >= max(1, min($limit, 50))) {
            break;
        }
    }

    return $out;
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/../../attendance-repository.php';
require_once __DIR__ . '/../../attendance-gps-phase1.php';
require_once __DIR__ . '/../../attendance-gps-phase15.php';
require_once __DIR__ . '/../../attendance-gps-signout.php';
require_once __DIR__ . '/../../staff-venue-checkin.php';
require_once __DIR__ . '/../../staff-portal-shift.php';
require_once __DIR__ . '/../../staff-portal-dashboard.php';
require_once __DIR__ . '/../../staff-profile-gate.php';
require_once __DIR__ . '/../../staff-repository.php';
require_once __DIR__ . '/../../events-repository.php';
require_once __DIR__ . '/../../maps.php';
require_once __DIR__ . '/../../staff-app-v3-data.php';
require_once __DIR__ . '/MobileShiftService.php';
require_once __DIR__ . '/../mobile-rate-limit.php';

function mobileAttendanceReadThrottle(int $staffId): void
{
    mobileThrottleOrFail('attendance_read_' . $staffId, 120, 60);
}

function mobileAttendanceWriteThrottle(int $staffId): void
{
    mobileThrottleOrFail('attendance_write_' . $staffId, 30, 60);
}

function mobileAttendancePingThrottle(int $staffId): void
{
    mobileThrottleOrFail('attendance_ping_' . $staffId, 90, 60);
}

/**
 * @param array<string, mixed> $body
 * @return array{lat: float, lng: float, accuracy_m: ?int}|null
 */
function mobileAttendanceParseGps(array $body): ?array
{
    return parseSigninCoordinates([
        'sign_lat'        => $body['sign_lat'] ?? null,
        'sign_lng'        => $body['sign_lng'] ?? null,
        'sign_accuracy_m' => $body['sign_accuracy_m'] ?? null,
    ]);
}

/**
 * @return array{ok: false, message: string, code: string, status: int}|null
 */
function mobileAttendanceRequireGpsEnabled(PDO $pdo): ?array
{
    if (isGpsAttendanceV2Enabled($pdo)) {
        return null;
    }

    return [
        'ok'      => false,
        'message' => 'GPS attendance is not enabled.',
        'code'    => 'GPS_DISABLED',
        'status'  => 503,
    ];
}

/**
 * @param array<string, mixed> $event
 * @param array{lat: float, lng: float, accuracy_m: ?int} $gps
 * @return array{distance_m: int, radius_m: int, in_zone: bool}
 */
function mobileAttendanceVenueDistance(PDO $pdo, array $event, array $gps): array
{
    $venue  = getEventVenueCoordinates($event);
    $radius = (int) getEventSigninRadiusMeters($event, $pdo);

    if ($venue === null) {
        return ['distance_m' => 0, 'radius_m' => $radius, 'in_zone' => false];
    }

    $distance = (int) round(haversineDistanceMeters(
        $venue['lat'],
        $venue['lng'],
        (float) $gps['lat'],
        (float) $gps['lng']
    ));

    return [
        'distance_m' => $distance,
        'radius_m'   => $radius,
        'in_zone'    => $distance <= $radius,
    ];
}

/**
 * @param array<string, mixed> $event
 * @return array<string, mixed>
 */
function mobileAttendanceFormatEvent(PDO $pdo, array $event): array
{
    $venue = getEventVenueCoordinates($event);

    return [
        'event_id'    => (int) ($event['id'] ?? $event['event_row_id'] ?? 0),
        'event_name'  => (string) ($event['event_name'] ?? $event['name'] ?? ''),
        'event_date'  => substr((string) ($event['event_date'] ?? ''), 0, 10),
        'venue'       => formatStaffStatusVenueLabel($event),
        'start_time'  => trim((string) ($event['start_time'] ?? '')) ?: null,
        'end_time'    => trim((string) ($event['end_time'] ?? '')) ?: null,
        'coordinates' => $venue !== null
            ? ['lat' => $venue['lat'], 'lng' => $venue['lng']]
            : null,
        'radius_m'    => (int) getEventSigninRadiusMeters($event, $pdo),
    ];
}

/**
 * @param array<string, mixed> $attendance
 * @param array{lat: float, lng: float, accuracy_m: ?int}|null $currentGps
 * @return array<string, mixed>
 */
function mobileAttendanceLocationPayload(array $attendance, ?array $currentGps = null): array
{
    $lastKnown = null;
    if (!empty($attendance['last_gps_at'])
        && $attendance['last_gps_lat'] !== null
        && $attendance['last_gps_lng'] !== null) {
        $lastKnown = [
            'lat'        => (float) $attendance['last_gps_lat'],
            'lng'        => (float) $attendance['last_gps_lng'],
            'accuracy_m' => $attendance['last_gps_accuracy_m'] !== null
                ? (int) $attendance['last_gps_accuracy_m'] : null,
            'at'         => (string) $attendance['last_gps_at'],
        ];
    }

    $checkIn = null;
    if ($attendance['check_in_lat'] !== null && $attendance['check_in_lng'] !== null) {
        $checkIn = [
            'lat'        => (float) $attendance['check_in_lat'],
            'lng'        => (float) $attendance['check_in_lng'],
            'accuracy_m' => $attendance['check_in_accuracy_m'] !== null
                ? (int) $attendance['check_in_accuracy_m'] : null,
            'at'         => (string) ($attendance['check_in_gps_at'] ?? $attendance['checked_in_at'] ?? ''),
        ];
    }

    $checkOut = null;
    if (!empty($attendance['checked_out_at'])) {
        $checkOut = [
            'lat'        => $currentGps['lat'] ?? ($lastKnown['lat'] ?? null),
            'lng'        => $currentGps['lng'] ?? ($lastKnown['lng'] ?? null),
            'accuracy_m' => $currentGps['accuracy_m'] ?? ($lastKnown['accuracy_m'] ?? null),
            'at'         => (string) $attendance['checked_out_at'],
        ];
    }

    return [
        'last_known' => $lastKnown,
        'check_in'   => $checkIn,
        'check_out'  => $checkOut,
    ];
}

/**
 * @return array{ok: true, registration: array}|array{ok: false, message: string, code: string, status: int}
 */
function mobileAttendanceLoadOwnedRegistration(PDO $pdo, array $staff, int $registrationId): array
{
    if ($registrationId < 1) {
        return [
            'ok'      => false,
            'message' => 'Invalid registration ID.',
            'code'    => 'VALIDATION_ERROR',
            'status'  => 422,
        ];
    }

    $registration = getStaffRegistrationById($pdo, $registrationId);
    if ($registration === null || !mobileShiftStaffOwnsRegistration($registration, $staff)) {
        return [
            'ok'      => false,
            'message' => 'Shift not found.',
            'code'    => 'NOT_FOUND',
            'status'  => 404,
        ];
    }

    return ['ok' => true, 'registration' => $registration];
}

/**
 * @return array<string, mixed>
 */
function mobileAttendanceBuildCheckinResponse(
    PDO $pdo,
    array $staff,
    array $registration,
    array $gps,
    array $attendance,
    bool $already = false,
    string $message = ''
): array {
    $eventId = (int) ($registration['event_id'] ?? 0);
    $event   = $eventId > 0 ? getEventById($pdo, $eventId) : null;
    $eventPayload = is_array($event) ? mobileAttendanceFormatEvent($pdo, $event) : null;
    $distance     = is_array($event) ? mobileAttendanceVenueDistance($pdo, $event, $gps) : null;
    $eligibility  = mobileShiftBuildCheckInEligibility($pdo, $staff, $registration);

    return [
        'ok'                  => true,
        'check_in_status'     => $already ? 'already_checked_in' : 'checked_in',
        'already'             => $already,
        'message'             => $message,
        'checked_in_at'       => $attendance['checked_in_at'] ?? $attendance['activated_at'] ?? null,
        'attendance_status'   => (string) ($attendance['attendance_status'] ?? ATTENDANCE_STATUS_ACTIVE),
        'registration_id'     => (int) ($registration['id'] ?? 0),
        'coordinates'         => [
            'lat'        => (float) $gps['lat'],
            'lng'        => (float) $gps['lng'],
            'accuracy_m' => $gps['accuracy_m'] ?? null,
        ],
        'venue_distance'      => $distance,
        'event'               => $eventPayload,
        'eligibility'         => $eligibility,
        'monitoring_required' => isGpsAttendanceV2Enabled($pdo),
        'locations'           => mobileAttendanceLocationPayload($attendance, $gps),
    ];
}

/**
 * @param array<string, mixed> $body
 * @return array<string, mixed>
 */
function mobileAttendanceServiceCheckin(PDO $pdo, array $staff, array $body): array
{
    $staffId = (int) ($staff['id'] ?? 0);
    mobileAttendanceWriteThrottle($staffId);

    $disabled = mobileAttendanceRequireGpsEnabled($pdo);
    if ($disabled !== null) {
        return $disabled;
    }

    $registrationId = (int) ($body['registration_id'] ?? 0);
    $owned          = mobileAttendanceLoadOwnedRegistration($pdo, $staff, $registrationId);
    if (empty($owned['ok'])) {
        return $owned;
    }

    $registration = $owned['registration'];
    $fresh        = getStaffById($pdo, $staffId) ?? $staff;

    if ((string) ($registration['status'] ?? '') !== 'approved') {
        return [
            'ok'      => false,
            'message' => 'Only approved shifts can be checked in.',
            'code'    => 'FORBIDDEN',
            'status'  => 403,
        ];
    }

    if (staffNeedsProfileForm($pdo, $fresh)) {
        return [
            'ok'      => false,
            'message' => 'Complete your profile before checking in.',
            'code'    => 'PROFILE_INCOMPLETE',
            'status'  => 403,
        ];
    }

    $gps = mobileAttendanceParseGps($body);
    if ($gps === null) {
        return [
            'ok'      => false,
            'message' => getGpsRequiredMessage(),
            'code'    => 'GPS_REQUIRED',
            'status'  => 422,
        ];
    }

    $window = getEventCheckinWindow($registration);
    if (empty($window['is_open'])) {
        return [
            'ok'      => false,
            'message' => formatCheckinWindowMessage($window),
            'code'    => 'CHECKIN_WINDOW_CLOSED',
            'status'  => 422,
        ];
    }

    $venueGpsError = assertSelfCheckinVenueGps($pdo, $registration, $gps, 'self');
    if ($venueGpsError !== null) {
        $eventId  = (int) ($registration['event_id'] ?? 0);
        $event    = $eventId > 0 ? getEventById($pdo, $eventId) : null;
        $distance = is_array($event) ? mobileAttendanceVenueDistance($pdo, $event, $gps) : null;

        return [
            'ok'             => false,
            'message'        => $venueGpsError,
            'code'           => str_contains(strtolower($venueGpsError), 'accuracy') ? 'GPS_ACCURACY_LOW' : 'OUTSIDE_VENUE',
            'status'         => 422,
            'venue_distance' => $distance,
            'coordinates'    => [
                'lat'        => (float) $gps['lat'],
                'lng'        => (float) $gps['lng'],
                'accuracy_m' => $gps['accuracy_m'] ?? null,
            ],
        ];
    }

    if (hasCheckedIn($pdo, $registrationId)) {
        $attendance = getAttendanceByRegistration($pdo, $registrationId);
        if ($attendance === null) {
            return [
                'ok'      => false,
                'message' => 'Already checked in.',
                'code'    => 'ALREADY_CHECKED_IN',
                'status'  => 409,
            ];
        }

        return mobileAttendanceBuildCheckinResponse(
            $pdo,
            $fresh,
            $registration,
            $gps,
            $attendance,
            true,
            'You are already checked in for this shift.'
        );
    }

    try {
        $result = recordCheckin($pdo, $registrationId, 'self', $gps);
    } catch (Throwable $e) {
        error_log('[MobileAPI] recordCheckin: ' . $e->getMessage());

        return [
            'ok'      => false,
            'message' => 'Check-in could not be saved.',
            'code'    => 'CHECKIN_FAILED',
            'status'  => 500,
        ];
    }

    if ($result !== true && $result !== 'pre_checked_in') {
        $code   = 'CHECKIN_FAILED';
        $status = 422;
        if ($result === 'Already checked in.') {
            $code   = 'ALREADY_CHECKED_IN';
            $status = 409;
        }

        return [
            'ok'      => false,
            'message' => is_string($result) ? $result : 'Check-in failed.',
            'code'    => $code,
            'status'  => $status,
        ];
    }

    $attendance = getAttendanceByRegistration($pdo, $registrationId);
    if ($attendance === null) {
        return [
            'ok'      => false,
            'message' => 'Check-in saved but attendance record missing.',
            'code'    => 'CHECKIN_FAILED',
            'status'  => 500,
        ];
    }

    $message = $result === 'pre_checked_in'
        ? getHibernationCheckinMessagePhase15()
        : 'Check-in successful.';

    return mobileAttendanceBuildCheckinResponse($pdo, $fresh, $registration, $gps, $attendance, false, $message);
}

/**
 * @param array<string, mixed> $body
 * @return array<string, mixed>
 */
function mobileAttendanceServiceGpsPing(PDO $pdo, array $staff, array $body): array
{
    $staffId = (int) ($staff['id'] ?? 0);
    $email   = strtolower(trim((string) ($staff['email'] ?? '')));

    mobileAttendancePingThrottle($staffId);

    $disabled = mobileAttendanceRequireGpsEnabled($pdo);
    if ($disabled !== null) {
        return $disabled;
    }

    $gps = mobileAttendanceParseGps($body);
    if ($gps === null) {
        return [
            'ok'      => false,
            'message' => getGpsRequiredMessage(),
            'code'    => 'GPS_REQUIRED',
            'status'  => 422,
        ];
    }

    $registrationId = (int) ($body['registration_id'] ?? 0);
    $shift          = null;

    if ($registrationId > 0) {
        $owned = mobileAttendanceLoadOwnedRegistration($pdo, $staff, $registrationId);
        if (empty($owned['ok'])) {
            return $owned;
        }
        $attendance = getAttendanceByRegistration($pdo, $registrationId);
        if ($attendance === null) {
            return [
                'ok'      => false,
                'message' => 'Attendance not found.',
                'code'    => 'NOT_FOUND',
                'status'  => 404,
            ];
        }
        $event = getEventById($pdo, (int) ($attendance['event_id'] ?? 0));
        if ($event === null) {
            return [
                'ok'      => false,
                'message' => 'Event not found.',
                'code'    => 'NOT_FOUND',
                'status'  => 404,
            ];
        }
    } else {
        $shift = getStaffActiveShiftMonitoring($pdo, $email, $staffId);
        if ($shift === null) {
            return [
                'ok'         => false,
                'message'    => 'No active shift to monitor today.',
                'code'       => 'NO_ACTIVE_SHIFT',
                'status'     => 404,
                'monitoring' => false,
            ];
        }
        $registrationId = (int) ($shift['registration_id'] ?? 0);
        $attendance     = getAttendanceByRegistration($pdo, $registrationId);
        $event          = getEventById($pdo, (int) ($shift['event_id'] ?? 0));
        if ($attendance === null || $event === null) {
            return [
                'ok'      => false,
                'message' => 'Active shift data not found.',
                'code'    => 'NOT_FOUND',
                'status'  => 404,
            ];
        }
    }

    $result = processGpsAttendancePing($pdo, $attendance, $event, $gps);
    $result['source'] = 'mobile_app';
    $result['registration_id'] = $registrationId;
    $result['venue_distance'] = mobileAttendanceVenueDistance($pdo, $event, $gps);
    $result['coordinates'] = [
        'lat'        => (float) $gps['lat'],
        'lng'        => (float) $gps['lng'],
        'accuracy_m' => $gps['accuracy_m'] ?? null,
    ];

    $freshAttendance = getAttendanceByRegistration($pdo, $registrationId);
    if ($freshAttendance !== null) {
        $result['locations'] = mobileAttendanceLocationPayload($freshAttendance, $gps);
    }

    if (!empty($result['ok'])) {
        return array_merge(['ok' => true], $result);
    }

    return [
        'ok'      => false,
        'message' => (string) ($result['error'] ?? $result['message'] ?? 'GPS ping failed.'),
        'code'    => 'GPS_PING_FAILED',
        'status'  => 422,
        'details' => $result,
    ];
}

/**
 * @param array<string, mixed> $query
 * @return array<string, mixed>
 */
function mobileAttendanceServiceGpsStatus(PDO $pdo, array $staff, array $query): array
{
    $staffId = (int) ($staff['id'] ?? 0);
    $email   = strtolower(trim((string) ($staff['email'] ?? '')));

    mobileAttendanceReadThrottle($staffId);

    $gpsEnabled = isGpsAttendanceV2Enabled($pdo);
    $registrationId = (int) ($query['registration_id'] ?? 0);

    try {
        enforceStaleGeofenceSignouts($pdo);
    } catch (Throwable $e) {
        error_log('[MobileAPI] enforceStaleGeofenceSignouts: ' . $e->getMessage());
    }

    $shift = null;
    if ($registrationId > 0) {
        $owned = mobileAttendanceLoadOwnedRegistration($pdo, $staff, $registrationId);
        if (empty($owned['ok'])) {
            return $owned;
        }
        $attendance = getAttendanceByRegistration($pdo, $registrationId);
        if ($attendance !== null) {
            $event = getEventById($pdo, (int) ($attendance['event_id'] ?? 0));
            if ($event !== null) {
                $shift = array_merge($attendance, [
                    'registration_id' => $registrationId,
                    'event_name'      => (string) ($event['name'] ?? ''),
                    'event_date'      => (string) ($event['event_date'] ?? ''),
                ]);
            }
        }
    } else {
        $shift = getStaffActiveShiftMonitoring($pdo, $email, $staffId);
    }

    if ($shift === null) {
        return [
            'ok'         => true,
            'monitoring' => false,
            'shift'      => null,
            'attendance' => null,
            'locations'  => null,
            'policy'     => [
                'gps_enabled'       => $gpsEnabled,
                'max_accuracy_m'    => getGpsMaxAccuracyMeters($pdo),
                'geofence_strikes'  => GPS_GEOFENCE_EXIT_STRIKES,
            ],
        ];
    }

    $regId      = (int) ($shift['registration_id'] ?? $shift['id'] ?? 0);
    $attendance = getAttendanceByRegistration($pdo, $regId) ?? $shift;
    $eventId    = (int) ($attendance['event_id'] ?? 0);
    $event      = $eventId > 0 ? getEventById($pdo, $eventId) : null;
    $status     = strtolower((string) ($attendance['attendance_status'] ?? ''));

    return [
        'ok'         => true,
        'monitoring' => in_array($status, [ATTENDANCE_STATUS_ACTIVE, ATTENDANCE_STATUS_PRE_CHECKED_IN], true),
        'live'       => $status === ATTENDANCE_STATUS_ACTIVE,
        'shift'      => [
            'registration_id' => $regId,
            'event'           => is_array($event) ? mobileAttendanceFormatEvent($pdo, $event) : null,
        ],
        'attendance' => [
            'attendance_status' => (string) ($attendance['attendance_status'] ?? ''),
            'checked_in_at'     => $attendance['checked_in_at'] ?? $attendance['activated_at'] ?? null,
            'checked_out_at'    => $attendance['checked_out_at'] ?? null,
            'hours_worked'      => isset($attendance['hours_worked']) ? (float) $attendance['hours_worked'] : null,
            'signout_reason'    => $attendance['signout_reason'] ?? null,
        ],
        'locations'  => mobileAttendanceLocationPayload($attendance),
        'policy'     => [
            'gps_enabled'      => $gpsEnabled,
            'max_accuracy_m'   => getGpsMaxAccuracyMeters($pdo),
            'geofence_strikes' => GPS_GEOFENCE_EXIT_STRIKES,
        ],
    ];
}

/**
 * @param array<string, mixed> $body
 * @return array<string, mixed>
 */
function mobileAttendanceServiceCheckout(PDO $pdo, array $staff, array $body): array
{
    $staffId = (int) ($staff['id'] ?? 0);
    mobileAttendanceWriteThrottle($staffId);

    $disabled = mobileAttendanceRequireGpsEnabled($pdo);
    if ($disabled !== null) {
        return $disabled;
    }

    $registrationId = (int) ($body['registration_id'] ?? 0);
    $owned          = mobileAttendanceLoadOwnedRegistration($pdo, $staff, $registrationId);
    if (empty($owned['ok'])) {
        return $owned;
    }

    $registration = $owned['registration'];
    if ((string) ($registration['status'] ?? '') !== 'approved') {
        return [
            'ok'      => false,
            'message' => 'Only approved shifts can be checked out.',
            'code'    => 'FORBIDDEN',
            'status'  => 403,
        ];
    }

    $attendance = getAttendanceByRegistration($pdo, $registrationId);
    if ($attendance === null || !hasCheckedIn($pdo, $registrationId)) {
        return [
            'ok'      => false,
            'message' => 'Not checked in yet.',
            'code'    => 'NOT_CHECKED_IN',
            'status'  => 422,
        ];
    }

    $checkedOutAt = trim((string) ($attendance['checked_out_at'] ?? ''));
    if ($checkedOutAt !== '') {
        return [
            'ok'             => true,
            'check_out_status' => 'already_checked_out',
            'signed_out'     => true,
            'already'        => true,
            'message'        => 'Already checked out.',
            'checked_out_at' => $checkedOutAt,
            'hours_worked'   => isset($attendance['hours_worked']) ? (float) $attendance['hours_worked'] : null,
            'attendance_status' => (string) ($attendance['attendance_status'] ?? ''),
            'reason'         => (string) ($attendance['signout_reason'] ?? ''),
            'registration_id'=> $registrationId,
            'locations'      => mobileAttendanceLocationPayload($attendance),
        ];
    }

    $status = strtolower((string) ($attendance['attendance_status'] ?? ATTENDANCE_STATUS_ACTIVE));
    if ($status === ATTENDANCE_STATUS_PRE_CHECKED_IN) {
        return [
            'ok'      => false,
            'message' => 'Shift has not activated yet; check-out is not available.',
            'code'    => 'SHIFT_NOT_ACTIVE',
            'status'  => 422,
        ];
    }

    if ($status !== ATTENDANCE_STATUS_ACTIVE && $status !== '') {
        return [
            'ok'      => false,
            'message' => 'Check-out is not available for this attendance state.',
            'code'    => 'FORBIDDEN',
            'status'  => 403,
        ];
    }

    $gps = mobileAttendanceParseGps($body);
    $eventId = (int) ($attendance['event_id'] ?? 0);
    $event   = $eventId > 0 ? getEventById($pdo, $eventId) : null;

    if ($gps !== null) {
        updateAttendanceLastGps($pdo, (int) ($attendance['id'] ?? 0), $gps);
    }

    $result = autoSignOutAttendance($pdo, (int) ($attendance['id'] ?? 0), 'manual');
    if (empty($result['ok'])) {
        return [
            'ok'      => false,
            'message' => (string) ($result['message'] ?? 'Check-out failed.'),
            'code'    => 'CHECKOUT_FAILED',
            'status'  => 409,
        ];
    }

    $after = getAttendanceByRegistration($pdo, $registrationId) ?? $attendance;
    $outcome = resolveStaffShiftOutcomeMeta(array_merge($registration, $after));

    return [
        'ok'               => true,
        'check_out_status' => 'checked_out',
        'signed_out'       => true,
        'already'          => false,
        'message'          => 'Check-out successful.',
        'checked_out_at'   => $after['checked_out_at'] ?? null,
        'hours_worked'     => isset($after['hours_worked']) ? (float) $after['hours_worked'] : null,
        'attendance_status'=> (string) ($after['attendance_status'] ?? ATTENDANCE_STATUS_AUTO_SIGNED_OUT),
        'attendance_outcome' => [
            'code'  => (string) ($outcome['code'] ?? ''),
            'label' => (string) ($outcome['label'] ?? ''),
        ],
        'reason'           => (string) ($after['signout_reason'] ?? 'manual'),
        'registration_id'  => $registrationId,
        'coordinates'      => $gps !== null ? [
            'lat'        => (float) $gps['lat'],
            'lng'        => (float) $gps['lng'],
            'accuracy_m' => $gps['accuracy_m'] ?? null,
        ] : null,
        'venue_distance'   => ($gps !== null && is_array($event))
            ? mobileAttendanceVenueDistance($pdo, $event, $gps)
            : null,
        'event'            => is_array($event) ? mobileAttendanceFormatEvent($pdo, $event) : null,
        'locations'        => mobileAttendanceLocationPayload($after, $gps),
    ];
}

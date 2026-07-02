<?php

declare(strict_types=1);

require_once __DIR__ . '/../../staff-app-v3-data.php';
require_once __DIR__ . '/../../staff-portal-dashboard.php';
require_once __DIR__ . '/../../staff-repository.php';
require_once __DIR__ . '/../../staff-venue-checkin.php';
require_once __DIR__ . '/../../staff-profile-gate.php';
require_once __DIR__ . '/../../attendance-repository.php';
require_once __DIR__ . '/../../company.php';
require_once __DIR__ . '/../../status-repository.php';
require_once __DIR__ . '/../../automation/staff-self-service.php';
require_once __DIR__ . '/../../automation/automation-schema.php';
require_once __DIR__ . '/../mobile-rate-limit.php';
require_once __DIR__ . '/../mappers/MobileShiftMapper.php';
require_once __DIR__ . '/MobileDashboardService.php';

function mobileShiftReadThrottle(int $staffId): void
{
    mobileThrottleOrFail('shifts_read_' . $staffId, 120, 60);
}

function mobileShiftWriteThrottle(int $staffId): void
{
    mobileThrottleOrFail('shifts_write_' . $staffId, 20, 60);
}

/**
 * @return array{ok: true, shifts: list, pagination: array, filters: array}|array{ok: false, message: string, code: string, status: int}
 */
function mobileShiftServiceList(PDO $pdo, array $staff, array $query): array
{
    $staffId = (int) ($staff['id'] ?? 0);
    $email   = strtolower(trim((string) ($staff['email'] ?? '')));

    mobileShiftReadThrottle($staffId);

    $filter   = strtolower(trim((string) ($query['filter'] ?? 'all')));
    $employer = trim((string) ($query['employer'] ?? ''));
    $search   = trim((string) ($query['q'] ?? ''));
    $page     = max(1, (int) ($query['page'] ?? 1));
    $perPage  = max(1, min(50, (int) ($query['per_page'] ?? 50)));

    if (!in_array($filter, ['upcoming', 'past', 'all'], true)) {
        return [
            'ok'      => false,
            'message' => 'Invalid filter. Use upcoming, past, or all.',
            'code'    => 'VALIDATION_ERROR',
            'status'  => 422,
        ];
    }

    $statusToken    = $email !== '' ? (resolveStatusTokenByEmail($pdo, $email) ?? '') : '';
    $rows           = getStaffV3ShiftRows($pdo, $email, $statusToken);
    if ($rows === [] && $staffId > 0) {
        $rows = getStaffV3ShiftRowsByStaffId($pdo, $staffId);
    }
    $companyName    = getCompanyName($pdo);
    $today          = getOperationalTodayYmd($pdo);
    $filtered       = mobileShiftFilterRows($rows, $filter, $today, $employer, $search, $companyName);
    $waitlistRows   = mobileShiftLoadWaitlistRows($pdo, $email, $staffId);
    $combined       = array_merge($filtered, $waitlistRows);

    usort($combined, static function (array $a, array $b): int {
        $da = substr((string) ($a['event_date'] ?? ''), 0, 10);
        $db = substr((string) ($b['event_date'] ?? ''), 0, 10);

        return strcmp($da, $db);
    });

    $total  = count($combined);
    $offset = ($page - 1) * $perPage;
    $slice  = array_slice($combined, $offset, $perPage);
    $shifts = [];

    foreach ($slice as $row) {
        $shifts[] = mobileMapShiftRow($pdo, $row, $staff, $companyName);
    }

    return [
        'ok'         => true,
        'shifts'     => $shifts,
        'pagination' => [
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => $total,
            'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        ],
        'filters'    => [
            'filter'   => $filter,
            'employer' => $employer !== '' ? $employer : null,
            'q'        => $search !== '' ? $search : null,
        ],
    ];
}

/**
 * @return array{ok: true, shift: array, checkin: array}|array{ok: false, message: string, code: string, status: int}
 */
function mobileShiftServiceToday(PDO $pdo, array $staff): array
{
    $staffId = (int) ($staff['id'] ?? 0);
    $email   = strtolower(trim((string) ($staff['email'] ?? '')));

    mobileShiftReadThrottle($staffId);

    $statusToken = $email !== '' ? (resolveStatusTokenByEmail($pdo, $email) ?? '') : '';
    $shiftRows   = getStaffV3ShiftRows($pdo, $email, $statusToken);
    $todayShift  = getStaffV3TodayShift($shiftRows, $pdo);
    $companyName = getCompanyName($pdo);

    $fresh = getStaffById($pdo, $staffId) ?? $staff;
    $checkInStatus = mobileDashboardBuildCheckInStatus($pdo, $email, $fresh, $todayShift, $shiftRows);

    return [
        'ok'      => true,
        'shift'   => $todayShift !== null
            ? mobileMapShiftRow($pdo, $todayShift, $fresh, $companyName)
            : null,
        'checkin' => $checkInStatus,
    ];
}

/**
 * @return array{ok: true, shift: array}|array{ok: false, message: string, code: string, status: int}
 */
function mobileShiftServiceGet(PDO $pdo, array $staff, int $registrationId): array
{
    $staffId = (int) ($staff['id'] ?? 0);

    mobileShiftReadThrottle($staffId);

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

    $companyName = getCompanyName($pdo);
    $fresh       = getStaffById($pdo, $staffId) ?? $staff;

    return [
        'ok'    => true,
        'shift' => mobileMapShiftRow($pdo, $registration, $fresh, $companyName),
    ];
}

/**
 * @return array{ok: true, shift: array, message: string}|array{ok: false, message: string, code: string, status: int}
 */
function mobileShiftServiceRespond(PDO $pdo, array $staff, int $registrationId, string $response): array
{
    $staffId = (int) ($staff['id'] ?? 0);
    $email   = strtolower(trim((string) ($staff['email'] ?? '')));

    mobileShiftWriteThrottle($staffId);

    if ($registrationId < 1) {
        return [
            'ok'      => false,
            'message' => 'Invalid registration ID.',
            'code'    => 'VALIDATION_ERROR',
            'status'  => 422,
        ];
    }

    $response = strtolower(trim($response));
    if (!in_array($response, ['accepted', 'declined'], true)) {
        return [
            'ok'      => false,
            'message' => 'Response must be accepted or declined.',
            'code'    => 'VALIDATION_ERROR',
            'status'  => 422,
        ];
    }

    if (!auto_shift_response_available($pdo)) {
        return [
            'ok'      => false,
            'message' => 'Shift response is not available.',
            'code'    => 'FEATURE_UNAVAILABLE',
            'status'  => 503,
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

    if ((string) ($registration['status'] ?? '') !== 'approved') {
        return [
            'ok'      => false,
            'message' => 'Only approved shifts can be accepted or declined.',
            'code'    => 'FORBIDDEN',
            'status'  => 403,
        ];
    }

    $current = strtolower(trim((string) ($registration['shift_response'] ?? '')));
    if ($current !== '' && $current !== $response) {
        return [
            'ok'      => false,
            'message' => 'Shift response was already recorded as ' . $current . '.',
            'code'    => 'CONFLICT',
            'status'  => 409,
        ];
    }

    if ($current === $response) {
        $companyName = getCompanyName($pdo);
        $fresh       = getStaffById($pdo, $staffId) ?? $staff;

        return [
            'ok'      => true,
            'message' => 'Shift response unchanged.',
            'shift'   => mobileMapShiftRow($pdo, $registration, $fresh, $companyName),
        ];
    }

    if ($email === '') {
        return [
            'ok'      => false,
            'message' => 'Staff email is required.',
            'code'    => 'FORBIDDEN',
            'status'  => 403,
        ];
    }

    $updated = ssp_set_shift_response($pdo, $registrationId, $email, $response);
    if (!$updated) {
        return [
            'ok'      => false,
            'message' => 'Could not save shift response.',
            'code'    => 'UPDATE_FAILED',
            'status'  => 500,
        ];
    }

    $after = getStaffRegistrationById($pdo, $registrationId);
    if ($after === null) {
        return [
            'ok'      => false,
            'message' => 'Shift not found after update.',
            'code'    => 'NOT_FOUND',
            'status'  => 404,
        ];
    }

    $companyName = getCompanyName($pdo);
    $fresh       = getStaffById($pdo, $staffId) ?? $staff;

    return [
        'ok'      => true,
        'message' => $response === 'accepted' ? 'Shift accepted.' : 'Shift declined.',
        'shift'   => mobileMapShiftRow($pdo, $after, $fresh, $companyName),
    ];
}

/**
 * @param array<string, mixed> $registration
 * @param array<string, mixed> $staff
 */
function mobileShiftStaffOwnsRegistration(array $registration, array $staff): bool
{
    $email      = strtolower(trim((string) ($staff['email'] ?? '')));
    $staffId    = (int) ($staff['id'] ?? 0);
    $regEmail   = strtolower(trim((string) ($registration['email'] ?? '')));
    $regStaffId = (int) ($registration['staff_id'] ?? 0);

    if ($email !== '' && $regEmail !== '' && $regEmail === $email) {
        return true;
    }

    return $staffId > 0 && $regStaffId > 0 && $regStaffId === $staffId;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function mobileShiftRowIsPastForFilter(array $row, string $today): bool
{
    $eventDate = substr((string) ($row['event_date'] ?? ''), 0, 10);
    if (staffV3AttendanceHasCompletedCheckout($row)) {
        return true;
    }

    $outcomeCode = (string) (resolveStaffShiftOutcomeMeta($row)['code'] ?? '');
    if (in_array($outcomeCode, ['completed', 'no_show'], true)) {
        return true;
    }

    if ($eventDate !== '' && $eventDate < $today) {
        return true;
    }

    $window = getEventCheckinWindow($row);

    return (($window['status'] ?? '') === 'after');
}

function mobileShiftFilterRows(
    array $rows,
    string $filter,
    string $today,
    string $employer,
    string $search,
    string $companyName
): array {
    $out = [];

    foreach ($rows as $row) {
        $status = (string) ($row['status'] ?? '');

        if ($filter === 'upcoming' || $filter === 'past') {
            if ($status !== 'approved') {
                continue;
            }
            $isPast = mobileShiftRowIsPastForFilter($row, $today);
            if ($filter === 'upcoming' && $isPast) {
                continue;
            }
            if ($filter === 'past' && !$isPast) {
                continue;
            }
        }
        if ($employer !== '' && getStaffV3EmployerLabel($row, $companyName) !== $employer) {
            continue;
        }
        if ($search !== '') {
            $hay = strtolower(
                (string) ($row['event_name'] ?? '') . ' '
                . formatStaffStatusVenueLabel($row) . ' '
                . getStaffV3EmployerLabel($row, $companyName)
            );
            if (!str_contains($hay, strtolower($search))) {
                continue;
            }
        }

        $out[] = $row;
    }

    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function mobileShiftLoadWaitlistRows(PDO $pdo, string $email, int $staffId): array
{
    $email = strtolower(trim($email));
    if ($email === '' && $staffId < 1) {
        return [];
    }

    require_once __DIR__ . '/../../staff-allocation.php';
    if (!staffAllocationTableExists($pdo, 'staff_waitlist')) {
        return [];
    }

    try {
        $conditions = [];
        $params     = [];

        if ($email !== '') {
            $conditions[] = 'LOWER(w.email) = :email';
            $params['email'] = $email;
        }
        if ($staffId > 0) {
            $conditions[] = 'w.staff_id = :staff_id';
            $params['staff_id'] = $staffId;
        }

        if ($conditions === []) {
            return [];
        }

        $sql = 'SELECT w.*, e.name AS event_name, e.event_date, e.location AS event_location,
                       e.start_time, e.end_time, e.main_security_company, e.is_active
                FROM staff_waitlist w
                LEFT JOIN events e ON e.id = w.preferred_event_id
                WHERE w.status = \'active\' AND (' . implode(' OR ', $conditions) . ')
                ORDER BY e.event_date ASC, w.created_at ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'record_type'           => 'waitlist',
                'waitlist_id'           => (int) ($row['id'] ?? 0),
                'id'                    => null,
                'registration_id'       => null,
                'event_id'              => (int) ($row['preferred_event_id'] ?? 0),
                'event_name'            => (string) ($row['event_name'] ?? 'Waiting list'),
                'event_date'            => (string) ($row['event_date'] ?? ''),
                'event_location'        => (string) ($row['event_location'] ?? ''),
                'event_start_time'      => (string) ($row['start_time'] ?? ''),
                'event_end_time'        => (string) ($row['end_time'] ?? ''),
                'main_security_company' => (string) ($row['main_security_company'] ?? ''),
                'status'                => 'waitlist',
                'shift_response'        => '',
                'is_active'             => (int) ($row['is_active'] ?? 1),
                'allocation_type'       => (string) ($row['allocation_type'] ?? ''),
            ];
        }

        return $out;
    } catch (Throwable $e) {
        error_log('[MobileAPI] waitlist rows: ' . $e->getMessage());

        return [];
    }
}

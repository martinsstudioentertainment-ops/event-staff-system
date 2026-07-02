<?php

declare(strict_types=1);

require_once __DIR__ . '/../../automation/staff-portal.php';
require_once __DIR__ . '/../../automation/staff-self-service.php';
require_once __DIR__ . '/../../workforce/staff-availability.php';
require_once __DIR__ . '/../mobile-rate-limit.php';
require_once __DIR__ . '/../mappers/MobileAvailabilityMapper.php';

function mobileAvailabilityReadThrottle(int $staffId): void
{
    mobileThrottleOrFail('availability_read_' . $staffId, 120, 60);
}

function mobileAvailabilityWriteThrottle(int $staffId): void
{
    mobileThrottleOrFail('availability_write_' . $staffId, 60, 60);
}

function mobileAvailabilityEnsurePreferredStatus(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $path = dirname(__DIR__, 3) . '/database/migrate-phase69-mobile-availability-preferred.sql';
    if (!is_file($path)) {
        return;
    }

    try {
        $sql = (string) file_get_contents($path);
        if (trim($sql) !== '') {
            $pdo->exec($sql);
        }
    } catch (Throwable $e) {
        // ENUM may already include preferred; ignore.
    }
}

/**
 * @return array{ok: false, message: string, code: string, status: int}|null
 */
function mobileAvailabilityValidateDate(string $date): ?array
{
    $date = trim($date);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return [
            'ok'      => false,
            'message' => 'Invalid date format. Use YYYY-MM-DD.',
            'code'    => 'VALIDATION_ERROR',
            'status'  => 422,
        ];
    }

    $ts = strtotime($date);
    if ($ts === false) {
        return [
            'ok'      => false,
            'message' => 'Invalid date.',
            'code'    => 'VALIDATION_ERROR',
            'status'  => 422,
        ];
    }

    if ($date < date('Y-m-d')) {
        return [
            'ok'      => false,
            'message' => 'Cannot change availability for past dates.',
            'code'    => 'VALIDATION_ERROR',
            'status'  => 422,
        ];
    }

    return null;
}

/**
 * @return array{ok: false, message: string, code: string, status: int}|null
 */
function mobileAvailabilityConflictForSet(PDO $pdo, int $staffId, string $date): ?array
{
    if (!wf_availability_table_exists($pdo)) {
        return null;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT status, admin_approved FROM staff_availability
             WHERE staff_id = :staff_id AND avail_date = :date LIMIT 1'
        );
        $stmt->execute(['staff_id' => $staffId, 'date' => $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $status = (string) ($row['status'] ?? '');
        if (in_array($status, ['leave_approved', 'holiday_approved'], true)) {
            return [
                'ok'      => false,
                'message' => 'Approved leave or holiday is set for this date.',
                'code'    => 'CONFLICT',
                'status'  => 409,
            ];
        }

        if (in_array($status, ['leave_requested', 'holiday_requested'], true)) {
            return [
                'ok'      => false,
                'message' => 'A leave or holiday request is pending for this date.',
                'code'    => 'CONFLICT',
                'status'  => 409,
            ];
        }
    } catch (Throwable $e) {
        error_log('[MobileAPI] availability conflict check: ' . $e->getMessage());
    }

    return null;
}

/**
 * @param array<string, mixed> $query
 * @return array{ok: true, month: string, days: list, statuses: list}|array{ok: false, message: string, code: string, status: int}
 */
function mobileAvailabilityServiceGetMonth(PDO $pdo, array $staff, array $query): array
{
    $staffId = (int) ($staff['id'] ?? 0);
    mobileAvailabilityReadThrottle($staffId);

    $month = trim((string) ($query['month'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        return [
            'ok'      => false,
            'message' => 'Month is required (YYYY-MM).',
            'code'    => 'VALIDATION_ERROR',
            'status'  => 400,
        ];
    }

    wf_ensure_availability_schema($pdo);
    $rows = portal_availability_month($pdo, $staffId, $month);

    $byDate = [];
    foreach ($rows as $row) {
        $mapped = mobileMapAvailabilityDay($row);
        $byDate[$mapped['date']] = $mapped;
    }

    $from = $month . '-01';
    $to   = date('Y-m-t', strtotime($from));
    $days = [];
    $cursor = $from;

    while ($cursor <= $to) {
        if (isset($byDate[$cursor])) {
            $days[] = $byDate[$cursor];
        } else {
            $days[] = [
                'date'            => $cursor,
                'status'          => 'available',
                'approval_status' => 'none',
                'notes'           => '',
                'admin_approved'  => false,
                'updated_at'      => '',
            ];
        }
        $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
    }

    return [
        'ok'       => true,
        'month'    => $month,
        'days'     => $days,
        'statuses' => array_merge(
            mobileAvailabilitySettableStatuses(),
            mobileAvailabilityLeaveTypes()
        ),
    ];
}

/**
 * @param array<string, mixed> $body
 * @return array{ok: true, day: array, message: string}|array{ok: false, message: string, code: string, status: int}
 */
function mobileAvailabilityServiceSetDay(PDO $pdo, array $staff, string $date, array $body): array
{
    $staffId = (int) ($staff['id'] ?? 0);
    mobileAvailabilityWriteThrottle($staffId);

    if ($staffId < 1) {
        return [
            'ok'      => false,
            'message' => 'Staff account is required.',
            'code'    => 'FORBIDDEN',
            'status'  => 403,
        ];
    }

    $dateError = mobileAvailabilityValidateDate($date);
    if ($dateError !== null) {
        return $dateError;
    }

    $status = strtolower(trim((string) ($body['status'] ?? '')));
    $dbStatus = mobileAvailabilityToDbStatus($status);
    if ($dbStatus === null) {
        return [
            'ok'      => false,
            'message' => 'Status must be available, unavailable, or preferred.',
            'code'    => 'VALIDATION_ERROR',
            'status'  => 422,
        ];
    }

    $notes = trim((string) ($body['notes'] ?? ''));
    if (mb_strlen($notes) > 500) {
        return [
            'ok'      => false,
            'message' => 'Notes must be 500 characters or fewer.',
            'code'    => 'VALIDATION_ERROR',
            'status'  => 422,
        ];
    }

    $conflict = mobileAvailabilityConflictForSet($pdo, $staffId, $date);
    if ($conflict !== null) {
        return $conflict;
    }

    mobileAvailabilityEnsurePreferredStatus($pdo);
    wf_ensure_availability_schema($pdo);

    if (!ssp_confirm_availability($pdo, $staffId, $date, $dbStatus, $notes)) {
        return [
            'ok'      => false,
            'message' => 'Could not update availability.',
            'code'    => 'UPDATE_FAILED',
            'status'  => 500,
        ];
    }

    $day = mobileAvailabilityServiceFetchDay($pdo, $staffId, $date);

    return [
        'ok'      => true,
        'message' => 'Availability updated.',
        'day'     => $day,
    ];
}

/**
 * @param array<string, mixed> $body
 * @return array{ok: true, day: array, message: string}|array{ok: false, message: string, code: string, status: int}
 */
function mobileAvailabilityServiceLeave(PDO $pdo, array $staff, array $body): array
{
    $staffId = (int) ($staff['id'] ?? 0);
    mobileAvailabilityWriteThrottle($staffId);

    if ($staffId < 1) {
        return [
            'ok'      => false,
            'message' => 'Staff account is required.',
            'code'    => 'FORBIDDEN',
            'status'  => 403,
        ];
    }

    $date = trim((string) ($body['date'] ?? ''));
    $dateError = mobileAvailabilityValidateDate($date);
    if ($dateError !== null) {
        return $dateError;
    }

    $type = strtolower(trim((string) ($body['type'] ?? '')));
    if (!in_array($type, mobileAvailabilityLeaveTypes(), true)) {
        return [
            'ok'      => false,
            'message' => 'Type must be leave or holiday.',
            'code'    => 'VALIDATION_ERROR',
            'status'  => 422,
        ];
    }

    $notes = trim((string) ($body['notes'] ?? ''));
    if (mb_strlen($notes) > 500) {
        return [
            'ok'      => false,
            'message' => 'Notes must be 500 characters or fewer.',
            'code'    => 'VALIDATION_ERROR',
            'status'  => 422,
        ];
    }

    $conflict = mobileAvailabilityConflictForSet($pdo, $staffId, $date);
    if ($conflict !== null) {
        return $conflict;
    }

    wf_ensure_availability_schema($pdo);

    if (!ssp_request_leave($pdo, $staffId, $date, $type, $notes)) {
        return [
            'ok'      => false,
            'message' => 'Could not submit leave request.',
            'code'    => 'REQUEST_FAILED',
            'status'  => 500,
        ];
    }

    $day = mobileAvailabilityServiceFetchDay($pdo, $staffId, $date);

    return [
        'ok'      => true,
        'message' => ucfirst($type) . ' request submitted for admin approval.',
        'day'     => $day,
    ];
}

/**
 * @return array<string, mixed>
 */
function mobileAvailabilityServiceFetchDay(PDO $pdo, int $staffId, string $date): array
{
    if (!wf_availability_table_exists($pdo)) {
        return [
            'date'            => $date,
            'status'          => 'available',
            'approval_status' => 'none',
            'notes'           => '',
            'admin_approved'  => false,
            'updated_at'      => '',
        ];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM staff_availability WHERE staff_id = :staff_id AND avail_date = :date LIMIT 1'
        );
        $stmt->execute(['staff_id' => $staffId, 'date' => $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return mobileMapAvailabilityDay($row);
        }
    } catch (Throwable $e) {
        error_log('[MobileAPI] fetch availability day: ' . $e->getMessage());
    }

    return [
        'date'            => $date,
        'status'          => 'available',
        'approval_status' => 'none',
        'notes'           => '',
        'admin_approved'  => false,
        'updated_at'      => '',
    ];
}

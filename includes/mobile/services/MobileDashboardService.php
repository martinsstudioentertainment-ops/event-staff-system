<?php

declare(strict_types=1);

require_once __DIR__ . '/../../staff-app-v3-data.php';
require_once __DIR__ . '/../../staff-portal-dashboard.php';
require_once __DIR__ . '/../../staff-portal-shift.php';
require_once __DIR__ . '/../../staff-venue-checkin.php';
require_once __DIR__ . '/../../staff-messages.php';
require_once __DIR__ . '/../../notification-center.php';
require_once __DIR__ . '/../../status-repository.php';
require_once __DIR__ . '/../../events-repository.php';
require_once __DIR__ . '/../../staff-profile-gate.php';
require_once __DIR__ . '/../../attendance-repository.php';
require_once __DIR__ . '/../../company.php';
require_once __DIR__ . '/MobileProfileService.php';
require_once __DIR__ . '/../mappers/MobileShiftMapper.php';

/**
 * @return array{ok: true, profile: array, approval_status: array, upcoming_shifts: list, unread: array, check_in_status: array, available_events_count: int, monthly: array, today_shift: ?array, profile_gate: array}
 */
function mobileDashboardServiceBuild(PDO $pdo, array $staff): array
{
    $staffId = (int) ($staff['id'] ?? 0);
    $email   = strtolower(trim((string) ($staff['email'] ?? '')));

    $fresh = getStaffById($pdo, $staffId);
    if ($fresh === null) {
        return ['ok' => false, 'message' => 'Staff not found.', 'code' => 'STAFF_NOT_FOUND', 'status' => 404];
    }

    $statusToken = $email !== '' ? (resolveStatusTokenByEmail($pdo, $email) ?? '') : '';
    $metrics     = getStaffPortalDashboardMetrics($pdo, $email);
    $monthly     = getStaffV3MonthlyStats($pdo, $email, $staffId);
    $shiftRows   = getStaffV3ShiftRows($pdo, $email, $statusToken);
    $todayShift  = getStaffV3TodayShift($shiftRows, $pdo);
    $documents   = portal_staff_documents($pdo, $fresh);
    $docStatus   = mobileProfileSummarizeDocuments($documents);
    $missing     = getStaffOnboardingMissingFields($fresh);

    $profilePayload = mobileProfileServiceFormatStaff($pdo, $fresh, $metrics, $docStatus, $missing);
    $checkInStatus  = mobileDashboardBuildCheckInStatus($pdo, $email, $fresh, $todayShift, $shiftRows);
    $activeMonitor  = getStaffActiveShiftMonitoring($pdo, $email);

    if ($activeMonitor !== null) {
        $checkInStatus['monitoring_active'] = true;
        $checkInStatus['attendance_status'] = (string) ($activeMonitor['attendance_status'] ?? $checkInStatus['attendance_status']);
    }

    $availableEventsCount = mobileDashboardCountAvailableEvents($pdo);
    $gateBlocked          = staffNeedsProfileForm($pdo, $fresh);
    $companyName          = getCompanyName($pdo);
    $upcoming             = mobileFilterUpcomingShifts($pdo, $shiftRows, $fresh, $companyName, 10);

    return [
        'ok'                     => true,
        'profile'                => $profilePayload,
        'approval_status'        => [
            'approved'          => (int) ($metrics['approved'] ?? 0),
            'pending'           => (int) ($metrics['pending'] ?? 0),
            'rejected'          => (int) ($metrics['rejected'] ?? 0),
            'upcoming_shifts'   => (int) ($metrics['upcoming'] ?? 0),
            'total'             => (int) ($metrics['total'] ?? 0),
            'overall'           => mobileDashboardOverallApprovalLabel($metrics),
        ],
        'upcoming_shifts'        => $upcoming,
        'unread'                 => [
            'messages'      => $email !== '' ? countUnreadAdminRepliesForStaff($pdo, $email) : 0,
            'notifications' => $email !== '' ? countUnreadStaffNotifications($pdo, $email) : 0,
        ],
        'check_in_status'        => $checkInStatus,
        'available_events_count' => $availableEventsCount,
        'monthly'                => $monthly,
        'today_shift'            => $todayShift !== null
            ? mobileMapShiftRow($pdo, $todayShift, $fresh, $companyName)
            : null,
        'profile_gate'           => [
            'blocked' => $gateBlocked,
            'reason'  => $gateBlocked ? mobileDashboardProfileGateReason($pdo, $fresh, $missing) : null,
        ],
    ];
}

/**
 * @param array<string, int|bool> $metrics
 */
function mobileDashboardOverallApprovalLabel(array $metrics): string
{
    $approved = (int) ($metrics['approved'] ?? 0);
    $pending  = (int) ($metrics['pending'] ?? 0);
    $total    = (int) ($metrics['total'] ?? 0);

    if ($total === 0) {
        return 'no_registrations';
    }
    if ($approved > 0 && $pending === 0) {
        return 'approved';
    }
    if ($pending > 0) {
        return 'pending';
    }

    return 'mixed';
}

function mobileDashboardCountAvailableEvents(PDO $pdo): int
{
    try {
        return count(getActiveEventsForFrontend($pdo));
    } catch (Throwable $e) {
        error_log('[MobileAPI] available events count: ' . $e->getMessage());

        return 0;
    }
}

/**
 * @param list<array<string, mixed>> $shiftRows
 * @return array<string, mixed>
 */
function mobileDashboardBuildCheckInStatus(
    PDO $pdo,
    string $email,
    array $staff,
    ?array $todayShift,
    array $shiftRows
): array {
    $registration = getStaffTodayApprovedRegistration($pdo, $email, $staff, $todayShift);
    $regId        = $registration !== null ? (int) ($registration['id'] ?? 0) : 0;

    $status = [
        'has_shift_today'      => $registration !== null,
        'registration_id'      => $regId > 0 ? $regId : null,
        'checked_in'           => false,
        'checked_in_at'        => null,
        'checked_out_at'       => null,
        'attendance_status'    => null,
        'checkin_allowed'      => false,
        'checkin_block_reason' => null,
        'monitoring_active'    => false,
    ];

    if ($registration === null) {
        $status['checkin_block_reason'] = explainStaffTodayCheckinMiss($pdo, $staff);

        return $status;
    }

    $regId = (int) ($registration['id'] ?? 0);
    $status['checked_in'] = hasCheckedIn($pdo, $regId);
    $attendance = getAttendanceByRegistration($pdo, $regId);

    if ($attendance !== null) {
        $status['checked_in_at']     = $attendance['checked_in_at'] ?? null;
        $status['checked_out_at']    = $attendance['checked_out_at'] ?? null;
        $status['attendance_status'] = $attendance['attendance_status'] ?? null;
    } elseif (!empty($registration['checked_in_at'])) {
        $status['checked_in_at'] = $registration['checked_in_at'];
        $status['checked_in']    = true;
    }

    if ($status['checked_in']) {
        $status['checkin_allowed'] = false;
        $status['checkin_block_reason'] = 'Already checked in.';

        return $status;
    }

    if (staffNeedsProfileForm($pdo, $staff)) {
        $status['checkin_block_reason'] = 'Complete your profile before checking in.';

        return $status;
    }

    $window = getEventCheckinWindow($registration);
    if (empty($window['is_open'])) {
        $status['checkin_block_reason'] = formatCheckinWindowMessage($window);

        return $status;
    }

    $status['checkin_allowed'] = true;

    return $status;
}

/**
 * @param list<string> $missing
 */
function mobileDashboardProfileGateReason(PDO $pdo, array $staff, array $missing): string
{
    if (staffRequiresProfileReverify($staff)) {
        return 'Profile re-verification required.';
    }
    if ($missing !== []) {
        return 'Please complete required profile fields.';
    }
    if (staffMustUpdateProfile($pdo, $staff)) {
        return 'Profile update required by admin.';
    }

    return 'Profile incomplete.';
}

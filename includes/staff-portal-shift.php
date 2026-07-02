<?php

declare(strict_types=1);

require_once __DIR__ . '/attendance-gps-phase1.php';
require_once __DIR__ . '/attendance-gps-signout.php';
require_once __DIR__ . '/attendance-repository.php';
require_once __DIR__ . '/date-format.php';
require_once __DIR__ . '/maps.php';
require_once __DIR__ . '/staff-repository.php';

/**
 * Active or pre-checked attendance today for GPS monitoring via staff app.
 *
 * @return array<string, mixed>|null
 */
function getStaffActiveShiftMonitoring(PDO $pdo, string $email): ?array
{
    if (!isGpsAttendanceV2Enabled($pdo)) {
        return null;
    }

    $email = strtolower(trim($email));
    if ($email === '') {
        return null;
    }

    try {
        ensureAttendanceGpsSignoutSchema($pdo);

        $sql = "SELECT a.*, sr.id AS registration_id, sr.first_name, sr.surname,
                       e.id AS event_row_id, e.name AS event_name, e.event_date,
                       e.start_time, e.end_time, e.venue_lat, e.venue_lng, e.venue_eircode,
                       e.signin_radius_m, e.location
                FROM attendance a
                INNER JOIN staff_registrations sr ON sr.id = a.registration_id
                INNER JOIN events e ON e.id = a.event_id
                WHERE LOWER(sr.email) = :email
                  AND e.event_date = :today
                  AND a.attendance_status IN (:active, :pre)
                ORDER BY a.checked_in_at DESC
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'email'  => $email,
            'today'  => getOperationalTodayYmd($pdo),
            'active' => ATTENDANCE_STATUS_ACTIVE,
            'pre'    => ATTENDANCE_STATUS_PRE_CHECKED_IN,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    } catch (Throwable $e) {
        error_log('[EventStaff] getStaffActiveShiftMonitoring: ' . $e->getMessage());

        return null;
    }
}

/**
 * @param array<string, mixed> $shift
 */
function staffShiftMonitoringIsActive(array $shift): bool
{
    return strtolower((string) ($shift['attendance_status'] ?? '')) === ATTENDANCE_STATUS_ACTIVE;
}

/**
 * @param array<string, mixed> $portalStaff
 * @return array<string, string>
 */
function staffPortalShiftBodyAttributes(PDO $pdo, array $portalStaff): array
{
    $email = strtolower(trim((string) ($portalStaff['email'] ?? '')));
    $shift = getStaffActiveShiftMonitoring($pdo, $email);
    if ($shift === null) {
        return [];
    }

    $venue = getEventVenueCoordinates($shift);
    if ($venue === null) {
        return [];
    }

    $registrationId = (int) ($shift['registration_id'] ?? 0);
    $checkinToken     = $registrationId > 0 ? (string) (ensureCheckinToken($pdo, $registrationId) ?? '') : '';
    $isActive         = staffShiftMonitoringIsActive($shift);

    return [
        'data-staff-shift-monitor'   => '1',
        'data-shift-event-id'        => (string) (int) ($shift['event_id'] ?? 0),
        'data-shift-registration-id' => (string) $registrationId,
        'data-shift-checkin-token'   => $checkinToken,
        'data-shift-venue-lat'       => (string) $venue['lat'],
        'data-shift-venue-lng'       => (string) $venue['lng'],
        'data-shift-radius-m'        => (string) (int) getEventSigninRadiusMeters($shift, $pdo),
        'data-shift-active'          => $isActive ? '1' : '0',
        'data-shift-pre-check'       => $isActive ? '0' : '1',
        'data-shift-event-name'      => (string) ($shift['event_name'] ?? 'Shift'),
        'data-session-idle-timeout'  => '28800',
    ];
}

/**
 * @param array<string, mixed>|null $portalStaff
 */
function renderStaffPortalShiftBanner(PDO $pdo, ?array $portalStaff): void
{
    if ($portalStaff === null) {
        return;
    }

    $email = strtolower(trim((string) ($portalStaff['email'] ?? '')));
    $shift = getStaffActiveShiftMonitoring($pdo, $email);
    if ($shift === null) {
        return;
    }

    $eventName = (string) ($shift['event_name'] ?? 'your shift');
    $radius    = (int) getEventSigninRadiusMeters($shift, $pdo);
    $isActive  = staffShiftMonitoringIsActive($shift);
    ?>
    <div class="es-v3__shift-banner es-v3__shift-banner--<?= $isActive ? 'active' : 'precheck' ?>" role="status" id="staff-shift-banner">
        <span class="es-v3__shift-banner-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        </span>
        <div class="es-v3__shift-banner-copy">
            <?php if ($isActive): ?>
                <strong>On shift — <?= h($eventName) ?></strong>
                <span>Stay signed in on this phone. GPS tracks you inside the <?= (int) $radius ?> m venue zone; leaving signs you out automatically. Add this app to your home screen and reopen it during the shift if you switch apps.</span>
            <?php else: ?>
                <strong>Checked in — <?= h($eventName) ?></strong>
                <span>Attendance activates when the event starts. Keep the staff app open on this phone so GPS can verify you are still at the venue.</span>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * @param array<string, mixed>|null $portalStaff
 */
/**
 * Today's signed-in shift payroll info for staff app (read-only).
 *
 * @return array<string, mixed>|null
 */
function getStaffTodayPayrollSummary(PDO $pdo, string $email): ?array
{
    ensureWorkHoursSchema($pdo);

    $email = strtolower(trim($email));
    if ($email === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT a.hours_worked, a.hours_paid, a.hours_note, a.work_end_at, a.checked_out_at,
                e.name AS event_name
         FROM attendance a
         INNER JOIN staff_registrations sr ON sr.id = a.registration_id
         INNER JOIN events e ON e.id = a.event_id
         WHERE LOWER(sr.email) = :email AND e.event_date = CURDATE()
         ORDER BY a.checked_in_at DESC
         LIMIT 1"
    );
    $stmt->execute(['email' => $email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * @param array<string, mixed>|null $portalStaff
 */
function renderStaffPortalTodayPayrollNote(PDO $pdo, ?array $portalStaff): void
{
    if ($portalStaff === null) {
        return;
    }

    require_once __DIR__ . '/work-hours-repository.php';

    $summary = getStaffTodayPayrollSummary($pdo, (string) ($portalStaff['email'] ?? ''));
    if ($summary === null) {
        return;
    }

    $note = trim((string) ($summary['hours_note'] ?? ''));
    $paid = (float) ($summary['hours_paid'] ?? 0);
    $worked = (float) ($summary['hours_worked'] ?? 0);
    if ($note === '' && $paid >= $worked) {
        return;
    }

    $eventName = (string) ($summary['event_name'] ?? 'Today\'s shift');
    ?>
    <div class="es-v3__shift-banner es-v3__shift-banner--precheck" role="status">
        <span class="es-v3__shift-banner-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
        </span>
        <div class="es-v3__shift-banner-copy">
            <strong><?= h($eventName) ?> — shift hours</strong>
            <span>Payable hours: <strong><?= h(formatHoursDecimal($paid)) ?></strong><?= $note !== '' ? ' · ' . h($note) : '' ?></span>
        </div>
    </div>
    <?php
}

/**
 * @param array<string, mixed>|null $portalStaff
 */
function renderStaffPortalShiftMonitorScript(PDO $pdo, ?array $portalStaff): void
{
    if ($portalStaff === null) {
        return;
    }

    $email = strtolower(trim((string) ($portalStaff['email'] ?? '')));
    if (getStaffActiveShiftMonitoring($pdo, $email) === null) {
        return;
    }

    $path = dirname(__DIR__) . '/assets/js/staff-shift-gps.js';
    $ver  = is_file($path) ? (string) filemtime($path) : '1';
    ?>
    <script src="assets/js/staff-shift-gps.js?v=<?= h($ver) ?>"></script>
    <?php
}

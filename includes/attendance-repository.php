<?php
/**
 * Event Staff System — Attendance & QR check-in
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/maps.php';
require_once __DIR__ . '/date-format.php';
require_once __DIR__ . '/events-repository.php';
require_once __DIR__ . '/event-checkin-window-schema.php';

const CHECKIN_WINDOW_HOURS = 1;

/** Legacy constant — sign-in window (checkin_close_time) is the sole late rule; not used for blocking. */
const SELF_CHECKIN_LATE_MINUTES_AFTER_START = 60;

function getQrCodeImageUrl(string $data, int $size = 260): string
{
    return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&data=' . urlencode($data);
}

function parseEventDateTime(string $date, string $time): ?DateTime
{
    $time = strlen($time) === 5 ? $time . ':00' : substr($time, 0, 8);
    $tz   = new DateTimeZone(date_default_timezone_get());
    $dt   = DateTime::createFromFormat('Y-m-d H:i:s', $date . ' ' . $time, $tz);

    return $dt ?: null;
}

/**
 * Sign-in window: per-event checkin_open_time / checkin_close_time when set,
 * otherwise opens 1 hour before start and closes 1 hour after end.
 *
 * @return array{
 *     opens_at: DateTime,
 *     closes_at: DateTime,
 *     event_start: DateTime,
 *     event_end: DateTime,
 *     status: 'before'|'open'|'after',
 *     is_open: bool,
 *     uses_custom_times: bool
 * }
 */
function getEventCheckinWindow(array $event): array
{
    try {
        return getEventCheckinWindowInner($event);
    } catch (Throwable $e) {
        error_log('[EventStaff] getEventCheckinWindow: ' . $e->getMessage());
        $now = new DateTime('now');

        return [
            'opens_at'          => (clone $now)->modify('-1 hour'),
            'closes_at'         => (clone $now)->modify('+12 hours'),
            'event_start'       => $now,
            'event_end'         => (clone $now)->modify('+8 hours'),
            'status'            => 'open',
            'is_open'           => true,
            'uses_custom_times' => false,
        ];
    }
}

/**
 * @return array{
 *     opens_at: DateTime,
 *     closes_at: DateTime,
 *     event_start: DateTime,
 *     event_end: DateTime,
 *     status: 'before'|'open'|'after',
 *     is_open: bool,
 *     uses_custom_times: bool
 * }
 */
function getEventCheckinWindowInner(array $event): array
{
    $date      = (string) ($event['event_date'] ?? '');
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }
    $startTime = (string) ($event['event_start_time'] ?? $event['start_time'] ?? '09:00:00');
    $endTime   = (string) ($event['event_end_time'] ?? $event['end_time'] ?? '23:00:00');

    $eventStart = parseEventDateTime($date, $startTime) ?? new DateTime($date . ' 09:00:00');
    $eventEnd   = parseEventDateTime($date, $endTime) ?? new DateTime($date . ' 23:00:00');
    $tz         = $eventStart->getTimezone();

    $customOpen  = trim((string) ($event['checkin_open_time'] ?? ''));
    $customClose = trim((string) ($event['checkin_close_time'] ?? ''));
    $usesCustom  = $customOpen !== '' || $customClose !== '';

    if ($customOpen !== '') {
        $opensAt = parseEventDateTime($date, $customOpen) ?? (clone $eventStart)->modify('-' . CHECKIN_WINDOW_HOURS . ' hour');
    } else {
        $opensAt = (clone $eventStart)->modify('-' . CHECKIN_WINDOW_HOURS . ' hour');
    }

    if ($customClose !== '') {
        $closesAt = parseEventDateTime($date, $customClose) ?? (clone $eventEnd)->modify('+' . CHECKIN_WINDOW_HOURS . ' hour');
        if ($closesAt <= $opensAt) {
            $closesAt = $closesAt->modify('+1 day');
        }
    } else {
        $closesAt = (clone $eventEnd)->modify('+' . CHECKIN_WINDOW_HOURS . ' hour');
    }

    $now     = new DateTime('now', $tz);

    if ($now < $opensAt) {
        $status = 'before';
    } elseif ($now > $closesAt) {
        $status = 'after';
    } else {
        $status = 'open';
    }

    return [
        'opens_at'            => $opensAt,
        'closes_at'           => $closesAt,
        'event_start'         => $eventStart,
        'event_end'           => $eventEnd,
        'status'              => $status,
        'is_open'             => $status === 'open',
        'uses_custom_times'   => $usesCustom,
    ];
}

function formatCheckinWindowRangeLabel(array $window): string
{
    require_once __DIR__ . '/system-settings.php';

    $timeFormat = getSystemDateFormat() . ' H:i';

    return $window['opens_at']->format($timeFormat)
        . ' – '
        . $window['closes_at']->format($timeFormat);
}

function formatCheckinWindowMessage(array $window): string
{
    require_once __DIR__ . '/i18n.php';
    require_once __DIR__ . '/system-settings.php';

    $timeFormat = getSystemDateFormat() . ' H:i';

    if ($window['status'] === 'before') {
        return t('check_in_opens_at', ['time' => $window['opens_at']->format($timeFormat)]);
    }

    if ($window['status'] === 'after') {
        return t('check_in_closed_at', ['time' => $window['closes_at']->format($timeFormat)]);
    }

    return t('check_in_open_until', ['time' => $window['closes_at']->format($timeFormat)]);
}

/**
 * Block web self/scan check-in after the event sign-in window closes.
 *
 * The check-in window (checkin_close_time or end + 1 hour) is the single source of truth.
 * A separate "60 minutes after shift start" cap is not applied — it incorrectly blocked
 * staff on long concert shifts when sign-in was still open until 22:00.
 *
 * @param array<string, mixed>|null $existingAttendance
 */
function assertSelfCheckinWithinLateCutoff(array $event, ?array $existingAttendance = null, string $method = 'self'): ?string
{
    require_once __DIR__ . '/attendance-gps-phase15.php';

    if (checkinMethodBypassesGpsRules($method)) {
        return null;
    }

    require_once __DIR__ . '/attendance-gps-phase1.php';

    if ($existingAttendance !== null && isAttendancePreCheckedIn($existingAttendance)) {
        return null;
    }

    $window = getEventCheckinWindow($event);
    if ($window['is_open']) {
        return null;
    }

    require_once __DIR__ . '/date-format.php';
    require_once __DIR__ . '/system-settings.php';

    $timeFormat = getSystemDateFormat() . ' H:i';

    return 'Check-in closed at '
        . $window['closes_at']->format($timeFormat)
        . '. Ask your supervisor for manual check-in.';
}

/**
 * @return array{lat: float, lng: float, accuracy_m: ?int}|null
 */
function parseSigninCoordinates(array $input): ?array
{
    $lat = normalizeCoordinate(isset($input['sign_lat']) ? (string) $input['sign_lat'] : null);
    $lng = normalizeCoordinate(isset($input['sign_lng']) ? (string) $input['sign_lng'] : null);

    if ($lat === null || $lng === null) {
        return null;
    }

    if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
        return null;
    }

    $accuracy = null;
    if (isset($input['sign_accuracy_m']) && $input['sign_accuracy_m'] !== '') {
        $raw = (int) round((float) $input['sign_accuracy_m']);
        if ($raw >= 0 && $raw <= 65535) {
            $accuracy = $raw;
        }
    }

    return ['lat' => $lat, 'lng' => $lng, 'accuracy_m' => $accuracy];
}

function isWithinEventVenue(array $event, float $lat, float $lng, ?PDO $pdo = null): bool
{
    $venue = getEventVenueCoordinates($event);

    if ($venue === null) {
        return false;
    }

    $distance = haversineDistanceMeters($venue['lat'], $venue['lng'], $lat, $lng);

    return $distance <= (float) getEventSigninRadiusMeters($event, $pdo);
}

/**
 * Sign-in requires venue GPS, correct time window, and physical presence at venue.
 *
 * @return array{
 *     allowed: bool,
 *     time_open: bool,
 *     at_venue: bool,
 *     venue_configured: bool,
 *     window: array<string, mixed>,
 *     message: string
 * }
 */
function getEventSigninEligibility(array $event, ?float $userLat, ?float $userLng, bool $requireVenue = true, ?PDO $pdo = null, ?int $accuracyM = null): array
{
    if ($pdo === null && function_exists('getDB')) {
        try {
            $pdo = getDB();
        } catch (Throwable $e) {
            $pdo = null;
        }
    }

    $window          = getEventCheckinWindow($event);
    $venueConfigured = eventVenueIsConfigured($event);
    $timeOpen        = $window['is_open'];
    $atVenue         = false;

    if ($userLat !== null && $userLng !== null && $venueConfigured) {
        $atVenue = isWithinEventVenue($event, $userLat, $userLng, $pdo);
    }

    if (!$requireVenue) {
        if (!$timeOpen) {
            return [
                'allowed'          => false,
                'time_open'        => false,
                'at_venue'         => false,
                'venue_configured' => $venueConfigured,
                'window'           => $window,
                'message'          => formatCheckinWindowMessage($window),
            ];
        }

        return [
            'allowed'          => true,
            'time_open'        => true,
            'at_venue'         => false,
            'venue_configured' => $venueConfigured,
            'window'           => $window,
            'message'          => 'Enter your email to check in.',
        ];
    }

    if (!$venueConfigured) {
        return [
            'allowed'          => false,
            'time_open'        => $timeOpen,
            'at_venue'         => false,
            'venue_configured' => false,
            'window'           => $window,
            'message'          => 'Sign-in is not active — set the venue Eircode and GPS for this event.',
        ];
    }

    if (!$timeOpen) {
        return [
            'allowed'          => false,
            'time_open'        => false,
            'at_venue'         => $atVenue,
            'venue_configured' => true,
            'window'           => $window,
            'message'          => formatCheckinWindowMessage($window),
        ];
    }

    if ($userLat === null || $userLng === null) {
        $gpsMsg = 'Allow location access on your phone to sign in at the venue.';
        if ($pdo !== null) {
            require_once __DIR__ . '/attendance-gps-phase1.php';
            if (isGpsAttendanceV2Enabled($pdo)) {
                require_once __DIR__ . '/attendance-gps-phase15.php';
                $gpsMsg = getGpsRequiredMessage();
            }
        }

        return [
            'allowed'          => false,
            'time_open'        => true,
            'at_venue'         => false,
            'venue_configured' => true,
            'window'           => $window,
            'message'          => $gpsMsg,
        ];
    }

    if ($pdo !== null && isGpsAttendanceV2Enabled($pdo) && $requireVenue) {
        require_once __DIR__ . '/attendance-gps-phase15.php';
        $gpsCheck = validateGpsForCheckin($pdo, $event, [
            'lat'        => $userLat,
            'lng'        => $userLng,
            'accuracy_m' => $accuracyM,
        ]);
        if (!$gpsCheck['ok']) {
            return [
                'allowed'          => false,
                'time_open'        => true,
                'at_venue'         => false,
                'venue_configured' => true,
                'window'           => $window,
                'message'          => $gpsCheck['message'],
            ];
        }

        return [
            'allowed'          => true,
            'time_open'        => true,
            'at_venue'         => true,
            'venue_configured' => true,
            'window'           => $window,
            'message'          => 'You are at the venue. You can sign in now.',
        ];
    }

    if (!$atVenue) {
        return [
            'allowed'          => false,
            'time_open'        => true,
            'at_venue'         => false,
            'venue_configured' => true,
            'window'           => $window,
            'message'          => 'You must be at ' . formatEventLocationLabel($event)
                . ' to sign in. Move to the venue entrance and try again.',
        ];
    }

    return [
        'allowed'          => true,
        'time_open'        => true,
        'at_venue'         => true,
        'venue_configured' => true,
        'window'           => $window,
        'message'          => 'You are at the venue. You can sign in now.',
    ];
}

function ensureEventSigninToken(PDO $pdo, int $eventId): ?string
{
    $stmt = $pdo->prepare('SELECT signin_token FROM events WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $eventId]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    if (!empty($row['signin_token'])) {
        return (string) $row['signin_token'];
    }

    $token  = bin2hex(random_bytes(32));
    $update = $pdo->prepare('UPDATE events SET signin_token = :token WHERE id = :id');
    $update->execute(['token' => $token, 'id' => $eventId]);

    return $token;
}

function getEventVenueSigninUrl(string $token, ?PDO $pdo = null): string
{
    return getRegistrationSiteUrl($pdo) . '/event-sign.php?e=' . urlencode($token);
}

function getEventEmailSigninUrl(string $token, ?PDO $pdo = null): string
{
    return getRegistrationSiteUrl($pdo) . '/sign-in.php?e=' . urlencode($token);
}

/** @deprecated Use getEventVenueSigninUrl() or getEventEmailSigninUrl() */
function getEventSigninUrl(string $token, ?PDO $pdo = null): string
{
    return getEventEmailSigninUrl($token, $pdo);
}

/**
 * @return array<string, mixed>|null
 */
function getEventBySigninToken(PDO $pdo, string $token): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM events WHERE signin_token = :token LIMIT 1');
    $stmt->execute(['token' => $token]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * @return array<string, mixed>|null
 */
function getApprovedRegistrationByEmailForEvent(PDO $pdo, string $email, int $eventId): ?array
{
    require_once __DIR__ . '/staff-repository.php';

    $sql = 'SELECT sr.*, e.name AS event_name, e.event_date, e.location AS event_location
            FROM staff_registrations sr
            INNER JOIN events e ON e.id = sr.event_id
            WHERE LOWER(sr.email) = LOWER(:email) AND sr.event_id = :event_id AND sr.status = :status
            LIMIT 1';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'email'    => trim($email),
            'event_id' => $eventId,
            'status'   => 'approved',
        ]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('[EventStaff] getApprovedRegistrationByEmailForEvent: ' . $e->getMessage());

        return null;
    }

    if (!$row) {
        return null;
    }

    $row = mergeRegistrationWithEvent($pdo, $row);

    return mergeRegistrationWithStaff($pdo, $row);
}

function generateCheckinToken(): string
{
    return bin2hex(random_bytes(32));
}

function ensureCheckinToken(PDO $pdo, int $registrationId): ?string
{
    require_once __DIR__ . '/staff-registration-schema.php';
    ensureStaffRegistrationCheckinSchema($pdo);

    if (!staffRegistrationColumnExists($pdo, 'checkin_token')) {
        return null;
    }

    try {
        $stmt = $pdo->prepare('SELECT checkin_token, status FROM staff_registrations WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $registrationId]);
        $row = $stmt->fetch();

        if (!$row || $row['status'] !== 'approved') {
            return null;
        }

        if (!empty($row['checkin_token'])) {
            return (string) $row['checkin_token'];
        }

        $token = generateCheckinToken();
        $update = $pdo->prepare('UPDATE staff_registrations SET checkin_token = :token WHERE id = :id');
        $update->execute(['token' => $token, 'id' => $registrationId]);

        return $token;
    } catch (Throwable $e) {
        error_log('[EventStaff] ensureCheckinToken: ' . $e->getMessage());

        return null;
    }
}

function getCheckinUrl(string $token, ?PDO $pdo = null): string
{
    return getRegistrationSiteUrl($pdo) . '/check-in.php?token=' . urlencode($token);
}

/**
 * @return array<string, mixed>|null
 */
function getRegistrationByToken(PDO $pdo, string $token): ?array
{
    $token = trim($token);
    if ($token === '') {
        return null;
    }

    require_once __DIR__ . '/staff-registration-schema.php';
    ensureStaffRegistrationCheckinSchema($pdo);

    if (!staffRegistrationColumnExists($pdo, 'checkin_token')) {
        return null;
    }

    $sql = 'SELECT sr.*, e.name AS event_name, e.event_date, e.location AS event_location
            FROM staff_registrations sr
            INNER JOIN events e ON e.id = sr.event_id
            WHERE sr.checkin_token = :token
            LIMIT 1';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('[EventStaff] getRegistrationByToken: ' . $e->getMessage());

        return null;
    }

    if (!$row) {
        return null;
    }

    require_once __DIR__ . '/staff-repository.php';

    return mergeRegistrationWithEvent($pdo, $row);
}

function parseCheckinTokenFromScan(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    if (preg_match('/[?&]token=([^&\s#]+)/i', $raw, $matches)) {
        return urldecode($matches[1]);
    }

    if (preg_match('/^[a-f0-9]{64}$/i', $raw)) {
        return $raw;
    }

    return null;
}

function hasCheckedIn(PDO $pdo, int $registrationId): bool
{
    try {
        $attendance = getAttendanceByRegistration($pdo, $registrationId);

        return $attendance !== null && registrationHadVenueCheckin($attendance);
    } catch (PDOException $e) {
        error_log('[EventStaff] hasCheckedIn: ' . $e->getMessage());

        return false;
    }
}

/**
 * @return array<string, mixed>|null
 */
function getAttendanceByRegistration(PDO $pdo, int $registrationId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM attendance WHERE registration_id = :id LIMIT 1');
    $stmt->execute(['id' => $registrationId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * @param array{lat: float, lng: float, accuracy_m: ?int}|null $gps
 * @return true|'pre_checked_in'|string true on active check-in, pre_checked_in when hibernating, error message on failure
 */
function recordCheckin(
    PDO $pdo,
    int $registrationId,
    string $method = 'self',
    ?array $gps = null,
    ?string $bibNumber = null
): bool|string {
    require_once __DIR__ . '/checkin-bib.php';

    $bibRequired = isBibRequiredForCheckinMethod($method);
    $bibParsed   = parseCheckinBibNumber($bibNumber, $bibRequired);
    if (!$bibParsed['ok']) {
        return $bibParsed['error'];
    }
    $bibToStore = $bibParsed['bib'];

    $stmt = $pdo->prepare('SELECT id, status, event_id FROM staff_registrations WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $registrationId]);
    $reg = $stmt->fetch();

    if (!$reg) {
        return 'Registration not found.';
    }

    if ($reg['status'] !== 'approved') {
        return 'Only approved staff can check in.';
    }

    $existing = getAttendanceByRegistration($pdo, $registrationId);
    if ($existing !== null) {
        $existingStatus = strtolower(trim((string) ($existing['attendance_status'] ?? '')));
        if ($existingStatus === 'no_show') {
            resetCheckinForRegistration($pdo, $registrationId);
        } elseif (registrationHadVenueCheckin($existing)) {
            return 'Already checked in.';
        }
    }

    require_once __DIR__ . '/attendance-gps-phase1.php';

    if (isGpsAttendanceV2Enabled($pdo)) {
        ensureAttendanceGpsPhase1Schema($pdo);

        $eventStmt = $pdo->prepare('SELECT * FROM events WHERE id = :id LIMIT 1');
        $eventStmt->execute(['id' => (int) $reg['event_id']]);
        $event = $eventStmt->fetch();

        if (!$event) {
            return 'Event not found.';
        }

        require_once __DIR__ . '/attendance-gps-phase15.php';
        ensureAttendanceGpsPhase15Schema($pdo);

        $adminOverride = checkinMethodBypassesGpsRules($method);
        $window        = getEventCheckinWindow($event);

        if (!$adminOverride) {
            if (!$window['is_open']) {
                return formatCheckinWindowMessage($window);
            }
            $lateError = assertSelfCheckinWithinLateCutoff($event, $existing, $method);
            if ($lateError !== null) {
                return $lateError;
            }
            $gpsCheck = validateGpsForCheckin($pdo, $event, $gps);
            if (!$gpsCheck['ok']) {
                return $gpsCheck['message'];
            }
        }

        $now = new DateTime('now', $window['event_start']->getTimezone());
        if ($adminOverride) {
            $isPreCheck  = false;
            $status      = ATTENDANCE_STATUS_ACTIVE;
            $activatedAt = $now->format('Y-m-d H:i:s');
            $gpsAt       = null;
            $gps         = null;
        } else {
            $isPreCheck  = $now < $window['event_start'];
            $status      = $isPreCheck ? ATTENDANCE_STATUS_PRE_CHECKED_IN : ATTENDANCE_STATUS_ACTIVE;
            $activatedAt = $isPreCheck ? null : $now->format('Y-m-d H:i:s');
            $gpsAt       = $gps !== null ? $now->format('Y-m-d H:i:s') : null;
        }

        try {
            $insert = $pdo->prepare(
                'INSERT INTO attendance (
                    registration_id, event_id, checked_in_method,
                    attendance_status, activated_at,
                    check_in_lat, check_in_lng, check_in_accuracy_m, check_in_gps_at
                 ) VALUES (
                    :registration_id, :event_id, :method,
                    :attendance_status, :activated_at,
                    :check_in_lat, :check_in_lng, :check_in_accuracy_m, :check_in_gps_at
                 )'
            );
            $insert->execute([
                'registration_id'    => $registrationId,
                'event_id'           => (int) $reg['event_id'],
                'method'             => $method,
                'attendance_status'  => $status,
                'activated_at'       => $activatedAt,
                'check_in_lat'       => $gps['lat'] ?? null,
                'check_in_lng'       => $gps['lng'] ?? null,
                'check_in_accuracy_m'=> $gps['accuracy_m'] ?? null,
                'check_in_gps_at'    => $gpsAt,
            ]);
        } catch (Throwable $e) {
            error_log('[EventStaff] recordCheckin GPS insert id=' . $registrationId . ': ' . $e->getMessage());

            return recordCheckinLegacyInsert($pdo, $registrationId, (int) $reg['event_id'], $method, $bibToStore);
        }

        ensureCheckinToken($pdo, $registrationId);

        try {
            require_once __DIR__ . '/notifications.php';
            notifyStaffCheckin($pdo, $registrationId, $method);
        } catch (Throwable $e) {
            error_log('[EventStaff] notifyStaffCheckin id=' . $registrationId . ': ' . $e->getMessage());
        }

        $attendanceId = (int) $pdo->lastInsertId();
        if ($gps !== null && $attendanceId > 0) {
            try {
                updateAttendanceLastGps($pdo, $attendanceId, $gps);
            } catch (Throwable $e) {
                error_log('[EventStaff] updateAttendanceLastGps id=' . $attendanceId . ': ' . $e->getMessage());
            }
        }

        if ($status === ATTENDANCE_STATUS_ACTIVE) {
            try {
                require_once __DIR__ . '/work-hours-repository.php';
                initializeWorkHoursForRegistration($pdo, $registrationId);
            } catch (Throwable $e) {
                error_log('[EventStaff] initializeWorkHours id=' . $registrationId . ': ' . $e->getMessage());
            }
        }

        if ($bibToStore !== null) {
            saveAttendanceBibNumber($pdo, $registrationId, $bibToStore);
        }

        return $isPreCheck ? 'pre_checked_in' : true;
    }

    $eventStmt = $pdo->prepare('SELECT * FROM events WHERE id = :id LIMIT 1');
    $eventStmt->execute(['id' => (int) $reg['event_id']]);
    $event = $eventStmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($event)) {
        require_once __DIR__ . '/attendance-gps-phase15.php';
        $venueGpsError = assertSelfCheckinVenueGps($pdo, $event, $gps, $method);
        if ($venueGpsError !== null) {
            return $venueGpsError;
        }

        if (!checkinMethodBypassesGpsRules($method)) {
            $lateError = assertSelfCheckinWithinLateCutoff($event, $existing, $method);
            if ($lateError !== null) {
                return $lateError;
            }

            $window = getEventCheckinWindow($event);
            if (!$window['is_open']) {
                return formatCheckinWindowMessage($window);
            }
        }
    }

    return recordCheckinLegacyInsert($pdo, $registrationId, (int) $reg['event_id'], $method, $bibToStore);
}

/**
 * @return true|string
 */
function recordCheckinLegacyInsert(
    PDO $pdo,
    int $registrationId,
    int $eventId,
    string $method,
    ?string $bibNumber = null
): bool|string {
    try {
        $insert = $pdo->prepare(
            'INSERT INTO attendance (registration_id, event_id, checked_in_method) VALUES (:registration_id, :event_id, :method)'
        );
        $insert->execute([
            'registration_id' => $registrationId,
            'event_id'        => $eventId,
            'method'          => $method,
        ]);
    } catch (Throwable $e) {
        error_log('[EventStaff] recordCheckin legacy insert id=' . $registrationId . ': ' . $e->getMessage());

        return 'Check-in could not be saved. Please ask your supervisor to check you in manually.';
    }

    ensureCheckinToken($pdo, $registrationId);

    try {
        require_once __DIR__ . '/notifications.php';
        notifyStaffCheckin($pdo, $registrationId, $method);
    } catch (Throwable $e) {
        error_log('[EventStaff] notifyStaffCheckin id=' . $registrationId . ': ' . $e->getMessage());
    }

    try {
        require_once __DIR__ . '/work-hours-repository.php';
        initializeWorkHoursForRegistration($pdo, $registrationId);
    } catch (Throwable $e) {
        error_log('[EventStaff] initializeWorkHours id=' . $registrationId . ': ' . $e->getMessage());
    }

    if ($bibNumber !== null && $bibNumber !== '') {
        saveAttendanceBibNumber($pdo, $registrationId, $bibNumber);
    }

    return true;
}

/**
 * @return array{where: string, params: array<string, mixed>}
 */
function buildAttendanceListWhere(int $eventId = 0): array
{
    $where  = "sr.status = 'approved'";
    $params = [];

    if ($eventId > 0) {
        $where .= ' AND sr.event_id = :event_id';
        $params['event_id'] = $eventId;
    }

    return ['where' => $where, 'params' => $params];
}

function countAttendanceList(PDO $pdo, int $eventId = 0): int
{
    $parts  = buildAttendanceListWhere($eventId);
    $sql    = "SELECT COUNT(*)
               FROM staff_registrations sr
               INNER JOIN events e ON e.id = sr.event_id
               WHERE {$parts['where']}";
    $stmt   = $pdo->prepare($sql);
    $stmt->execute($parts['params']);

    return (int) $stmt->fetchColumn();
}

/**
 * @return array<int, array<string, mixed>>
 */
function getAttendanceList(PDO $pdo, int $eventId = 0, ?int $limit = null, int $offset = 0): array
{
    $parts = buildAttendanceListWhere($eventId);

    $bibSelect = '';
    if (!function_exists('registrationBibColumnEnabled')) {
        require_once __DIR__ . '/registration-bib.php';
    }
    if (registrationBibColumnEnabled($pdo)) {
        $bibSelect = ', sr.assigned_bib_number';
    }

    $sql = "SELECT sr.*, e.name AS event_name, e.event_date, e.start_time, e.end_time,
                   e.checkin_open_time, e.checkin_close_time,
                   a.id AS attendance_id, a.checked_in_at, a.checked_in_method, a.work_end_at,
                   a.attendance_status, a.activated_at, a.checked_out_at, a.bib_number,
                   a.scheduled_hours, a.hours_worked, a.hours_paid, a.hours_note{$bibSelect},
                   CASE
                       WHEN a.id IS NULL THEN 0
                       WHEN LOWER(COALESCE(a.attendance_status, '')) = 'no_show' THEN 0
                       ELSE 1
                   END AS is_checked_in
            FROM staff_registrations sr
            INNER JOIN events e ON e.id = sr.event_id
            LEFT JOIN attendance a ON a.registration_id = sr.id
            WHERE {$parts['where']}
            ORDER BY is_checked_in DESC, e.event_date ASC, sr.surname ASC, sr.first_name ASC";

    if ($limit !== null) {
        $sql .= ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($parts['params']);

    return $stmt->fetchAll();
}

function getTodayCheckinCount(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM attendance WHERE DATE(checked_in_at) = CURDATE()")->fetchColumn();
}

/**
 * Attendance rows counted in today's dashboard check-in metric.
 *
 * @return list<array<string, mixed>>
 */
function listTodayCheckinRows(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT a.id, a.registration_id, a.event_id, a.checked_in_at, a.checked_in_method,
                sr.first_name, sr.surname, sr.email,
                e.name AS event_name, e.event_date
         FROM attendance a
         INNER JOIN staff_registrations sr ON sr.id = a.registration_id
         INNER JOIN events e ON e.id = a.event_id
         WHERE DATE(a.checked_in_at) = CURDATE()
         ORDER BY a.checked_in_at DESC"
    );

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Remove a check-in so staff can sign in again. Deletes the attendance row.
 */
function resetCheckinForRegistration(PDO $pdo, int $registrationId): bool
{
    if ($registrationId < 1) {
        return false;
    }

    $stmt = $pdo->prepare('DELETE FROM attendance WHERE registration_id = :id');

    return $stmt->execute(['id' => $registrationId]) && $stmt->rowCount() > 0;
}

/**
 * Clear all check-ins recorded today (dashboard “Today's check-ins” count).
 *
 * @return array{deleted: int, rows: list<array<string, mixed>>}
 */
function resetAllTodayCheckins(PDO $pdo): array
{
    $rows = listTodayCheckinRows($pdo);
    if ($rows === []) {
        return ['deleted' => 0, 'rows' => []];
    }

    $stmt = $pdo->prepare('DELETE FROM attendance WHERE DATE(checked_in_at) = CURDATE()');
    $stmt->execute();

    return ['deleted' => $stmt->rowCount(), 'rows' => $rows];
}

/**
 * True when staff completed a venue sign-in (not pre-check only or no-show).
 *
 * @param array<string, mixed> $row
 */
function registrationHadVenueCheckin(array $row): bool
{
    if (empty($row['attendance_id']) && empty($row['id'])) {
        return false;
    }

    $activated = trim((string) ($row['activated_at'] ?? ''));
    if ($activated !== '' && !isEmptyDbDate($activated)) {
        return true;
    }

    $gpsAt = trim((string) ($row['check_in_gps_at'] ?? ''));
    if ($gpsAt !== '' && !isEmptyDbDate($gpsAt)) {
        return true;
    }

    $checkedIn = trim((string) ($row['checked_in_at'] ?? ''));
    if ($checkedIn !== '' && !isEmptyDbDate($checkedIn)) {
        $method = strtolower(trim((string) ($row['checked_in_method'] ?? '')));
        if ($method === '' || in_array($method, ['self', 'scan', 'qr'], true)) {
            return true;
        }
    }

    if ((float) ($row['hours_worked'] ?? 0) > 0) {
        return true;
    }

    $status = strtolower(trim((string) ($row['attendance_status'] ?? '')));
    if ($status === 'no_show') {
        return false;
    }

    if ($status === 'pre_checked_in') {
        return !empty($row['activated_at']);
    }

    return true;
}

/**
 * @return 'checked_in'|'awaiting'|'no_show'
 */
function resolveAttendanceBoardBucket(array $row): string
{
    $status = strtolower(trim((string) ($row['attendance_status'] ?? '')));

    if ($status === 'no_show' && registrationHadVenueCheckin($row)) {
        return 'checked_in';
    }

    if ($status === 'no_show') {
        return 'no_show';
    }

    if ((int) ($row['is_checked_in'] ?? 0) === 1) {
        return 'checked_in';
    }

    $window = getEventCheckinWindow($row);
    if (($window['status'] ?? '') === 'after') {
        return 'no_show';
    }

    return 'awaiting';
}

/**
 * @param array<int, array<string, mixed>> $list
 * @return array{checked_in: array<int, array<string, mixed>>, awaiting: array<int, array<string, mixed>>, no_show: array<int, array<string, mixed>>}
 */
function groupAttendanceBoardRows(array $list): array
{
    $groups = [
        'checked_in' => [],
        'awaiting'   => [],
        'no_show'    => [],
    ];

    foreach ($list as $row) {
        $groups[resolveAttendanceBoardBucket($row)][] = $row;
    }

    return $groups;
}

/**
 * Human-readable check-in board status for admin roster/export.
 *
 * @param array<string, mixed> $row
 */
function formatAttendanceBoardStatusLabel(array $row): string
{
    return match (resolveAttendanceBoardBucket($row)) {
        'checked_in' => 'Signed in',
        'awaiting'   => 'Awaiting sign-in',
        'no_show'    => 'No-show',
    };
}

/**
 * After the check-in window closes, mark approved staff who never signed in as no-show.
 *
 * @return array{marked: int, skipped: int, blacklisted: int}
 */
function closeAwaitingSigninsAsNoShows(PDO $pdo): array
{
    require_once __DIR__ . '/attendance-gps-phase1-schema.php';
    require_once __DIR__ . '/work-hours-schema.php';
    require_once __DIR__ . '/staff-blacklist.php';

    ensureAttendanceGpsPhase1Schema($pdo);
    ensureWorkHoursSchema($pdo);

    $stmt = $pdo->query(
        "SELECT sr.id AS registration_id, sr.email, sr.event_id,
                e.event_date, e.start_time, e.end_time, e.checkin_open_time, e.checkin_close_time,
                e.name AS event_name,
                a.id AS attendance_id, a.attendance_status, a.activated_at, a.checked_in_at
         FROM staff_registrations sr
         INNER JOIN events e ON e.id = sr.event_id
         LEFT JOIN attendance a ON a.registration_id = sr.id
         WHERE sr.status = 'approved'
           AND e.event_date <= CURDATE()"
    );

    $marked      = 0;
    $skipped     = 0;
    $blacklisted = 0;
    $emails      = [];

    $note = 'No-show — check-in window closed without venue sign-in.';

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $window = getEventCheckinWindow($row);
        if (($window['status'] ?? '') !== 'after') {
            continue;
        }

        if ($registrationHadVenueCheckin($row)) {
            $skipped++;
            continue;
        }

        $activated = trim((string) ($row['activated_at'] ?? ''));
        $checkedIn = trim((string) ($row['checked_in_at'] ?? ''));
        if (($activated !== '' && !isEmptyDbDate($activated)) || ($checkedIn !== '' && !isEmptyDbDate($checkedIn))) {
            $skipped++;
            continue;
        }

        $status = strtolower(trim((string) ($row['attendance_status'] ?? '')));
        if ($status === 'no_show') {
            $skipped++;
            continue;
        }

        $registrationId = (int) ($row['registration_id'] ?? 0);
        $eventId          = (int) ($row['event_id'] ?? 0);
        $attendanceId     = (int) ($row['attendance_id'] ?? 0);

        if ($attendanceId > 0) {
            $update = $pdo->prepare(
                "UPDATE attendance SET
                    attendance_status = 'no_show',
                    checked_out_at = COALESCE(checked_out_at, NOW()),
                    work_end_at = COALESCE(work_end_at, activated_at, checked_in_at, NOW()),
                    scheduled_hours = 0,
                    hours_worked = 0,
                    hours_paid = 0,
                    hours_note = :note,
                    signout_reason = NULL,
                    gps_outside_strikes = 0
                 WHERE id = :id"
            );
            $update->execute([
                'note' => $note,
                'id'   => $attendanceId,
            ]);
        } else {
            $insert = $pdo->prepare(
                "INSERT INTO attendance (
                    registration_id, event_id, checked_in_method, attendance_status,
                    scheduled_hours, hours_worked, hours_paid, hours_note
                 ) VALUES (
                    :registration_id, :event_id, 'system', 'no_show',
                    0, 0, 0, :note
                 )"
            );
            $insert->execute([
                'registration_id' => $registrationId,
                'event_id'        => $eventId,
                'note'            => $note,
            ]);
        }

        $marked++;
        $email = trim((string) ($row['email'] ?? ''));
        if ($email !== '') {
            $emails[$email] = true;
        }
    }

    foreach (array_keys($emails) as $email) {
        if (evaluateStaffBlacklist($pdo, $email) !== null) {
            $blacklisted++;
        }
    }

    return [
        'marked'      => $marked,
        'skipped'     => $skipped,
        'blacklisted' => $blacklisted,
    ];
}

function getAttendanceStats(PDO $pdo, int $eventId = 0): array
{
    $list  = getAttendanceList($pdo, $eventId);
    $board = groupAttendanceBoardRows($list);

    $approved        = count($list);
    $checkedIn       = count($board['checked_in']);
    $awaiting        = count($board['awaiting']);
    $noShow          = count($board['no_show']);
    $staffNeeded     = null;
    $spacesRemaining = null;

    if ($eventId > 0) {
        $event = getEventById($pdo, $eventId);
        if ($event && isset($event['staff_needed']) && $event['staff_needed'] !== null && $event['staff_needed'] !== '') {
            $staffNeeded       = max(0, (int) $event['staff_needed']);
            $spacesRemaining   = max(0, $staffNeeded - $approved);
        }
    }

    return [
        'approved'          => $approved,
        'checked_in'        => $checkedIn,
        'awaiting'          => $awaiting,
        'no_show'           => $noShow,
        'missing'           => $awaiting,
        'today'             => getTodayCheckinCount($pdo),
        'staff_needed'      => $staffNeeded,
        'spaces_remaining'  => $spacesRemaining,
    ];
}

/**
 * @return array<string, mixed>
 */
function getLiveAttendancePayload(PDO $pdo, int $eventId = 0): array
{
    $stats = getAttendanceStats($pdo, $eventId);
    $recent = [];

    $where  = "sr.status = 'approved' AND a.id IS NOT NULL
               AND LOWER(COALESCE(a.attendance_status, '')) <> 'no_show'";
    $params = [];

    if ($eventId > 0) {
        $where .= ' AND sr.event_id = :event_id';
        $params['event_id'] = $eventId;
    }

    $sql = "SELECT sr.first_name, sr.surname, e.name AS event_name,
                   a.checked_in_at, a.activated_at, a.check_in_gps_at, a.checked_in_method
            FROM staff_registrations sr
            INNER JOIN events e ON e.id = sr.event_id
            INNER JOIN attendance a ON a.registration_id = sr.id
            WHERE {$where}
            ORDER BY a.checked_in_at DESC
            LIMIT 8";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        $recent[] = [
            'name'       => trim($row['first_name'] . ' ' . $row['surname']),
            'event'      => (string) $row['event_name'],
            'checked_in' => formatAttendanceCheckinTime($row, $pdo),
            'method'     => (string) ($row['checked_in_method'] ?? ''),
        ];
    }

    return [
        'stats'      => $stats,
        'recent'     => $recent,
        'updated_at' => date('c'),
        'event_id'   => $eventId,
    ];
}

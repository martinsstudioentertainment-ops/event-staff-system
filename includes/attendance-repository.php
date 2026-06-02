<?php
/**
 * Event Staff System — Attendance & QR check-in
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/maps.php';
require_once __DIR__ . '/events-repository.php';

const CHECKIN_WINDOW_HOURS = 1;

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
 * Check-in opens 1 hour before event start and closes 1 hour after event end.
 *
 * @return array{
 *     opens_at: DateTime,
 *     closes_at: DateTime,
 *     event_start: DateTime,
 *     event_end: DateTime,
 *     status: 'before'|'open'|'after',
 *     is_open: bool
 * }
 */
function getEventCheckinWindow(array $event): array
{
    $date      = (string) ($event['event_date'] ?? '');
    $startTime = (string) ($event['event_start_time'] ?? $event['start_time'] ?? '09:00:00');
    $endTime   = (string) ($event['event_end_time'] ?? $event['end_time'] ?? '23:00:00');

    $eventStart = parseEventDateTime($date, $startTime) ?? new DateTime($date . ' 09:00:00');
    $eventEnd   = parseEventDateTime($date, $endTime) ?? new DateTime($date . ' 23:00:00');
    $tz         = $eventStart->getTimezone();

    $opensAt = (clone $eventStart)->modify('-' . CHECKIN_WINDOW_HOURS . ' hour');
    $closesAt = (clone $eventEnd)->modify('+' . CHECKIN_WINDOW_HOURS . ' hour');
    $now     = new DateTime('now', $tz);

    if ($now < $opensAt) {
        $status = 'before';
    } elseif ($now > $closesAt) {
        $status = 'after';
    } else {
        $status = 'open';
    }

    return [
        'opens_at'    => $opensAt,
        'closes_at'   => $closesAt,
        'event_start' => $eventStart,
        'event_end'   => $eventEnd,
        'status'      => $status,
        'is_open'     => $status === 'open',
    ];
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
 * @return array{lat: float, lng: float}|null
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

    return ['lat' => $lat, 'lng' => $lng];
}

function isWithinEventVenue(array $event, float $lat, float $lng): bool
{
    $venue = getEventVenueCoordinates($event);

    if ($venue === null) {
        return false;
    }

    $distance = haversineDistanceMeters($venue['lat'], $venue['lng'], $lat, $lng);

    return $distance <= (float) getEventSigninRadiusMeters($event);
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
function getEventSigninEligibility(array $event, ?float $userLat, ?float $userLng, bool $requireVenue = true): array
{
    $window          = getEventCheckinWindow($event);
    $venueConfigured = eventVenueIsConfigured($event);
    $timeOpen        = $window['is_open'];
    $atVenue         = false;

    if ($userLat !== null && $userLng !== null && $venueConfigured) {
        $atVenue = isWithinEventVenue($event, $userLat, $userLng);
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
        return [
            'allowed'          => false,
            'time_open'        => true,
            'at_venue'         => false,
            'venue_configured' => true,
            'window'           => $window,
            'message'          => 'Allow location access on your phone to sign in at the venue.',
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
    $sql = 'SELECT sr.*, e.name AS event_name, e.main_security_company, e.event_date, e.location AS event_location,
                   e.reporting_point, e.venue_eircode,
                   e.start_time AS event_start_time, e.end_time AS event_end_time,
                   e.venue_lat, e.venue_lng, e.signin_radius_m
            FROM staff_registrations sr
            INNER JOIN events e ON e.id = sr.event_id
            WHERE LOWER(sr.email) = LOWER(:email) AND sr.event_id = :event_id AND sr.status = :status
            LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'email'    => trim($email),
        'event_id' => $eventId,
        'status'   => 'approved',
    ]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function generateCheckinToken(): string
{
    return bin2hex(random_bytes(32));
}

function ensureCheckinToken(PDO $pdo, int $registrationId): ?string
{
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

    try {
        $event = getEventById($pdo, (int) ($row['event_id'] ?? 0));
        if ($event !== null) {
            foreach (['main_security_company', 'reporting_point', 'venue_eircode', 'start_time', 'end_time', 'venue_lat', 'venue_lng', 'signin_radius_m', 'name'] as $key) {
                if (!array_key_exists($key, $event)) {
                    continue;
                }
                $alias = match ($key) {
                    'start_time' => 'event_start_time',
                    'end_time'   => 'event_end_time',
                    'name'       => 'event_name',
                    default      => $key,
                };
                $row[$alias] = $event[$key];
            }
            if (!isset($row['location']) && isset($event['location'])) {
                $row['event_location'] = $event['location'];
            }
        }
    } catch (Throwable $e) {
        error_log('[EventStaff] getRegistrationByToken event merge: ' . $e->getMessage());
    }

    return $row;
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
        $stmt = $pdo->prepare('SELECT id FROM attendance WHERE registration_id = :id LIMIT 1');
        $stmt->execute(['id' => $registrationId]);

        return (bool) $stmt->fetchColumn();
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
 * @return true|string true on success, error message on failure
 */
function recordCheckin(PDO $pdo, int $registrationId, string $method = 'self'): bool|string
{
    $stmt = $pdo->prepare('SELECT id, status, event_id FROM staff_registrations WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $registrationId]);
    $reg = $stmt->fetch();

    if (!$reg) {
        return 'Registration not found.';
    }

    if ($reg['status'] !== 'approved') {
        return 'Only approved staff can check in.';
    }

    if (hasCheckedIn($pdo, $registrationId)) {
        return 'Already checked in.';
    }

    $insert = $pdo->prepare(
        'INSERT INTO attendance (registration_id, event_id, checked_in_method) VALUES (:registration_id, :event_id, :method)'
    );
    $insert->execute([
        'registration_id' => $registrationId,
        'event_id'        => (int) $reg['event_id'],
        'method'          => $method,
    ]);

    require_once __DIR__ . '/notifications.php';
    notifyStaffCheckin($pdo, $registrationId, $method);

    require_once __DIR__ . '/work-hours-repository.php';
    initializeWorkHoursForRegistration($pdo, $registrationId);

    return true;
}

/**
 * @return array<int, array<string, mixed>>
 */
function getAttendanceList(PDO $pdo, int $eventId = 0): array
{
    $where  = "sr.status = 'approved'";
    $params = [];

    if ($eventId > 0) {
        $where .= ' AND sr.event_id = :event_id';
        $params['event_id'] = $eventId;
    }

    $sql = "SELECT sr.*, e.name AS event_name, e.event_date,
                   a.checked_in_at, a.checked_in_method,
                   CASE WHEN a.id IS NULL THEN 0 ELSE 1 END AS is_checked_in
            FROM staff_registrations sr
            INNER JOIN events e ON e.id = sr.event_id
            LEFT JOIN attendance a ON a.registration_id = sr.id
            WHERE {$where}
            ORDER BY e.event_date ASC, sr.surname ASC, sr.first_name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function getTodayCheckinCount(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM attendance WHERE DATE(checked_in_at) = CURDATE()")->fetchColumn();
}

function getAttendanceStats(PDO $pdo, int $eventId = 0): array
{
    $list = getAttendanceList($pdo, $eventId);
    $checkedIn = 0;

    foreach ($list as $row) {
        if ((int) $row['is_checked_in'] === 1) {
            $checkedIn++;
        }
    }

    $approved   = count($list);
    $staffNeeded = null;
    $spacesRemaining = null;

    if ($eventId > 0) {
        $event = getEventById($pdo, $eventId);
        if ($event && isset($event['staff_needed']) && $event['staff_needed'] !== null && $event['staff_needed'] !== '') {
            $staffNeeded = max(0, (int) $event['staff_needed']);
            $spacesRemaining = max(0, $staffNeeded - $approved);
        }
    }

    return [
        'approved'          => $approved,
        'checked_in'        => $checkedIn,
        'missing'           => $approved - $checkedIn,
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

    $where  = "sr.status = 'approved' AND a.id IS NOT NULL";
    $params = [];

    if ($eventId > 0) {
        $where .= ' AND sr.event_id = :event_id';
        $params['event_id'] = $eventId;
    }

    $sql = "SELECT sr.first_name, sr.surname, e.name AS event_name, a.checked_in_at, a.checked_in_method
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
            'checked_in' => date('H:i', strtotime((string) $row['checked_in_at'])),
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

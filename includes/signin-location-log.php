<?php

declare(strict_types=1);

require_once __DIR__ . '/maps.php';
require_once __DIR__ . '/attendance-repository.php';
require_once __DIR__ . '/events-repository.php';
require_once __DIR__ . '/validation.php';

function ensureSigninLocationLogSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS signin_location_verifications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_id INT UNSIGNED NOT NULL,
            signin_token VARCHAR(128) NOT NULL,
            visitor_key CHAR(40) NOT NULL,
            verified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(500) NULL,
            lat DECIMAL(10,7) NOT NULL,
            lng DECIMAL(10,7) NOT NULL,
            accuracy_m SMALLINT UNSIGNED NULL,
            distance_m INT UNSIGNED NULL,
            in_zone TINYINT(1) NOT NULL DEFAULT 1,
            registration_id INT UNSIGNED NULL,
            staff_email VARCHAR(255) NULL,
            linked_at DATETIME NULL,
            INDEX idx_event_verified (event_id, verified_at DESC),
            INDEX idx_visitor_day (event_id, visitor_key, verified_at DESC),
            INDEX idx_registration (registration_id),
            INDEX idx_email (staff_email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ready = true;
}

function signinLocationVisitorKey(): string
{
    require_once __DIR__ . '/website-visitor-tracking.php';

    $ip = getClientIpAddress();
    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $day = date('Y-m-d');

    return $ip !== ''
        ? hash('sha256', strtolower($ip) . '|' . $ua . '|' . $day)
        : hash('sha256', $ua . '|' . $day . '|' . bin2hex(random_bytes(8)));
}

/**
 * @return array{ok: bool, message: string, verification_id: int, in_zone: bool}
 */
function recordSigninLocationVerification(
    PDO $pdo,
    string $signinToken,
    float $lat,
    float $lng,
    ?int $accuracyM = null
): array {
    ensureSigninLocationLogSchema($pdo);

    $signinToken = trim($signinToken);
    if ($signinToken === '') {
        return ['ok' => false, 'message' => 'Invalid sign-in link.', 'verification_id' => 0, 'in_zone' => false];
    }

    $event = getEventBySigninToken($pdo, $signinToken);
    if ($event === null) {
        return ['ok' => false, 'message' => 'Event not found.', 'verification_id' => 0, 'in_zone' => false];
    }

    $window = getEventCheckinWindow($event);
    if (!$window['is_open']) {
        return ['ok' => false, 'message' => formatCheckinWindowMessage($window), 'verification_id' => 0, 'in_zone' => false];
    }

    if (!eventVenueIsConfigured($event)) {
        return ['ok' => false, 'message' => 'Venue GPS is not configured for this event.', 'verification_id' => 0, 'in_zone' => false];
    }

    require_once __DIR__ . '/attendance-gps-phase1.php';
    require_once __DIR__ . '/attendance-gps-phase15.php';

    $gps = ['lat' => $lat, 'lng' => $lng, 'accuracy_m' => $accuracyM];
    if (isGpsAttendanceV2Enabled($pdo)) {
        $check = validateGpsForCheckin($pdo, $event, $gps);
        if (!$check['ok']) {
            return ['ok' => false, 'message' => (string) $check['message'], 'verification_id' => 0, 'in_zone' => false];
        }
    } elseif (!isWithinEventVenue($event, $lat, $lng, $pdo)) {
        $venue = getEventVenueCoordinates($event);
        $distance = $venue
            ? (int) round(haversineDistanceMeters($venue['lat'], $venue['lng'], $lat, $lng))
            : 0;

        return [
            'ok'              => false,
            'message'         => 'You must be at ' . formatEventLocationLabel($event) . ' to sign in.',
            'verification_id' => 0,
            'in_zone'         => false,
            'distance_m'      => $distance,
        ];
    }

    $venue   = getEventVenueCoordinates($event);
    $distanceM = $venue
        ? (int) round(haversineDistanceMeters($venue['lat'], $venue['lng'], $lat, $lng))
        : null;

    $visitorKey = signinLocationVisitorKey();
    $ip         = getClientIpAddress();
    $ua         = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $eventId    = (int) $event['id'];

    $recent = $pdo->prepare(
        'SELECT id FROM signin_location_verifications
         WHERE event_id = :event_id AND visitor_key = :visitor_key
           AND verified_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
         ORDER BY verified_at DESC LIMIT 1'
    );
    $recent->execute(['event_id' => $eventId, 'visitor_key' => $visitorKey]);
    $existingId = (int) $recent->fetchColumn();

    if ($existingId > 0) {
        $upd = $pdo->prepare(
            'UPDATE signin_location_verifications
             SET verified_at = NOW(), lat = :lat, lng = :lng, accuracy_m = :accuracy_m,
                 distance_m = :distance_m, in_zone = 1, ip_address = :ip, user_agent = :ua
             WHERE id = :id'
        );
        $upd->execute([
            'lat'         => $lat,
            'lng'         => $lng,
            'accuracy_m'  => $accuracyM,
            'distance_m'  => $distanceM,
            'ip'          => $ip !== '' ? $ip : null,
            'ua'          => $ua !== '' ? $ua : null,
            'id'          => $existingId,
        ]);

        return ['ok' => true, 'message' => 'Location verified.', 'verification_id' => $existingId, 'in_zone' => true];
    }

    $ins = $pdo->prepare(
        'INSERT INTO signin_location_verifications (
            event_id, signin_token, visitor_key, ip_address, user_agent,
            lat, lng, accuracy_m, distance_m, in_zone
        ) VALUES (
            :event_id, :signin_token, :visitor_key, :ip_address, :user_agent,
            :lat, :lng, :accuracy_m, :distance_m, 1
        )'
    );
    $ins->execute([
        'event_id'     => $eventId,
        'signin_token' => $signinToken,
        'visitor_key'  => $visitorKey,
        'ip_address'   => $ip !== '' ? $ip : null,
        'user_agent'   => $ua !== '' ? $ua : null,
        'lat'          => $lat,
        'lng'          => $lng,
        'accuracy_m'   => $accuracyM,
        'distance_m'   => $distanceM,
    ]);

    return ['ok' => true, 'message' => 'Location verified.', 'verification_id' => (int) $pdo->lastInsertId(), 'in_zone' => true];
}

function linkSigninLocationVerification(PDO $pdo, int $verificationId, int $registrationId, string $email): void
{
    if ($verificationId < 1 || $registrationId < 1) {
        return;
    }

    ensureSigninLocationLogSchema($pdo);

    $email = normalizeRegistrationEmail($email);
    $stmt  = $pdo->prepare(
        'UPDATE signin_location_verifications
         SET registration_id = :registration_id,
             staff_email = :email,
             linked_at = NOW()
         WHERE id = :id'
    );
    $stmt->execute([
        'registration_id' => $registrationId,
        'email'           => $email !== '' ? $email : null,
        'id'              => $verificationId,
    ]);
}

/**
 * @return array{total: int, unique_visitors: int, linked: int, unlinked: int}
 */
function countSigninLocationVerifications(PDO $pdo, int $eventId = 0, ?string $date = null): array
{
    ensureSigninLocationLogSchema($pdo);

    $where  = ['in_zone = 1'];
    $params = [];

    if ($eventId > 0) {
        $where[]           = 'event_id = :event_id';
        $params['event_id'] = $eventId;
    }

    if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
        $where[]         = 'DATE(verified_at) = :vdate';
        $params['vdate'] = $date;
    }

    $sql = 'SELECT COUNT(*) AS total,
                   COUNT(DISTINCT visitor_key) AS unique_visitors,
                   SUM(registration_id IS NOT NULL) AS linked
            FROM signin_location_verifications
            WHERE ' . implode(' AND ', $where);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $total   = (int) ($row['total'] ?? 0);
    $unique  = (int) ($row['unique_visitors'] ?? 0);
    $linked  = (int) ($row['linked'] ?? 0);

    return [
        'total'           => $total,
        'unique_visitors' => $unique,
        'linked'          => $linked,
        'unlinked'        => max(0, $unique - $linked),
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function listSigninLocationVerifications(PDO $pdo, int $limit = 100, int $eventId = 0, ?string $date = null): array
{
    ensureSigninLocationLogSchema($pdo);

    $where  = ['v.in_zone = 1'];
    $params = [];

    if ($eventId > 0) {
        $where[]           = 'v.event_id = :event_id';
        $params['event_id'] = $eventId;
    }

    if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
        $where[]         = 'DATE(v.verified_at) = :vdate';
        $params['vdate'] = $date;
    }

    $sql = 'SELECT v.*, e.name AS event_name, e.event_date,
                   sr.first_name, sr.surname, sr.id AS reg_id
            FROM signin_location_verifications v
            INNER JOIN events e ON e.id = v.event_id
            LEFT JOIN staff_registrations sr ON sr.id = v.registration_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY v.verified_at DESC
            LIMIT ' . max(1, min(500, $limit));

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

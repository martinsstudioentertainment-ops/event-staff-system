<?php

declare(strict_types=1);

require_once __DIR__ . '/attendance-gps-phase1.php';
require_once __DIR__ . '/attendance-gps-phase15-schema.php';
require_once __DIR__ . '/attendance-repository.php';
require_once __DIR__ . '/maps.php';
require_once __DIR__ . '/settings-repository.php';

const GPS_ACTIVATION_PROOF_MAX_AGE_SEC = 900;

function getGpsRequiredMessage(): string
{
    return 'Location access is required for attendance. Please enable GPS and allow location permission to continue.';
}

/**
 * Admin roster, QR scan, and manual sign-in bypass GPS/window rules for staff.
 */
function checkinMethodBypassesGpsRules(string $method): bool
{
    return in_array($method, ['admin', 'scan', 'admin_manual'], true);
}

function getGpsAdminBypassMessage(): string
{
    return 'GPS attendance applies to staff self check-in. Use Attendance → Check In, Scan QR, or Manual sign-in to override.';
}

function getGpsTokenCheckinBlockedMessage(?string $venueSigninUrl = null): string
{
    $msg = 'GPS attendance requires venue sign-in with location enabled.';
    if ($venueSigninUrl !== null && $venueSigninUrl !== '') {
        return $msg . ' Use the venue sign-in link: ' . $venueSigninUrl;
    }

    return $msg . ' Please scan the venue QR code at the event entrance.';
}

function getGpsMaxAccuracyMeters(?PDO $pdo): int
{
    if ($pdo === null) {
        return 100;
    }

    $raw = trim(getSetting($pdo, 'gps_max_accuracy_m', '100'));
    if ($raw === '' || !ctype_digit($raw)) {
        return 100;
    }

    $value = (int) $raw;

    return $value > 0 ? min(65535, $value) : 0;
}

/**
 * @param array{lat: float, lng: float, accuracy_m: ?int}|null $gps
 * @return array{ok: bool, message: string}
 */
function validateGpsForCheckin(PDO $pdo, array $event, ?array $gps): array
{
    if ($gps === null || !isset($gps['lat'], $gps['lng'])) {
        return ['ok' => false, 'message' => getGpsRequiredMessage()];
    }

    if (!isWithinEventVenue($event, (float) $gps['lat'], (float) $gps['lng'], $pdo)) {
        $venue = getEventVenueCoordinates($event);
        $distance = $venue !== null
            ? (int) round(haversineDistanceMeters($venue['lat'], $venue['lng'], (float) $gps['lat'], (float) $gps['lng']))
            : null;
        $radius = getEventSigninRadiusMeters($event, $pdo);
        $away   = $distance !== null ? " ({$distance}m away, limit {$radius}m)" : '';

        return [
            'ok'      => false,
            'message' => 'You must be inside the event attendance zone to check in' . $away . '.',
        ];
    }

    $maxAccuracy = getGpsMaxAccuracyMeters($pdo);
    if ($maxAccuracy > 0) {
        $accuracy = $gps['accuracy_m'] ?? null;
        if ($accuracy === null) {
            return [
                'ok'      => false,
                'message' => 'Waiting for a GPS accuracy reading. Please allow high-accuracy location and try again.',
            ];
        }
        if ($accuracy > $maxAccuracy) {
            return [
                'ok'      => false,
                'message' => "GPS accuracy is too low ({$accuracy}m). Open area or stronger signal needed (≤{$maxAccuracy}m).",
            ];
        }
    }

    return ['ok' => true, 'message' => ''];
}

/**
 * Self check-in at a geofenced event must include valid in-zone GPS (even when GPS v2 flag is off).
 *
 * @param array{lat: float, lng: float, accuracy_m: ?int}|null $gps
 */
function assertSelfCheckinVenueGps(PDO $pdo, array $event, ?array $gps, string $method): ?string
{
    if (checkinMethodBypassesGpsRules($method)) {
        return null;
    }

    if (!eventVenueIsConfigured($event)) {
        return null;
    }

    if ($gps === null || !isset($gps['lat'], $gps['lng'])) {
        return getGpsRequiredMessage();
    }

    if (isGpsAttendanceV2Enabled($pdo)) {
        $check = validateGpsForCheckin($pdo, $event, $gps);

        return $check['ok'] ? null : (string) $check['message'];
    }

    if (!isWithinEventVenue($event, (float) $gps['lat'], (float) $gps['lng'], $pdo)) {
        $venue = getEventVenueCoordinates($event);
        $distance = $venue !== null
            ? (int) round(haversineDistanceMeters($venue['lat'], $venue['lng'], (float) $gps['lat'], (float) $gps['lng']))
            : null;
        $radius = getEventSigninRadiusMeters($event, $pdo);
        $away   = $distance !== null ? " ({$distance}m away, limit {$radius}m)" : '';

        return 'You must be inside the event attendance zone to check in' . $away . '.';
    }

    return null;
}

/**
 * @param array{lat: float, lng: float, accuracy_m: ?int} $gps
 */
function updateAttendanceLastGps(PDO $pdo, int $attendanceId, array $gps): bool
{
    try {
        ensureAttendanceGpsPhase15Schema($pdo);

        $now = (new DateTime('now'))->format('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'UPDATE attendance SET
                last_gps_lat = :lat,
                last_gps_lng = :lng,
                last_gps_accuracy_m = :accuracy_m,
                last_gps_at = :gps_at
             WHERE id = :id'
        );

        return $stmt->execute([
            'lat'        => $gps['lat'],
            'lng'        => $gps['lng'],
            'accuracy_m' => $gps['accuracy_m'] ?? null,
            'gps_at'     => $now,
            'id'         => $attendanceId,
        ]);
    } catch (Throwable $e) {
        error_log('[EventStaff] updateAttendanceLastGps: ' . $e->getMessage());

        return false;
    }
}

/**
 * @param array<string, mixed> $attendance
 * @return array{lat: float, lng: float, accuracy_m: ?int, gps_at: string}|null
 */
function getActivationGpsProof(array $attendance): ?array
{
    $lat = null;
    $lng = null;
    $acc = null;
    $at  = null;

    if (!empty($attendance['last_gps_at']) && $attendance['last_gps_lat'] !== null && $attendance['last_gps_lng'] !== null) {
        $lat = (float) $attendance['last_gps_lat'];
        $lng = (float) $attendance['last_gps_lng'];
        $acc = $attendance['last_gps_accuracy_m'] !== null ? (int) $attendance['last_gps_accuracy_m'] : null;
        $at  = (string) $attendance['last_gps_at'];
    } elseif (!empty($attendance['check_in_gps_at']) && $attendance['check_in_lat'] !== null && $attendance['check_in_lng'] !== null) {
        $lat = (float) $attendance['check_in_lat'];
        $lng = (float) $attendance['check_in_lng'];
        $acc = $attendance['check_in_accuracy_m'] !== null ? (int) $attendance['check_in_accuracy_m'] : null;
        $at  = (string) $attendance['check_in_gps_at'];
    }

    if ($lat === null || $lng === null || $at === null || $at === '') {
        return null;
    }

    return ['lat' => $lat, 'lng' => $lng, 'accuracy_m' => $acc, 'gps_at' => $at];
}

/**
 * @param array<string, mixed> $attendance
 */
function canActivateWithGpsProof(PDO $pdo, array $event, array $attendance): bool
{
    $proof = getActivationGpsProof($attendance);
    if ($proof === null) {
        return false;
    }

    try {
        $gpsTime = new DateTime($proof['gps_at']);
        $now     = new DateTime('now', $gpsTime->getTimezone());
        if ($now->getTimestamp() - $gpsTime->getTimestamp() > GPS_ACTIVATION_PROOF_MAX_AGE_SEC) {
            return false;
        }
    } catch (Throwable $e) {
        return false;
    }

    $gps = [
        'lat'        => $proof['lat'],
        'lng'        => $proof['lng'],
        'accuracy_m' => $proof['accuracy_m'],
    ];

    $check = validateGpsForCheckin($pdo, $event, $gps);

    return $check['ok'];
}

/**
 * Update BR-aligned hibernation copy (Phase 1.5).
 */
function getHibernationCheckinMessagePhase15(): string
{
    return 'You are successfully checked in. Attendance will activate automatically when the event starts.';
}

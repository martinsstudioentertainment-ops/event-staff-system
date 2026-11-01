<?php

declare(strict_types=1);

/**
 * Admin Staff Sign Hub — snapshot helpers (additive; does not alter Manual sign-in / Attendant).
 */

require_once __DIR__ . '/../events-repository.php';
require_once __DIR__ . '/../attendance-roster-helpers.php';
require_once __DIR__ . '/../attendance-repository.php';
require_once __DIR__ . '/../maps-core.php';
require_once __DIR__ . '/../attendance-gps-phase1.php';
require_once __DIR__ . '/../attendant-actions.php';

/**
 * @return list<array<string, mixed>>
 */
function listEventsForStaffSignHub(PDO $pdo, int $limit = 60): array
{
    $events = getEventsForAttendanceFilter($pdo);
    if ($limit > 0 && count($events) > $limit) {
        return array_slice($events, 0, $limit);
    }

    return $events;
}

/**
 * @return array<string, mixed>|null
 */
function getStaffSignHubSnapshot(PDO $pdo, int $eventId): ?array
{
    if ($eventId < 1) {
        return null;
    }

    $event = getEventById($pdo, $eventId);
    if ($event === null) {
        return null;
    }

    ensureAttendanceGateStartTime($pdo);

    $venue = getEventVenueCoordinates($event);
    $radius = getEventSigninRadiusMeters($event, $pdo);
    $gpsOn  = isGpsAttendanceV2Enabled($pdo);

    $hubRows = [];
    $signedIn = 0;
    $signedOut = 0;
    $stillIn = 0;

    try {
        $stmt = $pdo->prepare(
            "SELECT a.id AS attendance_id, a.registration_id, a.checked_in_at, a.checked_out_at,
                    a.checked_in_method, a.gate_start_time, a.signout_reason, a.attendance_status,
                    a.check_in_lat, a.check_in_lng,
                    sr.first_name, sr.surname, sr.email, sr.mobile, sr.staff_role,
                    sr.psa_licence, sr.psa_expiry_date, sr.assigned_bib_number
             FROM attendance a
             INNER JOIN staff_registrations sr ON sr.id = a.registration_id
             WHERE sr.event_id = :eid
               AND LOWER(COALESCE(a.checked_in_method, '')) = 'staff_hub'
             ORDER BY a.checked_in_at DESC, sr.surname ASC, sr.first_name ASC"
        );
        $stmt->execute(['eid' => $eventId]);
        $hubRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        // gate_start_time / signout columns may be absent on very old DBs — retry without extras
        try {
            $stmt = $pdo->prepare(
                "SELECT a.id AS attendance_id, a.registration_id, a.checked_in_at, a.checked_out_at,
                        a.checked_in_method, a.attendance_status,
                        sr.first_name, sr.surname, sr.email, sr.mobile, sr.staff_role,
                        sr.psa_licence, sr.psa_expiry_date, sr.assigned_bib_number
                 FROM attendance a
                 INNER JOIN staff_registrations sr ON sr.id = a.registration_id
                 WHERE sr.event_id = :eid
                   AND LOWER(COALESCE(a.checked_in_method, '')) = 'staff_hub'
                 ORDER BY a.checked_in_at DESC"
            );
            $stmt->execute(['eid' => $eventId]);
            $hubRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e2) {
            error_log('[EventStaff] staff-sign-hub snapshot: ' . $e2->getMessage());
            $hubRows = [];
        }
    }

    foreach ($hubRows as $row) {
        $signedIn++;
        if (!empty($row['checked_out_at'])) {
            $signedOut++;
        } else {
            $stillIn++;
        }
    }

    $endTime = trim((string) ($event['end_time'] ?? ''));
    $eventDate = substr((string) ($event['event_date'] ?? ''), 0, 10);

    return [
        'event'            => $event,
        'venue_configured' => $venue !== null,
        'venue_lat'        => $venue['lat'] ?? null,
        'venue_lng'        => $venue['lng'] ?? null,
        'signin_radius_m'  => $radius,
        'gps_flag_on'      => $gpsOn,
        'end_time'         => $endTime,
        'event_date'       => $eventDate,
        'hub_signins'      => $hubRows,
        'count_signed_in'  => $signedIn,
        'count_signed_out' => $signedOut,
        'count_still_in'   => $stillIn,
        'app_url'          => staffSignHubAppPublicUrl($eventId),
    ];
}

function staffSignHubAppPublicUrl(int $eventId = 0): string
{
    $base = '';
    if (defined('REGISTRATION_SITE_URL')) {
        $base = rtrim((string) REGISTRATION_SITE_URL, '/');
    } elseif (defined('MAIN_SITE_URL')) {
        $base = rtrim((string) MAIN_SITE_URL, '/');
    }

    // Short link for iPhone / Android share (sign.php → app=1)
    $path = '/sign.php';
    $params = ['app' => '1'];
    if ($eventId > 0) {
        $params['event_id'] = $eventId;
    }
    $path .= '?' . http_build_query($params);

    return $base !== '' ? ($base . $path) : $path;
}

/** Ultra-short share URL (no query) — always opens app mode. */
function staffSignHubShortShareUrl(): string
{
    $base = '';
    if (defined('REGISTRATION_SITE_URL')) {
        $base = rtrim((string) REGISTRATION_SITE_URL, '/');
    } elseif (defined('MAIN_SITE_URL')) {
        $base = rtrim((string) MAIN_SITE_URL, '/');
    }

    $path = '/sign.php';

    return $base !== '' ? ($base . $path) : $path;
}

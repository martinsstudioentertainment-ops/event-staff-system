<?php



declare(strict_types=1);



require_once __DIR__ . '/attendance-repository.php';

require_once __DIR__ . '/date-format.php';

require_once __DIR__ . '/staff-repository.php';

require_once __DIR__ . '/attendance-gps-phase1.php';

require_once __DIR__ . '/attendance-gps-phase15.php';

require_once __DIR__ . '/events-repository.php';
require_once __DIR__ . '/checkin-bib.php';



/**

 * Today's approved registration for staff-app check-in (own phone).

 *

 * @param array<string, mixed>|null $todayShift Preloaded shift from buildStaffV3Context().

 * @return array<string, mixed>|null

 */

function getStaffTodayApprovedRegistration(PDO $pdo, string $email, ?array $portalStaff = null, ?array $todayShift = null): ?array

{

    if (is_array($todayShift) && (string) ($todayShift['status'] ?? '') === 'approved') {

        $eventDate = normalizeEventDateYmd((string) ($todayShift['event_date'] ?? ''));

        if ($eventDate === getOperationalTodayYmd($pdo) && (int) ($todayShift['id'] ?? 0) > 0) {

            return $todayShift;

        }

    }



    $email = strtolower(trim($email));

    $staffId = is_array($portalStaff) ? (int) ($portalStaff['id'] ?? 0) : 0;

    if ($email === '' && $staffId < 1) {

        return null;

    }



    $today = getOperationalTodayYmd($pdo);



    try {

        $conditions = [];

        $params     = ['today' => $today];



        if ($email !== '') {

            $conditions[] = 'LOWER(sr.email) = :email';

            $params['email'] = $email;

        }

        if ($staffId > 0) {

            $conditions[] = 'sr.staff_id = :staff_id';

            $params['staff_id'] = $staffId;

        }



        $matchSql = implode(' OR ', $conditions);

        $stmt = $pdo->prepare(

            "SELECT sr.*, e.name AS event_name, e.event_date, e.location AS event_location,
                    a.id AS attendance_id, a.checked_in_at, a.checked_out_at,
                    a.attendance_status, a.bib_number,
                    CASE WHEN a.id IS NULL THEN 0 ELSE 1 END AS is_checked_in
             FROM staff_registrations sr
             INNER JOIN events e ON e.id = sr.event_id
             LEFT JOIN attendance a ON a.registration_id = sr.id
             WHERE ({$matchSql})
               AND sr.status = 'approved'
               AND DATE(e.event_date) = :today
             ORDER BY sr.id DESC
             LIMIT 1"

        );

        $stmt->execute($params);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {

            return null;

        }



        return mergeRegistrationWithEvent($pdo, mergeRegistrationWithStaff($pdo, $row));
    } catch (Throwable $e) {
        error_log('[EventStaff] getStaffTodayApprovedRegistration: ' . $e->getMessage());

        return null;
    }
}



/**

 * Plain-language reason when today's check-in registration cannot be found.

 */

function explainStaffTodayCheckinMiss(PDO $pdo, array $portalStaff): string

{

    $email   = strtolower(trim((string) ($portalStaff['email'] ?? '')));

    $staffId = (int) ($portalStaff['id'] ?? 0);

    $today   = date('Y-m-d');



    if ($email === '') {

        return 'Sign in to the staff app first, then open Check In again.';

    }



    $signedIn = (string) ($portalStaff['email'] ?? $email);

    $base     = 'No approved shift for today (' . date('j M Y') . ') on ' . $signedIn . '.';



    try {

        $conditions = ['LOWER(sr.email) = :email'];

        $params     = ['email' => $email];

        if ($staffId > 0) {

            $conditions[] = 'sr.staff_id = :staff_id';

            $params['staff_id'] = $staffId;

        }



        $matchSql = implode(' OR ', $conditions);

        $stmt = $pdo->prepare(

            "SELECT sr.status, e.event_date, e.name AS event_name

             FROM staff_registrations sr

             INNER JOIN events e ON e.id = sr.event_id

             WHERE ({$matchSql})

             ORDER BY e.event_date DESC

             LIMIT 5"

        );

        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];



        foreach ($rows as $row) {

            $eventDate = normalizeEventDateYmd((string) ($row['event_date'] ?? ''));

            $status    = (string) ($row['status'] ?? '');

            if ($eventDate === $today && $status !== 'approved') {

                return $base . ' You have a registration for today but it is not approved yet — ask your supervisor.';

            }

        }



        foreach ($rows as $row) {

            $eventDate = normalizeEventDateYmd((string) ($row['event_date'] ?? ''));

            if ($eventDate === $today) {

                break;

            }

        }



        if ($rows === []) {

            return $base . ' Register for today\'s event with the same email address, or sign in with the Google account you used when registering.';

        }



        $nearest = $rows[0];

        $nearestDate = normalizeEventDateYmd((string) ($nearest['event_date'] ?? ''));

        $nearestName = trim((string) ($nearest['event_name'] ?? 'your next event'));

        if ($nearestDate !== '' && $nearestDate !== $today) {

            return $base . ' Your nearest booking is ' . $nearestName . ' on ' . formatEventDateLabel($nearestDate) . '.';

        }

    } catch (Throwable $e) {

        error_log('[EventStaff] explainStaffTodayCheckinMiss: ' . $e->getMessage());

    }



    return $base . ' Open Shifts to confirm today\'s event is approved on this account.';

}



/**

 * @param array<string, mixed>|null $todayShift

 * @return array{ok: bool, message: string, type: string, already?: bool}

 */

function processStaffAppVenueCheckin(PDO $pdo, array $portalStaff, array $post, ?array $todayShift = null): array

{

    $email = strtolower(trim((string) ($portalStaff['email'] ?? '')));

    if ($email === '') {

        return ['ok' => false, 'message' => 'Sign in to the staff app first.', 'type' => 'error'];

    }



    $row = getStaffTodayApprovedRegistration($pdo, $email, $portalStaff, $todayShift);

    if ($row === null) {

        return [

            'ok'      => false,

            'message' => explainStaffTodayCheckinMiss($pdo, $portalStaff),

            'type'    => 'warning',

        ];

    }



    $regId = (int) ($row['id'] ?? 0);

    if ($regId < 1) {

        return ['ok' => false, 'message' => 'Registration not found.', 'type' => 'error'];

    }



    if (hasCheckedIn($pdo, $regId)) {

        return [

            'ok'      => true,

            'message' => 'You are already checked in for ' . (string) ($row['event_name'] ?? 'today\'s event') . '.',

            'type'    => 'success',

            'already' => true,

        ];

    }



    $window = getEventCheckinWindow($row);

    if (!$window['is_open']) {

        return [

            'ok'      => false,

            'message' => formatCheckinWindowMessage($window),

            'type'    => 'warning',

        ];

    }



    $gps = parseSigninCoordinates($post);

    require_once __DIR__ . '/attendance-gps-phase15.php';

    $venueGpsError = assertSelfCheckinVenueGps($pdo, $row, $gps, 'self');

    if ($venueGpsError !== null) {

        return ['ok' => false, 'message' => $venueGpsError, 'type' => 'error'];

    }



    $bibParsed = resolveCheckinBibForRegistration($row, (string) ($post['bib_number'] ?? ''), true);
    if (!$bibParsed['ok']) {
        return ['ok' => false, 'message' => $bibParsed['error'], 'type' => 'error'];
    }

    try {
        $result = recordCheckin($pdo, $regId, 'self', $gps, $bibParsed['bib']);

    } catch (Throwable $e) {

        error_log('[EventStaff] processStaffAppVenueCheckin recordCheckin: ' . $e->getMessage());



        return [

            'ok'      => false,

            'message' => 'Check-in could not be saved. Ask your supervisor to confirm manually.',

            'type'    => 'error',

        ];

    }



    if ($result === true) {

        $name = trim((string) ($row['first_name'] ?? ''));



        return [

            'ok'      => true,

            'message' => 'Check-in successful' . ($name !== '' ? ', ' . $name : '') . '!',

            'type'    => 'success',

        ];

    }

    if ($result === 'pre_checked_in') {

        return ['ok' => true, 'message' => getHibernationCheckinMessage(), 'type' => 'success'];

    }

    if ($result === 'Already checked in.') {

        return ['ok' => true, 'message' => 'You are already checked in.', 'type' => 'success', 'already' => true];

    }



    return ['ok' => false, 'message' => (string) $result, 'type' => 'error'];

}



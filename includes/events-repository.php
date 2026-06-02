<?php
/**
 * Event Staff System — Event data (registration + admin)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/maps.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/venues-repository.php';
require_once __DIR__ . '/work-types-repository.php';
require_once __DIR__ . '/google-sheets-schema.php';
require_once __DIR__ . '/event-times-schema.php';
require_once __DIR__ . '/event-main-security-schema.php';

/**
 * Normalize event_date from DB (DATE or DATETIME) to Y-m-d.
 */
function normalizeEventDateYmd(string $eventDate): string
{
    $eventDate = trim($eventDate);
    if ($eventDate === '') {
        return '';
    }

    $dateOnly = substr($eventDate, 0, 10);
    $parsed   = DateTimeImmutable::createFromFormat('Y-m-d', $dateOnly);

    if ($parsed instanceof DateTimeImmutable) {
        return $parsed->format('Y-m-d');
    }

    $timestamp = strtotime($eventDate);

    return $timestamp !== false ? date('Y-m-d', $timestamp) : '';
}

/**
 * Whether staff can still register for this event (not past / finished).
 */
function isEventOpenForRegistration(array $event): bool
{
    if ((int) ($event['is_active'] ?? 0) !== 1) {
        return false;
    }

    $eventDate = normalizeEventDateYmd((string) ($event['event_date'] ?? ''));
    if ($eventDate === '') {
        return false;
    }

    $today = (new DateTimeImmutable('today'))->format('Y-m-d');
    if ($eventDate < $today) {
        return false;
    }

    $eventForWindow = $event;
    $eventForWindow['event_date'] = $eventDate;

    require_once __DIR__ . '/attendance-repository.php';

    return getEventCheckinWindow($eventForWindow)['status'] !== 'after';
}

/**
 * @return array<int, array<string, mixed>>
 */
function getActiveEvents(PDO $pdo): array
{
    $sql = 'SELECT id, name, event_date FROM events WHERE is_active = 1 ORDER BY event_date ASC, name ASC';
    return $pdo->query($sql)->fetchAll();
}

/**
 * Active events that still accept new registrations.
 *
 * @return array<int, array<string, mixed>>
 */
function getEventsOpenForRegistration(PDO $pdo): array
{
    $sql = 'SELECT * FROM events WHERE is_active = 1 AND event_date >= CURDATE() ORDER BY event_date ASC, name ASC';
    $rows = $pdo->query($sql)->fetchAll() ?: [];

    return array_values(array_filter($rows, static fn(array $row): bool => isEventOpenForRegistration($row)));
}

/**
 * @return array<int, array<string, mixed>>
 */
function getAllEvents(PDO $pdo): array
{
    ensureVenuesSchema($pdo);
    ensureGoogleSheetsSchema($pdo);

    $sql = 'SELECT e.*, v.name AS venue_name, COUNT(sr.id) AS registration_count
            FROM events e
            LEFT JOIN venues v ON v.id = e.venue_id
            LEFT JOIN staff_registrations sr ON sr.event_id = e.id
            GROUP BY e.id
            ORDER BY e.event_date ASC, e.name ASC';

    return $pdo->query($sql)->fetchAll();
}

/**
 * @return array<string, mixed>|null
 */
function getEventById(PDO $pdo, int $id): ?array
{
    ensureEventTimesSchema($pdo);
    ensureEventMainSecuritySchema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM events WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * @param array<string, mixed> $row
 * @return array{id: int, name: string, date: string}
 */
function formatEventForFrontend(array $row): array
{
    return [
        'id'   => (int) $row['id'],
        'name' => (string) $row['name'],
        'date' => date('d.m.Y', strtotime((string) $row['event_date'])),
    ];
}

/**
 * @return array<int, array{id: int, name: string, date: string}>
 */
function getActiveEventsForFrontend(PDO $pdo): array
{
    return array_map(
        'formatEventForFrontend',
        getEventsOpenForRegistration($pdo)
    );
}

/**
 * @param array<string, mixed> $data
 * @return array<string, string>
 */
function validateEventData(array $data, bool $isEdit = false): array
{
    $errors = [];
    $name   = trim((string) ($data['name'] ?? ''));
    $date   = trim((string) ($data['event_date'] ?? ''));

    if ($name === '') {
        $errors['name'] = 'Event name is required.';
    } elseif (strlen($name) > 150) {
        $errors['name'] = 'Event name is too long.';
    }

    $mainSecurity = trim((string) ($data['main_security_company'] ?? ''));
    if ($mainSecurity !== '' && strlen($mainSecurity) > 150) {
        $errors['main_security_company'] = 'Main security company name is too long.';
    }

    if ($date === '') {
        $errors['event_date'] = 'Event date is required.';
    } else {
        $parsed = DateTime::createFromFormat('Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            $errors['event_date'] = 'Please enter a valid event date.';
        }
    }

    $location = trim((string) ($data['location'] ?? ''));
    if (strlen($location) > 255) {
        $errors['location'] = 'Location is too long.';
    }

    $reporting = trim((string) ($data['reporting_point'] ?? ''));
    if (strlen($reporting) > 255) {
        $errors['reporting_point'] = 'Reporting point is too long.';
    }

    $eircode = normalizeEircode((string) ($data['venue_eircode'] ?? ''));
    if ($eircode === '') {
        $errors['venue_eircode'] = 'Venue Eircode is required for GPS sign-in.';
    } elseif (!isValidEircode($eircode)) {
        $errors['venue_eircode'] = 'Please enter a valid Eircode (e.g. D02 X285).';
    }

    $startTime = trim((string) ($data['start_time'] ?? ''));
    $endTime   = trim((string) ($data['end_time'] ?? ''));

    if ($startTime !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startTime)) {
        $errors['start_time'] = 'Please enter a valid start time.';
    }

    if ($endTime !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $endTime)) {
        $errors['end_time'] = 'Please enter a valid end time.';
    }

    if ($startTime !== '' && $endTime !== '' && strlen($startTime) === 5) {
        $startTime .= ':00';
    }
    if ($startTime !== '' && $endTime !== '' && strlen($endTime) === 5) {
        $endTime .= ':00';
    }

    if ($startTime !== '' && $endTime !== '' && $startTime >= $endTime) {
        $errors['end_time'] = 'End time must be after start time.';
    }

    $pdo      = getDB();
    $workType = trim((string) ($data['work_type'] ?? 'special_event'));
    if (!isValidWorkTypeSlug($pdo, $workType)) {
        $errors['work_type'] = 'Please select a valid work type.';
    }

    $rolesNeeded = normalizeRolesNeeded($data);
    if ($rolesNeeded === []) {
        $errors['roles_needed'] = 'Select at least one staff role for this posting.';
    }

    $venueIdRaw = trim((string) ($data['venue_id'] ?? ''));
    if ($venueIdRaw !== '' && (!ctype_digit($venueIdRaw) || (int) $venueIdRaw < 1)) {
        $errors['venue_id'] = 'Please select a valid venue.';
    }

    $venueLat = normalizeCoordinate(isset($data['venue_lat']) ? (string) $data['venue_lat'] : null);
    $venueLng = normalizeCoordinate(isset($data['venue_lng']) ? (string) $data['venue_lng'] : null);

    if ($venueLat === null || $venueLng === null) {
        $errors['venue_lat'] = 'Look up GPS from the Eircode and confirm the pin on the map.';
    }

    $staffNeededRaw = trim((string) ($data['staff_needed'] ?? ''));
    if ($staffNeededRaw !== '') {
        if (!ctype_digit($staffNeededRaw) || (int) $staffNeededRaw < 1) {
            $errors['staff_needed'] = 'Staff needed must be a whole number of at least 1, or leave blank for no limit.';
        } elseif ((int) $staffNeededRaw > 99999) {
            $errors['staff_needed'] = 'Staff needed is too large.';
        }
    }

    $sheetUrl = trim((string) ($data['google_sheet_url'] ?? ''));
    if ($sheetUrl !== '') {
        require_once __DIR__ . '/google-sheets-sync.php';
        if (parseGoogleSpreadsheetId($sheetUrl) === null) {
            $errors['google_sheet_url'] = 'Paste a valid Google Sheet URL (Share link from Google Sheets).';
        }
    }

    $sheetTab = trim((string) ($data['google_sheet_tab'] ?? ''));
    if (strlen($sheetTab) > 100) {
        $errors['google_sheet_tab'] = 'Sheet tab name is too long.';
    }

    return $errors;
}

/**
 * @param array<string, mixed> $data
 * @return array{
 *     name: string,
 *     main_security_company: string,
 *     event_date: string,
 *     location: ?string,
 *     reporting_point: ?string,
 *     venue_eircode: string,
 *     venue_lat: ?float,
 *     venue_lng: ?float,
 *     signin_radius_m: int,
 *     staff_needed: ?int,
 *     start_time: string,
 *     end_time: string,
 *     times_confirmed: int,
 *     is_active: int
 * }
 */
function normalizeEventPayload(array $data): array
{
    $startTime = trim((string) ($data['start_time'] ?? '09:00'));
    $endTime   = trim((string) ($data['end_time'] ?? '23:00'));

    if (strlen($startTime) === 5) {
        $startTime .= ':00';
    }
    if (strlen($endTime) === 5) {
        $endTime .= ':00';
    }

    $location = trim((string) ($data['location'] ?? ''));
    $reporting = trim((string) ($data['reporting_point'] ?? ''));
    $eircode  = normalizeEircode((string) ($data['venue_eircode'] ?? ''));

    $staffNeededRaw = trim((string) ($data['staff_needed'] ?? ''));
    $staffNeeded    = $staffNeededRaw !== '' ? (int) $staffNeededRaw : null;

    $venueIdRaw = trim((string) ($data['venue_id'] ?? ''));
    $venueId    = $venueIdRaw !== '' && ctype_digit($venueIdRaw) ? (int) $venueIdRaw : null;

    $pdo      = getDB();
    $workType = trim((string) ($data['work_type'] ?? 'special_event'));
    if (!isValidWorkTypeSlug($pdo, $workType)) {
        $workType = 'special_event';
    }

    $draftEvent = ['start_time' => $startTime, 'end_time' => $endTime];
    $timesConfirmed = !empty($data['times_confirmed']) ? 1 : 0;
    if (eventTimesArePlaceholder($draftEvent)) {
        $timesConfirmed = 0;
    }

    return [
        'name'                  => trim((string) ($data['name'] ?? '')),
        'main_security_company' => trim((string) ($data['main_security_company'] ?? '')),
        'event_date'            => trim((string) ($data['event_date'] ?? '')),
        'location'        => $location !== '' ? $location : null,
        'venue_id'        => $venueId,
        'work_type'       => $workType,
        'roles_needed'    => rolesNeededToString(normalizeRolesNeeded($data)),
        'reporting_point' => $reporting !== '' ? $reporting : null,
        'venue_eircode'   => $eircode,
        'venue_lat'       => normalizeCoordinate(isset($data['venue_lat']) ? (string) $data['venue_lat'] : null),
        'venue_lng'       => normalizeCoordinate(isset($data['venue_lng']) ? (string) $data['venue_lng'] : null),
        'signin_radius_m' => EVENT_SIGNIN_RADIUS_M,
        'staff_needed'    => $staffNeeded,
        'start_time'      => $startTime !== '' ? $startTime : '09:00:00',
        'end_time'        => $endTime !== '' ? $endTime : '23:00:00',
        'times_confirmed' => $timesConfirmed,
        'is_active'       => !empty($data['is_active']) ? 1 : 0,
        'google_sheet_url' => ($u = trim((string) ($data['google_sheet_url'] ?? ''))) !== '' ? $u : null,
        'google_sheet_tab' => ($t = trim((string) ($data['google_sheet_tab'] ?? ''))) !== '' ? $t : null,
    ];
}

/**
 * @param array<string, mixed> $data
 */
/**
 * Apply linked venue master data (or create venue from location text).
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function prepareEventPayloadFromForm(PDO $pdo, array $data): array
{
    $payload = normalizeEventPayload($data);
    $venueId = (int) ($payload['venue_id'] ?? 0);

    if ($venueId > 0) {
        syncEventFieldsFromVenue($pdo, $venueId, $payload);
    } else {
        $location = trim((string) ($payload['location'] ?? ''));
        if ($location !== '') {
            $resolvedId = findOrCreateVenueByName($pdo, $location, $payload);
            if ($resolvedId > 0) {
                $payload['venue_id'] = $resolvedId;
                syncEventFieldsFromVenue($pdo, $resolvedId, $payload);
            }
        }
    }

    return $payload;
}

/**
 * @param array<string, mixed> $payload
 */
function syncEventFieldsFromVenue(PDO $pdo, int $venueId, array &$payload): void
{
    $venue = getVenueById($pdo, $venueId);
    if ($venue === null) {
        return;
    }

    $payload['location'] = (string) $venue['name'];

    if (trim((string) ($payload['venue_eircode'] ?? '')) === '' && !empty($venue['venue_eircode'])) {
        $payload['venue_eircode'] = (string) $venue['venue_eircode'];
    }

    if ($payload['venue_lat'] === null && $venue['venue_lat'] !== null) {
        $payload['venue_lat'] = normalizeCoordinate((string) $venue['venue_lat']);
    }

    if ($payload['venue_lng'] === null && $venue['venue_lng'] !== null) {
        $payload['venue_lng'] = normalizeCoordinate((string) $venue['venue_lng']);
    }
}

/**
 * When an event is saved with GPS, store it on the linked venue and refresh sibling events.
 */
function persistVenueGpsFromEventPayload(PDO $pdo, array $payload): void
{
    $venueId = (int) ($payload['venue_id'] ?? 0);
    if ($venueId < 1) {
        return;
    }

    $eircode = trim((string) ($payload['venue_eircode'] ?? ''));
    if ($eircode === '' || $payload['venue_lat'] === null || $payload['venue_lng'] === null) {
        return;
    }

    ensureVenuesSchema($pdo);

    $stmt = $pdo->prepare(
        'UPDATE venues
         SET venue_eircode = :venue_eircode, venue_lat = :venue_lat, venue_lng = :venue_lng
         WHERE id = :id'
    );
    $stmt->execute([
        'venue_eircode' => $eircode,
        'venue_lat'     => $payload['venue_lat'],
        'venue_lng'     => $payload['venue_lng'],
        'id'            => $venueId,
    ]);

    propagateVenueDetailsToLinkedEvents($pdo, $venueId);
}

/**
 * @param array<string, mixed> $payload
 */
function findOrCreateVenueByName(PDO $pdo, string $name, array $payload): int
{
    ensureVenuesSchema($pdo);
    $name = trim($name);
    if ($name === '') {
        return 0;
    }

    $stmt = $pdo->prepare('SELECT id FROM venues WHERE LOWER(name) = LOWER(:name) LIMIT 1');
    $stmt->execute(['name' => $name]);
    $existing = $stmt->fetchColumn();
    if ($existing !== false) {
        return (int) $existing;
    }

    return createVenue($pdo, [
        'name'          => $name,
        'address'       => $payload['location'] ?? $name,
        'venue_type'    => 'arena',
        'venue_eircode' => $payload['venue_eircode'] ?? '',
        'venue_lat'     => $payload['venue_lat'] ?? '',
        'venue_lng'     => $payload['venue_lng'] ?? '',
        'is_active'     => 1,
    ]);
}

function createEvent(PDO $pdo, array $data): int
{
    ensureVenuesSchema($pdo);
    ensureGoogleSheetsSchema($pdo);
    ensureEventTimesSchema($pdo);
    ensureEventMainSecuritySchema($pdo);
    require_once __DIR__ . '/event-reporting-schema.php';
    ensureEventReportingSchema($pdo);
    $payload = prepareEventPayloadFromForm($pdo, $data);

    $stmt = $pdo->prepare(
        'INSERT INTO events (name, main_security_company, event_date, location, venue_id, work_type, roles_needed, reporting_point, venue_eircode, venue_lat, venue_lng, signin_radius_m, staff_needed, start_time, end_time, times_confirmed, is_active, google_sheet_url, google_sheet_tab)
         VALUES (:name, :main_security_company, :event_date, :location, :venue_id, :work_type, :roles_needed, :reporting_point, :venue_eircode, :venue_lat, :venue_lng, :signin_radius_m, :staff_needed, :start_time, :end_time, :times_confirmed, :is_active, :google_sheet_url, :google_sheet_tab)'
    );
    $stmt->execute($payload);
    $newId = (int) $pdo->lastInsertId();
    persistVenueGpsFromEventPayload($pdo, $payload);

    return $newId;
}

/**
 * @param array<string, mixed> $data
 */
function updateEvent(PDO $pdo, int $id, array $data): bool
{
    ensureVenuesSchema($pdo);
    ensureGoogleSheetsSchema($pdo);
    ensureEventTimesSchema($pdo);
    ensureEventMainSecuritySchema($pdo);
    require_once __DIR__ . '/event-reporting-schema.php';
    ensureEventReportingSchema($pdo);
    $payload = prepareEventPayloadFromForm($pdo, $data);
    $payload['id'] = $id;

    $stmt = $pdo->prepare(
        'UPDATE events
         SET name = :name, main_security_company = :main_security_company, event_date = :event_date, location = :location, venue_id = :venue_id,
             work_type = :work_type, roles_needed = :roles_needed, reporting_point = :reporting_point,
             venue_eircode = :venue_eircode, venue_lat = :venue_lat, venue_lng = :venue_lng,
             signin_radius_m = :signin_radius_m, staff_needed = :staff_needed,
             start_time = :start_time, end_time = :end_time, times_confirmed = :times_confirmed,
             is_active = :is_active,
             google_sheet_url = :google_sheet_url, google_sheet_tab = :google_sheet_tab
         WHERE id = :id'
    );
    $stmt->execute($payload);
    $updated = $stmt->rowCount() > 0;
    persistVenueGpsFromEventPayload($pdo, $payload);

    return $updated;
}

/**
 * Optional third-party contractor name for this shift (information only — not the portal).
 */
function formatEventMainSecurityLabel(array $event): string
{
    return trim((string) ($event['main_security_company'] ?? ''));
}

function formatEventLocationLabel(array $event): string
{
    $location = trim((string) ($event['location'] ?? $event['event_location'] ?? $event['venue_name'] ?? ''));
    $eircode  = normalizeEircode((string) ($event['venue_eircode'] ?? ''));

    if ($location !== '' && $eircode !== '') {
        return $location . ' · ' . $eircode;
    }

    if ($location !== '') {
        return $location;
    }

    if ($eircode !== '') {
        return $eircode;
    }

    return 'Location to be confirmed';
}

/**
 * Default DB/form times — not shown to staff until replaced in Admin → Events.
 */
function eventTimesArePlaceholder(array $event): bool
{
    $start = substr((string) ($event['start_time'] ?? $event['event_start_time'] ?? '09:00:00'), 0, 5);
    $end   = substr((string) ($event['end_time'] ?? $event['event_end_time'] ?? '23:00:00'), 0, 5);

    return $start === '09:00' && $end === '23:00';
}

/**
 * Whether shift times should appear on the staff registration form.
 */
function eventShowsTimeOnRegistration(array $event): bool
{
    if (array_key_exists('times_confirmed', $event)) {
        return (int) $event['times_confirmed'] === 1;
    }

    return !eventTimesArePlaceholder($event);
}

function formatEventTimeRangeLabel(array $event): string
{
    $start = substr((string) ($event['start_time'] ?? $event['event_start_time'] ?? '09:00:00'), 0, 5);
    $end   = substr((string) ($event['end_time'] ?? $event['event_end_time'] ?? '23:00:00'), 0, 5);

    return $start . ' – ' . $end;
}

/**
 * Time range for staff registration / public listings (omit placeholder defaults).
 */
function formatEventTimeRangeLabelForStaff(array $event): string
{
    if (!eventShowsTimeOnRegistration($event)) {
        return '';
    }

    return formatEventTimeRangeLabel($event);
}

function setEventActive(PDO $pdo, int $id, bool $active): bool
{
    $stmt = $pdo->prepare('UPDATE events SET is_active = :active WHERE id = :id');
    $stmt->execute(['active' => $active ? 1 : 0, 'id' => $id]);

    return $stmt->rowCount() > 0;
}

function formatEventDateLabel(string $date): string
{
    if ($date === '') {
        return '';
    }

    if (!function_exists('formatSystemDate')) {
        require_once __DIR__ . '/system-settings.php';
    }

    try {
        return formatSystemDate($date, getDB());
    } catch (Throwable $e) {
        return formatSystemDate($date, null);
    }
}

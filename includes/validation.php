<?php
require_once __DIR__ . '/registration-forms.php';
require_once __DIR__ . '/maps.php';
require_once __DIR__ . '/app-environment.php';
require_once __DIR__ . '/staff-registration-schema.php';
require_once __DIR__ . '/events-repository.php';

/**
 * Field names match index.html / database staff_registrations columns.
 */

function normalizeEircode(string $value): string
{
    return strtoupper(trim(preg_replace('/\s+/', ' ', $value)));
}

function isValidEircode(string $value): bool
{
    return (bool) preg_match('/^[A-Z0-9]{3}\s?[A-Z0-9]{4}$/i', normalizeEircode($value));
}

/**
 * @param array<string, mixed> $data
 * @return int[]
 */
function normalizeEventIds(array $data): array
{
    $ids = $data['event_ids'] ?? [];

    if (!is_array($ids)) {
        $ids = [$ids];
    }

    $ids = array_map('intval', $ids);
    $ids = array_filter($ids, static fn(int $id): bool => $id > 0);

    return array_values(array_unique($ids));
}

/**
 * @param int[] $eventIds
 */
function validateOneShiftPerDay(array $eventIds, ?PDO $pdo = null): ?string
{
    if (count($eventIds) <= 1) {
        return null;
    }

    if ($pdo === null && function_exists('getDB')) {
        try {
            $pdo = getDB();
        } catch (Throwable $e) {
            $pdo = null;
        }
    }

    if ($pdo === null) {
        return null;
    }

    require_once __DIR__ . '/events-repository.php';

    $seen = [];
    foreach ($eventIds as $eventId) {
        $event = getEventById($pdo, $eventId);
        if ($event === null) {
            continue;
        }
        $day = (string) ($event['event_date'] ?? '');
        if ($day === '') {
            continue;
        }
        if (isset($seen[$day])) {
            $label = date('d.m.Y', strtotime($day));

            return 'You can only select one shift per day. You chose more than one for ' . $label . '.';
        }
        $seen[$day] = true;
    }

    return null;
}

/**
 * @param array<string, mixed> $data
 * @return array<string, string> field name => error message
 */
function validateRegistration(array $data): array
{
    $errors = [];

    $required = [
        'surname'       => 'Surname',
        'first_name'    => 'First Name',
        'full_address'  => 'Full address',
        'eircode'       => 'Eircode',
        'email'         => 'Email Address',
        'mobile'        => 'Mobile Number',
        'date_of_birth' => 'Date of Birth',
        'gender'        => 'Gender',
        'pps_number'    => 'NI / PPS Number',
        'bank_iban'     => 'Bank Account / IBAN',
        'staff_role'    => 'Role',
    ];

    foreach ($required as $field => $label) {
        $value = trim((string) ($data[$field] ?? ''));
        if ($value === '') {
            $errors[$field] = $label . ' is required.';
        }
    }

    $eventIds = normalizeEventIds($data);
    if ($eventIds === []) {
        $errors['event_ids'] = 'Please select at least one shift or event.';
    } else {
        $pdoForDay = null;
        try {
            $pdoForDay = getDB();
        } catch (Throwable $e) {
            $pdoForDay = null;
        }
        $perDayError = validateOneShiftPerDay($eventIds, $pdoForDay);
        if ($perDayError !== null) {
            $errors['event_ids'] = $perDayError;
        }
    }

    $venueId = (int) ($data['venue_id'] ?? 0);
    if ($venueId < 1 && $eventIds === []) {
        $errors['venue_id'] = 'Please select a venue.';
    }

    $email = trim((string) ($data['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    $eircode = trim((string) ($data['eircode'] ?? ''));
    if ($eircode !== '' && !isValidEircode($eircode)) {
        $errors['eircode'] = 'Please enter a valid Eircode (e.g. D02 X285).';
    }

    $allowedGenders = ['male', 'female', 'other', 'prefer_not_to_say'];
    $gender = trim((string) ($data['gender'] ?? ''));
    if ($gender !== '' && !in_array($gender, $allowedGenders, true)) {
        $errors['gender'] = 'Please select a valid gender.';
    }

    $staffRole = normalizeStaffRole(trim((string) ($data['staff_role'] ?? '')));
    if ($staffRole !== '' && !in_array($staffRole, ['dsp', 'static', 'both', 'steward'], true)) {
        $errors['staff_role'] = 'Please select a valid role.';
    }

    $dob = trim((string) ($data['date_of_birth'] ?? ''));
    if ($dob !== '') {
        $date = DateTime::createFromFormat('Y-m-d', $dob);
        if (!$date || $date->format('Y-m-d') !== $dob) {
            $errors['date_of_birth'] = 'Please enter a valid date of birth.';
        }
    }

    if (!registrationPrivacyAccepted($data)) {
        $errors['privacy_consent'] = 'You must agree to the privacy notice before registering.';
    }

    return $errors;
}

/**
 * @param PDO $pdo
 * @param string $email
 * @param int[] $eventIds
 * @return array<int, array<string, mixed>>
 */
function getAlreadyRegisteredEvents(PDO $pdo, string $email, array $eventIds): array
{
    if ($eventIds === []) {
        return [];
    }

    $email = strtolower(trim($email));
    if ($email === '') {
        return [];
    }

    $params = ['email' => $email];
    $keys   = [];
    foreach ($eventIds as $index => $eventId) {
        $key          = 'event_' . $index;
        $keys[]       = ':' . $key;
        $params[$key] = $eventId;
    }

    $sql = 'SELECT sr.event_id, e.name AS event_name, e.event_date
            FROM staff_registrations sr
            INNER JOIN events e ON e.id = sr.event_id
            WHERE LOWER(sr.email) = :email AND sr.event_id IN (' . implode(',', $keys) . ')';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * @param array<int, array<string, mixed>> $rows
 */
function formatDuplicateEventsMessage(array $rows): string
{
    if ($rows === []) {
        return 'You are already registered for the selected event(s).';
    }

    $labels = array_map(static function (array $row): string {
        return formatEventLabel($row);
    }, $rows);

    return 'You are already registered for: ' . implode(', ', $labels) . '.';
}

function normalizeRegistrationEmail(string $email): string
{
    return strtolower(trim($email));
}

/**
 * @param PDO $pdo
 * @param int $eventId
 */
function registrationExistsForEmail(PDO $pdo, string $email, int $eventId): bool
{
    $stmt = $pdo->prepare(
        'SELECT id FROM staff_registrations WHERE LOWER(email) = :email AND event_id = :event_id LIMIT 1'
    );
    $stmt->execute([
        'email'    => normalizeRegistrationEmail($email),
        'event_id' => $eventId,
    ]);

    return (bool) $stmt->fetchColumn();
}

function eventExists(PDO $pdo, int $eventId): bool
{
    $stmt = $pdo->prepare('SELECT id FROM events WHERE id = :id AND is_active = 1 LIMIT 1');
    $stmt->execute(['id' => $eventId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * @param PDO $pdo
 * @param int[] $eventIds
 * @return int[] invalid event ids
 */
function getInvalidEventIds(PDO $pdo, array $eventIds): array
{
    require_once __DIR__ . '/events-repository.php';

    $invalid = [];

    foreach ($eventIds as $eventId) {
        $event = getEventById($pdo, $eventId);
        if ($event === null || !isEventOpenForRegistration($event)) {
            $invalid[] = $eventId;
        }
    }

    return $invalid;
}

/**
 * @param PDO $pdo
 * @param array<string, mixed> $data
 * @param int $eventId
 */
function saveRegistration(PDO $pdo, array $data, int $eventId, ?string $staffRoleOverride = null): int
{
    ensureStaffRegistrationRoleColumn($pdo);

    $statusToken = bin2hex(random_bytes(32));
    $staffRole   = $staffRoleOverride ?? normalizeStaffRole(trim((string) $data['staff_role']));

    $sql = 'INSERT INTO staff_registrations (
                surname, first_name, full_address, eircode, location_lat, location_lng, email, mobile,
                date_of_birth, gender, pps_number, bank_iban, staff_role, event_id, status_token, privacy_consented_at
            ) VALUES (
                :surname, :first_name, :full_address, :eircode, :location_lat, :location_lng, :email, :mobile,
                :date_of_birth, :gender, :pps_number, :bank_iban, :staff_role, :event_id, :status_token, :privacy_consented_at
            )';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'surname'             => trim((string) $data['surname']),
        'first_name'          => trim((string) $data['first_name']),
        'full_address'        => trim((string) $data['full_address']),
        'eircode'             => normalizeEircode((string) $data['eircode']),
        'location_lat'        => normalizeCoordinate(isset($data['location_lat']) ? (string) $data['location_lat'] : null),
        'location_lng'        => normalizeCoordinate(isset($data['location_lng']) ? (string) $data['location_lng'] : null),
        'email'               => normalizeRegistrationEmail((string) $data['email']),
        'mobile'              => trim((string) $data['mobile']),
        'date_of_birth'       => trim((string) $data['date_of_birth']),
        'gender'              => trim((string) $data['gender']),
        'pps_number'          => trim((string) $data['pps_number']),
        'bank_iban'           => trim((string) $data['bank_iban']),
        'staff_role'          => $staffRole,
        'event_id'            => $eventId,
        'status_token'        => $statusToken,
        'privacy_consented_at'=> registrationPrivacyAccepted($data) ? date('Y-m-d H:i:s') : null,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * @param PDO $pdo
 * @param array<string, mixed> $data
 * @param int[] $eventIds
 * @return int[] inserted registration ids
 */
function saveRegistrations(PDO $pdo, array $data, array $eventIds): array
{
    ensureStaffRegistrationRoleColumn($pdo);
    $pdo->beginTransaction();

    try {
        $ids   = [];
        $email = normalizeRegistrationEmail((string) ($data['email'] ?? ''));

        foreach ($eventIds as $eventId) {
            if (registrationExistsForEmail($pdo, $email, $eventId)) {
                throw new RuntimeException('Duplicate registration blocked for event ' . $eventId);
            }
            $event = getEventById($pdo, $eventId);
            $role  = $event !== null
                ? resolveStaffRoleForEventRegistration((string) ($data['staff_role'] ?? ''), $event)
                : normalizeStaffRole((string) ($data['staff_role'] ?? ''));
            if ($role === 'both') {
                $role = 'dsp';
            }
            $ids[] = saveRegistration($pdo, $data, $eventId, $role);
        }
        $pdo->commit();
        return $ids;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function isAjaxRequest(): bool
{
    return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

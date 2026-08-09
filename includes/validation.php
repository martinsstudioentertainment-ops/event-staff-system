<?php
require_once __DIR__ . '/registration-forms.php';
require_once __DIR__ . '/maps.php';
require_once __DIR__ . '/app-environment.php';
require_once __DIR__ . '/staff-registration-schema.php';
require_once __DIR__ . '/events-repository.php';
require_once __DIR__ . '/phone-numbers.php';

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

/** @return string[] */
function getAllowedRegistrationGenders(): array
{
    return ['male', 'female', 'other', 'prefer_not_to_say'];
}

/**
 * Gender choices for registration API payloads.
 *
 * @return list<array{value: string, label: string}>
 */
function getRegistrationGenderOptions(): array
{
    return [
        ['value' => 'male', 'label' => 'Male'],
        ['value' => 'female', 'label' => 'Female'],
        ['value' => 'other', 'label' => 'Other'],
        ['value' => 'prefer_not_to_say', 'label' => 'Prefer not to say'],
    ];
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

function isProfileOnlyRegistrationRequest(array $data): bool
{
    if (normalizeEventIds($data) !== []) {
        return false;
    }

    return !isWaitlistRegistrationRequest($data);
}

/**
 * Portal self-registration: staff account only — shift application happens after staff-app sign-in.
 *
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function normalizePortalRegistrationPost(array $data): array
{
    $data['event_ids'] = [];
    unset($data['join_waiting_list'], $data['waitlist_interest']);
    $data['registration_mode'] = 'profile_only';

    return $data;
}

function isRegistrationWithoutShiftRequest(array $data): bool
{
    return isWaitlistRegistrationRequest($data) || isProfileOnlyRegistrationRequest($data);
}

function isWaitlistRegistrationRequest(array $data): bool
{
    if (normalizeEventIds($data) !== []) {
        return false;
    }

    $mode = strtolower(trim((string) ($data['registration_mode'] ?? '')));
    if (in_array($mode, ['profile_only', 'register_without_shift', 'no_shift'], true)) {
        return false;
    }
    if (in_array($mode, ['waitlist', 'waiting_list', 'reserve', 'pending_allocation'], true)) {
        return true;
    }

    return !empty($data['join_waiting_list']) || !empty($data['waitlist_interest']);
}

function normalizeWaitlistAllocationType(array $data): string
{
    $type = strtolower(trim((string) ($data['waitlist_allocation_type'] ?? '')));
    if (in_array($type, ['waiting_list', 'pending_allocation', 'reserve_staff'], true)) {
        return $type;
    }

    $mode = strtolower(trim((string) ($data['registration_mode'] ?? '')));
    if ($mode === 'reserve') {
        return 'reserve_staff';
    }
    if ($mode === 'pending_allocation') {
        return 'pending_allocation';
    }

    return 'waiting_list';
}

/**
 * @param array<string, mixed> $data
 * @return array<string, string>
 */
function validateWaitlistRegistration(array $data): array
{
    $errors = validateRegistration($data);
    unset($errors['event_ids']);

    return $errors;
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
            $label = formatEventDateLabel($day);

            return 'You can only select one shift per day. You chose more than one for ' . $label . '.';
        }
        $seen[$day] = true;
    }

    return null;
}

/**
 * Existing registrations (non-rejected) on the same calendar date as a new selection.
 *
 * @param PDO $pdo
 * @param string $email
 * @param int[] $newEventIds
 * @return array<int, array<string, mixed>>
 */
function getSameDayRegistrationConflicts(PDO $pdo, string $email, array $newEventIds): array
{
    if ($newEventIds === []) {
        return [];
    }

    $email = normalizeRegistrationEmail($email);
    if ($email === '') {
        return [];
    }

    $params = ['email' => $email];
    $inKeys    = [];
    $notInKeys = [];
    foreach ($newEventIds as $index => $eventId) {
        $inKey    = 'new_in_' . $index;
        $notInKey = 'new_not_' . $index;
        $inKeys[]    = ':' . $inKey;
        $notInKeys[] = ':' . $notInKey;
        $params[$inKey]    = $eventId;
        $params[$notInKey] = $eventId;
    }

    $inNew    = implode(',', $inKeys);
    $notInNew = implode(',', $notInKeys);

    $sql = "SELECT DISTINCT e_existing.id AS event_id, e_existing.name AS event_name, e_existing.event_date
            FROM staff_registrations sr
            INNER JOIN events e_existing ON e_existing.id = sr.event_id
            INNER JOIN events e_new ON DATE(e_new.event_date) = DATE(e_existing.event_date)
            WHERE LOWER(sr.email) = :email
              AND sr.status != 'rejected'
              AND e_new.id IN ({$inNew})
              AND e_existing.id NOT IN ({$notInNew})";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @param PDO $pdo
 * @param string $email
 * @param int[] $eventIds
 */
function validateNoExistingShiftOnSameDay(PDO $pdo, string $email, array $eventIds): ?string
{
    $conflicts = getSameDayRegistrationConflicts($pdo, $email, $eventIds);
    if ($conflicts === []) {
        return null;
    }

    $labels = array_map(static function (array $row): string {
        $date = !empty($row['event_date'])
            ? formatEventDateLabel(normalizeEventDateYmd((string) $row['event_date']))
            : '';
        $name = (string) ($row['event_name'] ?? 'Event');

        return $date !== '' ? $name . ' (' . $date . ')' : $name;
    }, $conflicts);

    return 'You can only register for one shift per day. You already have: '
        . implode(', ', $labels) . '.';
}

/**
 * Staff with 2+ non-rejected registrations on the same calendar date.
 *
 * @return list<array{
 *   email: string,
 *   first_name: string,
 *   surname: string,
 *   event_day: string,
 *   event_count: int,
 *   registrations: list<array{registration_id: int, event_id: int, event_name: string, status: string}>
 * }>
 */
function getAllSameDayDoubleBookings(PDO $pdo, ?string $fromDateYmd = null): array
{
    $params = [];
    $dateFilter = '';
    if ($fromDateYmd !== null && $fromDateYmd !== '') {
        $dateFilter = ' AND DATE(e.event_date) >= :from_date';
        $params['from_date'] = $fromDateYmd;
    }

    $sql = "SELECT sr.id AS registration_id,
                   sr.event_id,
                   sr.status,
                   sr.first_name,
                   sr.surname,
                   sr.email,
                   sr.created_at,
                   e.name AS event_name,
                   DATE(e.event_date) AS event_day
            FROM staff_registrations sr
            INNER JOIN events e ON e.id = sr.event_id
            WHERE sr.status != 'rejected'
              AND e.event_date IS NOT NULL
              {$dateFilter}
            ORDER BY event_day DESC, LOWER(TRIM(sr.email)), sr.created_at ASC, sr.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $groups = [];
    foreach ($rows as $row) {
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        $day   = (string) ($row['event_day'] ?? '');
        if ($email === '' || $day === '') {
            continue;
        }
        $key = $email . '|' . $day;
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'email'          => $email,
                'first_name'     => (string) ($row['first_name'] ?? ''),
                'surname'        => (string) ($row['surname'] ?? ''),
                'event_day'      => $day,
                'event_count'    => 0,
                'registrations'  => [],
                '_event_ids'     => [],
            ];
        }
        $eventId = (int) ($row['event_id'] ?? 0);
        if (isset($groups[$key]['_event_ids'][$eventId])) {
            continue;
        }
        $groups[$key]['_event_ids'][$eventId] = true;
        $groups[$key]['registrations'][] = [
            'registration_id' => (int) ($row['registration_id'] ?? 0),
            'event_id'        => $eventId,
            'event_name'      => (string) ($row['event_name'] ?? ''),
            'status'          => (string) ($row['status'] ?? ''),
            'created_at'      => (string) ($row['created_at'] ?? ''),
        ];
        $groups[$key]['event_count'] = count($groups[$key]['registrations']);
    }

    $out = [];
    foreach ($groups as $group) {
        if ($group['event_count'] < 2) {
            continue;
        }
        unset($group['_event_ids']);
        usort($group['registrations'], static function (array $a, array $b): int {
            $ta = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
            $tb = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
            if ($ta !== $tb) {
                return $ta <=> $tb;
            }

            return ((int) ($a['registration_id'] ?? 0)) <=> ((int) ($b['registration_id'] ?? 0));
        });
        foreach ($group['registrations'] as $i => &$reg) {
            $reg['keep'] = ($i === 0);
        }
        unset($reg);
        $out[] = $group;
    }

    usort($out, static function (array $a, array $b): int {
        $day = strcmp((string) $b['event_day'], (string) $a['event_day']);
        if ($day !== 0) {
            return $day;
        }

        return strcmp((string) $a['email'], (string) $b['email']);
    });

    return $out;
}

/**
 * Reject later same-day shifts; keep the earliest registration per email + calendar date.
 *
 * @return array{
 *   groups: int,
 *   kept: int,
 *   rejected: int,
 *   errors: list<string>,
 *   details: list<array<string, mixed>>
 * }
 */
function rejectSameDayDuplicateShifts(PDO $pdo, ?string $fromDateYmd = null): array
{
    require_once __DIR__ . '/staff-repository.php';
    require_once __DIR__ . '/google-sheets-sync.php';
    require_once __DIR__ . '/audit-log.php';
    require_once __DIR__ . '/apply-remote-sync.php';

    $stats = [
        'groups'   => 0,
        'kept'     => 0,
        'rejected' => 0,
        'errors'   => [],
        'details'  => [],
    ];

    $conflicts = getAllSameDayDoubleBookings($pdo, $fromDateYmd);
    if ($conflicts === []) {
        return $stats;
    }

    $affectedEvents = [];

    foreach ($conflicts as $group) {
        $stats['groups']++;
        $regs = $group['registrations'] ?? [];
        if (count($regs) < 2) {
            continue;
        }

        $keep = $regs[0];
        $stats['kept']++;
        $detail = [
            'email'     => (string) ($group['email'] ?? ''),
            'event_day' => (string) ($group['event_day'] ?? ''),
            'kept_id'   => (int) ($keep['registration_id'] ?? 0),
            'kept_event'=> (string) ($keep['event_name'] ?? ''),
            'rejected'  => [],
        ];

        for ($i = 1, $n = count($regs); $i < $n; $i++) {
            $regId = (int) ($regs[$i]['registration_id'] ?? 0);
            if ($regId < 1) {
                continue;
            }

            try {
                if (!updateStaffStatus($pdo, $regId, 'rejected')) {
                    $stats['errors'][] = 'Could not reject registration #' . $regId;
                    continue;
                }

                $stats['rejected']++;
                $eventId = (int) ($regs[$i]['event_id'] ?? 0);
                if ($eventId > 0) {
                    $affectedEvents[$eventId] = $eventId;
                }

                try {
                    syncRegistrationToGoogleSheetWithOutcome($pdo, $regId);
                } catch (Throwable $sheetErr) {
                    error_log('[EventStaff] same-day reject sheet sync #' . $regId . ': ' . $sheetErr->getMessage());
                }

                logAdminAudit(
                    $pdo,
                    'same_day_reject',
                    'registration',
                    $regId,
                    'Auto-rejected duplicate same-day shift; kept #' . (int) ($keep['registration_id'] ?? 0)
                );

                $detail['rejected'][] = [
                    'registration_id' => $regId,
                    'event_name'      => (string) ($regs[$i]['event_name'] ?? ''),
                ];
            } catch (Throwable $e) {
                $stats['errors'][] = 'Registration #' . $regId . ': ' . $e->getMessage();
            }
        }

        $stats['details'][] = $detail;
    }

    if ($stats['rejected'] > 0) {
        try {
            triggerApplyPortalSyncAsync($pdo, true);
        } catch (Throwable $e) {
            error_log('[EventStaff] same-day reject apply sync: ' . $e->getMessage());
        }

        logAdminAudit(
            $pdo,
            'same_day_reject_bulk',
            'system',
            0,
            'Rejected ' . $stats['rejected'] . ' duplicate shift(s); kept ' . $stats['kept'] . ' first pick(s)'
        );
    }

    return $stats;
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
        'psa_licence'   => 'PSA licence number',
        'psa_expiry_date' => 'PSA expiry date',
        'staff_role'    => 'Role',
    ];

    $staffRole = normalizeStaffRole(trim((string) ($data['staff_role'] ?? '')));
    $requiresPsa = $staffRole !== 'steward';

    foreach ($required as $field => $label) {
        if (!$requiresPsa && in_array($field, ['psa_licence', 'psa_expiry_date'], true)) {
            continue;
        }
        $value = trim((string) ($data[$field] ?? ''));
        if ($value === '') {
            $errors[$field] = $label . ' is required.';
        }
    }

    $eventIds = normalizeEventIds($data);
    if ($eventIds !== []) {
        $pdoForDay = null;
        try {
            $pdoForDay = getDB();
        } catch (Throwable $e) {
            $pdoForDay = null;
        }
        $perDayError = validateOneShiftPerDay($eventIds, $pdoForDay);
        if ($perDayError !== null) {
            $errors['event_ids'] = $perDayError;
        } elseif ($pdoForDay !== null) {
            $emailForDay = normalizeRegistrationEmail((string) ($data['email'] ?? ''));
            if ($emailForDay !== '' && filter_var($emailForDay, FILTER_VALIDATE_EMAIL)) {
                $existingDayError = validateNoExistingShiftOnSameDay($pdoForDay, $emailForDay, $eventIds);
                if ($existingDayError !== null) {
                    $errors['event_ids'] = $existingDayError;
                }
            }
        }
    }

    $venueId = (int) ($data['venue_id'] ?? 0);

    $email = trim((string) ($data['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    $eircode = trim((string) ($data['eircode'] ?? ''));
    if ($eircode !== '' && !isValidEircode($eircode)) {
        $errors['eircode'] = 'Please enter a valid Eircode (e.g. D02 X285).';
    }

    $allowedGenders = getAllowedRegistrationGenders();
    $gender = trim((string) ($data['gender'] ?? ''));
    if ($gender !== '' && !in_array($gender, $allowedGenders, true)) {
        $errors['gender'] = 'Please select a valid gender.';
    }

    if ($staffRole !== '' && !in_array($staffRole, getKnownStaffRoles(), true)) {
        $errors['staff_role'] = 'Please select a valid role.';
    }

    $dob = normalizeDateOfBirthForDb((string) ($data['date_of_birth'] ?? ''));
    if ($dob !== '') {
        $date = DateTime::createFromFormat('Y-m-d', $dob);
        if (!$date || $date->format('Y-m-d') !== $dob) {
            $errors['date_of_birth'] = 'Please enter a valid date of birth.';
        }
    }

    if (!registrationPrivacyAccepted($data)) {
        $errors['privacy_consent'] = 'You must agree to the privacy notice before registering.';
    }

    prepareMobileFromRequest($data);
    $mobile = trim((string) ($data['mobile'] ?? ''));
    if ($mobile !== '') {
        $mobileError = validateMobileNumber($mobile);
        if ($mobileError !== null) {
            $errors['mobile'] = $mobileError;
        }
    }

    require_once __DIR__ . '/financial-field-validation.php';
    $errors = array_merge($errors, validateFinancialStaffFields($data, true, $requiresPsa));

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
        require_once __DIR__ . '/event-capacity.php';
        if ($event === null || !isEventAvailableForStaffRegistration($pdo, $event)) {
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
    ensureStaffRegistrationSaveSchema($pdo);

    $statusToken = bin2hex(random_bytes(32));
    $staffRole   = sanitizeStaffRoleForDb(
        $staffRoleOverride ?? (string) ($data['staff_role'] ?? 'dsp')
    );

    // Dual-write: Create/update staff record in normalized staff table
    $staffData = [
        'surname' => trim((string) $data['surname']),
        'first_name' => trim((string) $data['first_name']),
        'full_address' => trim((string) $data['full_address']),
        'eircode' => normalizeEircode((string) $data['eircode']),
        'location_lat' => normalizeCoordinate(isset($data['location_lat']) ? (string) $data['location_lat'] : null),
        'location_lng' => normalizeCoordinate(isset($data['location_lng']) ? (string) $data['location_lng'] : null),
        'email' => normalizeRegistrationEmail((string) $data['email']),
        'mobile' => normalizeMobileFromPost($data),
        'date_of_birth' => normalizeDateOfBirthForDb((string) ($data['date_of_birth'] ?? '')),
        'gender' => trim((string) $data['gender']),
        'pps_number' => trim((string) $data['pps_number']),
        'bank_iban' => trim((string) $data['bank_iban']),
        'psa_licence' => trim((string) ($data['psa_licence'] ?? '')),
    ];
    require_once __DIR__ . '/financial-field-validation.php';
    $staffData = normalizeFinancialStaffFields($staffData);
    $staffData = array_merge($staffData, [
        'psa_expiry_date' => trim((string) ($data['psa_expiry_date'] ?? '')),
        'staff_role' => $staffRole,
    ]);

    $staffId = 0;
    try {
        require_once __DIR__ . '/staff-repository.php';
        $staffId = findOrCreateStaff($pdo, $staffData);
    } catch (Throwable $e) {
        // If staff table operations fail, continue without staff_id (fallback to old structure)
        error_log('[EventStaff] Staff table operation failed: ' . $e->getMessage());
    }

    $data = normalizeFinancialStaffFields($data);

    $row = [
        'staff_id'             => $staffId > 0 ? $staffId : null,
        'surname'              => trim((string) $data['surname']),
        'first_name'           => trim((string) $data['first_name']),
        'full_address'         => trim((string) $data['full_address']),
        'eircode'              => normalizeEircode((string) $data['eircode']),
        'location_lat'         => normalizeCoordinate(isset($data['location_lat']) ? (string) $data['location_lat'] : null),
        'location_lng'         => normalizeCoordinate(isset($data['location_lng']) ? (string) $data['location_lng'] : null),
        'email'                => normalizeRegistrationEmail((string) $data['email']),
        'mobile'               => normalizeMobileFromPost($data),
        'date_of_birth'        => normalizeDateOfBirthForDb((string) ($data['date_of_birth'] ?? '')),
        'gender'               => trim((string) $data['gender']),
        'pps_number'           => trim((string) $data['pps_number']),
        'bank_iban'            => trim((string) $data['bank_iban']),
        'staff_role'           => $staffRole,
        'event_id'             => $eventId,
        'status_token'         => $statusToken,
        'privacy_consented_at' => registrationPrivacyAccepted($data) ? date('Y-m-d H:i:s') : null,
    ];

    $columns = [];
    $params  = [];
    foreach ($row as $column => $value) {
        if (!staffRegistrationColumnExists($pdo, $column)) {
            continue;
        }
        $columns[]        = $column;
        $params[$column]  = $value;
    }

    if ($columns === []) {
        throw new RuntimeException('staff_registrations table is missing required columns.');
    }

    $placeholders = array_map(static fn(string $c): string => ':' . $c, $columns);
    $sql          = 'INSERT INTO staff_registrations (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $stmt         = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $pdo->lastInsertId();
}

/**
 * @param PDO $pdo
 * @param array<string, mixed> $data
 * @param int[] $eventIds
 * @return int[] inserted registration ids
 */
function saveRegistrations(PDO $pdo, array $data, array $eventIds, array $files = []): array
{
    ensureStaffRegistrationSaveSchema($pdo);
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
            $ids[] = saveRegistration($pdo, $data, $eventId, $role);
        }
        $pdo->commit();

        require_once __DIR__ . '/staff-repository.php';
        require_once __DIR__ . '/staff-psa.php';
        $staffId = ensureStaffRecordForEmail($pdo, $email);
        if ($staffId !== null) {
            $psaSaveErrors = saveStaffPsaFromForm($pdo, $staffId, $data, $files, false);
            if ($psaSaveErrors !== []) {
                error_log('[EventStaff] PSA save after registration for ' . $email . ': ' . json_encode($psaSaveErrors));
            }
        }

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
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

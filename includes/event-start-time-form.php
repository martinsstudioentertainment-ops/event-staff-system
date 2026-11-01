<?php

declare(strict_types=1);

/**
 * AVIVA Stadium, 1 October 2026 — staff pick a start time.
 * Name must already be on this event. Caps: 50 at 14:00, 30 at 16:00.
 */

function avivaOct1StartTimeEventId(): int
{
    return 51;
}

function avivaOct1StartTimeEventDate(): string
{
    return '2026-10-01';
}

/**
 * @return array<string, int>
 */
function avivaOct1StartTimeCaps(): array
{
    return [
        '14:00' => 50,
        '16:00' => 30,
    ];
}

function eventStartTimeLabel(string $startTime): string
{
    return match ($startTime) {
        '14:00' => '2:00pm',
        '16:00' => '4:00pm',
        default => $startTime,
    };
}

function eventRosterNameKey(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '';
    }
    if (function_exists('mb_strtolower')) {
        $name = mb_strtolower($name, 'UTF-8');
    } else {
        $name = strtolower($name);
    }
    $name = str_replace(["\u{2019}", "\u{2018}", '`'], "'", $name);
    $key = preg_replace('/[^\p{L}\p{N}]+/u', '', $name);

    return is_string($key) ? $key : '';
}

function ensureEventStartTimeSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    require_once __DIR__ . '/staff-registration-schema.php';
    staffRegistrationInvalidateColumnCache();

    if (!staffRegistrationColumnExists($pdo, 'planned_start_time')) {
        $pdo->exec(
            'ALTER TABLE staff_registrations
             ADD COLUMN planned_start_time CHAR(5) NULL DEFAULT NULL'
        );
        staffRegistrationInvalidateColumnCache();
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS event_start_slots (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id INT UNSIGNED NOT NULL,
            start_time CHAR(5) NOT NULL,
            cap_count INT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY event_start_unique (event_id, start_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $insert = $pdo->prepare(
        'INSERT INTO event_start_slots (event_id, start_time, cap_count)
         VALUES (:event_id, :start_time, :cap)
         ON DUPLICATE KEY UPDATE cap_count = VALUES(cap_count)'
    );
    $eventId = avivaOct1StartTimeEventId();
    foreach (avivaOct1StartTimeCaps() as $startTime => $cap) {
        $insert->execute([
            'event_id'   => $eventId,
            'start_time' => $startTime,
            'cap'        => $cap,
        ]);
    }

    $ready = true;
}

/**
 * @return array<string, mixed>|null
 */
function avivaOct1StartTimeEvent(PDO $pdo): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, name, event_date, location
         FROM events
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => avivaOct1StartTimeEventId()]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$event) {
        return null;
    }
    if (substr((string) ($event['event_date'] ?? ''), 0, 10) !== avivaOct1StartTimeEventDate()) {
        return null;
    }
    if (stripos((string) ($event['name'] ?? ''), 'AVIVA') === false) {
        return null;
    }

    return $event;
}

/**
 * @return list<array{start_time: string, label: string, cap: int, taken: int, remaining: int, is_full: bool}>
 */
function eventStartTimeSlotSummaries(PDO $pdo, int $eventId): array
{
    $caps = $pdo->prepare(
        'SELECT start_time, cap_count
         FROM event_start_slots
         WHERE event_id = :event_id
         ORDER BY start_time ASC'
    );
    $caps->execute(['event_id' => $eventId]);
    $rows = $caps->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $count = $pdo->prepare(
        "SELECT COUNT(*)
         FROM staff_registrations
         WHERE event_id = :event_id
           AND status IN ('approved', 'pending')
           AND planned_start_time = :start_time"
    );

    $slots = [];
    foreach ($rows as $row) {
        $startTime = (string) $row['start_time'];
        $cap = (int) $row['cap_count'];
        $count->execute(['event_id' => $eventId, 'start_time' => $startTime]);
        $taken = (int) $count->fetchColumn();
        $remaining = max(0, $cap - $taken);
        $slots[] = [
            'start_time' => $startTime,
            'label'      => eventStartTimeLabel($startTime),
            'cap'        => $cap,
            'taken'      => $taken,
            'remaining'  => $remaining,
            'is_full'    => $taken >= $cap,
        ];
    }

    return $slots;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array{ok: bool, code: string, row?: array<string, mixed>}
 */
function matchEventRosterName(array $rows, string $typedName): array
{
    $typed = eventRosterNameKey($typedName);
    if ($typed === '') {
        return ['ok' => false, 'code' => 'not_found'];
    }

    $forward = [];
    $reverse = [];
    foreach ($rows as $row) {
        $first = (string) ($row['first_name'] ?? '');
        $surname = (string) ($row['surname'] ?? '');
        $forwardKey = eventRosterNameKey($first . ' ' . $surname);
        if ($forwardKey === '') {
            continue;
        }
        $forward[$forwardKey][] = $row;
        $reverseKey = eventRosterNameKey($surname . ' ' . $first);
        if ($reverseKey !== '' && $reverseKey !== $forwardKey) {
            $reverse[$reverseKey][] = $row;
        }
    }

    if (isset($forward[$typed])) {
        if (count($forward[$typed]) === 1) {
            return ['ok' => true, 'code' => 'matched', 'row' => $forward[$typed][0]];
        }

        return ['ok' => false, 'code' => 'ambiguous'];
    }

    if (isset($reverse[$typed])) {
        if (count($reverse[$typed]) === 1) {
            return ['ok' => true, 'code' => 'matched', 'row' => $reverse[$typed][0]];
        }

        return ['ok' => false, 'code' => 'ambiguous'];
    }

    return ['ok' => false, 'code' => 'not_found'];
}

/**
 * @return array{ok: bool, code: string, message: string, label?: string, name?: string, registration_id?: int, start_time?: string}
 */
function lookupAvivaOct1Roster(PDO $pdo, string $typedName): array
{
    ensureEventStartTimeSchema($pdo);
    $event = avivaOct1StartTimeEvent($pdo);
    if ($event === null) {
        return ['ok' => false, 'code' => 'error', 'message' => 'This event is not open for start times.'];
    }

    $roster = $pdo->prepare(
        "SELECT id, first_name, surname, planned_start_time
         FROM staff_registrations
         WHERE event_id = :event_id
           AND status IN ('approved', 'pending')"
    );
    $roster->execute(['event_id' => (int) $event['id']]);
    $rows = $roster->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $match = matchEventRosterName($rows, $typedName);
    if (empty($match['ok']) || !is_array($match['row'] ?? null)) {
        $message = ($match['code'] ?? '') === 'ambiguous'
            ? 'More than one person has this name.'
            : 'Information not found';

        return ['ok' => false, 'code' => (string) ($match['code'] ?? 'not_found'), 'message' => $message];
    }

    $row = $match['row'];
    $startTime = trim((string) ($row['planned_start_time'] ?? ''));
    $displayName = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['surname'] ?? ''));

    return [
        'ok'              => true,
        'code'            => $startTime !== '' ? 'locked' : 'open',
        'message'         => $startTime !== ''
            ? 'Your start time is locked at ' . eventStartTimeLabel($startTime) . '.'
            : '',
        'label'           => $startTime !== '' ? eventStartTimeLabel($startTime) : '',
        'name'            => $displayName,
        'registration_id' => (int) ($row['id'] ?? 0),
        'start_time'      => $startTime,
    ];
}

/**
 * @return array{ok: bool, code: string, message: string, label?: string, name?: string}
 */
function saveAvivaOct1StartTime(PDO $pdo, string $typedName, string $startTime, bool $adminOverride = false, int $forceRegistrationId = 0): array
{
    ensureEventStartTimeSchema($pdo);
    $event = avivaOct1StartTimeEvent($pdo);
    if ($event === null) {
        return ['ok' => false, 'code' => 'error', 'message' => 'This event is not open for start times.'];
    }

    $startTime = trim($startTime);
    if (!isset(avivaOct1StartTimeCaps()[$startTime])) {
        return ['ok' => false, 'code' => 'invalid', 'message' => 'Choose 2:00pm or 4:00pm.'];
    }

    $eventId = (int) $event['id'];
    if ($adminOverride && $forceRegistrationId > 0) {
        $one = $pdo->prepare(
            "SELECT id, first_name, surname, planned_start_time
             FROM staff_registrations
             WHERE id = :id
               AND event_id = :event_id
               AND status IN ('approved', 'pending')
             LIMIT 1"
        );
        $one->execute(['id' => $forceRegistrationId, 'event_id' => $eventId]);
        $row = $one->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return ['ok' => false, 'code' => 'not_found', 'message' => 'Information not found'];
        }
    } else {
        $roster = $pdo->prepare(
            "SELECT id, first_name, surname, planned_start_time
             FROM staff_registrations
             WHERE event_id = :event_id
               AND status IN ('approved', 'pending')"
        );
        $roster->execute(['event_id' => $eventId]);
        $rows = $roster->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $match = matchEventRosterName($rows, $typedName);
        if (empty($match['ok'])) {
            $message = ($match['code'] ?? '') === 'ambiguous'
                ? 'More than one person has this name.'
                : 'Information not found';

            return ['ok' => false, 'code' => (string) ($match['code'] ?? 'not_found'), 'message' => $message];
        }
        $row = $match['row'];
    }
    $registrationId = (int) ($row['id'] ?? 0);
    $displayName = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['surname'] ?? ''));
    $label = eventStartTimeLabel($startTime);
    if ($registrationId < 1) {
        return ['ok' => false, 'code' => 'not_found', 'message' => 'Information not found'];
    }

    $existing = trim((string) ($row['planned_start_time'] ?? ''));
    if ($existing !== '' && $existing === $startTime) {
        eventStartTimePushToSheet($pdo, $registrationId, $startTime);

        return [
            'ok'      => true,
            'code'    => 'locked',
            'message' => 'Your start time is locked at ' . $label . '. Only an admin can change it.',
            'label'   => $label,
            'name'    => $displayName,
        ];
    }
    if ($existing !== '' && !$adminOverride) {
        $kept = eventStartTimeLabel($existing);

        return [
            'ok'      => false,
            'code'    => 'locked',
            'message' => 'Your start time is locked at ' . $kept . '. Only an admin can change it.',
            'label'   => $kept,
            'name'    => $displayName,
        ];
    }

    $lockName = 'evt_start_' . $eventId . '_' . str_replace(':', '', $startTime);
    $lock = $pdo->prepare('SELECT GET_LOCK(:name, 5)');
    $lock->execute(['name' => $lockName]);
    if ((int) $lock->fetchColumn() !== 1) {
        return ['ok' => false, 'code' => 'error', 'message' => 'Could not save the start time. Try again.'];
    }

    try {
        $pdo->beginTransaction();
        $slot = $pdo->prepare(
            'SELECT cap_count
             FROM event_start_slots
             WHERE event_id = :event_id AND start_time = :start_time
             LIMIT 1
             FOR UPDATE'
        );
        $slot->execute(['event_id' => $eventId, 'start_time' => $startTime]);
        $cap = (int) $slot->fetchColumn();
        if ($cap < 1) {
            $pdo->rollBack();

            return ['ok' => false, 'code' => 'invalid', 'message' => 'Choose 2:00pm or 4:00pm.'];
        }

        $takenStmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM staff_registrations
             WHERE event_id = :event_id
               AND status IN ('approved', 'pending')
               AND planned_start_time = :start_time
               AND id <> :id"
        );
        $takenStmt->execute([
            'event_id'   => $eventId,
            'start_time' => $startTime,
            'id'         => $registrationId,
        ]);
        $taken = (int) $takenStmt->fetchColumn();
        if ($taken >= $cap) {
            $pdo->rollBack();

            return [
                'ok'      => false,
                'code'    => 'full',
                'message' => $label . ' is full.',
                'label'   => $label,
                'name'    => $displayName,
            ];
        }

        $update = $pdo->prepare(
            'UPDATE staff_registrations
             SET planned_start_time = :start_time
             WHERE id = :id AND event_id = :event_id
             LIMIT 1'
        );
        $update->execute([
            'start_time' => $startTime,
            'id'         => $registrationId,
            'event_id'   => $eventId,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[EventStaff] start time save: ' . $e->getMessage());

        return ['ok' => false, 'code' => 'error', 'message' => 'Could not save the start time. Try again.'];
    } finally {
        try {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $release->execute(['name' => $lockName]);
            $release->fetchColumn();
        } catch (Throwable $e) {
            error_log('[EventStaff] start time lock: ' . $e->getMessage());
        }
    }

    eventStartTimePushToSheet($pdo, $registrationId, $startTime);

    return [
        'ok'      => true,
        'code'    => 'saved',
        'message' => $displayName . ' is locked at ' . $label . '. Only an admin can change it.',
        'label'   => $label,
        'name'    => $displayName,
    ];
}

/**
 * @return array{ok: bool, code: string, message: string, label?: string, name?: string}
 */
/**
 * @return array{id: int, name: string, start_time: string, label: string}|null
 */
function avivaOct1RegistrationById(PDO $pdo, int $registrationId): ?array
{
    if ($registrationId < 1) {
        return null;
    }
    ensureEventStartTimeSchema($pdo);
    $stmt = $pdo->prepare(
        "SELECT id, first_name, surname, planned_start_time
         FROM staff_registrations
         WHERE id = :id
           AND event_id = :event_id
           AND status IN ('approved', 'pending')
         LIMIT 1"
    );
    $stmt->execute([
        'id'       => $registrationId,
        'event_id' => avivaOct1StartTimeEventId(),
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $startTime = trim((string) ($row['planned_start_time'] ?? ''));

    return [
        'id'         => (int) $row['id'],
        'name'       => trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['surname'] ?? '')),
        'start_time' => $startTime,
        'label'      => $startTime !== '' ? eventStartTimeLabel($startTime) : '',
    ];
}

function adminChangeRegistrationStartTime(PDO $pdo, int $registrationId, string $startTime): array
{
    if ($registrationId < 1) {
        return ['ok' => false, 'code' => 'error', 'message' => 'Staff not found.'];
    }

    return saveAvivaOct1StartTime($pdo, '', $startTime, true, $registrationId);
}

function eventStartTimePushToSheet(PDO $pdo, int $registrationId, string $startTime): void
{
    try {
        require_once __DIR__ . '/attendant-actions.php';
        saveAttendanceGateStartTime($pdo, $registrationId, $startTime);
    } catch (Throwable $e) {
        error_log('[EventStaff] start time sheet: ' . $e->getMessage());
    }

    try {
        eventStartTimeWriteFormResponses($pdo, $registrationId, $startTime);
    } catch (Throwable $e) {
        error_log('[EventStaff] start time form sheet: ' . $e->getMessage());
    }
}

function eventStartTimeWriteFormResponses(PDO $pdo, int $registrationId, string $startTime): bool
{
    $label = eventStartTimeLabel($startTime);
    if ($label === $startTime && !isset(avivaOct1StartTimeCaps()[$startTime])) {
        return false;
    }

    $person = $pdo->prepare(
        'SELECT first_name, surname
         FROM staff_registrations
         WHERE id = :id AND event_id = :event_id
         LIMIT 1'
    );
    $person->execute([
        'id' => $registrationId,
        'event_id' => avivaOct1StartTimeEventId(),
    ]);
    $who = $person->fetch(PDO::FETCH_ASSOC);
    if (!$who) {
        return false;
    }
    $wanted = eventRosterNameKey((string) $who['first_name'] . ' ' . (string) $who['surname']);
    if ($wanted === '') {
        return false;
    }

    $integration = $pdo->prepare(
        'SELECT google_sheet_id, google_sheet_tab
         FROM google_form_event_integrations
         WHERE event_id = :event_id
         ORDER BY id DESC
         LIMIT 1'
    );
    $integration->execute(['event_id' => avivaOct1StartTimeEventId()]);
    $sheet = $integration->fetch(PDO::FETCH_ASSOC);
    $sheetId = trim((string) ($sheet['google_sheet_id'] ?? ''));
    $tab = trim((string) ($sheet['google_sheet_tab'] ?? ''));
    if ($sheetId === '' || $tab === '') {
        return false;
    }

    require_once __DIR__ . '/google-form-sheet-sync.php';
    require_once __DIR__ . '/google-sheets-sync.php';
    $writers = googleFormIntakeWriteAuthCandidates($pdo);
    if ($writers === []) {
        return false;
    }
    $quotedTab = "'" . str_replace("'", "''", $tab) . "'";
    $values = null;
    foreach ($writers as $reader) {
        $values = googleSheetsReadRangeValues(
            (string) $reader['token'],
            $reader['project'],
            $sheetId,
            $quotedTab . '!A1:AZ2000'
        );
        if (is_array($values) && $values !== []) {
            break;
        }
        $values = null;
    }
    if (!is_array($values) || $values === []) {
        return false;
    }

    $header = array_map(static fn ($cell): string => trim((string) $cell), $values[0]);
    $headerKey = static function (string $title): string {
        $title = strtolower(trim($title));
        $title = preg_replace('/[^a-z0-9]+/', ' ', $title);

        return trim(is_string($title) ? $title : '');
    };
    $firstCol = -1;
    $surnameCol = -1;
    $timeCol = -1;
    foreach ($header as $index => $title) {
        $key = $headerKey($title);
        if ($key === 'first name') {
            $firstCol = $index;
        } elseif ($key === 'surname') {
            $surnameCol = $index;
        } elseif ($key === 'start time') {
            $timeCol = $index;
        }
    }
    if ($firstCol < 0 || $surnameCol < 0) {
        return false;
    }
    if ($timeCol < 0) {
        $timeCol = count($header);
        $headerRange = $quotedTab . '!' . googleSheetsSyncColumnLetter($timeCol) . '1';
        $headerWritten = false;
        foreach ($writers as $writer) {
            if (googleSheetsWriteRangeValues(
                (string) $writer['token'],
                $writer['project'],
                $sheetId,
                $headerRange,
                [['Start time']]
            )) {
                $headerWritten = true;
                break;
            }
        }
        if (!$headerWritten) {
            return false;
        }
    }

    $rows = [];
    foreach ($values as $index => $line) {
        if ($index === 0 || !is_array($line)) {
            continue;
        }
        $first = (string) ($line[$firstCol] ?? '');
        $surname = (string) ($line[$surnameCol] ?? '');
        if (eventRosterNameKey($first . ' ' . $surname) !== $wanted) {
            continue;
        }
        $rows[] = $index + 1;
    }
    if ($rows === []) {
        return false;
    }

    $column = googleSheetsSyncColumnLetter($timeCol);
    $written = false;
    foreach ($rows as $sheetRow) {
        $cell = $quotedTab . '!' . $column . $sheetRow;
        foreach ($writers as $writer) {
            if (googleSheetsWriteRangeValues(
                (string) $writer['token'],
                $writer['project'],
                $sheetId,
                $cell,
                [[$label]]
            )) {
                $written = true;
                break;
            }
        }
    }

    return $written;
}

function eventStartTimeSheetValue(?PDO $pdo, array $row): string
{
    $planned = trim((string) ($row['planned_start_time'] ?? ''));
    if (!$pdo instanceof PDO) {
        $gate = trim((string) ($row['gate_start_time'] ?? ''));

        return $gate !== '' ? $gate : $planned;
    }

    require_once __DIR__ . '/attendant-actions.php';
    $gate = attendanceGateStartTimeForRegistration($pdo, (int) ($row['id'] ?? 0));

    return $gate !== '' ? $gate : $planned;
}

function avivaOct1ParseStartTime(string $value): string
{
    $raw = strtolower(trim($value));
    if ($raw === '') {
        return '';
    }
    if (preg_match('/^14([:.]00)?(:00)?$/', $raw) === 1 || preg_match('/^0?2([:.]00)?(:00)?\s*pm$/', $raw) === 1) {
        return '14:00';
    }
    if (preg_match('/^16([:.]00)?(:00)?$/', $raw) === 1 || preg_match('/^0?4([:.]00)?(:00)?\s*pm$/', $raw) === 1) {
        return '16:00';
    }
    $compact = str_replace([' ', '.', ':'], '', $raw);
    if (in_array($compact, ['2pm', '200pm', '20000pm', '1400', '140000', '0200pm'], true)) {
        return '14:00';
    }
    if (in_array($compact, ['4pm', '400pm', '40000pm', '1600', '160000', '0400pm'], true)) {
        return '16:00';
    }

    return '';
}

/**
 * Keep the 1 October start time the same on the database, the form responses
 * sheet, and the event roster sheet. A time already stored on the registration
 * wins. A form-sheet time is copied in only while the registration is still open.
 * No email is sent.
 *
 * @return array<string, mixed>
 */
function avivaOct1StartTimeReconcile(PDO $pdo): array
{
    $result = [
        'ok' => false,
        'locked_from_form' => 0,
        'form_cells' => 0,
        'form_appended' => 0,
        'event_cells' => 0,
        'event_rebuilt' => false,
        'unmatched_form' => [],
        'emails_sent' => 0,
    ];

    ensureEventStartTimeSchema($pdo);
    $event = avivaOct1StartTimeEvent($pdo);
    if ($event === null) {
        $result['error'] = 'event_missing';

        return $result;
    }

    $eventId = (int) $event['id'];
    $rosterStmt = $pdo->prepare(
        "SELECT id, first_name, surname, planned_start_time
         FROM staff_registrations
         WHERE event_id = :event_id
           AND status IN ('approved', 'pending')"
    );
    $rosterStmt->execute(['event_id' => $eventId]);
    $roster = $rosterStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $byId = [];
    foreach ($roster as $row) {
        $byId[(int) $row['id']] = $row;
    }

    require_once __DIR__ . '/google-form-sheet-sync.php';
    require_once __DIR__ . '/google-sheets-sync.php';
    $writers = googleFormIntakeWriteAuthCandidates($pdo);
    if ($writers === []) {
        $result['error'] = 'no_sheet_auth';

        return $result;
    }

    $readSheet = static function (array $writers, string $sheetId, string $tab): ?array {
        $quoted = "'" . str_replace("'", "''", $tab) . "'";
        foreach ($writers as $writer) {
            $values = googleSheetsReadRangeValues(
                (string) $writer['token'],
                $writer['project'],
                $sheetId,
                $quoted . '!A1:AZ2000'
            );
            if (is_array($values) && $values !== []) {
                return $values;
            }
        }

        return null;
    };

    $headerKey = static function (string $title): string {
        $title = strtolower(trim($title));
        $title = preg_replace('/[^a-z0-9]+/', ' ', $title);

        return trim(is_string($title) ? $title : '');
    };

    $integration = $pdo->prepare(
        'SELECT google_sheet_id, google_sheet_tab
         FROM google_form_event_integrations
         WHERE event_id = :event_id
         ORDER BY id DESC
         LIMIT 1'
    );
    $integration->execute(['event_id' => $eventId]);
    $formMeta = $integration->fetch(PDO::FETCH_ASSOC) ?: [];
    $formSheetId = trim((string) ($formMeta['google_sheet_id'] ?? ''));
    $formTab = trim((string) ($formMeta['google_sheet_tab'] ?? ''));
    $formValues = ($formSheetId !== '' && $formTab !== '') ? $readSheet($writers, $formSheetId, $formTab) : null;

    $formRows = [];
    $formTimeCol = -1;
    $formHeader = [];
    if (is_array($formValues) && $formValues !== []) {
        $header = array_map(static fn ($cell): string => trim((string) $cell), $formValues[0]);
        $formHeader = $header;
        $firstCol = -1;
        $surnameCol = -1;
        foreach ($header as $index => $title) {
            $key = $headerKey($title);
            if ($key === 'start time') {
                $formTimeCol = $index;
            } elseif ($key === 'first name') {
                $firstCol = $index;
            } elseif ($key === 'surname') {
                $surnameCol = $index;
            }
        }
        if ($firstCol >= 0 && $surnameCol >= 0 && $formTimeCol >= 0) {
            foreach ($formValues as $index => $line) {
                if ($index === 0 || !is_array($line)) {
                    continue;
                }
                $name = trim((string) ($line[$firstCol] ?? '') . ' ' . (string) ($line[$surnameCol] ?? ''));
                if (eventRosterNameKey($name) === '') {
                    continue;
                }
                $match = matchEventRosterName($roster, $name);
                if (empty($match['ok'])) {
                    $shown = avivaOct1ParseStartTime((string) ($line[$formTimeCol] ?? ''));
                    if ($shown !== '' && count($result['unmatched_form']) < 20) {
                        $result['unmatched_form'][] = $name;
                    }
                    continue;
                }
                $registrationId = (int) ($match['row']['id'] ?? 0);
                $formRows[$registrationId] = [
                    'row' => $index + 1,
                    'time' => avivaOct1ParseStartTime((string) ($line[$formTimeCol] ?? '')),
                ];
            }
        }
    }

    foreach ($formRows as $registrationId => $formRow) {
        $current = trim((string) ($byId[$registrationId]['planned_start_time'] ?? ''));
        $incoming = (string) $formRow['time'];
        if ($current !== '' || $incoming === '') {
            continue;
        }
        $saved = adminChangeRegistrationStartTime($pdo, $registrationId, $incoming);
        if (!empty($saved['ok'])) {
            $byId[$registrationId]['planned_start_time'] = $incoming;
            $result['locked_from_form']++;
        }
    }

    if ($formHeader !== [] && $formTimeCol >= 0 && $formSheetId !== '' && is_array($formValues)) {
        $missingIds = [];
        foreach ($byId as $registrationId => $row) {
            if (trim((string) ($row['planned_start_time'] ?? '')) === '' || isset($formRows[$registrationId])) {
                continue;
            }
            $missingIds[] = (int) $registrationId;
        }
        if ($missingIds !== []) {
            require_once __DIR__ . '/staff-psa.php';
            $idList = implode(',', $missingIds);
            $people = $pdo->query(
                "SELECT sr.id, sr.first_name, sr.surname, sr.email, sr.mobile, sr.full_address, sr.eircode,
                        sr.gender, sr.staff_role, sr.planned_start_time,
                        s.email AS staff_email, s.mobile AS staff_mobile, s.full_address AS staff_address,
                        s.eircode AS staff_eircode, s.gender AS staff_gender, s.bank_iban AS staff_iban,
                        s.staff_role AS staff_role_master, s.psa_licence, s.psa_front_image, s.psa_back_image,
                        s.pps_number AS staff_pps, s.date_of_birth AS staff_dob
                 FROM staff_registrations sr
                 LEFT JOIN staff s ON s.id = sr.staff_id
                 WHERE sr.event_id = {$eventId} AND sr.id IN ({$idList})"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $pick = static function (array $person, string $staffKey, string $regKey): string {
                $staffValue = trim((string) ($person[$staffKey] ?? ''));
                if ($staffValue !== '') {
                    return $staffValue;
                }

                return trim((string) ($person[$regKey] ?? ''));
            };
            $quotedForm = "'" . str_replace("'", "''", $formTab) . "'";
            $width = count($formHeader);
            $nextRow = count($formValues) + 1;
            $stamp = (new DateTimeImmutable('now', new DateTimeZone('Europe/Dublin')))->format('n/j/Y g:i:s A');
            $appendData = [];
            foreach ($people as $person) {
                $dbTime = trim((string) ($person['planned_start_time'] ?? ''));
                if ($dbTime === '') {
                    continue;
                }
                $role = $pick($person, 'staff_role_master', 'staff_role');
                if (function_exists('formatRoleLabel')) {
                    $role = formatRoleLabel($role);
                }
                $gender = $pick($person, 'staff_gender', 'gender');
                if (function_exists('formatGenderLabel')) {
                    $gender = formatGenderLabel($gender);
                }
                $address = str_replace(["\r\n", "\r", "\n"], ', ', $pick($person, 'staff_address', 'full_address'));
                $address = trim((string) (preg_replace('/\s{2,}/', ' ', $address) ?? $address), ' ,');
                $mapped = [
                    'timestamp' => $stamp,
                    'email address' => $pick($person, 'staff_email', 'email'),
                    'first name' => trim((string) ($person['first_name'] ?? '')),
                    'surname' => trim((string) ($person['surname'] ?? '')),
                    'mobile number' => $pick($person, 'staff_mobile', 'mobile'),
                    'full address' => $address,
                    'eircode' => $pick($person, 'staff_eircode', 'eircode'),
                    'gender' => $gender,
                    'iban' => trim((string) ($person['staff_iban'] ?? '')),
                    'role' => $role,
                    'psa licence number' => exportPsaLicenceForSheet((string) ($person['psa_licence'] ?? '')),
                    'psa badge front' => psaImagePublicUrl((string) ($person['psa_front_image'] ?? ''), $pdo),
                    'psa badge back' => psaImagePublicUrl((string) ($person['psa_back_image'] ?? ''), $pdo),
                    'pps number' => trim((string) ($person['staff_pps'] ?? '')),
                    'date of birth' => trim((string) ($person['staff_dob'] ?? '')),
                    'start time' => eventStartTimeLabel($dbTime),
                ];
                $line = array_fill(0, $width, '');
                foreach ($formHeader as $index => $title) {
                    $key = $headerKey($title);
                    if (array_key_exists($key, $mapped)) {
                        $line[$index] = $mapped[$key];
                    }
                }
                $range = $quotedForm . '!A' . $nextRow . ':' . googleSheetsSyncColumnLetter($width - 1) . $nextRow;
                $appendData[] = ['range' => $range, 'values' => [$line]];
                $nextRow++;
                $result['form_appended']++;
            }
            if ($appendData !== []) {
                $payload = json_encode(['valueInputOption' => 'RAW', 'data' => $appendData], JSON_UNESCAPED_UNICODE);
                if ($payload !== false) {
                    $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($formSheetId) . '/values:batchUpdate';
                    $wroteAppend = false;
                    foreach ($writers as $writer) {
                        $response = googleSheetsHttpRequest(
                            'POST',
                            $url,
                            googleSheetsAuthHeaders((string) $writer['token'], $writer['project']),
                            $payload
                        );
                        if ((int) ($response['code'] ?? 0) === 200) {
                            $wroteAppend = true;
                            break;
                        }
                    }
                    if (!$wroteAppend) {
                        $result['form_appended'] = 0;
                    }
                }
            }
        }
    }

    $eventUrl = trim((string) ($event['google_sheet_url'] ?? ''));
    $eventSheetId = $eventUrl !== '' ? parseGoogleSpreadsheetId($eventUrl) : null;
    $eventTab = trim((string) ($event['google_sheet_tab'] ?? 'Registrations'));
    if ($eventTab === '') {
        $eventTab = 'Registrations';
    }
    $eventValues = is_string($eventSheetId) && $eventSheetId !== ''
        ? $readSheet($writers, $eventSheetId, $eventTab)
        : null;

    $eventRows = [];
    $eventTimeCol = -1;
    $missingOnEventSheet = false;
    if (is_array($eventValues) && $eventValues !== []) {
        $header = array_map(static fn ($cell): string => trim((string) $cell), $eventValues[0]);
        $firstCol = -1;
        $surnameCol = -1;
        $idCol = -1;
        foreach ($header as $index => $title) {
            $key = $headerKey($title);
            if ($key === 'start time') {
                $eventTimeCol = $index;
            } elseif ($key === 'first name') {
                $firstCol = $index;
            } elseif ($key === 'surname') {
                $surnameCol = $index;
            } elseif ($key === 'registration id') {
                $idCol = $index;
            }
        }
        if ($eventTimeCol >= 0) {
            foreach ($eventValues as $index => $line) {
                if ($index === 0 || !is_array($line)) {
                    continue;
                }
                $registrationId = 0;
                if ($idCol >= 0 && ctype_digit(trim((string) ($line[$idCol] ?? '')))) {
                    $registrationId = (int) trim((string) $line[$idCol]);
                }
                if ($registrationId < 1 && $firstCol >= 0 && $surnameCol >= 0) {
                    $match = matchEventRosterName($roster, trim((string) ($line[$firstCol] ?? '') . ' ' . (string) ($line[$surnameCol] ?? '')));
                    if (!empty($match['ok'])) {
                        $registrationId = (int) ($match['row']['id'] ?? 0);
                    }
                }
                if ($registrationId < 1 || isset($eventRows[$registrationId])) {
                    continue;
                }
                $eventRows[$registrationId] = [
                    'row' => $index + 1,
                    'time' => avivaOct1ParseStartTime((string) ($line[$eventTimeCol] ?? '')),
                ];
            }
        }
        foreach ($byId as $registrationId => $row) {
            if (!isset($eventRows[$registrationId])) {
                $missingOnEventSheet = true;
                break;
            }
        }
    } else {
        $missingOnEventSheet = $byId !== [];
    }

    $batches = [];
    $queueCell = static function (string $sheetId, string $range, string $value) use (&$batches): void {
        $batches[$sheetId][] = ['range' => $range, 'values' => [[$value]]];
    };

    if ($formTimeCol >= 0 && $formSheetId !== '' && $formTab !== '') {
        $quoted = "'" . str_replace("'", "''", $formTab) . "'";
        $column = googleSheetsSyncColumnLetter($formTimeCol);
        foreach ($formRows as $registrationId => $formRow) {
            $dbTime = trim((string) ($byId[$registrationId]['planned_start_time'] ?? ''));
            if ($dbTime === '') {
                continue;
            }
            $want = eventStartTimeLabel($dbTime);
            $have = (string) $formRow['time'];
            $haveLabel = $have === '' ? '' : eventStartTimeLabel($have);
            if ($want === $haveLabel) {
                continue;
            }
            $queueCell($formSheetId, $quoted . '!' . $column . (int) $formRow['row'], $want);
            $result['form_cells']++;
        }
    }

    if ($missingOnEventSheet && is_string($eventSheetId) && $eventSheetId !== '') {
        $auth = null;
        foreach ($writers as $writer) {
            $probe = googleSheetsReadRangeValues(
                (string) $writer['token'],
                $writer['project'],
                $eventSheetId,
                "'" . str_replace("'", "''", $eventTab) . "'!A1:A1"
            );
            if (is_array($probe)) {
                $auth = [
                    'token' => (string) $writer['token'],
                    'project' => $writer['project'],
                    'label' => 'start-time-watch',
                ];
                break;
            }
        }
        if ($auth !== null) {
            $rebuilt = googleSheetsRebuildEventTab($pdo, $eventId, $auth, false);
            $result['event_rebuilt'] = !empty($rebuilt['ok']);
        }
    } elseif ($eventTimeCol >= 0 && is_string($eventSheetId) && $eventSheetId !== '') {
        $quoted = "'" . str_replace("'", "''", $eventTab) . "'";
        $column = googleSheetsSyncColumnLetter($eventTimeCol);
        foreach ($eventRows as $registrationId => $eventRow) {
            if (!isset($byId[$registrationId])) {
                continue;
            }
            $dbTime = trim((string) ($byId[$registrationId]['planned_start_time'] ?? ''));
            if ((string) $eventRow['time'] === $dbTime) {
                continue;
            }
            $queueCell($eventSheetId, $quoted . '!' . $column . (int) $eventRow['row'], $dbTime);
            $result['event_cells']++;
        }
    }

    foreach ($batches as $sheetId => $data) {
        if ($data === []) {
            continue;
        }
        $payload = json_encode([
            'valueInputOption' => 'RAW',
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            continue;
        }
        $url = 'https://sheets.googleapis.com/v4/spreadsheets/'
            . rawurlencode((string) $sheetId)
            . '/values:batchUpdate';
        foreach ($writers as $writer) {
            $response = googleSheetsHttpRequest(
                'POST',
                $url,
                googleSheetsAuthHeaders((string) $writer['token'], $writer['project']),
                $payload
            );
            if ((int) ($response['code'] ?? 0) === 200) {
                break;
            }
        }
    }

    $result['ok'] = true;
    if (function_exists('setSetting')) {
        setSetting($pdo, 'aviva_start_time_watch_at', gmdate('Y-m-d H:i:s'));
    }

    return $result;
}

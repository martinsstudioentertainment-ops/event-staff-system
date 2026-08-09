<?php

declare(strict_types=1);

require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/events-repository.php';
require_once __DIR__ . '/system-settings.php';
require_once __DIR__ . '/staff-roster-download.php';
require_once __DIR__ . '/attendance-repository.php';

function formatSignInMethodLabel(?string $method): string
{
    $method = strtolower(trim((string) $method));

    return match ($method) {
        'admin', 'admin_manual' => 'Manual sign-in',
        'scan'                 => 'QR scan sign-in',
        'self'                 => 'Self sign-in',
        default                => $method !== '' ? ucfirst(str_replace('_', ' ', $method)) : 'Unknown',
    };
}

/**
 * @param array<string, mixed> $row
 */
function resolveContractorSheetSignInType(array $row): string
{
    $method = strtolower(trim((string) ($row['checked_in_method'] ?? '')));
    if ($method !== '') {
        return formatSignInMethodLabel($method);
    }

    $note = strtolower((string) ($row['hours_note'] ?? ''));
    if (str_contains($note, 'manual sign-in') || str_contains($note, 'manual sign in') || str_contains($note, 'venue qr')) {
        return 'Manual sign-in';
    }
    if (str_contains($note, 'qr scan') || str_contains($note, 'qr sign-in')) {
        return 'QR scan sign-in';
    }

    return 'Manual sign-in';
}

/**
 * @param array<string, mixed> $row
 */
function contractorSheetCheckInAt(array $row): string
{
    $checkIn   = trim((string) ($row['checked_in_at'] ?? ''));
    $eventDate = trim((string) ($row['event_date'] ?? ''));
    if ($checkIn === '' || $eventDate === '') {
        return $checkIn;
    }

    if (substr($checkIn, 0, 10) === $eventDate) {
        return $checkIn;
    }

    $time = strlen($checkIn) >= 19 ? substr($checkIn, 11, 8) : '20:00:00';

    return $eventDate . ' ' . $time;
}

/**
 * SQL expression for the best available sign-in timestamp on attendance rows.
 */
function contractorSheetEffectiveSignInSql(PDO $pdo): string
{
    require_once __DIR__ . '/attendance-gps-phase1-schema.php';
    ensureAttendanceGpsPhase1Schema($pdo);

    $parts = ['a.checked_in_at'];

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM attendance')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (in_array('activated_at', $cols, true)) {
            $parts[] = 'a.activated_at';
        }
        if (in_array('check_in_gps_at', $cols, true)) {
            $parts[] = 'a.check_in_gps_at';
        }
    } catch (Throwable $e) {
        error_log('[EventStaff] contractorSheetEffectiveSignInSql: ' . $e->getMessage());
    }

    return count($parts) === 1 ? $parts[0] : 'COALESCE(' . implode(', ', $parts) . ')';
}

/**
 * True when a row is a real contractor sign-in (self, manual, scan, or edited hours).
 *
 * @param array<string, mixed> $row
 */
function isContractorSheetSignInRow(array $row): bool
{
    $checkedInAt = trim((string) ($row['export_checked_in_at'] ?? $row['checked_in_at'] ?? ''));
    $hasValidTime = $checkedInAt !== '' && substr($checkedInAt, 0, 5) !== '0000-';
    if ($hasValidTime) {
        try {
            new DateTime($checkedInAt);
        } catch (Throwable $e) {
            $hasValidTime = false;
        }
    }

    $status = strtolower(trim((string) ($row['attendance_status'] ?? '')));
    if ($status === 'no_show') {
        return false;
    }

    $method = strtolower(trim((string) ($row['checked_in_method'] ?? '')));
    if ($method === 'auto_no_show') {
        return false;
    }

    if (!$hasValidTime && !in_array($status, ['active', 'pre_checked_in', 'completed', 'auto_signed_out'], true)) {
        return false;
    }

    return true;
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function filterContractorSheetSignInRows(array $rows): array
{
    return array_values(array_filter($rows, static function (array $row): bool {
        return isContractorSheetSignInRow($row);
    }));
}

/**
 * Approved staff who actually signed in for an event (self, manual, or scan).
 * Excludes waiting roster and no-shows; one row per person (latest attendance).
 *
 * @return array<int, array<string, mixed>>
 */
function getEventSignInExportRows(PDO $pdo, int $eventId): array
{
    if ($eventId < 1) {
        return [];
    }

    require_once __DIR__ . '/staff-registration-schema.php';
    ensureStaffRegistrationSaveSchema($pdo);
    $signInAtSql    = contractorSheetEffectiveSignInSql($pdo);
    $bibSelect      = staffRegistrationColumnExists($pdo, 'assigned_bib_number')
        ? ', sr.assigned_bib_number'
        : '';

    $signInAtSubSql = contractorSheetEffectiveSignInSql($pdo);
    $signedInSubquery = "SELECT a1.id
                        FROM attendance a1
                        WHERE a1.registration_id = sr.id
                          AND LOWER(COALESCE(a1.attendance_status, 'active')) NOT IN ('no_show')
                          AND LOWER(COALESCE(a1.checked_in_method, '')) NOT IN ('auto_no_show')
                          AND (
                              {$signInAtSubSql} IS NOT NULL
                              OR LOWER(COALESCE(a1.attendance_status, 'active')) IN ('active', 'pre_checked_in', 'completed', 'auto_signed_out')
                          )
                        ORDER BY a1.id DESC
                        LIMIT 1";

    $stmt = $pdo->prepare(
        "SELECT sr.first_name, sr.surname, sr.email, sr.mobile, sr.staff_role, sr.pps_number{$bibSelect},
                e.name AS event_name, e.event_date, e.start_time, e.end_time,
                e.event_start_time, e.event_end_time, e.checkin_open_time, e.checkin_close_time,
                {$signInAtSql} AS export_checked_in_at,
                a.checked_in_at, a.checked_in_method, a.work_end_at, a.attendance_status,
                a.hours_worked, a.hours_paid, a.hours_note
         FROM staff_registrations sr
         INNER JOIN events e ON e.id = sr.event_id
         LEFT JOIN attendance a ON a.id = ({$signedInSubquery})
         WHERE sr.status = 'approved'
           AND sr.event_id = :event_id
           AND a.id IS NOT NULL
           AND LOWER(COALESCE(a.attendance_status, 'active')) NOT IN ('no_show')
           AND (
               {$signInAtSql} IS NOT NULL
               OR LOWER(COALESCE(a.attendance_status, 'active')) IN ('active', 'pre_checked_in', 'completed', 'auto_signed_out')
           )
           AND LOWER(COALESCE(a.checked_in_method, '')) NOT IN ('auto_no_show')
         ORDER BY {$signInAtSql} ASC, sr.surname ASC, sr.first_name ASC"
    );
    $stmt->execute(['event_id' => $eventId]);

    return filterContractorSheetSignInRows($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

/**
 * Same checked-in rules as the Attendance roster (no dependency on helper deploy sync).
 *
 * @param array<string, mixed> $row
 */
function isContractorSheetCheckedInRow(array $row): bool
{
    $status = strtolower(trim((string) ($row['attendance_status'] ?? '')));
    if ($status === 'no_show') {
        return false;
    }

    $method = strtolower(trim((string) ($row['checked_in_method'] ?? '')));
    if ($method === 'auto_no_show') {
        return false;
    }

    if ((int) ($row['is_checked_in'] ?? 0) === 1) {
        return true;
    }

    if (in_array($status, ['active', 'pre_checked_in', 'completed', 'auto_signed_out'], true)) {
        return true;
    }

    return trim((string) ($row['checked_in_at'] ?? '')) !== '';
}

/**
 * @deprecated Use isContractorSheetCheckedInRow() for roster rows.
 * @param array<string, mixed> $row
 */
function isContractorSheetAttendanceRow(array $row): bool
{
    return isContractorSheetCheckedInRow($row) && contractorSheetRowHasPaidHours($row);
}

/**
 * @param array<string, mixed> $row
 */
function contractorSheetRowHasPaidHours(array $row): bool
{
    $hoursPaid   = (float) ($row['hours_paid'] ?? 0);
    $hoursWorked = (float) ($row['hours_worked'] ?? 0);

    return $hoursPaid > 0 || $hoursWorked > 0;
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function filterContractorSheetPaidRows(array $rows): array
{
    return array_values(array_filter($rows, static function (array $row): bool {
        return contractorSheetRowHasPaidHours($row);
    }));
}

/**
 * Contractor sheet order: first name A–Z, then surname A–Z (matches Name column).
 *
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function sortContractorSheetRowsAlphabetically(array $rows): array
{
    usort($rows, static function (array $a, array $b): int {
        $byFirst = strnatcasecmp((string) ($a['first_name'] ?? ''), (string) ($b['first_name'] ?? ''));
        if ($byFirst !== 0) {
            return $byFirst;
        }

        return strnatcasecmp((string) ($a['surname'] ?? ''), (string) ($b['surname'] ?? ''));
    });

    return array_values($rows);
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function finalizeContractorSheetRows(array $rows, bool $paidOnly = false): array
{
    if ($paidOnly) {
        $rows = filterContractorSheetPaidRows($rows);
    }

    return sortContractorSheetRowsAlphabetically($rows);
}

/**
 * Signed-in staff from the attendance roster (same rules as Attendance page).
 *
 * @return array<int, array<string, mixed>>
 */
function buildContractorSheetRowsFromAttendance(PDO $pdo, int $eventId, bool $paidOnly = false): array
{
    if ($eventId < 1) {
        return [];
    }

    $rows = [];
    foreach (getAttendanceList($pdo, $eventId, null, 0, false) as $row) {
        if (!isContractorSheetCheckedInRow($row)) {
            continue;
        }

        $checkInAt = contractorSheetCheckInAt($row);

        $rows[] = [
            'registration_id'      => (int) ($row['id'] ?? 0),
            'attendance_id'        => (int) ($row['attendance_id'] ?? 0),
            'first_name'           => (string) ($row['first_name'] ?? ''),
            'surname'              => (string) ($row['surname'] ?? ''),
            'email'                => (string) ($row['email'] ?? ''),
            'mobile'               => (string) ($row['mobile'] ?? ''),
            'staff_role'           => (string) ($row['staff_role'] ?? ''),
            'event_name'           => (string) ($row['event_name'] ?? ''),
            'event_date'           => (string) ($row['event_date'] ?? ''),
            'start_time'           => (string) ($row['start_time'] ?? ''),
            'end_time'             => (string) ($row['end_time'] ?? ''),
            'scheduled_hours'      => $row['scheduled_hours'] ?? null,
            'checked_in_method'    => (string) ($row['checked_in_method'] ?? ''),
            'export_checked_in_at' => $checkInAt,
            'checked_in_at'        => $checkInAt,
            'work_end_at'          => (string) ($row['work_end_at'] ?? ''),
            'hours_worked'         => $row['hours_worked'] ?? null,
            'hours_paid'           => $row['hours_paid'] ?? null,
            'hours_note'           => (string) ($row['hours_note'] ?? ''),
            'assigned_bib_number'  => (string) ($row['assigned_bib_number'] ?? ''),
            'attendance_status'    => (string) ($row['attendance_status'] ?? ''),
        ];
    }

    return finalizeContractorSheetRows($rows, $paidOnly);
}

/**
 * Signed-in staff suitable for contractor sheet export.
 *
 * @return array<int, array<string, mixed>>
 */
function getContractorSheetSignInRows(PDO $pdo, int $eventId): array
{
    try {
        $rows = buildContractorSheetRowsFromAttendance($pdo, $eventId, true);
        if ($rows !== []) {
            return $rows;
        }

        return finalizeContractorSheetRows(
            filterContractorSheetPaidRows(getEventSignInExportRows($pdo, $eventId)),
            false
        );
    } catch (Throwable $e) {
        error_log('[EventStaff] getContractorSheetSignInRows: ' . $e->getMessage());

        try {
            return finalizeContractorSheetRows(
            filterContractorSheetPaidRows(getEventSignInExportRows($pdo, $eventId)),
            false
        );
        } catch (Throwable $inner) {
            error_log('[EventStaff] getContractorSheetSignInRows fallback: ' . $inner->getMessage());

            return [];
        }
    }
}

/**
 * All checked-in staff for the on-screen contractor roster (includes rows needing hour fixes).
 *
 * @return array<int, array<string, mixed>>
 */
function getContractorSheetRosterRows(PDO $pdo, int $eventId): array
{
    try {
        return buildContractorSheetRowsFromAttendance($pdo, $eventId, false);
    } catch (Throwable $e) {
        error_log('[EventStaff] getContractorSheetRosterRows: ' . $e->getMessage());

        return [];
    }
}

/**
 * Signed-in headcount per event (for contractor sheet list).
 *
 * @param array<int, array<string, mixed>> $events
 * @return array<int, int> event_id => count
 */
function getContractorSheetSignInCountsByEvent(PDO $pdo, array $events): array
{
    $counts = [];

    foreach ($events as $event) {
        $eventId = (int) ($event['id'] ?? 0);
        if ($eventId < 1) {
            continue;
        }
        try {
            $counts[$eventId] = count(getContractorSheetSignInRows($pdo, $eventId));
        } catch (Throwable $e) {
            error_log('[EventStaff] contractor sheet count event=' . $eventId . ': ' . $e->getMessage());
            $counts[$eventId] = 0;
        }
    }

    return $counts;
}

/**
 * @deprecated Use getContractorSheetSignInCountsByEvent() for contractor sheet UI.
 * @return array<int, int> event_id => count
 */
function getEventSignInCountsByEvent(PDO $pdo): array
{
    require_once __DIR__ . '/attendance-roster-helpers.php';
    $events = getEventsForAttendanceFilter($pdo);

    return getContractorSheetSignInCountsByEvent($pdo, $events);
}

function buildContractorSheetFilename(PDO $pdo, int $eventId, string $format): string
{
    $base = buildEventSignInExportFilename($pdo, $eventId, $format);

    return preg_replace('/_signins_/', '_contractor_sheet_', $base, 1) ?? $base;
}

/**
 * @param array<int, array<string, mixed>> $rows
 */
function sendContractorSheetDownload(PDO $pdo, int $eventId, array $rows, string $format): void
{
    $rows      = sortContractorSheetRowsAlphabetically($rows);
    $built     = buildContractorSheetExportSheet($rows, $pdo);
    $headers   = $built['headers'];
    $sheetRows = $built['sheetRows'];
    $basename  = buildContractorSheetFilename($pdo, $eventId, $format);

    if (strtolower($format) === 'xlsx') {
        staffRosterSendXlsxDownload($headers, $sheetRows, $basename);

        return;
    }

    staffRosterSendCsvDownload($headers, $sheetRows, $basename);
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array{headers: list<string>, sheetRows: list<list<string>>}
 */
function buildContractorSheetExportSheet(array $rows, PDO $pdo): array
{
    require_once __DIR__ . '/registration-bib.php';
    $bibEnabled = registrationBibColumnEnabled($pdo);

    $headers = ['#', 'First name', 'Surname'];
    if ($bibEnabled) {
        $headers[] = 'Bib #';
    }
    $headers = array_merge($headers, [
        'Email',
        'Mobile',
        'Role',
        'Event',
        'Event date',
        'Sign-in time',
        'Sign-in type',
        'Shift end',
        'Hours worked',
        'Hours paid',
        'Hours note',
    ]);

    $sheetRows = [];
    $rowNumber = 0;

    foreach ($rows as $row) {
        $rowNumber++;
        $checkInAt = trim((string) ($row['export_checked_in_at'] ?? contractorSheetCheckInAt($row)));
        $shiftEnd  = trim((string) ($row['work_end_at'] ?? ''));

        $line = [
            (string) $rowNumber,
            (string) ($row['first_name'] ?? ''),
            (string) ($row['surname'] ?? ''),
        ];
        if ($bibEnabled) {
            $line[] = formatRegistrationBibDisplay($row['assigned_bib_number'] ?? null);
            if ($line[array_key_last($line)] === '—') {
                $line[array_key_last($line)] = '';
            }
        }
        $line = array_merge($line, [
            (string) ($row['email'] ?? ''),
            (string) ($row['mobile'] ?? ''),
            formatRoleLabel((string) ($row['staff_role'] ?? '')),
            formatEventLabel($row),
            formatEventDateLabel((string) ($row['event_date'] ?? '')),
            $checkInAt !== '' ? formatSystemDateTime($checkInAt, $pdo) : '',
            resolveContractorSheetSignInType($row),
            $shiftEnd !== '' ? formatSystemDateTime($shiftEnd, $pdo) : '',
            isset($row['hours_worked']) && $row['hours_worked'] !== null ? (string) $row['hours_worked'] : '',
            isset($row['hours_paid']) && $row['hours_paid'] !== null ? (string) $row['hours_paid'] : '',
            (string) ($row['hours_note'] ?? ''),
        ]);
        $sheetRows[] = $line;
    }

    return ['headers' => $headers, 'sheetRows' => $sheetRows];
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array{headers: list<string>, sheetRows: list<list<string>>}
 */
function buildEventSignInExportSheet(array $rows, PDO $pdo): array
{
    $headers = [
        'First name',
        'Surname',
        'Email',
        'Mobile',
        'Role',
        'Event',
        'Event date',
        'Sign-in time',
        'Sign-in type',
        'Shift end',
        'Hours worked',
        'Hours paid',
        'Hours note',
    ];

    $sheetRows = [];

    foreach ($rows as $row) {
        $checkInAt = trim((string) ($row['export_checked_in_at'] ?? contractorSheetCheckInAt($row)));
        $shiftEnd  = trim((string) ($row['work_end_at'] ?? ''));

        $sheetRows[] = [
            (string) ($row['first_name'] ?? ''),
            (string) ($row['surname'] ?? ''),
            (string) ($row['email'] ?? ''),
            (string) ($row['mobile'] ?? ''),
            formatRoleLabel((string) ($row['staff_role'] ?? '')),
            formatEventLabel($row),
            formatEventDateLabel((string) ($row['event_date'] ?? '')),
            $checkInAt !== '' ? formatSystemDateTime($checkInAt, $pdo) : '',
            resolveContractorSheetSignInType($row),
            $shiftEnd !== '' ? formatSystemDateTime($shiftEnd, $pdo) : '',
            isset($row['hours_worked']) && $row['hours_worked'] !== null ? (string) $row['hours_worked'] : '',
            isset($row['hours_paid']) && $row['hours_paid'] !== null ? (string) $row['hours_paid'] : '',
            (string) ($row['hours_note'] ?? ''),
        ];
    }

    return ['headers' => $headers, 'sheetRows' => $sheetRows];
}

function buildEventSignInExportFilename(PDO $pdo, int $eventId, string $format): string
{
    $event = getEventById($pdo, $eventId);
    $slug  = 'event-signins';

    if (is_array($event)) {
        $name = preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string) ($event['name'] ?? 'event')) ?: 'event';
        $date = preg_replace('/[^0-9-]/', '', (string) ($event['event_date'] ?? ''));
        $slug = trim($name . '_' . $date, '_');
    }

    $ext = strtolower($format) === 'xlsx' ? 'xlsx' : 'csv';

    return $slug . '_signins_' . date('Y-m-d') . '.' . $ext;
}

/**
 * @param array<int, array<string, mixed>> $rows
 */
function sendEventSignInExportDownload(PDO $pdo, int $eventId, array $rows, string $format): void
{
    $built    = buildEventSignInExportSheet($rows, $pdo);
    $headers  = $built['headers'];
    $sheetRows = $built['sheetRows'];
    $basename = buildEventSignInExportFilename($pdo, $eventId, $format);

    if (strtolower($format) === 'xlsx') {
        staffRosterSendXlsxDownload($headers, $sheetRows, $basename);

        return;
    }

    staffRosterSendCsvDownload($headers, $sheetRows, $basename);
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/events-repository.php';
require_once __DIR__ . '/system-settings.php';
require_once __DIR__ . '/staff-roster-download.php';

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
 * Approved staff who have checked in for an event (self, manual, or scan).
 *
 * @return array<int, array<string, mixed>>
 */
function getEventSignInExportRows(PDO $pdo, int $eventId): array
{
    if ($eventId < 1) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT sr.first_name, sr.surname, sr.email, sr.mobile, sr.staff_role, sr.pps_number,
                e.name AS event_name, e.event_date, e.start_time, e.end_time,
                a.checked_in_at, a.checked_in_method, a.work_end_at,
                a.hours_worked, a.hours_paid, a.hours_note
         FROM staff_registrations sr
         INNER JOIN events e ON e.id = sr.event_id
         INNER JOIN attendance a ON a.registration_id = sr.id
         WHERE sr.status = \'approved\' AND sr.event_id = :event_id
         ORDER BY a.checked_in_at ASC, sr.surname ASC, sr.first_name ASC'
    );
    $stmt->execute(['event_id' => $eventId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
        $checkInAt = trim((string) ($row['checked_in_at'] ?? ''));
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
            formatSignInMethodLabel((string) ($row['checked_in_method'] ?? '')),
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

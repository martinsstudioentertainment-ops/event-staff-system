<?php

declare(strict_types=1);

/**
 * Compare a fixed name list against Teddy Swoms event roster/sign-in.
 * GET: ?key=...&event_id=6
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/platform/production-health.php';

header('Content-Type: application/json; charset=UTF-8');

/** @var list<array{first: string, surname: string, bib: string, email: string}> */
const COMPARE_LIST = [
    ['first' => 'Abdiqani Abdulle', 'surname' => 'Weydow', 'bib' => '1703', 'email' => 'weydow79@gmail.com'],
    ['first' => 'Abdullah', 'surname' => 'Abdullah', 'bib' => '1231', 'email' => 'abdullahyaqoobb014@gmail.com'],
    ['first' => 'Akinwande Oluwasegun', 'surname' => 'Jagun', 'bib' => '1086', 'email' => 'akinwandeoluwasegunjagun@gmail.com'],
    ['first' => 'Amit', 'surname' => 'Kataria', 'bib' => '1956', 'email' => 'amitkataria9408@gmail.com'],
    ['first' => 'Billy John', 'surname' => 'Oamen', 'bib' => '1298', 'email' => 'oseahumenkaiben@gmail.com'],
    ['first' => 'Dare', 'surname' => 'Adelaja', 'bib' => '1623', 'email' => 'darea9775@gmail.com'],
    ['first' => 'Mabeka Enock', 'surname' => 'KABANDA', 'bib' => '1601', 'email' => 'mabekaenockkabanda@gmail.com'],
    ['first' => 'Mahamoud Mahamed', 'surname' => 'Sayid', 'bib' => '1263', 'email' => 'sayid_mahamed@live.no'],
    ['first' => 'Maureen Chigozie', 'surname' => 'Agwuna', 'bib' => '1621', 'email' => 'mchigozie13@gmail.com'],
    ['first' => 'Mohamed', 'surname' => 'Osman', 'bib' => '1733', 'email' => 'mucaad385@gmail.com'],
    ['first' => 'Mpho', 'surname' => 'Mathaba', 'bib' => '1958', 'email' => 'mmathaba35@gmail.com'],
    ['first' => 'Mubbashar', 'surname' => 'Munir', 'bib' => '1886', 'email' => 'mubsharmunir1@gmail.com'],
    ['first' => 'Mustaf Osman', 'surname' => 'Diriye', 'bib' => '1300', 'email' => 'modrage47@gmail.com'],
    ['first' => 'Naveen', 'surname' => 'Choutpally', 'bib' => '1611', 'email' => '092naveen@gmail.com'],
    ['first' => 'Nikhil Kadabagere', 'surname' => 'Shivashankaraiah', 'bib' => '1924', 'email' => 'nicks.ks88@gmail.com'],
    ['first' => 'Osman Ali', 'surname' => 'Nur', 'bib' => '1654', 'email' => 'gureyyare09@gmail.com'],
    ['first' => 'Pranuthi', 'surname' => 'Chityala', 'bib' => '1177', 'email' => 'pranuthi1310@gmail.com'],
    ['first' => 'Sohaib', 'surname' => 'Ahmad', 'bib' => '1094', 'email' => 'sulehria066@gmail.com'],
];

function normEmail(string $email): string
{
    return strtolower(trim($email));
}

function normName(string $first, string $surname): string
{
    return strtolower(trim($first . ' ' . $surname));
}

try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    if (!productionHealthAuthorize($pdo, $key)) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT));
    }

    $eventId = (int) ($_GET['event_id'] ?? 6);
    $event = $pdo->prepare('SELECT id, name, event_date FROM events WHERE id = :id LIMIT 1');
    $event->execute(['id' => $eventId]);
    $eventRow = $event->fetch(PDO::FETCH_ASSOC);
    if (!$eventRow) {
        exit(json_encode(['ok' => false, 'error' => 'Event not found'], JSON_PRETTY_PRINT));
    }

    $stmt = $pdo->prepare(
        "SELECT sr.id AS registration_id, sr.first_name, sr.surname, sr.email, sr.status AS reg_status,
                a.id AS attendance_id, a.bib_number, a.checked_in_at, a.attendance_status,
                a.hours_worked, a.hours_paid
         FROM staff_registrations sr
         LEFT JOIN attendance a ON a.registration_id = sr.id
         WHERE sr.event_id = :event_id"
    );
    $stmt->execute(['event_id' => $eventId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $byEmail = [];
    foreach ($rows as $row) {
        $byEmail[normEmail((string) ($row['email'] ?? ''))] = $row;
    }

    $found = [];
    $missingFromEvent = [];
    $notCheckedIn = [];

    foreach (COMPARE_LIST as $person) {
        $email = normEmail($person['email']);
        $row = $byEmail[$email] ?? null;
        $label = trim($person['first'] . ' ' . $person['surname']);

        if ($row === null) {
            $missingFromEvent[] = [
                'name'  => $label,
                'email' => $person['email'],
                'bib'   => $person['bib'],
            ];
            continue;
        }

        $status = strtolower(trim((string) ($row['attendance_status'] ?? '')));
        $checkedIn = trim((string) ($row['checked_in_at'] ?? ''));
        $isCheckedIn = $checkedIn !== '' && $status !== 'no_show';

        $entry = [
            'name'            => trim(($row['first_name'] ?? '') . ' ' . ($row['surname'] ?? '')),
            'email'           => $row['email'],
            'registration_id' => (int) $row['registration_id'],
            'reg_status'      => $row['reg_status'],
            'bib_sheet'       => $person['bib'],
            'bib_system'      => (string) ($row['bib_number'] ?? ''),
            'checked_in'      => $isCheckedIn,
            'attendance_status' => $status !== '' ? $status : 'none',
            'hours_paid'      => $row['hours_paid'],
        ];
        $found[] = $entry;

        if (!$isCheckedIn) {
            $notCheckedIn[] = $entry;
        }
    }

    $extraOnEvent = [];
    foreach ($rows as $row) {
        $email = normEmail((string) ($row['email'] ?? ''));
        $inSheet = false;
        foreach (COMPARE_LIST as $person) {
            if (normEmail($person['email']) === $email) {
                $inSheet = true;
                break;
            }
        }
        if (!$inSheet) {
            $status = strtolower(trim((string) ($row['attendance_status'] ?? '')));
            $checkedIn = trim((string) ($row['checked_in_at'] ?? ''));
            $extraOnEvent[] = [
                'name'       => trim(($row['first_name'] ?? '') . ' ' . ($row['surname'] ?? '')),
                'email'      => $row['email'],
                'reg_status' => $row['reg_status'],
                'checked_in' => $checkedIn !== '' && $status !== 'no_show',
                'hours_paid' => $row['hours_paid'],
            ];
        }
    }

    echo json_encode([
        'ok'    => true,
        'event' => $eventRow,
        'sheet_total' => count(COMPARE_LIST),
        'summary' => [
            'on_event'           => count($found),
            'missing_from_event' => count($missingFromEvent),
            'on_event_not_signed_in' => count($notCheckedIn),
            'signed_in_from_sheet'   => count($found) - count($notCheckedIn),
            'extra_on_event_not_on_sheet' => count($extraOnEvent),
        ],
        'missing_from_event' => $missingFromEvent,
        'on_event_not_signed_in' => $notCheckedIn,
        'found_on_event' => $found,
        'extra_on_event_not_on_sheet' => $extraOnEvent,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}

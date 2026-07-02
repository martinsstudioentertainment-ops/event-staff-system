<?php

declare(strict_types=1);

/**
 * Compare Katy Perry sheet list against event roster/sign-in.
 * GET: ?key=...&event_id=7
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/platform/production-health.php';

header('Content-Type: application/json; charset=UTF-8');

/** @var list<array{first: string, surname: string, bib: string, email: string}> */
const COMPARE_LIST = [
    ['first' => 'Abdiqani Abdulle', 'surname' => 'Weydow', 'bib' => '1086', 'email' => 'weydow79@gmail.com'],
    ['first' => 'Alyaan', 'surname' => 'Liaqat', 'bib' => '1094', 'email' => 'alyaanch3@gmail.com'],
    ['first' => 'Amit', 'surname' => 'Kataria', 'bib' => '1976', 'email' => 'amitkataria9408@gmail.com'],
    ['first' => 'Mabeka Enock', 'surname' => 'KABANDA', 'bib' => '1069', 'email' => 'mabekaenockkabanda@gmail.com'],
    ['first' => 'Mahamoud', 'surname' => 'Sayid', 'bib' => '1597', 'email' => 'sayid_mahamed@live.no'],
    ['first' => 'Maureen Chigozie', 'surname' => 'Agwuna', 'bib' => '1538', 'email' => 'mchigozie13@gmail.com'],
    ['first' => 'Mohamed', 'surname' => 'Osman', 'bib' => '1536', 'email' => 'mucaad385@gmail.com'],
    ['first' => 'Muhammad Sultan', 'surname' => 'Ahmad', 'bib' => '1151', 'email' => 'sultan2thousand1@gmail.com'],
    ['first' => 'Mustaf Osman', 'surname' => 'Diriye', 'bib' => '1534', 'email' => 'modrage47@gmail.com'],
    ['first' => 'Naveen', 'surname' => 'Choutpally', 'bib' => '1979', 'email' => '092naveen@gmail.com'],
    ['first' => 'Nikhil Kadabagere', 'surname' => 'Shivashankaraiah', 'bib' => '1924', 'email' => 'nicks.ks88@gmail.com'],
    ['first' => 'Osman Ali', 'surname' => 'Nur', 'bib' => '1362', 'email' => 'gureyyare09@gmail.com'],
    ['first' => 'POOVIZHI RAJAN', 'surname' => 'DEVARAJ', 'bib' => '1556', 'email' => 'poovi9495@gmail.com'],
    ['first' => 'Pranuthi', 'surname' => 'Chityala', 'bib' => '1642', 'email' => 'pranuthi1310@gmail.com'],
    ['first' => 'Prince Ralph', 'surname' => 'Eke', 'bib' => '1978', 'email' => 'princeralpheke@gmail.com'],
    ['first' => 'Rafiu', 'surname' => 'Salau', 'bib' => '1420', 'email' => 'rafiusalaujr@gmail.com'],
    ['first' => 'Roy', 'surname' => 'Ajibade', 'bib' => '1980', 'email' => 'royajibade562@gmail.com'],
    ['first' => 'Sohaib', 'surname' => 'Ahmad', 'bib' => '0000', 'email' => 'sulehria066@gmail.com'],
    ['first' => 'Sonariwo', 'surname' => 'Saheed adediran', 'bib' => '1621', 'email' => 'saidisonariwo@gmail.com'],
];

function normEmail(string $email): string
{
    return strtolower(trim($email));
}

try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    if (!productionHealthAuthorize($pdo, $key)) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT));
    }

    $eventId = (int) ($_GET['event_id'] ?? 7);
    $event = $pdo->prepare('SELECT id, name, event_date FROM events WHERE id = :id LIMIT 1');
    $event->execute(['id' => $eventId]);
    $eventRow = $event->fetch(PDO::FETCH_ASSOC);
    if (!$eventRow) {
        exit(json_encode(['ok' => false, 'error' => 'Event not found'], JSON_PRETTY_PRINT));
    }

    $stmt = $pdo->prepare(
        "SELECT sr.id AS registration_id, sr.first_name, sr.surname, sr.email, sr.status AS reg_status,
                sr.assigned_bib_number, a.id AS attendance_id, a.bib_number, a.checked_in_at,
                a.attendance_status, a.hours_paid, a.checked_in_method
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
        $checkedIn = trim((string) ($row['checked_in_at'] ?? '')) !== '' && $status !== 'no_show';

        $entry = [
            'name'              => trim(($row['first_name'] ?? '') . ' ' . ($row['surname'] ?? '')),
            'email'             => $row['email'],
            'registration_id'   => (int) $row['registration_id'],
            'reg_status'        => $row['reg_status'],
            'bib_sheet'         => $person['bib'],
            'bib_system'        => (string) ($row['bib_number'] ?? $row['assigned_bib_number'] ?? ''),
            'checked_in'        => $checkedIn,
            'attendance_status' => $status !== '' ? $status : 'none',
            'sign_in_method'    => (string) ($row['checked_in_method'] ?? ''),
            'hours_paid'        => $row['hours_paid'],
        ];
        $found[] = $entry;
        if (!$checkedIn) {
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
        if (!$inSheet && (string) ($row['reg_status'] ?? '') === 'approved') {
            $status = strtolower(trim((string) ($row['attendance_status'] ?? '')));
            $extraOnEvent[] = [
                'name'       => trim(($row['first_name'] ?? '') . ' ' . ($row['surname'] ?? '')),
                'email'      => $row['email'],
                'checked_in' => trim((string) ($row['checked_in_at'] ?? '')) !== '' && $status !== 'no_show',
                'hours_paid' => $row['hours_paid'],
            ];
        }
    }

    echo json_encode([
        'ok'    => true,
        'event' => $eventRow,
        'sheet_total' => count(COMPARE_LIST),
        'summary' => [
            'on_event'               => count($found),
            'missing_from_event'     => count($missingFromEvent),
            'on_event_not_signed_in' => count($notCheckedIn),
            'signed_in_from_sheet'   => count($found) - count($notCheckedIn),
            'extra_approved_not_on_sheet' => count($extraOnEvent),
        ],
        'missing_from_event'     => $missingFromEvent,
        'on_event_not_signed_in' => $notCheckedIn,
        'found_on_event'         => $found,
        'extra_on_event_not_on_sheet' => $extraOnEvent,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}

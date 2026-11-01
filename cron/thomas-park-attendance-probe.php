<?php

declare(strict_types=1);

/**
 * Probe Thomas Park 2026-06-26 event and staff list before bulk attendance.
 *
 * CLI: php cron/thomas-park-attendance-probe.php
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';
require_once dirname(__DIR__) . '/includes/attendance-repository.php';

const THOMAS_PARK_DATE = '2026-06-26';

/** @var list<string> */
const STAFF_NAMES = [
    'Rishika Undralla',
    'Llagat Alvaan',
    'Agwuna Maureen Chigozie',
    'Ajibaee Roy',
    'Adeelaja Oludare',
    'Saiad Ahmed Ali',
    'Samsun Victor Faboade',
    'Chinomso Paschaline',
    'Codwin Osahan Lgbinedion',
    'Manishankar Induri',
    'Awe Margret',
    'Mahamoud Mahamed David',
];

/**
 * @return list<array<string, mixed>>
 */
function findEvents(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        "SELECT id, name, event_date, location, start_time, end_time, is_active
         FROM events
         WHERE event_date = :event_date
           AND (
             name LIKE '%Thomas%'
             OR location LIKE '%Limerick%'
             OR name LIKE '%Park%'
           )
         ORDER BY id ASC"
    );
    $stmt->execute(['event_date' => THOMAS_PARK_DATE]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return list<array<string, mixed>>
 */
function searchStaffByName(PDO $pdo, string $fullName): array
{
    $parts = preg_split('/\s+/', trim($fullName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if ($parts === []) {
        return [];
    }

    $surname   = array_pop($parts);
    $firstName = implode(' ', $parts);

    $matches = [];

    // Exact-ish match on staff_registrations for this date
    $sql = "SELECT sr.id AS registration_id, sr.staff_id, sr.first_name, sr.surname, sr.email,
                   sr.staff_role, sr.status, e.id AS event_id, e.name AS event_name,
                   a.id AS attendance_id, a.hours_worked, a.hours_paid, a.checked_in_at, a.bib_number
            FROM staff_registrations sr
            LEFT JOIN events e ON e.id = sr.event_id
            LEFT JOIN attendance a ON a.registration_id = sr.id
            WHERE (
                (LOWER(sr.surname) LIKE LOWER(:surname) AND LOWER(sr.first_name) LIKE LOWER(:first))
                OR LOWER(CONCAT(sr.first_name, ' ', sr.surname)) LIKE LOWER(:full)
                OR LOWER(CONCAT(sr.surname, ' ', sr.first_name)) LIKE LOWER(:full_rev)
            )
            ORDER BY
                CASE WHEN e.event_date = :event_date THEN 0 ELSE 1 END,
                sr.id DESC
            LIMIT 10";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'surname'    => '%' . $surname . '%',
        'first'      => '%' . $firstName . '%',
        'full'       => '%' . $fullName . '%',
        'full_rev'   => '%' . $fullName . '%',
        'event_date' => THOMAS_PARK_DATE,
    ]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $matches[] = $row;
    }

    // Also search master staff table
    $sql2 = "SELECT s.id AS staff_id, s.first_name, s.surname, s.email, s.staff_role
             FROM staff s
             WHERE (
                (LOWER(s.surname) LIKE LOWER(:surname) AND LOWER(s.first_name) LIKE LOWER(:first))
                OR LOWER(CONCAT(s.first_name, ' ', s.surname)) LIKE LOWER(:full)
             )
             LIMIT 5";
    $stmt2 = $pdo->prepare($sql2);
    $stmt2->execute([
        'surname' => '%' . $surname . '%',
        'first'   => '%' . $firstName . '%',
        'full'    => '%' . $fullName . '%',
    ]);
    $staffRows = $stmt2->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return ['registrations' => $matches, 'staff_directory' => $staffRows];
}

try {
    $pdo = getDB();
    $events = findEvents($pdo);

    echo "=== Events on " . THOMAS_PARK_DATE . " matching Thomas/Limerick/Park ===\n";
    if ($events === []) {
        echo "NONE FOUND\n\n";
        $all = $pdo->prepare('SELECT id, name, location, start_time, end_time FROM events WHERE event_date = :d ORDER BY id');
        $all->execute(['d' => THOMAS_PARK_DATE]);
        echo "All events on that date:\n";
        foreach ($all->fetchAll(PDO::FETCH_ASSOC) ?: [] as $e) {
            echo "  #{$e['id']} {$e['name']} @ {$e['location']} ({$e['start_time']}-{$e['end_time']})\n";
        }
    } else {
        foreach ($events as $e) {
            echo "  #{$e['id']} {$e['name']} @ {$e['location']} ({$e['start_time']}-{$e['end_time']})\n";
        }
    }

    echo "\n=== Staff search ===\n";
    foreach (STAFF_NAMES as $name) {
        echo "\n--- {$name} ---\n";
        $result = searchStaffByName($pdo, $name);
        $regs = $result['registrations'] ?? [];
        $staff = $result['staff_directory'] ?? [];

        if ($regs === [] && $staff === []) {
            echo "  NOT FOUND\n";
            continue;
        }

        foreach ($regs as $r) {
            $role = (string) ($r['staff_role'] ?? '');
            $psa  = in_array(strtolower($role), ['steward'], true) ? 'Steward' : 'PSA Holder';
            echo "  REG #{$r['registration_id']} {$r['first_name']} {$r['surname']} | role={$role} ({$psa}) | status={$r['status']}\n";
            echo "    event: #{$r['event_id']} {$r['event_name']} | attendance: " . ($r['attendance_id'] ? "#{$r['attendance_id']} {$r['hours_paid']}h" : 'none') . "\n";
        }
        foreach ($staff as $s) {
            echo "  STAFF #{$s['staff_id']} {$s['first_name']} {$s['surname']} | role={$s['staff_role']} | {$s['email']}\n";
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

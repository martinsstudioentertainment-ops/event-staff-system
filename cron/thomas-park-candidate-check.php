<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json; charset=UTF-8');

$key = trim((string) ($_GET['key'] ?? ''));
if ($key !== 'email-encoding-verify-20260606') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$staffIds = [22, 34, 129, 130, 140, 20];
$eventId = 38;

try {
    $pdo = getDB();
    $out = [];

    foreach ($staffIds as $sid) {
        $s = $pdo->prepare('SELECT * FROM staff WHERE id = :id');
        $s->execute(['id' => $sid]);
        $staff = $s->fetch(PDO::FETCH_ASSOC);

        $r = $pdo->prepare(
            'SELECT sr.*, e.name, e.event_date FROM staff_registrations sr
             JOIN events e ON e.id = sr.event_id
             WHERE sr.staff_id = :sid OR LOWER(sr.email) = LOWER(:email)
             ORDER BY e.event_date DESC'
        );
        $r->execute(['sid' => $sid, 'email' => (string) ($staff['email'] ?? '')]);
        $regs = $r->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $tp = $pdo->prepare(
            'SELECT * FROM staff_registrations WHERE staff_id = :sid AND event_id = :eid'
        );
        $tp->execute(['sid' => $sid, 'eid' => $eventId]);

        $out[] = [
            'staff' => $staff,
            'thomas_park_reg' => $tp->fetch(PDO::FETCH_ASSOC) ?: null,
            'all_regs' => array_map(static fn($x) => [
                'id' => $x['id'], 'event' => $x['name'], 'date' => $x['event_date'], 'status' => $x['status'],
            ], $regs),
        ];
    }

    // Extra surname searches
    $extra = ['Undralla', 'Alvaan', 'Agwuna', 'Oludare', 'Faboade', 'Paschaline', 'Osahan'];
    $extraHits = [];
    foreach ($extra as $s) {
        $stmt = $pdo->prepare(
            "SELECT id, first_name, surname, email, staff_role FROM staff
             WHERE LOWER(surname) LIKE :s1 OR LOWER(first_name) LIKE :s2 LIMIT 5"
        );
        $needle = '%' . strtolower($s) . '%';
        $stmt->execute(['s1' => $needle, 's2' => $needle]);
        $extraHits[$s] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    echo json_encode(['candidates' => $out, 'extra_hits' => $extraHits], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

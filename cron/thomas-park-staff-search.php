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

/** @var list<string> */
$names = [
    'Rishika Undralla',
    'Llagat Alvaan',
    'Agwuna Maureen Chigozie',
    'Ajibaee Roy',
    'Adeelaja Oludare',
    'Saiad Ahmed Ali',
    'Samsun Victor Faboade',
    'Chinomso Paschaline',
    'Codwin Osahan Lgbinedion',
    'Mahamoud Mahamed David',
];

/** @var list<string> */
$surnameOnly = [
    'Undralla', 'Alvaan', 'Agwuna', 'Chigozie', 'Ajibaee', 'Ajibade', 'Roy',
    'Adeelaja', 'Oludare', 'Saiad', 'Sayid', 'Ahmed', 'Faboade', 'Samsun',
    'Chinomso', 'Paschaline', 'Codwin', 'Godwin', 'Lgbinedion', 'Osahan',
    'Mahamoud', 'Mahamed', 'David',
];

try {
    $pdo = getDB();
    $results = [];

    foreach ($names as $name) {
        $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $surname = array_pop($parts);
        $first = implode(' ', $parts);

        $staff = $pdo->prepare(
            "SELECT id, first_name, surname, email, staff_role FROM staff
             WHERE LOWER(surname) LIKE LOWER(:s) OR LOWER(first_name) LIKE LOWER(:f)
                OR LOWER(CONCAT(first_name,' ',surname)) LIKE LOWER(:full)
             LIMIT 8"
        );
        $staff->execute(['s' => '%' . $surname . '%', 'f' => '%' . $first . '%', 'full' => '%' . $name . '%']);

        $regs = $pdo->prepare(
            "SELECT sr.id, sr.first_name, sr.surname, sr.email, sr.staff_role, sr.status,
                    e.id AS event_id, e.name AS event_name, e.event_date
             FROM staff_registrations sr
             JOIN events e ON e.id = sr.event_id
             WHERE LOWER(sr.surname) LIKE LOWER(:s) OR LOWER(sr.first_name) LIKE LOWER(:f)
                OR LOWER(CONCAT(sr.first_name,' ',sr.surname)) LIKE LOWER(:full)
             ORDER BY e.event_date DESC LIMIT 8"
        );
        $regs->execute(['s' => '%' . $surname . '%', 'f' => '%' . $first . '%', 'full' => '%' . $name . '%']);

        $results[$name] = [
            'staff' => $staff->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'registrations' => $regs->fetchAll(PDO::FETCH_ASSOC) ?: [],
        ];
    }

    $surnameHits = [];
    foreach ($surnameOnly as $s) {
        $stmt = $pdo->prepare(
            "SELECT 'staff' AS src, id, first_name, surname, email FROM staff WHERE LOWER(surname) LIKE LOWER(:s1) OR LOWER(first_name) LIKE LOWER(:s2)
             UNION ALL
             SELECT 'reg' AS src, id, first_name, surname, email FROM staff_registrations WHERE LOWER(surname) LIKE LOWER(:s3) OR LOWER(first_name) LIKE LOWER(:s4)
             LIMIT 5"
        );
        $needle = '%' . strtolower($s) . '%';
        $stmt->execute(['s1' => $needle, 's2' => $needle, 's3' => $needle, 's4' => $needle]);
        $hits = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($hits !== []) {
            $surnameHits[$s] = $hits;
        }
    }

    echo json_encode(['by_name' => $results, 'surname_hits' => $surnameHits], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

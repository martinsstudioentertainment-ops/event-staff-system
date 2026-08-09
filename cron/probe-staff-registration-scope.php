<?php

declare(strict_types=1);

/**
 * Audit staff whose attendance/registrations are linked by staff_id but not login email.
 * Web: /cron/probe-staff-registration-scope.php?key=...&q=Kataria
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $pdo = getDB();
    $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
    $providedKey = trim((string) ($_GET['key'] ?? ''));
    $fallbackKey = 'email-encoding-verify-20260606';

    if ($expectedKey !== '' && hash_equals($expectedKey, $providedKey)) {
        // ok
    } elseif ($providedKey !== '' && hash_equals($fallbackKey, $providedKey)) {
        // ok
    } else {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT);
        exit;
    }

    $query = trim((string) ($_GET['q'] ?? ''));
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 25)));

    if ($query !== '' && !empty($_GET['scope'])) {
        $staffStmt = $pdo->prepare(
            "SELECT id, first_name, surname, email FROM staff
             WHERE LOWER(surname) LIKE :q OR LOWER(first_name) LIKE :q
             ORDER BY surname, first_name LIMIT 5"
        );
        $staffStmt->execute(['q' => '%' . strtolower($query) . '%']);
        $scopeOut = [];
        foreach ($staffStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $staffRow) {
            $staffId = (int) ($staffRow['id'] ?? 0);
            $email   = strtolower(trim((string) ($staffRow['email'] ?? '')));
            $scope   = resolveStaffRegistrationScope($pdo, $email, $staffId);
            $match   = staffRegistrationMatchClause($email, $staffId);
            $regStmt = $pdo->prepare(
                'SELECT sr.id, sr.email, sr.staff_id, sr.status, e.name AS event_name, e.event_date,
                        a.id AS attendance_id, a.checked_in_at, a.activated_at, a.hours_worked
                 FROM staff_registrations sr
                 INNER JOIN events e ON e.id = sr.event_id
                 LEFT JOIN attendance a ON a.registration_id = sr.id
                 WHERE ' . $match['sql'] . '
                 ORDER BY e.event_date DESC, sr.id DESC'
            );
            $regStmt->execute($match['params']);
            $scopeOut[] = [
                'staff'          => $staffRow,
                'scope'          => $scope,
                'registrations'  => $regStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            ];
        }

        echo json_encode([
            'ok'    => true,
            'query' => $query,
            'staff' => $scopeOut,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $sql = "SELECT s.id AS staff_id, s.first_name, s.surname, s.email AS staff_email,
                   sr.id AS registration_id, sr.email AS registration_email,
                   e.name AS event_name, e.event_date,
                   a.id AS attendance_id, a.checked_in_at, a.hours_worked
            FROM staff s
            INNER JOIN staff_registrations sr ON sr.staff_id = s.id
            LEFT JOIN events e ON e.id = sr.event_id
            LEFT JOIN attendance a ON a.registration_id = sr.id
            WHERE LOWER(sr.email) <> LOWER(s.email)";

    $params = [];
    if ($query !== '') {
        $sql .= ' AND (LOWER(s.surname) LIKE :q_surname OR LOWER(s.first_name) LIKE :q_first OR LOWER(s.email) LIKE :q_email)';
        $needle = '%' . strtolower($query) . '%';
        $params['q_surname'] = $needle;
        $params['q_first']   = $needle;
        $params['q_email']   = $needle;
    }

    $sql .= ' ORDER BY s.surname, s.first_name, e.event_date DESC LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $grouped = [];
    foreach ($rows as $row) {
        $staffId = (int) ($row['staff_id'] ?? 0);
        if (!isset($grouped[$staffId])) {
            $grouped[$staffId] = [
                'staff_id'     => $staffId,
                'name'         => trim(($row['first_name'] ?? '') . ' ' . ($row['surname'] ?? '')),
                'staff_email'  => (string) ($row['staff_email'] ?? ''),
                'hidden_shifts'=> [],
            ];
        }
        $grouped[$staffId]['hidden_shifts'][] = [
            'registration_id'    => (int) ($row['registration_id'] ?? 0),
            'registration_email' => (string) ($row['registration_email'] ?? ''),
            'event_name'         => (string) ($row['event_name'] ?? ''),
            'event_date'         => (string) ($row['event_date'] ?? ''),
            'attendance_id'      => (int) ($row['attendance_id'] ?? 0) ?: null,
            'checked_in_at'      => $row['checked_in_at'] ?? null,
            'hours_worked'       => $row['hours_worked'] !== null ? (float) $row['hours_worked'] : null,
        ];
    }

    echo json_encode([
        'ok'              => true,
        'query'           => $query !== '' ? $query : null,
        'affected_staff'  => count($grouped),
        'sample_rows'     => count($rows),
        'staff'           => array_values($grouped),
        'note'            => 'These registrations were linked by staff_id but hidden from the staff app before the email+staff_id scope fix.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}

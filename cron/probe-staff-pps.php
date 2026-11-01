<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/platform/production-health.php';

header('Content-Type: application/json; charset=UTF-8');

$pps = trim((string) ($_GET['pps'] ?? '9730349FA'));
$name = trim((string) ($_GET['name'] ?? 'Shivashankaraiah'));

try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    if (!productionHealthAuthorize($pdo, $key)) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Forbidden']));
    }

    $staff = $pdo->prepare(
        "SELECT id, first_name, surname, email, mobile, pps_number, psa_licence, profile_completed, created_at
         FROM staff
         WHERE UPPER(REPLACE(TRIM(COALESCE(pps_number, '')), ' ', '')) = UPPER(REPLACE(:pps, ' ', ''))
            OR LOWER(surname) LIKE LOWER(:name)
         ORDER BY id ASC"
    );
    $staff->execute(['pps' => $pps, 'name' => '%' . $name . '%']);

    $regs = $pdo->prepare(
        "SELECT sr.id, sr.staff_id, sr.event_id, sr.first_name, sr.surname, sr.email, sr.status, sr.pps_number,
                e.name AS event_name, e.event_date
         FROM staff_registrations sr
         LEFT JOIN events e ON e.id = sr.event_id
         WHERE UPPER(REPLACE(TRIM(COALESCE(sr.pps_number, '')), ' ', '')) = UPPER(REPLACE(:pps, ' ', ''))
            OR LOWER(sr.surname) LIKE LOWER(:name)
         ORDER BY sr.status DESC, sr.id ASC"
    );
    $regs->execute(['pps' => $pps, 'name' => '%' . $name . '%']);

    $approvedEmails = $pdo->prepare(
        "SELECT LOWER(TRIM(email)) AS email, COUNT(*) AS approved_regs, GROUP_CONCAT(DISTINCT staff_id ORDER BY staff_id) AS staff_ids
         FROM staff_registrations
         WHERE status = 'approved'
           AND (UPPER(REPLACE(TRIM(COALESCE(pps_number, '')), ' ', '')) = UPPER(REPLACE(:pps, ' ', ''))
                OR LOWER(surname) LIKE LOWER(:name))
         GROUP BY LOWER(TRIM(email))
         ORDER BY email"
    );
    $approvedEmails->execute(['pps' => $pps, 'name' => '%' . $name . '%']);

    $approvedGroups = $approvedEmails->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'ok' => true,
        'staff_rows' => $staff->fetchAll(PDO::FETCH_ASSOC),
        'registrations' => $regs->fetchAll(PDO::FETCH_ASSOC),
        'approved_email_groups' => $approvedGroups,
        'payroll_row_count' => count($approvedGroups),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

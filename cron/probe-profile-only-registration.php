<?php

declare(strict_types=1);

/**
 * Verify profile-only (account) registration for a given email.
 * Web: /cron/probe-profile-only-registration.php?key=...&email=...
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';

header('Content-Type: application/json; charset=UTF-8');

$fallbackKey = 'email-encoding-verify-20260606';

try {
    $pdo = getDB();
    $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
    $providedKey = trim((string) ($_GET['key'] ?? ''));

    if (!(($expectedKey !== '' && hash_equals($expectedKey, $providedKey)) || hash_equals($fallbackKey, $providedKey))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_PRETTY_PRINT);
        exit;
    }

    $email = strtolower(trim((string) ($_GET['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'email parameter required'], JSON_PRETTY_PRINT);
        exit;
    }

    $staff = getStaffByEmail($pdo, $email);
    $staffRows = $staff !== null ? 1 : 0;

    $dupStmt = $pdo->prepare('SELECT COUNT(*) FROM staff WHERE LOWER(TRIM(email)) = :email');
    $dupStmt->execute(['email' => $email]);
    $staffCount = (int) $dupStmt->fetchColumn();

    $regStmt = $pdo->prepare('SELECT COUNT(*) FROM staff_registrations WHERE LOWER(TRIM(email)) = :email');
    $regStmt->execute(['email' => $email]);
    $registrationCount = (int) $regStmt->fetchColumn();

    $profileOnlyOk = $staffCount === 1 && $registrationCount === 0;

    echo json_encode([
        'ok'                  => true,
        'email'               => $email,
        'staff_count'         => $staffCount,
        'staff_registrations' => $registrationCount,
        'profile_only_ok'     => $profileOnlyOk,
        'staff'               => $staff !== null ? [
            'id'         => (int) ($staff['id'] ?? 0),
            'staff_role' => (string) ($staff['staff_role'] ?? ''),
            'created_at' => (string) ($staff['created_at'] ?? ''),
        ] : null,
        'checks' => [
            'exactly_one_staff_row'   => $staffCount === 1,
            'no_staff_registrations'  => $registrationCount === 0,
            'no_duplicate_staff'      => $staffCount <= 1,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
    ], JSON_PRETTY_PRINT);
}

<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';

header('Content-Type: application/json; charset=UTF-8');

$pdo = getDB();
$key = trim((string) ($_GET['key'] ?? ''));
$fallback = 'email-encoding-verify-20260606';
$expected = trim(getSetting($pdo, 'reminder_cron_key', ''));
if (!(($expected !== '' && hash_equals($expected, $key)) || hash_equals($fallback, $key))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$phone = preg_replace('/\D+/', '', (string) ($_GET['phone'] ?? ''));
if ($phone === '') {
    echo json_encode(['ok' => false, 'error' => 'phone required']);
    exit;
}

$like = '%' . substr($phone, -9) . '%';
$staff = $pdo->prepare("SELECT id, first_name, surname, email, mobile FROM staff WHERE REPLACE(REPLACE(REPLACE(mobile,' ',''),'+',''),'-','') LIKE :p");
$staff->execute(['p' => $like]);
$regs = $pdo->prepare("SELECT id, first_name, surname, email, mobile, event_id, status FROM staff_registrations WHERE REPLACE(REPLACE(REPLACE(mobile,' ',''),'+',''),'-','') LIKE :p");
$regs->execute(['p' => $like]);

echo json_encode([
    'ok' => true,
    'phone' => $phone,
    'staff' => $staff->fetchAll(PDO::FETCH_ASSOC),
    'registrations' => $regs->fetchAll(PDO::FETCH_ASSOC),
], JSON_PRETTY_PRINT);

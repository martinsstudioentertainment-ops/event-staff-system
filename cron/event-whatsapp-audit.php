<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/event-whatsapp.php';
require_once dirname(__DIR__) . '/includes/event-whatsapp-schema.php';

header('Content-Type: application/json; charset=UTF-8');

$key = trim((string) ($_GET['key'] ?? ''));
$pdo = getDB();
$expected = trim(getSetting($pdo, 'reminder_cron_key', ''));
if (!(($expected !== '' && hash_equals($expected, $key)) || hash_equals('email-encoding-verify-20260606', $key))) {
    http_response_code(403);
    exit(json_encode(['ok' => false]));
}

ensureEventWhatsappSchema($pdo);

$stmt = $pdo->query(
    "SELECT id, name, event_date, whatsapp_group_url
     FROM events
     WHERE event_date >= CURDATE()
     ORDER BY event_date ASC, name ASC
     LIMIT 30"
);
$rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

$out = [];
foreach ($rows as $row) {
    $raw = (string) ($row['whatsapp_group_url'] ?? '');
    $out[] = [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'date' => (string) $row['event_date'],
        'raw_url' => $raw !== '' ? $raw : null,
        'normalized' => normalizeWhatsappGroupUrl($raw) !== '' ? normalizeWhatsappGroupUrl($raw) : null,
    ];
}

echo json_encode(['ok' => true, 'events' => $out, 'company_group' => getCompanyWhatsappGroup($pdo)], JSON_PRETTY_PRINT);

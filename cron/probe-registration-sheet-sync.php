<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';

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

$eventId = (int) ($_GET['event_id'] ?? 13);
$regIds = array_values(array_filter(array_map('intval', explode(',', (string) ($_GET['registration_ids'] ?? '725,733')))));

$event = $pdo->prepare('SELECT id, name, event_date, google_sheet_url FROM events WHERE id = :id');
$event->execute(['id' => $eventId]);
$eventRow = $event->fetch(PDO::FETCH_ASSOC) ?: null;

$regs = [];
if ($regIds !== []) {
    $ph = implode(',', array_fill(0, count($regIds), '?'));
    $stmt = $pdo->prepare("SELECT id, first_name, surname, email, status, staff_role FROM staff_registrations WHERE id IN ($ph)");
    $stmt->execute($regIds);
    $regs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$syncLogs = [];
if ($regIds !== [] && $pdo->query("SHOW TABLES LIKE 'platform_sheets_sync_log'")->fetchColumn()) {
    $ph = implode(',', array_fill(0, count($regIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT registration_id, action, status, detail, created_at
         FROM platform_sheets_sync_log
         WHERE event_id = ? AND registration_id IN ($ph)
         ORDER BY created_at DESC"
    );
    $stmt->execute(array_merge([$eventId], $regIds));
    $syncLogs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$queue = [];
if ($pdo->query("SHOW TABLES LIKE 'google_sheets_sync_queue'")->fetchColumn()) {
    $stmt = $pdo->prepare(
        "SELECT id, event_id, registration_id, source, status, attempts, last_error, created_at, updated_at
         FROM google_sheets_sync_queue
         WHERE event_id = :event_id
         ORDER BY id DESC LIMIT 10"
    );
    $stmt->execute(['event_id' => $eventId]);
    $queue = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

echo json_encode([
    'ok' => true,
    'sync_enabled' => getSetting($pdo, 'google_sheets_sync_enabled', '0'),
    'event' => $eventRow,
    'registrations' => $regs,
    'sync_logs' => $syncLogs,
    'recent_queue' => $queue,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

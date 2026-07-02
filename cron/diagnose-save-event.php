<?php

declare(strict_types=1);

/**
 * Diagnose save-event.php load chain.
 * GET: ?key=email-encoding-verify-20260606
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';

header('Content-Type: application/json; charset=UTF-8');

$key = trim((string) ($_GET['key'] ?? ''));
$expected = trim(getSetting(getDB(), 'reminder_cron_key', ''));
if (!(($expected !== '' && hash_equals($expected, $key)) || hash_equals('email-encoding-verify-20260606', $key))) {
    http_response_code(403);
    exit(json_encode(['ok' => false]));
}

$steps = [];

try {
    require_once dirname(__DIR__) . '/includes/auth.php';
    $steps[] = ['auth', true];
} catch (Throwable $e) {
    $steps[] = ['auth', false, $e->getMessage()];
}

try {
    require_once dirname(__DIR__) . '/includes/events-repository.php';
    $steps[] = ['events-repository', true];
} catch (Throwable $e) {
    $steps[] = ['events-repository', false, $e->getMessage()];
}

try {
    require_once dirname(__DIR__) . '/includes/maps.php';
    $steps[] = ['maps', true];
} catch (Throwable $e) {
    $steps[] = ['maps', false, $e->getMessage()];
}

try {
    require_once dirname(__DIR__) . '/includes/audit-log.php';
    $steps[] = ['audit-log', true];
} catch (Throwable $e) {
    $steps[] = ['audit-log', false, $e->getMessage()];
}

try {
    require_once dirname(__DIR__) . '/includes/event-staff-alerts.php';
    $steps[] = ['event-staff-alerts', true];
} catch (Throwable $e) {
    $steps[] = ['event-staff-alerts', false, $e->getMessage()];
}

try {
    require_once dirname(__DIR__) . '/includes/attendance-repository.php';
    $steps[] = ['attendance-repository', true];
} catch (Throwable $e) {
    $steps[] = ['attendance-repository', false, $e->getMessage()];
}

try {
    require_once dirname(__DIR__) . '/includes/checkin-bib.php';
    $steps[] = ['checkin-bib', true];
} catch (Throwable $e) {
    $steps[] = ['checkin-bib', false, $e->getMessage()];
}

try {
    require_once dirname(__DIR__) . '/includes/event-sign-flow.php';
    $steps[] = ['event-sign-flow', true];
} catch (Throwable $e) {
    $steps[] = ['event-sign-flow', false, $e->getMessage()];
}

$pdo = getDB();
$testPost = [
    'name' => 'DIAG TEST DELETE',
    'event_date' => '2099-01-01',
    'location' => 'Test',
    'start_time' => '09:00',
    'end_time' => '17:00',
    'staff_needed' => '1',
    'is_active' => '0',
    'venue_lat' => '53.3498',
    'venue_lng' => '-6.2603',
];

try {
    $errors = validateEventData($testPost, false);
    $steps[] = ['validateEventData', true, 'errors' => $errors];
} catch (Throwable $e) {
    $steps[] = ['validateEventData', false, $e->getMessage(), $e->getFile(), $e->getLine()];
}

try {
    $pdo->beginTransaction();
    $newId = createEvent($pdo, $testPost);
    $pdo->rollBack();
    $steps[] = ['createEvent_dry', true, 'would_id' => $newId];
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $steps[] = ['createEvent_dry', false, $e->getMessage(), $e->getFile(), $e->getLine()];
}

$failed = array_values(array_filter($steps, static fn ($s) => ($s[1] ?? true) === false));

echo json_encode([
    'ok' => $failed === [],
    'failed' => $failed,
    'steps' => $steps,
], JSON_PRETTY_PRINT);

<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/friendly-response.php';
require_once __DIR__ . '/../includes/attendance-repository.php';

requireAdminApiSession();

if (!adminCan('attendance')) {
    renderFriendlyJson(['ok' => false, 'error' => 'Forbidden'], 403);
}

$eventId = (int) ($_GET['event_id'] ?? 0);

try {
    $pdo = getDB();
    if ($eventId <= 0) {
        renderFriendlyJson(['ok' => false, 'error' => 'event_id_required', 'message' => 'Pass event_id.'], 400);
    }

    renderFriendlyJson(getLiveAttendancePayload($pdo, $eventId));
} catch (Throwable $e) {
    friendlyLogError('attendance-live', $e);
    renderFriendlyJson(['ok' => false, 'error' => 'Unable to load attendance data.'], 503);
}

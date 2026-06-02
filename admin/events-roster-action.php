<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/live-events-sync.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('events');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request.');
    header('Location: events.php');
    exit;
}

$pdo = getDB();

try {
    $result = syncLiveEventsFromMasterFile($pdo, false);
} catch (Throwable $e) {
    setAdminFlash('error', 'Roster import failed: ' . $e->getMessage());
    header('Location: events.php');
    exit;
}

logAdminAudit(
    $pdo,
    'import_live_roster',
    'system',
    0,
    "created {$result['created']}, updated {$result['updated']}, skipped {$result['skipped']}"
);

if ($result['success'] && $result['skipped'] === 0) {
    setAdminFlash(
        'success',
        "Master roster imported: {$result['updated']} updated, {$result['created']} created. "
        . "Working for: {$result['main_security_company']}. "
        . 'Check Events list for location, staff needed, and times.'
    );
} elseif ($result['updated'] > 0 || $result['created'] > 0) {
    setAdminFlash(
        'warning',
        "Roster partly imported: {$result['updated']} updated, {$result['created']} created, "
        . "{$result['skipped']} skipped. " . implode('; ', array_slice($result['errors'], 0, 3))
    );
} else {
    setAdminFlash('error', 'Roster import had errors: ' . implode('; ', $result['errors']));
}

header('Location: events.php');
exit;

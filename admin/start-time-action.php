<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit-log.php';
require_once __DIR__ . '/../includes/event-start-time-form.php';

requireAdminCapability('attendance');

$registrationId = (int) ($_POST['registration_id'] ?? 0);
$back = 'view-staff.php?id=' . $registrationId;

if (!in_array(getAdminRole(), ['admin', 'manager'], true)) {
    setAdminFlash('error', 'Only an admin can change a locked start time.');
    header('Location: ' . $back);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request.');
    header('Location: ' . ($registrationId > 0 ? $back : 'staff.php'));
    exit;
}

$pdo = getDB();
$result = adminChangeRegistrationStartTime($pdo, $registrationId, trim((string) ($_POST['start_time'] ?? '')));
if (!empty($result['ok'])) {
    logAdminAudit(
        $pdo,
        'start_time_admin',
        'staff_registration',
        $registrationId,
        (string) ($result['message'] ?? 'Start time changed')
    );
    setAdminFlash('success', (string) ($result['message'] ?? 'Start time saved.'));
} else {
    setAdminFlash('error', (string) ($result['message'] ?? 'Could not change the start time.'));
}

header('Location: ' . $back);
exit;

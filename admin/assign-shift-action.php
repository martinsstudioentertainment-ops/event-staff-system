<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/admin-shift-assign.php';
require_once __DIR__ . '/../includes/audit-log.php';
require_once __DIR__ . '/../includes/apply-remote-sync.php';

requireAdminCapability('staff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: staff.php');
    exit;
}

if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request. Please try again.');
    header('Location: staff.php');
    exit;
}

$staffId            = (int) ($_POST['staff_id'] ?? 0);
$targetEventId      = (int) ($_POST['target_event_id'] ?? 0);
$fromRegistrationId = (int) ($_POST['from_registration_id'] ?? 0);
$autoApprove        = !isset($_POST['auto_approve']) || (string) $_POST['auto_approve'] === '1';

$redirect = 'staff-directory.php';
if (!empty($_POST['redirect'])) {
    $candidate = ltrim((string) $_POST['redirect'], '/');
    if ($candidate !== ''
        && !str_contains($candidate, '://')
        && preg_match('/^(staff\.php|staff-directory\.php|view-staff\.php|staff-edit\.php)(\?.*)?$/', $candidate) === 1) {
        $redirect = $candidate;
    }
}

if ($staffId < 1 || $targetEventId < 1) {
    setAdminFlash('error', 'Select a staff member and target shift.');
    header('Location: ' . $redirect);
    exit;
}

$pdo    = getDB();
$result = adminAssignStaffToShift(
    $pdo,
    $staffId,
    $targetEventId,
    $fromRegistrationId > 0 ? $fromRegistrationId : null,
    $autoApprove
);

if (!$result['ok']) {
    setAdminFlash('error', $result['message']);
    header('Location: ' . $redirect);
    exit;
}

logAdminAudit(
    $pdo,
    'admin_assign_shift',
    'registration',
    (int) $result['registration_id'],
    $result['message']
);

try {
    triggerApplyPortalSyncAsync($pdo, true);
} catch (Throwable $e) {
    error_log('[EventStaff] Apply sync after admin assign shift: ' . $e->getMessage());
}

setAdminFlash('success', $result['message']);
header('Location: view-staff.php?id=' . (int) $result['registration_id']);
exit;

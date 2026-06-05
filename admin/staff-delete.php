<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/audit-log.php';
require_once __DIR__ . '/../includes/apply-remote-sync.php';

if (!isAdminSuperUser()) {
    setAdminFlash('error', 'Only administrators can permanently delete staff profiles.');
    header('Location: staff-directory.php');
    exit;
}

requireAdminCapability('staff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: staff-directory.php');
    exit;
}

if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request. Please try again.');
    header('Location: staff-directory.php');
    exit;
}

$staffId = (int) ($_POST['staff_id'] ?? 0);
$confirm = trim((string) ($_POST['confirm_email'] ?? ''));

if ($staffId < 1) {
    setAdminFlash('error', 'Invalid staff ID.');
    header('Location: staff-directory.php');
    exit;
}

$pdo   = getDB();
$staff = getStaffById($pdo, $staffId);

if ($staff === null) {
    setAdminFlash('error', 'Staff member not found.');
    header('Location: staff-directory.php');
    exit;
}

$staffEmail = strtolower(trim((string) ($staff['email'] ?? '')));
if ($confirm === '' || strtolower($confirm) !== $staffEmail) {
    setAdminFlash('error', 'Type the staff email exactly to confirm permanent deletion.');
    header('Location: staff-edit.php?id=' . $staffId);
    exit;
}

$result = deleteStaffProfileCompletely($pdo, $staffId);

if (!$result['ok']) {
    setAdminFlash('error', $result['error']);
    header('Location: staff-edit.php?id=' . $staffId);
    exit;
}

logAdminAudit(
    $pdo,
    'staff_delete',
    'staff',
    $staffId,
    'Deleted staff profile ' . ($staff['email'] ?? '') . ' and ' . (int) $result['deleted_registrations'] . ' registration(s)'
);

try {
    triggerApplyPortalSyncAsync($pdo, true);
} catch (Throwable $e) {
    error_log('[EventStaff] Apply sync after staff delete: ' . $e->getMessage());
}

setAdminFlash(
    'success',
    'Staff profile deleted permanently (' . (int) $result['deleted_registrations'] . ' registration(s) removed).'
);
header('Location: staff-directory.php');
exit;

<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/attendance-repository.php';
require_once __DIR__ . '/../includes/audit-log.php';
require_once __DIR__ . '/../includes/staff-registration-schema.php';
require_once __DIR__ . '/../includes/event-reporting-schema.php';
require_once __DIR__ . '/../includes/google-sheets-sync.php';

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

$id     = (int) ($_POST['id'] ?? 0);
$status = trim((string) ($_POST['status'] ?? ''));

if ($id <= 0 || !in_array($status, ['pending', 'approved', 'rejected'], true)) {
    setAdminFlash('error', 'Invalid status update.');
    header('Location: staff.php');
    exit;
}

$pdo = getDB();
ensureStaffRegistrationCheckinSchema($pdo);
ensureEventReportingSchema($pdo);

if (updateStaffStatus($pdo, $id, $status)) {
    try {
        if ($status === 'approved') {
            ensureCheckinToken($pdo, $id);
        }
        notifyStaffStatusChange($pdo, $id, $status);
    } catch (Throwable $e) {
        error_log('[EventStaff] update-status id=' . $id . ': ' . $e->getMessage());
    }

    $sheetNote = '';
    try {
        if (isGoogleSheetsSyncEnabled($pdo) && syncRegistrationToGoogleSheet($pdo, $id)) {
            $sheetNote = ' Google Sheet updated.';
        }
    } catch (Throwable $sheetErr) {
        error_log('[EventStaff] Google Sheets sync on status change: ' . $sheetErr->getMessage());
    }

    logAdminAudit($pdo, 'status_change', 'registration', $id, 'Status set to ' . $status);
    setAdminFlash('success', 'Registration status updated to ' . formatStatusLabel($status) . '.' . $sheetNote);
} else {
    setAdminFlash('error', 'Registration not found.');
}

$redirect = 'staff.php';
if (!empty($_POST['redirect'])) {
    $redirect = ltrim((string) $_POST['redirect'], '/');
} elseif (!empty($_POST['redirect_query'])) {
    $redirect .= '?' . ltrim((string) $_POST['redirect_query'], '?');
}

header('Location: ' . $redirect);
exit;

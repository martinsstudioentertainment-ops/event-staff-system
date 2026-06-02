<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/attendance-repository.php';
require_once __DIR__ . '/../includes/audit-log.php';
require_once __DIR__ . '/../includes/staff-registration-schema.php';
require_once __DIR__ . '/../includes/event-reporting-schema.php';

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

$status = trim((string) ($_POST['status'] ?? ''));
$ids    = $_POST['ids'] ?? [];

if (!is_array($ids)) {
    $ids = [];
}

if (!in_array($status, ['approved', 'rejected', 'pending'], true)) {
    setAdminFlash('error', 'Invalid bulk status.');
    header('Location: staff.php');
    exit;
}

$pdo = getDB();
ensureStaffRegistrationCheckinSchema($pdo);
ensureEventReportingSchema($pdo);

$updated = 0;

foreach ($ids as $rawId) {
    $id = (int) $rawId;
    if ($id <= 0) {
        continue;
    }

    if (!updateStaffStatus($pdo, $id, $status)) {
        continue;
    }

    $updated++;

    try {
        if ($status === 'approved') {
            ensureCheckinToken($pdo, $id);
        }

        notifyStaffStatusChange($pdo, $id, $status);
    } catch (Throwable $e) {
        error_log('[EventStaff] bulk-status id=' . $id . ': ' . $e->getMessage());
    }
}

logAdminAudit($pdo, 'bulk_status_change', 'registration', null, 'Updated ' . $updated . ' to ' . $status);

$label = formatStatusLabel($status);
setAdminFlash('success', 'Updated ' . $updated . ' registration(s) to ' . $label . '.');

$redirect = 'staff.php';
if (!empty($_POST['redirect_query'])) {
    $redirect .= '?' . ltrim((string) $_POST['redirect_query'], '?');
}

header('Location: ' . $redirect);
exit;

<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/attendance-repository.php';
require_once __DIR__ . '/../includes/audit-log.php';
require_once __DIR__ . '/../includes/staff-registration-schema.php';
require_once __DIR__ . '/../includes/event-reporting-schema.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';
require_once __DIR__ . '/../includes/staff-onboarding.php';

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

$updated            = 0;
$skippedIncomplete  = 0;
$allowIncomplete    = isAdminSuperUser();
$notifyIds          = [];

foreach ($ids as $rawId) {
    $id = (int) $rawId;
    if ($id <= 0) {
        continue;
    }

    if (!updateStaffStatus($pdo, $id, $status, $allowIncomplete)) {
        if ($status === 'approved') {
            $row = getStaffRegistrationById($pdo, $id);
            if ($row !== null && !isStaffOnboardingComplete($row)) {
                $skippedIncomplete++;
            }
        }
        continue;
    }

    $updated++;
    $notifyIds[] = $id;

    try {
        if ($status === 'approved') {
            ensureCheckinToken($pdo, $id);
        }
    } catch (Throwable $e) {
        error_log('[EventStaff] bulk-status id=' . $id . ': ' . $e->getMessage());
    }
}

if ($notifyIds !== [] && in_array($status, ['approved', 'rejected'], true)) {
    try {
        notifyStaffStatusChanges($pdo, $notifyIds, $status);
    } catch (Throwable $e) {
        error_log('[EventStaff] bulk-status notify: ' . $e->getMessage());
    }
}

logAdminAudit($pdo, 'bulk_status_change', 'registration', null, 'Updated ' . $updated . ' to ' . $status);

$label = formatStatusLabel($status);
$message = 'Updated ' . $updated . ' registration(s) to ' . $label . '.';
if ($skippedIncomplete > 0) {
    $message .= ' Skipped ' . $skippedIncomplete . ' — profile incomplete (send profile link from Staff Directory).';
    if (!$allowIncomplete) {
        $message .= ' Administrators can override when needed.';
    }
}
setAdminFlash($skippedIncomplete > 0 && $updated === 0 ? 'warning' : 'success', $message);

$redirect = 'staff.php';
if (!empty($_POST['redirect_query'])) {
    $redirect .= '?' . ltrim((string) $_POST['redirect_query'], '?');
}

header('Location: ' . $redirect);
exit;

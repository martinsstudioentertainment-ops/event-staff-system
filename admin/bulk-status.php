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
require_once __DIR__ . '/../includes/status-change-post-save.php';

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
$emails = $_POST['emails'] ?? [];

if (!is_array($ids)) {
    $ids = [];
}
if (!is_array($emails)) {
    $emails = [];
}

if (!in_array($status, ['approved', 'rejected', 'pending'], true)) {
    setAdminFlash('error', 'Invalid bulk status.');
    header('Location: staff.php');
    exit;
}

$pdo = getDB();
ensureStaffRegistrationCheckinSchema($pdo);
ensureEventReportingSchema($pdo);

if ($emails !== []) {
    parse_str(ltrim((string) ($_POST['redirect_query'] ?? ''), '?&'), $redirectParams);
    $listFilters = getStaffFiltersFromRequest($redirectParams);
    foreach ($emails as $email) {
        $ids = array_merge($ids, getStaffRegistrationIdsForEmail($pdo, (string) $email, $listFilters));
    }
}

$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

$updated           = 0;
$skippedIncomplete = 0;
$allowIncomplete   = isAdminSuperUser();
$notifyIds         = [];

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

logAdminAudit($pdo, 'bulk_status_change', 'registration', null, 'Updated ' . $updated . ' to ' . $status);

$label   = formatStatusLabel($status);
$message = 'Updated ' . $updated . ' registration(s) to ' . $label . '.';
if ($updated > 0) {
    $message .= ' Emails and Google Sheets sync run in the background.';
}
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

if ($updated > 0 && $notifyIds !== []) {
    flushHttpResponse($redirect);
    runBulkStatusChangePostJobs($pdo, $notifyIds, $status);
    exit;
}

header('Location: ' . $redirect);
exit;

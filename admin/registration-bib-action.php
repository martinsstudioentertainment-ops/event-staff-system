<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/registration-bib.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('attendance');

if (!in_array(getAdminRole(), ['admin', 'manager'], true)) {
    setAdminFlash('error', 'Only administrators and managers can update bib numbers.');
    header('Location: work-hours.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: work-hours.php');
    exit;
}

if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request.');
    header('Location: work-hours.php');
    exit;
}

$pdo            = getDB();
$registrationId = (int) ($_POST['registration_id'] ?? 0);
$bib            = trim((string) ($_POST['assigned_bib_number'] ?? ''));
$eventId        = (int) ($_POST['event_id'] ?? 0);
$workDate       = trim((string) ($_POST['work_date'] ?? ''));
$redirectTo     = trim((string) ($_POST['redirect'] ?? ''));

$result = updateStaffRegistrationBibNumber($pdo, $registrationId, $bib);

if ($result === true) {
    logAdminAudit(
        $pdo,
        'registration_bib_update',
        'staff_registration',
        $registrationId,
        $bib !== '' ? 'Bib #' . $bib : 'Bib cleared'
    );
    setAdminFlash('success', 'Bib number saved.');
} else {
    setAdminFlash('error', (string) $result);
}

if ($redirectTo !== '' && (str_starts_with($redirectTo, 'view-staff.php') || str_starts_with($redirectTo, 'contractor-sheet.php'))) {
    header('Location: ' . $redirectTo);
    exit;
}

$redirect = 'work-hours.php';
$query    = [];
if ($eventId > 0) {
    $query['event_id'] = $eventId;
}
if ($workDate !== '') {
    $query['work_date'] = $workDate;
}
if ($query !== []) {
    $redirect .= '?' . http_build_query($query);
}

header('Location: ' . $redirect);
exit;

<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/work-hours-repository.php';
require_once __DIR__ . '/../includes/registration-bib.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('attendance');

if (!in_array(getAdminRole(), ['admin', 'manager'], true)) {
    setAdminFlash('error', 'Only administrators and managers can update contractor sheet rows.');
    header('Location: contractor-sheet.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: contractor-sheet.php');
    exit;
}

if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request.');
    header('Location: contractor-sheet.php');
    exit;
}

$pdo            = getDB();
$adminUser      = getAdminUser();
$attendanceId   = (int) ($_POST['attendance_id'] ?? 0);
$registrationId = (int) ($_POST['registration_id'] ?? 0);
$eventId        = (int) ($_POST['event_id'] ?? 0);
$hoursPaid      = (float) ($_POST['hours_paid'] ?? 0);
$note           = trim((string) ($_POST['hours_note'] ?? ''));
$bib            = trim((string) ($_POST['assigned_bib_number'] ?? ''));
$saveHours      = !empty($_POST['save_hours']);
$saveBib        = array_key_exists('assigned_bib_number', $_POST) && (int) ($_POST['registration_id'] ?? 0) > 0;

$redirect = $eventId > 0 ? 'contractor-sheet.php?event_id=' . $eventId : 'contractor-sheet.php';
$messages = [];
$errors   = [];

if ($saveHours && $attendanceId > 0) {
    $result = correctAdminShiftHours($pdo, $attendanceId, $hoursPaid, $note, (int) $adminUser['id']);
    if ($result === true) {
        logAdminAudit(
            $pdo,
            'shift_hours_corrected',
            'attendance',
            $attendanceId,
            'Contractor sheet — ' . $hoursPaid . 'h' . ($note !== '' ? ' — ' . $note : '')
        );
        $messages[] = 'Hours saved.';
    } else {
        $errors[] = (string) $result;
    }
}

if ($saveBib && $registrationId > 0) {
    $bibResult = updateStaffRegistrationBibNumber($pdo, $registrationId, $bib);
    if ($bibResult === true) {
        logAdminAudit(
            $pdo,
            'registration_bib_update',
            'staff_registration',
            $registrationId,
            $bib !== '' ? 'Contractor sheet bib #' . $bib : 'Contractor sheet bib cleared'
        );
        $messages[] = 'Bib number saved.';
    } else {
        $errors[] = (string) $bibResult;
    }
}

if ($errors !== []) {
    setAdminFlash('error', implode(' ', $errors));
} elseif ($messages !== []) {
    setAdminFlash('success', implode(' ', $messages));
} else {
    setAdminFlash('error', 'Nothing to save.');
}

header('Location: ' . $redirect);
exit;

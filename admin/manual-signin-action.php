<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-manual-signin.php';
require_once __DIR__ . '/../includes/audit-log.php';
require_once __DIR__ . '/../includes/platform/trust-scores.php';
require_once __DIR__ . '/../includes/staff-repository.php';

requireAdminCapability('attendance');

if (!in_array(getAdminRole(), ['admin', 'manager'], true)) {
    setAdminFlash('error', 'Only administrators and managers can record manual sign-ins.');
    header('Location: attendance.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: manual-signin.php');
    exit;
}

if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request.');
    header('Location: manual-signin.php');
    exit;
}

$pdo       = getDB();
$adminUser = getAdminUser();
$eventId   = (int) ($_POST['event_id'] ?? 0);
$note      = trim((string) ($_POST['hours_note'] ?? ''));
$selected  = array_map('intval', (array) ($_POST['selected'] ?? []));
$hoursMap  = (array) ($_POST['hours'] ?? []);

$toProcess = [];
foreach ($selected as $regId) {
    if ($regId < 1) {
        continue;
    }
    $hours = (float) ($hoursMap[$regId] ?? $hoursMap[(string) $regId] ?? 0);
    if ($hours > 0) {
        $toProcess[$regId] = $hours;
    }
}

if ($toProcess === []) {
    setAdminFlash('error', 'Select at least one staff member and enter hours greater than 0.');
    header('Location: manual-signin.php' . ($eventId > 0 ? '?event_id=' . $eventId : ''));
    exit;
}

$result = recordAdminManualCheckinBulk($pdo, $eventId, $toProcess, $note, (int) $adminUser['id']);

$failedIds = array_flip(array_column($result['failed'], 'id'));
foreach ($toProcess as $regId => $hours) {
    if (isset($failedIds[$regId])) {
        continue;
    }

    logAdminAudit(
        $pdo,
        'admin_manual_signin',
        'registration',
        (int) $regId,
        'Manual sign-in with ' . $hours . 'h' . ($note !== '' ? ' — ' . $note : '')
    );
    $regRow = getStaffRegistrationById($pdo, (int) $regId);
    $trustStaffId = (int) ($regRow['staff_id'] ?? 0);
    if ($trustStaffId < 1 && $regRow !== null) {
        $trustStaffId = (int) (ensureStaffRecordForEmail($pdo, (string) ($regRow['email'] ?? '')) ?? 0);
    }
    if ($trustStaffId > 0) {
        try {
            refreshStaffTrustScoreOnEvent($pdo, $trustStaffId);
        } catch (Throwable $e) {
            error_log('[EventStaff] trust score manual signin: ' . $e->getMessage());
        }
    }
}

$msg = 'Signed in ' . (int) $result['signed'] . ' staff with hours recorded.';
if ($result['failed'] !== []) {
    $bits = array_map(static fn(array $f): string => $f['name'] . ': ' . $f['error'], $result['failed']);
    $msg .= ' Failed: ' . implode('; ', array_slice($bits, 0, 5));
    if (count($bits) > 5) {
        $msg .= ' (+' . (count($bits) - 5) . ' more)';
    }
    setAdminFlash($result['signed'] > 0 ? 'warning' : 'error', $msg);
} else {
    setAdminFlash('success', $msg);
}

header('Location: manual-signin.php?event_id=' . $eventId);
exit;

<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/attendance-repository.php';
require_once __DIR__ . '/../includes/audit-log.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/registration-bib.php';
require_once __DIR__ . '/../includes/platform/trust-scores.php';
require_once __DIR__ . '/../includes/attendant-actions.php';
require_once __DIR__ . '/../includes/work-hours-repository.php';

requireAdminCapability('attendance');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: attendant.php');
    exit;
}

if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request. Try again.');
    header('Location: attendant.php');
    exit;
}

$pdo      = getDB();
$admin    = getAdminUser();
$adminId  = (int) ($admin['id'] ?? 0);
$action   = trim((string) ($_POST['action'] ?? 'present'));
$eventId  = (int) ($_POST['event_id'] ?? 0);
$attId    = (int) ($_POST['attendance_id'] ?? 0);
$regId    = (int) ($_POST['registration_id'] ?? 0);
$bib      = preg_replace('/\s+/', '', trim((string) ($_POST['bib'] ?? ''))) ?? '';
$date     = trim((string) ($_POST['date'] ?? ''));
$nameQ    = trim((string) ($_POST['name'] ?? ''));

$query = [];
if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $query['date'] = $date;
}
if ($eventId > 0) {
    $query['event_id'] = $eventId;
}
if ($nameQ !== '') {
    $query['name'] = $nameQ;
}
if ((string) ($_POST['app'] ?? '') === '1') {
    $query['app'] = '1';
}
$redirect = 'attendant.php' . ($query !== [] ? ('?' . http_build_query($query)) : '');

if ($action === 'save_bib') {
    if (!in_array(getAdminRole(), ['admin', 'manager'], true)) {
        setAdminFlash('error', 'Only administrators and managers can update bib numbers.');
        header('Location: ' . $redirect);
        exit;
    }
    $result = updateStaffRegistrationBibNumber($pdo, $regId, $bib);
    if ($result === true) {
        logAdminAudit($pdo, 'registration_bib_update', 'staff_registration', $regId, $bib !== '' ? 'Bib #' . $bib : 'Bib cleared');
        setAdminFlash('success', $bib !== '' ? ('Bib #' . $bib . ' saved.') : 'Bib cleared.');
    } else {
        setAdminFlash('error', (string) $result);
    }
    header('Location: ' . $redirect);
    exit;
}

if ($action === 'clock_out') {
    if ($attId < 1) {
        setAdminFlash('error', 'Missing attendance record.');
        header('Location: ' . $redirect);
        exit;
    }
    $result = attendantClockOut($pdo, $attId);
    if ($result === true) {
        logAdminAudit($pdo, 'attendant_clock_out', 'attendance', $attId, 'Clocked out by attendant');
        setAdminFlash('success', 'Clocked out — hours recorded on back-office sheet.');
    } else {
        setAdminFlash('error', (string) $result);
    }
    header('Location: ' . $redirect);
    exit;
}

if ($action === 'sent_off') {
    if ($attId < 1) {
        setAdminFlash('error', 'Staff must be Present before Sent off.');
        header('Location: ' . $redirect);
        exit;
    }
    if ($adminId < 1) {
        setAdminFlash('error', 'Admin session required.');
        header('Location: ' . $redirect);
        exit;
    }
    $result = attendantSentOffNow($pdo, $attId, $adminId);
    if ($result === true) {
        logAdminAudit($pdo, 'sent_home_recorded', 'attendance', $attId, 'Sent off by attendant');
        setAdminFlash('success', 'Sent off recorded with time on back-office sheet.');
    } else {
        setAdminFlash('error', (string) $result);
    }
    header('Location: ' . $redirect);
    exit;
}

// present: assign bib at gate (now), then check in
if ($eventId < 1) {
    setAdminFlash('error', 'Select an event first.');
    header('Location: ' . $redirect);
    exit;
}

$startTime = normalizeGateStartTime((string) ($_POST['start_time'] ?? ''));
if ($startTime === null) {
    setAdminFlash('error', 'Choose a start time from 00:00 to 23:00.');
    header('Location: ' . $redirect);
    exit;
}

if ($bib === '') {
    setAdminFlash('error', 'Enter bib number when marking Present.');
    header('Location: ' . $redirect);
    exit;
}

if (!registrationBibColumnEnabled($pdo)) {
    setAdminFlash('error', 'Bib numbers are not enabled.');
    header('Location: ' . $redirect);
    exit;
}

$reg = null;

if ($regId > 0) {
    $stmt = $pdo->prepare(
        "SELECT id, first_name, surname, email, staff_id, event_id, status, assigned_bib_number
         FROM staff_registrations
         WHERE id = :id AND event_id = :event_id AND status = 'approved'
         LIMIT 1"
    );
    $stmt->execute(['id' => $regId, 'event_id' => $eventId]);
    $reg = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$reg) {
        setAdminFlash('error', 'Staff not found on this event.');
        header('Location: ' . $redirect);
        exit;
    }

    // Bib must be unique on this event (except this registration).
    $dup = $pdo->prepare(
        "SELECT id, first_name, surname
         FROM staff_registrations
         WHERE event_id = :event_id
           AND status = 'approved'
           AND id <> :id
           AND REPLACE(TRIM(COALESCE(assigned_bib_number,'')), ' ', '') = :bib
         LIMIT 1"
    );
    $dup->execute(['event_id' => $eventId, 'id' => $regId, 'bib' => $bib]);
    $other = $dup->fetch(PDO::FETCH_ASSOC);
    if ($other) {
        $otherName = trim((string) ($other['first_name'] ?? '') . ' ' . (string) ($other['surname'] ?? ''));
        setAdminFlash('error', 'Bib #' . $bib . ' already used by ' . $otherName . '.');
        header('Location: ' . $redirect);
        exit;
    }

    $bibSave = updateStaffRegistrationBibNumber($pdo, $regId, $bib);
    if ($bibSave !== true) {
        setAdminFlash('error', is_string($bibSave) ? $bibSave : 'Could not save bib.');
        header('Location: ' . $redirect);
        exit;
    }
} else {
    // Lookup by bib already assigned (re-scan / late confirm).
    $stmt = $pdo->prepare(
        "SELECT id, first_name, surname, email, staff_id, event_id, status, assigned_bib_number
         FROM staff_registrations
         WHERE event_id = :event_id
           AND status = 'approved'
           AND REPLACE(TRIM(COALESCE(assigned_bib_number,'')), ' ', '') = :bib
         LIMIT 2"
    );
    $stmt->execute(['event_id' => $eventId, 'bib' => $bib]);
    $matches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($matches === []) {
        setAdminFlash('error', 'No staff with bib #' . $bib . '. Open their name first, enter bib, then Present.');
        header('Location: ' . $redirect);
        exit;
    }
    if (count($matches) > 1) {
        setAdminFlash('error', 'Bib #' . $bib . ' is duplicated. Fix bibs first.');
        header('Location: ' . $redirect);
        exit;
    }
    $reg = $matches[0];
    $regId = (int) $reg['id'];
}

$name = trim((string) ($reg['first_name'] ?? '') . ' ' . (string) ($reg['surname'] ?? ''));
$result = recordCheckin($pdo, $regId, 'admin');

if ($result === true) {
    saveAttendanceGateStartTime($pdo, $regId, $startTime);
    logAdminAudit($pdo, 'attendant_checkin', 'registration', $regId, 'Present at gate · bib #' . $bib . ' · start ' . $startTime . ': ' . $name);
    $trustStaffId = (int) ($reg['staff_id'] ?? 0);
    if ($trustStaffId < 1) {
        $trustStaffId = (int) (ensureStaffRecordForEmail($pdo, (string) ($reg['email'] ?? '')) ?? 0);
    }
    if ($trustStaffId > 0) {
        try {
            refreshStaffTrustScoreOnEvent($pdo, $trustStaffId);
        } catch (Throwable $e) {
            error_log('[EventStaff] attendant trust: ' . $e->getMessage());
        }
    }
    setAdminFlash('success', $name . ' · bib #' . $bib . ' · ' . $startTime . ' marked Present.');
} elseif ($result === 'Already checked in.') {
    setAdminFlash('warning', $name . ' is already Present (bib #' . $bib . ').');
} else {
    setAdminFlash('error', is_string($result) ? $result : 'Could not mark present.');
}

header('Location: ' . $redirect);
exit;

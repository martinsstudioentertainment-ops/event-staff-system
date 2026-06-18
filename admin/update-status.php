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

require_once __DIR__ . '/../includes/admin-capabilities.php';

require_once __DIR__ . '/../includes/staff-onboarding.php';

require_once __DIR__ . '/../includes/platform/trust-scores.php';

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



$allowIncomplete = isAdminSuperUser();

$updated         = false;



if (updateStaffStatus($pdo, $id, $status, $allowIncomplete)) {

    $updated = true;

    try {

        if ($status === 'approved') {

            ensureCheckinToken($pdo, $id);

        }

    } catch (Throwable $e) {

        error_log('[EventStaff] update-status id=' . $id . ': ' . $e->getMessage());

    }



    logAdminAudit($pdo, 'status_change', 'registration', $id, 'Status set to ' . $status);



    if ($status === 'approved') {

        $regForTrust = getStaffRegistrationById($pdo, $id);

        $trustStaffId = (int) ($regForTrust['staff_id'] ?? 0);

        if ($trustStaffId < 1 && $regForTrust !== null) {

            $trustStaffId = (int) (ensureStaffRecordForEmail($pdo, (string) ($regForTrust['email'] ?? '')) ?? 0);

        }

        if ($trustStaffId > 0) {

            try {

                refreshStaffTrustScoreOnEvent($pdo, $trustStaffId);

            } catch (Throwable $trustErr) {

                error_log('[EventStaff] trust score refresh on approve: ' . $trustErr->getMessage());

            }

        }

    }



    setAdminFlash(

        'success',

        'Registration status updated to ' . formatStatusLabel($status) . '. Notifications and sheet sync run in the background.'

    );

} else {

    $row = getStaffRegistrationById($pdo, $id);

    if ($status === 'approved' && $row !== null && !isStaffOnboardingComplete($row)) {

        setAdminFlash(

            'error',

            'Cannot approve — staff profile is incomplete (PSA, bank, address, etc.). Email a profile update link from Staff Directory.'

            . ($allowIncomplete ? '' : ' Only administrators can approve incomplete profiles.')

        );

    } else {

        setAdminFlash('error', 'Registration not found or status unchanged.');

    }

}



$redirect = 'staff.php';

if (!empty($_POST['redirect'])) {

    $candidate = ltrim((string) $_POST['redirect'], '/');

    if ($candidate !== ''

        && !str_contains($candidate, '://')

        && !str_starts_with($candidate, '//')

        && preg_match('/^(staff\.php|staff-directory\.php|events\.php|dashboard\.php)(\?.*)?$/', $candidate) === 1) {

        $redirect = $candidate;

    }

} elseif (!empty($_POST['redirect_query'])) {

    $query = ltrim((string) $_POST['redirect_query'], '?');

    if ($query !== '' && preg_match('/^[a-zA-Z0-9_=&%.-]+$/', $query) === 1) {

        $redirect .= '?' . $query;

    }

}



if ($updated) {

    flushHttpResponse($redirect);

    runSingleStatusChangePostJobs($pdo, $id, $status);

    exit;

}



header('Location: ' . $redirect);

exit;


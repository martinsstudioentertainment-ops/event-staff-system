<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staff-repository.php';
require_once __DIR__ . '/../includes/staff-profile-email.php';
require_once __DIR__ . '/../includes/audit-log.php';

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

$pdo      = getDB();
$redirect = trim((string) ($_POST['redirect'] ?? 'staff-directory.php'));
if ($redirect === '' || !str_starts_with($redirect, 'staff')) {
    $redirect = 'staff-directory.php';
}

if (!empty($_POST['bulk_all'])) {
    $filters = [
        'q'           => trim((string) ($_POST['filter_q'] ?? '')),
        'role'        => trim((string) ($_POST['filter_role'] ?? '')),
        'blacklisted' => isset($_POST['filter_blacklisted']) && $_POST['filter_blacklisted'] !== ''
            ? (bool) (int) $_POST['filter_blacklisted']
            : null,
        'profile'     => in_array((string) ($_POST['filter_profile'] ?? ''), ['complete', 'incomplete'], true)
            ? (string) $_POST['filter_profile']
            : '',
    ];

    $staffIds = getStaffIdsForProfileLinkBulk($pdo, $filters);
    if ($staffIds === []) {
        setAdminFlash('error', 'No registered staff found to email.');
        header('Location: ' . $redirect);
        exit;
    }

    $result = sendBulkStaffProfileUpdateLinkEmails($pdo, $staffIds);
    logAdminAudit(
        $pdo,
        'staff_profile_link_bulk',
        'staff',
        null,
        'Bulk profile links: sent ' . $result['sent'] . ', failed ' . $result['failed'] . ', skipped ' . $result['skipped']
    );

    if ($result['sent'] === 0) {
        setAdminFlash('error', 'Could not send any emails. Check SMTP settings.');
    } else {
        $msg = 'Profile update link sent to ' . $result['sent'] . ' staff member(s).';
        if ($result['failed'] > 0) {
            $msg .= ' ' . $result['failed'] . ' failed.';
        }
        setAdminFlash('success', $msg);
    }

    header('Location: ' . $redirect);
    exit;
}

$staffId = (int) ($_POST['staff_id'] ?? 0);
if ($staffId < 1) {
    setAdminFlash('error', 'Invalid staff member.');
    header('Location: staff-directory.php');
    exit;
}

$staff = getStaffById($pdo, $staffId);
if ($staff === null) {
    setAdminFlash('error', 'Staff member not found.');
    header('Location: staff-directory.php');
    exit;
}

if (sendStaffProfileUpdateLinkEmail($pdo, $staffId)) {
    logAdminAudit(
        $pdo,
        'staff_profile_link_sent',
        'staff',
        $staffId,
        'Profile update link emailed to ' . (string) $staff['email']
    );
    setAdminFlash('success', 'Profile update link sent to ' . (string) $staff['email'] . ' (this is not an approval email).');
} else {
    setAdminFlash('error', 'Could not send email. Check SMTP settings and that the staff member has a valid email.');
}

$singleRedirect = trim((string) ($_POST['single_redirect'] ?? ''));
header('Location: ' . ($singleRedirect !== '' ? $singleRedirect : 'staff-edit.php?id=' . $staffId));
exit;

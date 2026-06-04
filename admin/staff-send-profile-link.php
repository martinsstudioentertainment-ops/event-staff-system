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

$staffId = (int) ($_POST['staff_id'] ?? 0);
$redirect = 'staff-edit.php?id=' . $staffId;
if ($staffId < 1) {
    setAdminFlash('error', 'Invalid staff member.');
    header('Location: staff-directory.php');
    exit;
}

$pdo   = getDB();
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

header('Location: ' . $redirect);
exit;

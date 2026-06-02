<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staff-blacklist.php';
require_once __DIR__ . '/../includes/audit-log.php';

requireAdminCapability('staff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf_token'] ?? null)) {
    setAdminFlash('error', 'Invalid request.');
    header('Location: blacklist.php');
    exit;
}

$pdo       = getDB();
$adminUser = getAdminUser();
$action    = trim((string) ($_POST['action'] ?? ''));
$email     = normalizeBlacklistEmail((string) ($_POST['email'] ?? ''));
$returnId  = (int) ($_POST['return_id'] ?? 0);

$redirect = $returnId > 0 ? 'view-staff.php?id=' . $returnId : 'blacklist.php';

if ($email === '') {
    setAdminFlash('error', 'Email is required.');
    header('Location: ' . $redirect);
    exit;
}

if ($action === 'remove') {
    if (removeFromBlacklist($pdo, $email, (int) ($adminUser['id'] ?? 0))) {
        logAdminAudit($pdo, 'staff_unblacklist', 'staff_email', 0, $email);
        setAdminFlash('success', 'Removed from blacklist. They can register again.');
    } else {
        setAdminFlash('error', 'Could not remove from blacklist — they may already be cleared.');
    }
    header('Location: ' . $redirect);
    exit;
}

if ($action === 'add') {
    $reason = trim((string) ($_POST['reason'] ?? 'Added manually by admin'));
    $entry  = blacklistEmail($pdo, $email, $reason !== '' ? $reason : 'Added manually by admin', false);
    if ($entry !== null) {
        logAdminAudit($pdo, 'staff_blacklist', 'staff_email', (int) $entry['id'], $email);
        setAdminFlash('success', 'Added to blacklist.');
    } else {
        setAdminFlash('error', 'Could not add to blacklist — they may already be listed.');
    }
    header('Location: ' . $redirect);
    exit;
}

setAdminFlash('error', 'Unknown action.');
header('Location: ' . $redirect);
exit;

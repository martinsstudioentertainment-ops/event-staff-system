<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staff-repository.php';

requireAdminCapability('staff');

$pdo = getDB();
$staffId = (int) ($_GET['id'] ?? 0);

if ($staffId < 1) {
    setAdminFlash('error', 'Invalid staff ID');
    header('Location: staff-directory.php');
    exit;
}

try {
    $token = generateStaffProfileToken($pdo, $staffId);
    setAdminFlash('success', 'Profile token regenerated successfully. New link: ' . getRegistrationFormUrl($pdo) . '/staff-profile.php?token=' . $token);
} catch (Exception $e) {
    setAdminFlash('error', 'Failed to regenerate token: ' . $e->getMessage());
}

header('Location: staff-edit.php?id=' . $staffId);
exit;

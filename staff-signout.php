<?php

require_once __DIR__ . '/config.php';

initSecureSession();
require_once __DIR__ . '/includes/staff-portal-session.php';

clearStaffPortalSession();

$return = trim((string) ($_GET['return'] ?? 'staff-app.php'));
if ($return === '' || str_contains($return, '://') || str_starts_with($return, '//')) {
    $return = 'staff-app.php';
}

$reason = trim((string) ($_GET['reason'] ?? ''));
if ($reason === 'idle') {
    $sep    = str_contains($return, '?') ? '&' : '?';
    $return .= $sep . 'signed_out=idle';
}

header('Location: ' . $return);
exit;

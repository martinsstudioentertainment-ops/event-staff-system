<?php

require_once __DIR__ . '/config.php';
initSecureSession();

require_once __DIR__ . '/includes/staff-app-v3-pages.php';

$pdo         = getDB();
$portalStaff = staffV3RequireSignIn($pdo);
$ctx         = buildStaffV3Context($pdo, $portalStaff);

$notice = '';
if (isset($_GET['applied']) && (string) $_GET['applied'] === '1') {
    $notice = 'Your application was submitted. You will see it under Shifts once approved.';
} elseif (!empty($_GET['error'])) {
    $notice = trim((string) $_GET['error']);
}
if ($notice !== '') {
    $ctx['signed_out_notice'] = $notice;
}

renderStaffV3PageStart($ctx, 'shifts', 'Browse shifts');
renderStaffV3ApplyShiftsPage($ctx);
renderStaffV3PageEnd($ctx);

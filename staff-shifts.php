<?php

require_once __DIR__ . '/config.php';
initSecureSession();

require_once __DIR__ . '/includes/staff-app-v3-pages.php';

$pdo         = getDB();
$portalStaff = staffV3RequireSignIn($pdo);
$ctx         = buildStaffV3Context($pdo, $portalStaff);

$notice = '';
if (isset($_GET['applied']) && (string) $_GET['applied'] === '1') {
    $notice = 'Application submitted. It will appear here once the coordinator approves it.';
}
if ($notice !== '') {
    $ctx['signed_out_notice'] = $notice;
}

renderStaffV3PageStart($ctx, 'shifts', 'Shifts');
renderStaffV3ShiftsPage($ctx);
renderStaffV3PageEnd($ctx);

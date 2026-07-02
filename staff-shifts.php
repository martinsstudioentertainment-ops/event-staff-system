<?php

require_once __DIR__ . '/config.php';
initSecureSession();

require_once __DIR__ . '/includes/staff-app-v3-pages.php';

$pdo         = getDB();
$portalStaff = staffV3RequireSignIn($pdo);
$ctx         = buildStaffV3Context($pdo, $portalStaff);

renderStaffV3PageStart($ctx, 'shifts', 'Shifts');
renderStaffV3ShiftsPage($ctx);
renderStaffV3PageEnd($ctx);

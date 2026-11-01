<?php

header('Content-Type: text/plain');
require_once dirname(__DIR__) . '/config.php';
guardDevOnlyEndpoint('Probe disabled in production.');

$files = [
    'includes/staff-app-v3-data.php',
    'includes/staff-portal-dashboard.php',
    'includes/staff-portal-shift.php',
    'includes/staff-venue-checkin.php',
    'includes/staff-messages.php',
    'includes/notification-center.php',
    'includes/status-repository.php',
    'includes/events-repository.php',
    'includes/staff-profile-gate.php',
    'includes/attendance-repository.php',
    'includes/company.php',
    'includes/mobile/services/MobileProfileService.php',
    'includes/mobile/mappers/MobileShiftMapper.php',
    'includes/mobile/services/MobileDashboardService.php',
];
foreach ($files as $rel) {
    try {
        require_once dirname(__DIR__) . '/' . $rel;
        echo basename($rel) . ':ok ';
    } catch (Throwable $e) {
        error_log('[EventStaff] mobile-dashboard-probe: ' . $rel . ' — ' . $e->getMessage());
        echo basename($rel) . ':FAIL';
        exit;
    }
}
echo 'ALL_OK';

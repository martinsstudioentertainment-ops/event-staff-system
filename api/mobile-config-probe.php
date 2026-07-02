<?php

header('Content-Type: text/plain');
require_once dirname(__DIR__) . '/config.php';
guardDevOnlyEndpoint('Probe disabled in production.');

$files = [
    'includes/mobile/services/MobileDashboardService.php',
    'includes/mobile/middleware/MobileAuthMiddleware.php',
];
foreach ($files as $rel) {
    try {
        require_once dirname(__DIR__) . '/' . $rel;
        echo basename($rel) . ':ok ';
    } catch (Throwable $e) {
        error_log('[EventStaff] mobile-config-probe: ' . $rel . ' — ' . $e->getMessage());
        echo basename($rel) . ':FAIL';
        exit;
    }
}
echo 'done';

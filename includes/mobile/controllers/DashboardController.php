<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/MobileDashboardService.php';
require_once __DIR__ . '/../mobile-response.php';
require_once __DIR__ . '/../middleware/MobileAuthMiddleware.php';

function mobileDashboardControllerShow(PDO $pdo): void
{
    $auth = mobileRequireAuth($pdo);
    $GLOBALS['mobile_audit']['staff_id'] = $auth['staff_id'];

    $result = mobileDashboardServiceBuild($pdo, $auth['staff']);
    if (empty($result['ok'])) {
        mobileJsonError(
            (string) ($result['message'] ?? 'Error'),
            (int) ($result['status'] ?? 404),
            (string) ($result['code'] ?? 'ERROR')
        );
    }

    unset($result['ok']);
    mobileJsonOk($result);
}

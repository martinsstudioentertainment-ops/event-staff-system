<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/MobilePushService.php';
require_once __DIR__ . '/../mobile-response.php';
require_once __DIR__ . '/../mobile-request.php';
require_once __DIR__ . '/../middleware/MobileAuthMiddleware.php';

function mobilePushControllerRegister(PDO $pdo): void
{
    $auth = mobileRequireAuth($pdo);
    $GLOBALS['mobile_audit']['staff_id'] = $auth['staff_id'];

    $body   = mobileParseJsonBody();
    $result = mobilePushServiceRegister($pdo, $auth['staff'], $body);

    if (empty($result['ok'])) {
        mobileJsonError(
            (string) ($result['message'] ?? 'Error'),
            (int) ($result['status'] ?? 400),
            (string) ($result['code'] ?? 'ERROR')
        );
    }

    unset($result['ok']);
    mobileJsonOk($result);
}

function mobilePushControllerUnregister(PDO $pdo): void
{
    $auth = mobileRequireAuth($pdo);
    $GLOBALS['mobile_audit']['staff_id'] = $auth['staff_id'];

    $deviceId = trim((string) ($_GET['device_id'] ?? ''));
    $result   = mobilePushServiceUnregister($pdo, $auth['staff'], $deviceId);

    if (empty($result['ok'])) {
        mobileJsonError(
            (string) ($result['message'] ?? 'Error'),
            (int) ($result['status'] ?? 400),
            (string) ($result['code'] ?? 'ERROR')
        );
    }

    unset($result['ok']);
    mobileJsonOk($result);
}

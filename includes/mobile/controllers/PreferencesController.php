<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/MobilePreferencesService.php';
require_once __DIR__ . '/../mobile-response.php';
require_once __DIR__ . '/../middleware/MobileAuthMiddleware.php';

function mobilePreferencesControllerShow(PDO $pdo): void
{
    $auth = mobileRequireAuth($pdo);
    $GLOBALS['mobile_audit']['staff_id'] = $auth['staff_id'];

    $result = mobilePreferencesServiceGet($pdo, $auth['staff_id']);
    unset($result['ok']);
    mobileJsonOk($result);
}

function mobilePreferencesControllerPut(PDO $pdo): void
{
    $auth = mobileRequireAuth($pdo);
    $GLOBALS['mobile_audit']['staff_id'] = $auth['staff_id'];

    $body   = mobileParseJsonBody();
    $result = mobilePreferencesServicePut($pdo, $auth['staff_id'], $body);

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

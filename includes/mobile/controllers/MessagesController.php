<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/MobileMessageService.php';
require_once __DIR__ . '/../mobile-response.php';
require_once __DIR__ . '/../mobile-request.php';
require_once __DIR__ . '/../middleware/MobileAuthMiddleware.php';

function mobileMessagesControllerIndex(PDO $pdo): void
{
    $auth = mobileRequireAuth($pdo);
    $GLOBALS['mobile_audit']['staff_id'] = $auth['staff_id'];

    $result = mobileMessageServiceList($pdo, $auth['staff'], $_GET);
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

function mobileMessagesControllerSend(PDO $pdo): void
{
    $auth = mobileRequireAuth($pdo);
    $GLOBALS['mobile_audit']['staff_id'] = $auth['staff_id'];

    $body   = mobileParseJsonBody();
    $result = mobileMessageServiceSend($pdo, $auth['staff'], $body);

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

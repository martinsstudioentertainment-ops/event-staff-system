<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/MobileProfileService.php';
require_once __DIR__ . '/../services/MobileEmailOtpAuthService.php';
require_once __DIR__ . '/../mobile-response.php';
require_once __DIR__ . '/../middleware/MobileAuthMiddleware.php';

function mobileProfileControllerShow(PDO $pdo): void
{
    $auth = mobileRequireAuth($pdo);
    $GLOBALS['mobile_audit']['staff_id'] = $auth['staff_id'];

    $result = mobileProfileServiceBuild($pdo, $auth['staff']);
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

function mobileProfileControllerPatch(PDO $pdo): void
{
    $auth = mobileRequireAuth($pdo);
    $GLOBALS['mobile_audit']['staff_id'] = $auth['staff_id'];

    $body   = mobileParseJsonBody();
    $result = mobileProfileServicePatch($pdo, $auth['staff'], $body);

    if (empty($result['ok'])) {
        mobileJsonError(
            (string) ($result['message'] ?? 'Error'),
            (int) ($result['status'] ?? 400),
            (string) ($result['code'] ?? 'ERROR'),
            is_array($result['details'] ?? null) ? $result['details'] : []
        );
    }

    unset($result['ok']);
    mobileJsonOk($result);
}

function mobileProfileControllerPassword(PDO $pdo): void
{
    $auth = mobileRequireAuth($pdo);
    $GLOBALS['mobile_audit']['staff_id'] = $auth['staff_id'];

    $body = mobileParseJsonBody();

    if (!empty($body['send_code'])) {
        $result = mobileEmailOtpAuthSendPasswordCode($pdo, $auth['staff']);
        if (empty($result['ok'])) {
            mobileJsonError(
                (string) ($result['message'] ?? 'Error'),
                (int) ($result['status'] ?? 400),
                (string) ($result['code'] ?? 'ERROR')
            );
        }
        unset($result['ok']);
        mobileJsonOk($result);

        return;
    }

    $result = mobileEmailOtpAuthChangePassword($pdo, $auth['staff'], $body);
    if (empty($result['ok'])) {
        mobileJsonError(
            (string) ($result['message'] ?? 'Error'),
            (int) ($result['status'] ?? 400),
            (string) ($result['code'] ?? 'ERROR')
        );
    }

    mobileJsonOk([
        'message' => (string) ($result['message'] ?? 'Password updated successfully.'),
    ]);
}

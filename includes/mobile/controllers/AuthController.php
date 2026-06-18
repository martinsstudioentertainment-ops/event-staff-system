<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/MobileAuthService.php';
require_once __DIR__ . '/../services/MobileGoogleAuthService.php';
require_once __DIR__ . '/../mobile-response.php';
require_once __DIR__ . '/../mobile-request.php';
require_once __DIR__ . '/../middleware/MobileAuthMiddleware.php';

function mobileAuthControllerGoogle(PDO $pdo): void
{
    $body   = mobileParseJsonBody();
    $result = mobileGoogleAuthServiceLogin($pdo, $body);

    if (!$result['ok']) {
        mobileJsonError(
            (string) $result['message'],
            (int) ($result['status'] ?? 401),
            (string) ($result['code'] ?? 'ERROR')
        );
    }

    unset($result['ok']);
    mobileJsonOk($result);
}

function mobileAuthControllerPps(PDO $pdo): void
{
    $body   = mobileParseJsonBody();
    $result = mobileAuthServiceLoginWithPps($pdo, $body);

    if (!$result['ok']) {
        mobileJsonError(
            (string) $result['message'],
            (int) ($result['status'] ?? 401),
            (string) ($result['code'] ?? 'ERROR')
        );
    }

    unset($result['ok']);
    mobileJsonOk($result);
}

function mobileAuthControllerRefresh(PDO $pdo): void
{
    $body   = mobileParseJsonBody();
    $result = mobileAuthServiceRefresh($pdo, $body);

    if (!$result['ok']) {
        mobileJsonError(
            (string) $result['message'],
            (int) ($result['status'] ?? 401),
            (string) ($result['code'] ?? 'ERROR')
        );
    }

    unset($result['ok']);
    mobileJsonOk($result);
}

function mobileAuthControllerLogout(PDO $pdo): void
{
    $body         = mobileParseJsonBody();
    $optionalAuth = mobileOptionalAuth($pdo);
    $staff        = $optionalAuth['staff'] ?? null;

    $result = mobileAuthServiceLogout($pdo, $body, is_array($staff) ? $staff : null);

    if (!$result['ok']) {
        mobileJsonError(
            (string) $result['message'],
            (int) ($result['status'] ?? 401),
            (string) ($result['code'] ?? 'ERROR')
        );
    }

    mobileJsonOk(['message' => $result['message']]);
}

function mobileAuthControllerOtpSend(PDO $pdo): void
{
    require_once __DIR__ . '/../services/MobileEmailOtpAuthService.php';

    $body   = mobileParseJsonBody();
    $result = mobileEmailOtpAuthSend($pdo, $body);

    if (empty($result['ok'])) {
        mobileJsonError(
            (string) ($result['message'] ?? 'Could not send code.'),
            (int) ($result['status'] ?? 400),
            (string) ($result['code'] ?? 'ERROR')
        );
    }

    unset($result['ok']);
    mobileJsonOk($result);
}

function mobileAuthControllerOtpVerify(PDO $pdo): void
{
    require_once __DIR__ . '/../services/MobileEmailOtpAuthService.php';

    $body   = mobileParseJsonBody();
    $result = mobileEmailOtpAuthVerifyLogin($pdo, $body);

    if (empty($result['ok'])) {
        mobileJsonError(
            (string) ($result['message'] ?? 'Verification failed.'),
            (int) ($result['status'] ?? 401),
            (string) ($result['code'] ?? 'ERROR')
        );
    }

    unset($result['ok']);
    mobileJsonOk($result);
}

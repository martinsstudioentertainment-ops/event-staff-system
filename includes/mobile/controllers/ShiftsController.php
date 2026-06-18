<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/MobileShiftService.php';
require_once __DIR__ . '/../mobile-response.php';
require_once __DIR__ . '/../mobile-request.php';
require_once __DIR__ . '/../middleware/MobileAuthMiddleware.php';

function mobileShiftsControllerIndex(PDO $pdo): void
{
    $auth = mobileRequireAuth($pdo);
    $GLOBALS['mobile_audit']['staff_id'] = $auth['staff_id'];

    $result = mobileShiftServiceList($pdo, $auth['staff'], $_GET);
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

function mobileShiftsControllerToday(PDO $pdo): void
{
    $auth = mobileRequireAuth($pdo);
    $GLOBALS['mobile_audit']['staff_id'] = $auth['staff_id'];

    $result = mobileShiftServiceToday($pdo, $auth['staff']);
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

function mobileShiftsControllerShow(PDO $pdo, int $registrationId): void
{
    $auth = mobileRequireAuth($pdo);
    $GLOBALS['mobile_audit']['staff_id'] = $auth['staff_id'];

    $result = mobileShiftServiceGet($pdo, $auth['staff'], $registrationId);
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

function mobileShiftsControllerRespond(PDO $pdo, int $registrationId): void
{
    $auth = mobileRequireAuth($pdo);
    $GLOBALS['mobile_audit']['staff_id'] = $auth['staff_id'];

    $body     = mobileParseJsonBody();
    $response = (string) ($body['response'] ?? '');

    $result = mobileShiftServiceRespond($pdo, $auth['staff'], $registrationId, $response);
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

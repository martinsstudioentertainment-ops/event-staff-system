<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/MobileDocumentService.php';
require_once __DIR__ . '/../mobile-response.php';
require_once __DIR__ . '/../middleware/MobileAuthMiddleware.php';

function mobileDocumentsControllerIndex(PDO $pdo): void
{
    $auth = mobileRequireAuth($pdo);
    $GLOBALS['mobile_audit']['staff_id'] = $auth['staff_id'];

    $result = mobileDocumentServiceList($pdo, $auth['staff']);
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

function mobileDocumentsControllerFile(PDO $pdo, string $key): void
{
    $auth = mobileRequireAuth($pdo);
    $GLOBALS['mobile_audit']['staff_id'] = $auth['staff_id'];

    $error = mobileDocumentServiceStreamFile($pdo, $auth['staff'], $key);
    if ($error !== null) {
        mobileJsonError(
            (string) ($error['message'] ?? 'Error'),
            (int) ($error['status'] ?? 404),
            (string) ($error['code'] ?? 'ERROR')
        );
    }
}

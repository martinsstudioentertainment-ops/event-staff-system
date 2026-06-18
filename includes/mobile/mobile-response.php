<?php

declare(strict_types=1);

function mobileJsonResponse(array $payload, int $status = 200): void
{
    if (ob_get_level() > 0) {
        ob_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    if (isset($GLOBALS['mobile_audit']) && is_array($GLOBALS['mobile_audit'])) {
        $ctx = $GLOBALS['mobile_audit'];
        if (($ctx['pdo'] ?? null) instanceof PDO) {
            require_once __DIR__ . '/mobile-audit-log.php';
            mobileAuditLog(
                $ctx['pdo'],
                (string) ($ctx['path'] ?? ''),
                (string) ($ctx['method'] ?? 'GET'),
                $status,
                isset($ctx['staff_id']) ? (int) $ctx['staff_id'] : null
            );
        }
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mobileJsonOk(array $payload = [], int $status = 200): void
{
    mobileJsonResponse(array_merge(['ok' => true], $payload), $status);
}

/**
 * @param array<string, mixed> $details
 */
function mobileJsonError(
    string $message,
    int $status = 400,
    string $code = 'ERROR',
    array $details = []
): void {
    $body = [
        'ok'    => false,
        'error' => $message,
        'code'  => $code,
    ];
    if ($details !== []) {
        $body['details'] = $details;
    }
    mobileJsonResponse($body, $status);
}

function mobileServiceUnavailable(PDO $pdo, string $message = 'Mobile API is not available.'): void
{
    mobileJsonError($message, 503, 'SERVICE_UNAVAILABLE');
}

function mobileApiDisabledResponse(PDO $pdo): void
{
    mobileJsonError('Mobile API is disabled.', 503, 'API_DISABLED');
}

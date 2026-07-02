<?php

declare(strict_types=1);

/**
 * Mobile config diagnostic — read-only.
 * https://register.olasentra.com/cron/mobile-config-probe.php?key=email-encoding-verify-20260606
 */

require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$key = trim((string) ($_GET['key'] ?? ''));
$allowed = ['email-encoding-verify-20260606'];
if ($key === '' || !in_array($key, $allowed, true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$steps = [];

function probe_step(array &$steps, string $name, callable $fn): void
{
    try {
        $result = $fn();
        $steps[] = ['step' => $name, 'ok' => true, 'detail' => $result];
    } catch (Throwable $e) {
        $steps[] = [
            'step'  => $name,
            'ok'    => false,
            'error' => $e->getMessage(),
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
        ];
    }
}

probe_step($steps, 'getDB', static function () {
    $pdo = getDB();

    return 'connected';
});

probe_step($steps, 'load MobileConfigService', static function () {
    require_once dirname(__DIR__) . '/includes/mobile/services/MobileConfigService.php';

    return 'loaded';
});

probe_step($steps, 'mobileConfigServiceGetPublic', static function () {
    require_once dirname(__DIR__) . '/includes/mobile/schema/mobile-api-schema.php';
    $pdo = getDB();
    $config = mobileConfigServiceGetPublic($pdo);

    return array_keys($config);
});

probe_step($steps, 'json_encode config', static function () {
    require_once dirname(__DIR__) . '/includes/mobile/services/MobileConfigService.php';
    require_once dirname(__DIR__) . '/includes/mobile/schema/mobile-api-schema.php';
    $pdo = getDB();
    $json = json_encode(mobileConfigServiceGetPublic($pdo), JSON_THROW_ON_ERROR);

    return strlen($json) . ' bytes';
});

probe_step($steps, 'load mobile-router', static function () {
    require_once dirname(__DIR__) . '/includes/mobile/mobile-response.php';
    require_once dirname(__DIR__) . '/includes/mobile/mobile-request.php';
    require_once dirname(__DIR__) . '/includes/mobile/mobile-router.php';

    return 'loaded';
});

probe_step($steps, 'mobileRouterParsePath', static function () {
    require_once dirname(__DIR__) . '/includes/mobile/mobile-router.php';
    $path = mobileRouterParsePath('/api/mobile/v1/config');

    return $path;
});

probe_step($steps, 'mobileConfigControllerShow (no exit)', static function () {
    require_once dirname(__DIR__) . '/includes/mobile/controllers/ConfigController.php';
    require_once dirname(__DIR__) . '/includes/mobile/schema/mobile-api-schema.php';
    $pdo = getDB();
    $payload = array_merge(['ok' => true], mobileConfigServiceGetPublic($pdo));
    json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return 'payload ok';
});

echo json_encode(['ok' => true, 'steps' => $steps], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

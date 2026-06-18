<?php

declare(strict_types=1);

/**
 * Mobile API front controller bootstrap.
 */

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/settings-repository.php';
require_once dirname(__DIR__, 2) . '/includes/mobile/schema/mobile-api-schema.php';
require_once dirname(__DIR__, 2) . '/includes/mobile/mobile-response.php';
require_once dirname(__DIR__, 2) . '/includes/mobile/mobile-request.php';
require_once dirname(__DIR__, 2) . '/includes/mobile/mobile-router.php';

try {
    $pdo = getDB();
} catch (Throwable $e) {
    error_log('[MobileAPI] DB: ' . $e->getMessage());
    mobileJsonError('Database unavailable.', 503, 'SERVICE_UNAVAILABLE');
}

ensureMobileApiSchema($pdo);

$path = mobileRouterParsePath((string) ($_SERVER['REQUEST_URI'] ?? ''));
mobileRouterDispatch($pdo, (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path);

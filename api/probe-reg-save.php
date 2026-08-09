<?php

declare(strict_types=1);

/**
 * Legacy registration save probe — disabled in production.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/app-environment.php';
require_once __DIR__ . '/../includes/friendly-response.php';

guardDevOnlyEndpoint('Registration save probe is disabled in production.');

renderFriendlyJson([
    'ok'      => false,
    'error'   => 'probe_disabled',
    'message' => 'This diagnostic endpoint is only available in non-production environments.',
], 403);

<?php

declare(strict_types=1);

/**
 * Lightweight health probe (diagnostics only).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/app-environment.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

echo json_encode([
    'ok'         => true,
    'probe'      => 'ping',
    'service'    => 'olasentra-registration',
    'timestamp'  => gmdate('c'),
    'deprecated' => true,
    'message'    => 'Use /api/health.php for production health checks.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

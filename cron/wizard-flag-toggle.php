<?php

declare(strict_types=1);

/**
 * Toggle feature_registration_wizard_v2 on production (ops / go-live).
 *
 * Web (reminder_cron_key from Admin → Settings → Email):
 *   https://register.olasentra.com/cron/wizard-flag-toggle.php?key=SECRET&action=enable
 *   action=disable | status
 *
 * CLI:
 *   php cron/wizard-flag-toggle.php enable
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/feature-flags.php';

$isCli = PHP_SAPI === 'cli' || defined('STDIN');

if (!$isCli) {
    header('Content-Type: application/json; charset=UTF-8');

    try {
        $pdo         = getDB();
        $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
        $providedKey = trim((string) ($_GET['key'] ?? ''));

        if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Forbidden']);
            exit;
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Database error']);
        exit;
    }
}

try {
    $pdo = getDB();
} catch (Throwable $e) {
    if ($isCli) {
        fwrite(STDERR, "Database error\n");
        exit(1);
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
    exit;
}

$action = $isCli
    ? strtolower(trim((string) ($argv[1] ?? 'status')))
    : strtolower(trim((string) ($_GET['action'] ?? 'status')));

if (!in_array($action, ['enable', 'disable', 'status'], true)) {
    $action = 'status';
}

if ($action === 'enable') {
    setSetting($pdo, 'feature_registration_wizard_v2', '1');
} elseif ($action === 'disable') {
    setSetting($pdo, 'feature_registration_wizard_v2', '0');
}

$enabled = isFeatureEnabled($pdo, 'feature_registration_wizard_v2');
$payload = [
    'ok'      => true,
    'action'  => $action,
    'flag'    => $enabled ? '1' : '0',
    'enabled' => $enabled,
];

if ($isCli) {
    echo json_encode($payload, JSON_PRETTY_PRINT) . PHP_EOL;
    exit($enabled || $action === 'disable' ? 0 : 1);
}

echo json_encode($payload);

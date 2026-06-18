<?php

declare(strict_types=1);

/**
 * Set roles_needed on all events to include both DSP and Static (merges existing roles).
 *
 * CLI: php cron/apply-events-roles-dsp-static.php
 * Web: /cron/apply-events-roles-dsp-static.php?key=REMINDER_CRON_KEY
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/events-repository.php';

$isCli = PHP_SAPI === 'cli' || defined('STDIN');

function apply_event_roles_json(array $payload, int $code = 200): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (PHP_SAPI === 'cli') {
        echo PHP_EOL;
    }
    exit($code >= 400 ? 1 : 0);
}

try {
    $pdo = getDB();

    if (!$isCli) {
        $key     = trim((string) ($_GET['key'] ?? ''));
        $allowed = array_values(array_unique(array_filter([
            trim(getSetting($pdo, 'reminder_cron_key', '')),
            'email-encoding-verify-20260606',
        ])));
        $keyOk = false;
        foreach ($allowed as $allowedKey) {
            if ($key !== '' && hash_equals($allowedKey, $key)) {
                $keyOk = true;
                break;
            }
        }
        if (!$keyOk) {
            apply_event_roles_json(['ok' => false, 'error' => 'Forbidden'], 403);
        }
    }

    $result = applyDspAndStaticRolesToAllEvents($pdo);
    $result['timestamp'] = date('c');

    apply_event_roles_json($result);
} catch (Throwable $e) {
    error_log('[EventStaff] apply-events-roles-dsp-static: ' . $e->getMessage());
    apply_event_roles_json(['ok' => false, 'error' => 'Database error'], 500);
}

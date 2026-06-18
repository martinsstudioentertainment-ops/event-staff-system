<?php

declare(strict_types=1);

/**
 * Run production smoke scripts on-server (PHP curl available).
 * Web: /cron/run-production-smoke.php?key=REMINDER_CRON_KEY
 * CLI: php cron/run-production-smoke.php
 */
$root   = dirname(__DIR__);
$isCli  = PHP_SAPI === 'cli' || defined('STDIN');
$report = [
    'generated_at' => gmdate('c'),
    'environment'  => 'production-server',
    'php_curl'     => function_exists('curl_init'),
    'scripts'      => [],
];

if (!$isCli) {
    header('Content-Type: application/json; charset=UTF-8');
    require_once $root . '/config.php';
    require_once $root . '/includes/settings-repository.php';
    try {
        $pdo         = getDB();
        $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
        $providedKey = trim((string) ($_GET['key'] ?? ''));
        if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_THROW_ON_ERROR);
            exit;
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Database error'], JSON_THROW_ON_ERROR);
        exit;
    }
}

if (!function_exists('curl_init')) {
    $report['ok']    = false;
    $report['error'] = 'PHP curl extension not available on server';
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit(1);
}

$scripts = [
    'admin-production-smoke'  => $root . '/scripts/admin-production-smoke.php',
    'apply-production-smoke'    => $root . '/scripts/apply-production-smoke.php',
    'platform-health-report'    => $root . '/scripts/platform-health-report.php',
];

$allOk = true;
foreach ($scripts as $name => $path) {
    $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $cmd        = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path);
    $proc       = proc_open($cmd, $descriptor, $pipes, $root);
    $out        = '';
    $exitCode   = 1;
    if (is_resource($proc)) {
        fclose($pipes[0]);
        $out      = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);
    }
    $ok = $exitCode === 0 && !str_contains($out, 'Fatal error');
    if (!$ok) {
        $allOk = false;
    }
    $report['scripts'][$name] = [
        'ok'        => $ok,
        'exit_code' => $exitCode,
        'output'    => $out,
    ];
}

$report['ok'] = $allOk;
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit($allOk ? 0 : 1);

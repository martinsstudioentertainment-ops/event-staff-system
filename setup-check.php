<?php
/**
 * One-time production diagnostic — delete this file after the site works.
 * Visit: https://yourdomain.com/setup-check.php?token=CHANGE_ME_BEFORE_USE
 */
declare(strict_types=1);

$expectedToken = 'CHANGE_ME_BEFORE_USE';

header('Content-Type: text/plain; charset=utf-8');

if (($_GET['token'] ?? '') !== $expectedToken) {
    http_response_code(404);
    echo "Not found.\n";
    exit;
}

echo "Event Staff System — setup check\n";
echo str_repeat('-', 40) . "\n";
echo 'PHP version: ' . PHP_VERSION . (version_compare(PHP_VERSION, '8.1.0', '>=') ? ' (ok)' : ' (need 8.1+)') . "\n";

$configPath = __DIR__ . '/config.php';
echo 'config.php: ' . (is_file($configPath) ? 'found' : 'MISSING — create from config.production.example.php') . "\n";

if (!is_file($configPath)) {
    exit(1);
}

require_once $configPath;

echo 'APP_ENV: ' . (defined('APP_ENV') ? (string) APP_ENV : '(not set)') . "\n";

try {
    $pdo = getDB();
    $pdo->query('SELECT 1');
    echo "Database: connected\n";
    echo 'Events: ' . (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn() . "\n";
    echo 'Admin users: ' . (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() . "\n";
} catch (Throwable $e) {
    echo "Database: FAILED — " . $e->getMessage() . "\n";
}

$logDir = __DIR__ . '/storage/logs';
if (is_dir($logDir) && is_writable($logDir)) {
    echo "storage/logs: writable\n";
} elseif (is_dir($logDir)) {
    echo "storage/logs: exists but not writable (chmod 755)\n";
} else {
    echo "storage/logs: missing — create folder in File Manager\n";
}

echo str_repeat('-', 40) . "\n";
echo "Delete setup-check.php when done.\n";

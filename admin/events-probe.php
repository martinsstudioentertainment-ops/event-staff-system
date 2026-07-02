<?php

declare(strict_types=1);

/**
 * Temporary admin diagnostic — delete after Events/Event Hub are confirmed working.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

requireAdminCapability('events');

header('Content-Type: text/plain; charset=utf-8');

$steps = [];
$run = static function (string $label, callable $fn) use (&$steps): void {
    try {
        $fn();
        $steps[] = 'OK  ' . $label;
    } catch (Throwable $e) {
        $steps[] = 'ERR ' . $label . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
    }
};

$run('getDB', static function (): void {
    getDB();
});

$pdo = getDB();

$run('getAllEvents', static function () use ($pdo): void {
    $n = count(getAllEvents($pdo));
    echo "events_count={$n}\n";
});

$run('countEventsGoogleSheetStatus', static function () use ($pdo): void {
    require_once __DIR__ . '/../includes/google-sheets-sync.php';
    json_encode(countEventsGoogleSheetStatus($pdo));
});

$run('isGoogleServiceAccountConfigured', static function (): void {
    require_once __DIR__ . '/../includes/google-sheets-sync.php';
    isGoogleServiceAccountConfigured();
});

$run('layout-top deps', static function () use ($pdo): void {
    require_once __DIR__ . '/../includes/admin/system-health.php';
    require_once __DIR__ . '/../includes/platform/sidebar-ops.php';
    getPlatformOpsSidebarItems($pdo);
});

$run('getEventHubSnapshot', static function () use ($pdo): void {
    require_once __DIR__ . '/../includes/platform/event-hub.php';
    $events = listEventsForHubPicker($pdo, 1);
    if ($events === []) {
        return;
    }
    getEventHubSnapshot($pdo, (int) $events[0]['id']);
});

echo "\n--- probe results ---\n";
echo implode("\n", $steps) . "\n";

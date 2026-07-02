<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/settings-repository.php';
require_once __DIR__ . '/includes/event-sign-flow.php';

$pdo        = getDB();
$eventToken = trim((string) ($_GET['e'] ?? $_POST['e'] ?? ''));
$siteName   = getSiteName($pdo);

try {
    $state = handleEventEmailSigninRequest($pdo, $eventToken, false);
    renderEventSigninPage($state, $eventToken, false, $siteName);
} catch (Throwable $e) {
    error_log('[EventStaff] sign-in.php: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    require_once __DIR__ . '/includes/staff-app-v3-public.php';
    renderStaffV3ErrorScreen(
        'Sign-in temporarily unavailable',
        'Please try again in a moment or contact your coordinator.',
        [
            ['label' => 'Try again', 'href' => $eventToken !== '' ? 'sign-in.php?e=' . urlencode($eventToken) : 'sign-in.php', 'primary' => true],
            ['label' => 'Staff app', 'href' => 'staff-app.php'],
        ],
        $siteName
    );
}

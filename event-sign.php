<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/settings-repository.php';
require_once __DIR__ . '/includes/event-sign-flow.php';

$pdo        = getDB();
$eventToken = trim((string) ($_GET['e'] ?? $_POST['e'] ?? ''));
$siteName   = getSiteName($pdo);

try {
    $state = handleEventEmailSigninRequest($pdo, $eventToken, true);
    renderEventSigninPage($state, $eventToken, true, $siteName);
} catch (Throwable $e) {
    error_log('[EventStaff] event-sign.php: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    require_once __DIR__ . '/includes/staff-app-v3-public.php';
    renderStaffV3ErrorScreen(
        'Sign-in temporarily unavailable',
        'Use the staff app on your own phone instead of a shared venue barcode: sign in with Google, then tap Check In. If you already checked in, your supervisor can confirm in admin.',
        [
            ['label' => 'Check in (staff app)', 'href' => 'staff-checkin.php', 'primary' => true],
            ['label' => 'Sign in to app', 'href' => 'staff-app.php'],
        ],
        $siteName
    );
}

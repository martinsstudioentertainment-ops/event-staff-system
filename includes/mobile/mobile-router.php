<?php

declare(strict_types=1);

require_once __DIR__ . '/mobile-response.php';
require_once __DIR__ . '/schema/mobile-api-schema.php';
require_once __DIR__ . '/controllers/ConfigController.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/ProfileController.php';
require_once __DIR__ . '/controllers/DashboardController.php';
require_once __DIR__ . '/controllers/ShiftsController.php';
require_once __DIR__ . '/controllers/AttendanceController.php';
require_once __DIR__ . '/controllers/NotificationsController.php';
require_once __DIR__ . '/controllers/MessagesController.php';
require_once __DIR__ . '/controllers/PushController.php';
require_once __DIR__ . '/controllers/DocumentsController.php';
require_once __DIR__ . '/controllers/AvailabilityController.php';
require_once __DIR__ . '/controllers/SyncController.php';
require_once __DIR__ . '/controllers/EventsController.php';
require_once __DIR__ . '/controllers/PreferencesController.php';

function mobileRouterDispatch(PDO $pdo, string $method, string $path): void
{
    $method = strtoupper($method);
    $path   = trim($path, '/');

    $GLOBALS['mobile_audit'] = [
        'pdo'    => $pdo,
        'path'   => $path !== '' ? $path : 'root',
        'method' => $method,
    ];

    if ($path === 'config' && $method === 'GET') {
        mobileConfigControllerShow($pdo);

        return;
    }

    if (!mobileApiIsEnabled($pdo)) {
        mobileApiDisabledResponse($pdo);
    }

    if ($path === 'auth/google' && $method === 'POST') {
        mobileAuthControllerGoogle($pdo);

        return;
    }
    if ($path === 'auth/pps' && $method === 'POST') {
        mobileAuthControllerPps($pdo);

        return;
    }
    if ($path === 'auth/otp/send' && $method === 'POST') {
        mobileAuthControllerOtpSend($pdo);

        return;
    }
    if ($path === 'auth/otp/verify' && $method === 'POST') {
        mobileAuthControllerOtpVerify($pdo);

        return;
    }
    if ($path === 'auth/refresh' && $method === 'POST') {
        mobileAuthControllerRefresh($pdo);

        return;
    }
    if ($path === 'auth/logout' && $method === 'POST') {
        mobileAuthControllerLogout($pdo);

        return;
    }

    if ($path === 'me' && $method === 'GET') {
        mobileProfileControllerShow($pdo);

        return;
    }
    if ($path === 'me' && $method === 'PATCH') {
        mobileProfileControllerPatch($pdo);

        return;
    }
    if ($path === 'me/password' && $method === 'POST') {
        mobileProfileControllerPassword($pdo);

        return;
    }
    if ($path === 'me/preferences' && $method === 'GET') {
        mobilePreferencesControllerShow($pdo);

        return;
    }
    if ($path === 'me/preferences' && $method === 'PUT') {
        mobilePreferencesControllerPut($pdo);

        return;
    }
    if ($path === 'dashboard' && $method === 'GET') {
        mobileDashboardControllerShow($pdo);

        return;
    }

    if ($path === 'shifts' && $method === 'GET') {
        mobileShiftsControllerIndex($pdo);

        return;
    }
    if ($path === 'shifts/today' && $method === 'GET') {
        mobileShiftsControllerToday($pdo);

        return;
    }
    if (preg_match('#^shifts/(\d+)$#', $path, $shiftMatch) && $method === 'GET') {
        mobileShiftsControllerShow($pdo, (int) $shiftMatch[1]);

        return;
    }
    if (preg_match('#^shifts/(\d+)/respond$#', $path, $respondMatch) && $method === 'POST') {
        mobileShiftsControllerRespond($pdo, (int) $respondMatch[1]);

        return;
    }

    if ($path === 'checkin' && $method === 'POST') {
        mobileAttendanceControllerCheckin($pdo);

        return;
    }
    if ($path === 'checkout' && $method === 'POST') {
        mobileAttendanceControllerCheckout($pdo);

        return;
    }
    if ($path === 'gps/ping' && $method === 'POST') {
        mobileAttendanceControllerGpsPing($pdo);

        return;
    }
    if ($path === 'gps/status' && $method === 'GET') {
        mobileAttendanceControllerGpsStatus($pdo);

        return;
    }

    if ($path === 'notifications' && $method === 'GET') {
        mobileNotificationsControllerIndex($pdo);

        return;
    }
    if ($path === 'notifications/read-all' && $method === 'POST') {
        mobileNotificationsControllerMarkAllRead($pdo);

        return;
    }
    if (preg_match('#^notifications/(\d+)/read$#', $path, $notifMatch) && $method === 'POST') {
        mobileNotificationsControllerMarkRead($pdo, (int) $notifMatch[1]);

        return;
    }

    if ($path === 'messages' && $method === 'GET') {
        mobileMessagesControllerIndex($pdo);

        return;
    }
    if ($path === 'messages' && $method === 'POST') {
        mobileMessagesControllerSend($pdo);

        return;
    }

    if ($path === 'push/register' && $method === 'POST') {
        mobilePushControllerRegister($pdo);

        return;
    }
    if ($path === 'push/register' && $method === 'DELETE') {
        mobilePushControllerUnregister($pdo);

        return;
    }

    if ($path === 'documents' && $method === 'GET') {
        mobileDocumentsControllerIndex($pdo);

        return;
    }
    if (preg_match('#^documents/([a-z0-9_]+)/file$#', $path, $docMatch) && $method === 'GET') {
        mobileDocumentsControllerFile($pdo, $docMatch[1]);

        return;
    }

    if ($path === 'availability' && $method === 'GET') {
        mobileAvailabilityControllerIndex($pdo);

        return;
    }
    if (preg_match('#^availability/(\d{4}-\d{2}-\d{2})$#', $path, $availMatch) && $method === 'PUT') {
        mobileAvailabilityControllerSet($pdo, $availMatch[1]);

        return;
    }
    if ($path === 'leave' && $method === 'POST') {
        mobileAvailabilityControllerLeave($pdo);

        return;
    }

    if ($path === 'sync/offline' && $method === 'POST') {
        mobileSyncControllerOffline($pdo);

        return;
    }

    if ($path === 'events' && $method === 'GET') {
        mobileEventsControllerIndex($pdo);

        return;
    }
    if ($path === 'events/register' && $method === 'POST') {
        mobileEventsControllerRegister($pdo);

        return;
    }

    mobileJsonError('Route not found.', 404, 'NOT_FOUND');
}

function mobileRouterParsePath(string $requestUri): string
{
    $uri  = parse_url($requestUri, PHP_URL_PATH);
    $path = is_string($uri) ? $uri : '';

    if (preg_match('#/api/mobile/v1/(.*)$#', $path, $m)) {
        return trim($m[1], '/');
    }

    return trim($path, '/');
}

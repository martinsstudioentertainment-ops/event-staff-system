<?php

declare(strict_types=1);

require_once __DIR__ . '/mobile-response.php';
require_once __DIR__ . '/schema/mobile-api-schema.php';

function mobileRouterLoadController(string $name): void
{
    static $loaded = [];
    if (isset($loaded[$name])) {
        return;
    }
    $loaded[$name] = true;

    $path = __DIR__ . '/controllers/' . $name . '.php';
    if (!is_file($path)) {
        mobileJsonError('Mobile controller unavailable.', 503, 'SERVICE_UNAVAILABLE');
    }

    require_once $path;
}

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
        mobileRouterLoadController('ConfigController');
        mobileConfigControllerShow($pdo);

        return;
    }

    if (!mobileApiIsEnabled($pdo)) {
        mobileApiDisabledResponse($pdo);
    }

    if ($path === 'auth/google' && $method === 'POST') {
        mobileRouterLoadController('AuthController');
        mobileAuthControllerGoogle($pdo);

        return;
    }
    if ($path === 'auth/pps' && $method === 'POST') {
        mobileRouterLoadController('AuthController');
        mobileAuthControllerPps($pdo);

        return;
    }
    if ($path === 'auth/otp/send' && $method === 'POST') {
        mobileRouterLoadController('AuthController');
        mobileAuthControllerOtpSend($pdo);

        return;
    }
    if ($path === 'auth/otp/verify' && $method === 'POST') {
        mobileRouterLoadController('AuthController');
        mobileAuthControllerOtpVerify($pdo);

        return;
    }
    if ($path === 'auth/refresh' && $method === 'POST') {
        mobileRouterLoadController('AuthController');
        mobileAuthControllerRefresh($pdo);

        return;
    }
    if ($path === 'auth/logout' && $method === 'POST') {
        mobileRouterLoadController('AuthController');
        mobileAuthControllerLogout($pdo);

        return;
    }

    if ($path === 'me' && $method === 'GET') {
        mobileRouterLoadController('ProfileController');
        mobileProfileControllerShow($pdo);

        return;
    }
    if ($path === 'me' && $method === 'PATCH') {
        mobileRouterLoadController('ProfileController');
        mobileProfileControllerPatch($pdo);

        return;
    }
    if ($path === 'dashboard' && $method === 'GET') {
        mobileRouterLoadController('DashboardController');
        mobileDashboardControllerShow($pdo);

        return;
    }

    if ($path === 'shifts' && $method === 'GET') {
        mobileRouterLoadController('ShiftsController');
        mobileShiftsControllerIndex($pdo);

        return;
    }
    if ($path === 'shifts/today' && $method === 'GET') {
        mobileRouterLoadController('ShiftsController');
        mobileShiftsControllerToday($pdo);

        return;
    }
    if (preg_match('#^shifts/(\d+)$#', $path, $shiftMatch) && $method === 'GET') {
        mobileRouterLoadController('ShiftsController');
        mobileShiftsControllerShow($pdo, (int) $shiftMatch[1]);

        return;
    }
    if (preg_match('#^shifts/(\d+)/respond$#', $path, $respondMatch) && $method === 'POST') {
        mobileRouterLoadController('ShiftsController');
        mobileShiftsControllerRespond($pdo, (int) $respondMatch[1]);

        return;
    }

    if ($path === 'checkin' && $method === 'POST') {
        mobileRouterLoadController('AttendanceController');
        mobileAttendanceControllerCheckin($pdo);

        return;
    }
    if ($path === 'checkout' && $method === 'POST') {
        mobileRouterLoadController('AttendanceController');
        mobileAttendanceControllerCheckout($pdo);

        return;
    }
    if ($path === 'gps/ping' && $method === 'POST') {
        mobileRouterLoadController('AttendanceController');
        mobileAttendanceControllerGpsPing($pdo);

        return;
    }
    if ($path === 'gps/status' && $method === 'GET') {
        mobileRouterLoadController('AttendanceController');
        mobileAttendanceControllerGpsStatus($pdo);

        return;
    }

    if ($path === 'notifications' && $method === 'GET') {
        mobileRouterLoadController('NotificationsController');
        mobileNotificationsControllerIndex($pdo);

        return;
    }
    if ($path === 'notifications/read-all' && $method === 'POST') {
        mobileRouterLoadController('NotificationsController');
        mobileNotificationsControllerMarkAllRead($pdo);

        return;
    }
    if (preg_match('#^notifications/(\d+)/read$#', $path, $notifMatch) && $method === 'POST') {
        mobileRouterLoadController('NotificationsController');
        mobileNotificationsControllerMarkRead($pdo, (int) $notifMatch[1]);

        return;
    }

    if ($path === 'messages' && $method === 'GET') {
        mobileRouterLoadController('MessagesController');
        mobileMessagesControllerIndex($pdo);

        return;
    }
    if ($path === 'messages' && $method === 'POST') {
        mobileRouterLoadController('MessagesController');
        mobileMessagesControllerSend($pdo);

        return;
    }

    if ($path === 'push/register' && $method === 'POST') {
        mobileRouterLoadController('PushController');
        mobilePushControllerRegister($pdo);

        return;
    }
    if ($path === 'push/register' && $method === 'DELETE') {
        mobileRouterLoadController('PushController');
        mobilePushControllerUnregister($pdo);

        return;
    }

    if ($path === 'documents' && $method === 'GET') {
        mobileRouterLoadController('DocumentsController');
        mobileDocumentsControllerIndex($pdo);

        return;
    }
    if (preg_match('#^documents/([a-z0-9_]+)/file$#', $path, $docMatch) && $method === 'GET') {
        mobileRouterLoadController('DocumentsController');
        mobileDocumentsControllerFile($pdo, $docMatch[1]);

        return;
    }

    if ($path === 'availability' && $method === 'GET') {
        mobileRouterLoadController('AvailabilityController');
        mobileAvailabilityControllerIndex($pdo);

        return;
    }
    if (preg_match('#^availability/(\d{4}-\d{2}-\d{2})$#', $path, $availMatch) && $method === 'PUT') {
        mobileRouterLoadController('AvailabilityController');
        mobileAvailabilityControllerSet($pdo, $availMatch[1]);

        return;
    }
    if ($path === 'leave' && $method === 'POST') {
        mobileRouterLoadController('AvailabilityController');
        mobileAvailabilityControllerLeave($pdo);

        return;
    }

    if ($path === 'sync/offline' && $method === 'POST') {
        mobileRouterLoadController('SyncController');
        mobileSyncControllerOffline($pdo);

        return;
    }

    if ($path === 'events' && $method === 'GET') {
        mobileRouterLoadController('EventsController');
        mobileEventsControllerIndex($pdo);

        return;
    }
    if ($path === 'events/register' && $method === 'POST') {
        mobileRouterLoadController('EventsController');
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

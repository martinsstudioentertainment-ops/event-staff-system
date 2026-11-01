<?php

declare(strict_types=1);

/**
 * Public Staff Sign Hub APK download (no admin login).
 * Used by the force-install gate on sign.php for Android phones.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/app-environment.php';
require_once __DIR__ . '/includes/staff-sign-hub-installs.php';

if (function_exists('initSecureSession')) {
    initSecureSession();
}

$apkPath = __DIR__ . '/storage/mobile/android/olasentra-staff-sign-hub-latest.apk';
$version = '1.0.0';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, private');

if (!is_file($apkPath) || filesize($apkPath) < 1000) {
    header('Content-Type: text/html; charset=UTF-8');
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Staff Sign install</title></head><body style="font-family:sans-serif;padding:24px;max-width:420px;margin:0 auto">'
        . '<h1>Staff Sign APK not on server yet</h1>'
        . '<p>Use the install page instead — Chrome <strong>Install app</strong> / <strong>Add to Home Screen</strong>.</p>'
        . '<p><a href="sign.php" style="display:block;padding:14px;background:#0b3d2e;color:#fff;text-align:center;text-decoration:none;font-weight:700">Open install page</a></p>'
        . '</body></html>';
    exit;
}

try {
    require_once __DIR__ . '/includes/auth.php';
    $pdo = getDB();
    $vk = normalizeStaffSignHubVisitorKey((string) ($_GET['v'] ?? $_GET['visitor_key'] ?? ''));
    if ($vk === '') {
        $vk = bin2hex(random_bytes(16));
    }
    recordStaffSignHubDeviceEvent($pdo, 'download', [
        'visitor_key'  => $vk,
        'channel'      => 'apk',
        'display_mode' => 'standalone',
        'app'          => '1',
        'user_agent'   => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
    ]);
} catch (Throwable $e) {
    error_log('[EventStaff] staff-sign-apk log: ' . $e->getMessage());
}

$size = filesize($apkPath);
header('Content-Type: application/vnd.android.package-archive');
header('Content-Disposition: attachment; filename="olasentra-staff-sign-v' . $version . '.apk"');
header('Content-Length: ' . (string) $size);
readfile($apkPath);
exit;

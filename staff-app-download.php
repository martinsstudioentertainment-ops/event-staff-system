<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/staff-app-android.php';

try {
    $pdo = getDB();
} catch (Throwable $e) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Service unavailable.';
    exit;
}

$wantsDownload = isset($_GET['download']) || isset($_GET['apk']);

if ($wantsDownload) {
    $result = staffAppAndroidStreamDownload($pdo);
    if (!($result['ok'] ?? false)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo (string) ($result['error'] ?? 'Not found.');
        exit;
    }
    exit;
}

staffAppAndroidRenderDownloadPage($pdo);

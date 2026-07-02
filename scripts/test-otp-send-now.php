<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/mobile/services/MobileEmailOtpAuthService.php';

$email = $argv[1] ?? 'olabodeoluwafemi25800@gmail.com';
$deviceId = $argv[2] ?? 'android00000001';

try {
    $pdo = getDB();
    $result = mobileEmailOtpAuthSend($pdo, [
        'email'     => $email,
        'device_id' => $deviceId,
        'purpose'   => 'login',
    ]);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'FATAL: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL);
    exit(1);
}

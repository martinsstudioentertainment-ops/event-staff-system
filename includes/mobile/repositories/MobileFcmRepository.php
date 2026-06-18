<?php

declare(strict_types=1);

require_once __DIR__ . '/../schema/mobile-api-schema.php';
require_once __DIR__ . '/../mobile-request.php';

function mobileRegisterFcmToken(PDO $pdo, int $staffId, string $deviceId, string $fcmToken, string $platform = 'android'): bool
{
    ensureMobileApiSchema($pdo);
    $deviceId  = substr(trim($deviceId), 0, 64);
    $fcmToken  = substr(trim($fcmToken), 0, 512);
    $platform  = $platform === 'ios' ? 'ios' : 'android';

    if ($staffId < 1 || $deviceId === '' || $fcmToken === '') {
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO fcm_device_tokens (staff_id, device_id, fcm_token, platform, is_active, last_seen_at)
             VALUES (:staff_id, :device_id, :token, :platform, 1, NOW())
             ON DUPLICATE KEY UPDATE
                fcm_token = VALUES(fcm_token),
                platform = VALUES(platform),
                is_active = 1,
                last_seen_at = NOW(),
                updated_at = NOW()'
        );
        $stmt->execute([
            'staff_id'  => $staffId,
            'device_id' => $deviceId,
            'token'     => $fcmToken,
            'platform'  => $platform,
        ]);

        return true;
    } catch (Throwable $e) {
        error_log('[MobileAPI] FCM register: ' . $e->getMessage());

        return false;
    }
}

function mobileUnregisterFcmToken(PDO $pdo, int $staffId, string $deviceId): bool
{
    ensureMobileApiSchema($pdo);

    try {
        $stmt = $pdo->prepare(
            'UPDATE fcm_device_tokens SET is_active = 0, updated_at = NOW()
             WHERE staff_id = :staff_id AND device_id = :device_id'
        );
        $stmt->execute([
            'staff_id'  => $staffId,
            'device_id' => substr(trim($deviceId), 0, 64),
        ]);

        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('[MobileAPI] FCM unregister: ' . $e->getMessage());

        return false;
    }
}

function mobileDeactivateAllFcmForStaff(PDO $pdo, int $staffId): void
{
    ensureMobileApiSchema($pdo);
    try {
        $stmt = $pdo->prepare(
            'UPDATE fcm_device_tokens SET is_active = 0, updated_at = NOW() WHERE staff_id = :staff_id'
        );
        $stmt->execute(['staff_id' => $staffId]);
    } catch (Throwable $e) {
        error_log('[MobileAPI] FCM deactivate all: ' . $e->getMessage());
    }
}

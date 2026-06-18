<?php

declare(strict_types=1);

require_once __DIR__ . '/../repositories/MobileFcmRepository.php';
require_once __DIR__ . '/../mobile-request.php';
require_once __DIR__ . '/../mobile-rate-limit.php';

function mobilePushWriteThrottle(int $staffId): void
{
    mobileThrottleOrFail('push_write_' . $staffId, 30, 60);
}

/**
 * @return array{ok: true, device_id: string, platform: string, registered: bool}|array{ok: false, message: string, code: string, status: int}
 */
function mobilePushServiceRegister(PDO $pdo, array $staff, array $body): array
{
    $staffId = (int) ($staff['id'] ?? 0);
    mobilePushWriteThrottle($staffId);

    $deviceId = trim((string) ($body['device_id'] ?? ''));
    $fcmToken = trim((string) ($body['fcm_token'] ?? ''));
    $platform = strtolower(trim((string) ($body['platform'] ?? 'android')));

    if (!mobileValidateDeviceId($deviceId)) {
        return [
            'ok'      => false,
            'message' => 'Invalid device_id.',
            'code'    => 'VALIDATION_ERROR',
            'status'  => 422,
        ];
    }

    if ($fcmToken === '' || strlen($fcmToken) > 512) {
        return [
            'ok'      => false,
            'message' => 'Invalid FCM token.',
            'code'    => 'VALIDATION_ERROR',
            'status'  => 422,
        ];
    }

    if ($staffId < 1) {
        return [
            'ok'      => false,
            'message' => 'Staff account is required.',
            'code'    => 'FORBIDDEN',
            'status'  => 403,
        ];
    }

    $ok = mobileRegisterFcmToken($pdo, $staffId, $deviceId, $fcmToken, $platform);
    if (!$ok) {
        return [
            'ok'      => false,
            'message' => 'Could not register push token.',
            'code'    => 'REGISTER_FAILED',
            'status'  => 500,
        ];
    }

    return [
        'ok'         => true,
        'device_id'  => $deviceId,
        'platform'   => $platform === 'ios' ? 'ios' : 'android',
        'registered' => true,
    ];
}

/**
 * @return array{ok: true, device_id: string, unregistered: bool}|array{ok: false, message: string, code: string, status: int}
 */
function mobilePushServiceUnregister(PDO $pdo, array $staff, string $deviceId): array
{
    $staffId = (int) ($staff['id'] ?? 0);
    mobilePushWriteThrottle($staffId);

    $deviceId = trim($deviceId);
    if (!mobileValidateDeviceId($deviceId)) {
        return [
            'ok'      => false,
            'message' => 'Invalid device_id.',
            'code'    => 'VALIDATION_ERROR',
            'status'  => 422,
        ];
    }

    if ($staffId < 1) {
        return [
            'ok'      => false,
            'message' => 'Staff account is required.',
            'code'    => 'FORBIDDEN',
            'status'  => 403,
        ];
    }

    $ok = mobileUnregisterFcmToken($pdo, $staffId, $deviceId);

    return [
        'ok'           => true,
        'device_id'    => $deviceId,
        'unregistered' => $ok,
    ];
}

/**
 * @return list<array{device_id: string, platform: string, is_active: bool, last_seen_at: string|null}>
 */
function mobilePushListActiveDevices(PDO $pdo, int $staffId): array
{
    ensureMobileApiSchema($pdo);

    if ($staffId < 1) {
        return [];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT device_id, platform, is_active, last_seen_at
             FROM fcm_device_tokens
             WHERE staff_id = :staff_id AND is_active = 1
             ORDER BY last_seen_at DESC'
        );
        $stmt->execute(['staff_id' => $staffId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            return [
                'device_id'    => (string) ($row['device_id'] ?? ''),
                'platform'     => (string) ($row['platform'] ?? 'android'),
                'is_active'    => (int) ($row['is_active'] ?? 0) === 1,
                'last_seen_at' => $row['last_seen_at'] ?? null,
            ];
        }, $rows);
    } catch (Throwable $e) {
        error_log('[MobileAPI] push list devices: ' . $e->getMessage());

        return [];
    }
}

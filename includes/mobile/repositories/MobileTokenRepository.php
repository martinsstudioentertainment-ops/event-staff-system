<?php

declare(strict_types=1);

require_once __DIR__ . '/../schema/mobile-api-schema.php';
require_once __DIR__ . '/../mobile-request.php';

const MOBILE_MAX_DEVICES_PER_STAFF = 5;

function mobileRefreshTokenHash(string $token): string
{
    return hash('sha256', $token);
}

function mobileCreateRefreshToken(
    PDO $pdo,
    int $staffId,
    string $deviceId,
    string $deviceLabel = '',
    string $userAgent = ''
): string {
    ensureMobileApiSchema($pdo);
    mobilePruneExpiredRefreshTokens($pdo, $staffId);

    $active = mobileCountActiveRefreshTokens($pdo, $staffId);
    if ($active >= MOBILE_MAX_DEVICES_PER_STAFF) {
        mobileRevokeOldestRefreshToken($pdo, $staffId);
    }

    $token     = bin2hex(random_bytes(32));
    $hash      = mobileRefreshTokenHash($token);
    $days      = mobileJwtRefreshDays($pdo);
    $expiresAt = (new DateTimeImmutable('now'))->modify('+' . $days . ' days')->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'INSERT INTO mobile_refresh_tokens
            (staff_id, token_hash, device_id, device_label, user_agent, expires_at)
         VALUES (:staff_id, :hash, :device_id, :label, :ua, :expires)'
    );
    $stmt->execute([
        'staff_id'  => $staffId,
        'hash'      => $hash,
        'device_id' => substr($deviceId, 0, 64),
        'label'     => $deviceLabel !== '' ? substr($deviceLabel, 0, 128) : null,
        'ua'        => $userAgent !== '' ? substr($userAgent, 0, 255) : null,
        'expires'   => $expiresAt,
    ]);

    return $token;
}

/**
 * @return array{staff_id: int, id: int}|null
 */
function mobileFindRefreshToken(PDO $pdo, string $token, string $deviceId): ?array
{
    ensureMobileApiSchema($pdo);
    $hash = mobileRefreshTokenHash($token);

    $stmt = $pdo->prepare(
        'SELECT id, staff_id FROM mobile_refresh_tokens
         WHERE token_hash = :hash AND device_id = :device_id
           AND revoked_at IS NULL AND expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute(['hash' => $hash, 'device_id' => substr($deviceId, 0, 64)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? ['id' => (int) $row['id'], 'staff_id' => (int) $row['staff_id']] : null;
}

function mobileRevokeRefreshTokenById(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare('UPDATE mobile_refresh_tokens SET revoked_at = NOW() WHERE id = :id');
    $stmt->execute(['id' => $id]);
}

function mobileRevokeAllRefreshTokens(PDO $pdo, int $staffId): void
{
    ensureMobileApiSchema($pdo);
    $stmt = $pdo->prepare(
        'UPDATE mobile_refresh_tokens SET revoked_at = NOW()
         WHERE staff_id = :staff_id AND revoked_at IS NULL'
    );
    $stmt->execute(['staff_id' => $staffId]);
}

function mobileRevokeRefreshTokenForDevice(PDO $pdo, int $staffId, string $deviceId): void
{
    ensureMobileApiSchema($pdo);
    $stmt = $pdo->prepare(
        'UPDATE mobile_refresh_tokens SET revoked_at = NOW()
         WHERE staff_id = :staff_id AND device_id = :device_id AND revoked_at IS NULL'
    );
    $stmt->execute(['staff_id' => $staffId, 'device_id' => substr($deviceId, 0, 64)]);
}

function mobileTouchRefreshToken(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare('UPDATE mobile_refresh_tokens SET last_used_at = NOW() WHERE id = :id');
    $stmt->execute(['id' => $id]);
}

function mobileCountActiveRefreshTokens(PDO $pdo, int $staffId): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM mobile_refresh_tokens
         WHERE staff_id = :staff_id AND revoked_at IS NULL AND expires_at > NOW()'
    );
    $stmt->execute(['staff_id' => $staffId]);

    return (int) $stmt->fetchColumn();
}

function mobileRevokeOldestRefreshToken(PDO $pdo, int $staffId): void
{
    $stmt = $pdo->prepare(
        'SELECT id FROM mobile_refresh_tokens
         WHERE staff_id = :staff_id AND revoked_at IS NULL
         ORDER BY COALESCE(last_used_at, created_at) ASC LIMIT 1'
    );
    $stmt->execute(['staff_id' => $staffId]);
    $id = (int) $stmt->fetchColumn();
    if ($id > 0) {
        mobileRevokeRefreshTokenById($pdo, $id);
    }
}

function mobilePruneExpiredRefreshTokens(PDO $pdo, int $staffId): void
{
    $stmt = $pdo->prepare(
        'DELETE FROM mobile_refresh_tokens
         WHERE staff_id = :staff_id AND (expires_at <= NOW() OR revoked_at IS NOT NULL)'
    );
    $stmt->execute(['staff_id' => $staffId]);
}

function mobileRotateRefreshToken(PDO $pdo, string $oldToken, string $deviceId): ?string
{
    $found = mobileFindRefreshToken($pdo, $oldToken, $deviceId);
    if ($found === null) {
        return null;
    }

    mobileRevokeRefreshTokenById($pdo, $found['id']);
    mobileTouchRefreshToken($pdo, $found['id']);

    return mobileCreateRefreshToken($pdo, $found['staff_id'], $deviceId, '', mobileUserAgent());
}

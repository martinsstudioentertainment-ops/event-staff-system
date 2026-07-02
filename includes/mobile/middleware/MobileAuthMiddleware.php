<?php

declare(strict_types=1);

require_once __DIR__ . '/../mobile-auth.php';
require_once __DIR__ . '/../mobile-response.php';
require_once __DIR__ . '/../../staff-repository.php';

/**
 * @return array{staff: array<string, mixed>, staff_id: int, email: string}
 */
function mobileRequireAuth(PDO $pdo): array
{
    $token = mobileBearerToken();
    if ($token === '') {
        mobileJsonError('Authentication required.', 401, 'UNAUTHORIZED');
    }

    $payload = mobileJwtDecode($token, mobileJwtSecret($pdo));
    if ($payload === null) {
        mobileJsonError('Invalid or expired access token.', 401, 'TOKEN_EXPIRED');
    }

    $staffId = (int) ($payload['sub'] ?? 0);
    $email   = strtolower(trim((string) ($payload['email'] ?? '')));

    if ($staffId < 1) {
        mobileJsonError('Invalid token subject.', 401, 'UNAUTHORIZED');
    }

    $staff = getStaffById($pdo, $staffId);
    if ($staff === null) {
        mobileJsonError('Staff not found.', 404, 'STAFF_NOT_FOUND');
    }

    if ($email !== '' && strtolower(trim((string) ($staff['email'] ?? ''))) !== $email) {
        mobileJsonError('Token email mismatch.', 401, 'UNAUTHORIZED');
    }

    if (mobileStaffIsBlacklisted($pdo, (string) ($staff['email'] ?? ''), $staff)) {
        mobileJsonError('Access denied.', 403, 'BLACKLISTED');
    }

    return [
        'staff'    => $staff,
        'staff_id' => $staffId,
        'email'    => strtolower(trim((string) ($staff['email'] ?? ''))),
    ];
}

/**
 * Optional bearer — returns null if absent or invalid (no exit).
 *
 * @return array{staff: array<string, mixed>, staff_id: int, email: string}|null
 */
function mobileOptionalAuth(PDO $pdo): ?array
{
    $token = mobileBearerToken();
    if ($token === '') {
        return null;
    }

    $payload = mobileJwtDecode($token, mobileJwtSecret($pdo));
    if ($payload === null) {
        return null;
    }

    $staffId = (int) ($payload['sub'] ?? 0);
    if ($staffId < 1) {
        return null;
    }

    $staff = getStaffById($pdo, $staffId);

    return is_array($staff)
        ? ['staff' => $staff, 'staff_id' => $staffId, 'email' => strtolower(trim((string) ($staff['email'] ?? '')))]
        : null;
}

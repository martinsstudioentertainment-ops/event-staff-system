<?php

/**
 * Public staff portal session (email + date of birth).
 */

require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/staff-registration-schema.php';

const STAFF_PORTAL_SESSION_TTL = 43200; // 12 hours

function staffPortalNormalizeDob(string $value): string
{
    return normalizeDateOfBirthForDb($value);
}

/**
 * @return array<string, mixed>|null
 */
function authenticateStaffPortal(PDO $pdo, string $email, string $dateOfBirth): ?array
{
    require_once __DIR__ . '/validation.php';

    $email = normalizeRegistrationEmail($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    $dobInput = staffPortalNormalizeDob($dateOfBirth);
    if ($dobInput === '') {
        return null;
    }

    ensureStaffRecordForEmail($pdo, $email);
    $staff = getStaffByEmail($pdo, $email);
    if ($staff === null) {
        return null;
    }

    $storedDob = staffPortalNormalizeDob((string) ($staff['date_of_birth'] ?? ''));
    if ($storedDob === '' || $storedDob !== $dobInput) {
        return null;
    }

    return $staff;
}

function establishStaffPortalSession(array $staff): void
{
    $_SESSION['staff_portal_staff_id']    = (int) $staff['id'];
    $_SESSION['staff_portal_verified_at'] = time();
}

function clearStaffPortalSession(): void
{
    unset($_SESSION['staff_portal_staff_id'], $_SESSION['staff_portal_verified_at']);
}

/**
 * @return array<string, mixed>|null
 */
function getStaffFromPortalSession(PDO $pdo): ?array
{
    $staffId    = (int) ($_SESSION['staff_portal_staff_id'] ?? 0);
    $verifiedAt = (int) ($_SESSION['staff_portal_verified_at'] ?? 0);

    if ($staffId < 1 || $verifiedAt < 1 || (time() - $verifiedAt) > STAFF_PORTAL_SESSION_TTL) {
        clearStaffPortalSession();

        return null;
    }

    return getStaffById($pdo, $staffId);
}

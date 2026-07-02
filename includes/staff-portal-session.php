<?php

/**
 * Public staff portal session (email + last 4 of PPS — same as venue sign-in).
 */

require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/staff-registration-schema.php';
require_once __DIR__ . '/app-environment.php';
require_once __DIR__ . '/staff-portal-remember.php';

const STAFF_PORTAL_SESSION_TTL = APP_SESSION_IDLE_TTL;

function staffPortalNormalizeDob(string $value): string
{
    return normalizeDateOfBirthForDb($value);
}

/**
 * @return array<string, mixed>|null
 */
function authenticateStaffPortal(PDO $pdo, string $email, string $ppsLast4): ?array
{
    require_once __DIR__ . '/validation.php';
    require_once __DIR__ . '/sensitive-data.php';
    require_once __DIR__ . '/signin-display.php';

    $email = normalizeRegistrationEmail($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    $ppsLast4 = strtoupper(preg_replace('/\s+/', '', trim($ppsLast4)));
    if (!isSigninPpsRequired($pdo)) {
        if ($ppsLast4 !== '') {
            return null;
        }
    } elseif (!isValidPpsLastFourInput($ppsLast4)) {
        return null;
    }

    ensureStaffRecordForEmail($pdo, $email);
    $staff = getStaffByEmail($pdo, $email);
    if ($staff === null) {
        return null;
    }

    if (signinIdentityMatches($staff, $ppsLast4, $pdo)) {
        return $staff;
    }

    $stmt = $pdo->prepare(
        "SELECT pps_number FROM staff_registrations
         WHERE LOWER(email) = :email AND status IN ('approved', 'pending')
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute(['email' => strtolower($email)]);
    $regPps = (string) ($stmt->fetchColumn() ?: '');
    if ($regPps !== '' && signinIdentityMatches(['pps_number' => $regPps], $ppsLast4, $pdo)) {
        return $staff;
    }

    return null;
}

function establishStaffPortalSession(array $staff): void
{
    $_SESSION['staff_portal_staff_id']       = (int) $staff['id'];
    $_SESSION['staff_portal_last_activity']  = time();
    unset($_SESSION['staff_portal_verified_at']);
}

function clearStaffPortalSession(): void
{
    unset(
        $_SESSION['staff_portal_staff_id'],
        $_SESSION['staff_portal_verified_at'],
        $_SESSION['staff_portal_last_activity'],
        $_SESSION['staff_portal_remembered']
    );
    clearStaffRememberCookie();
}

function staffPortalSignOutUrl(string $return = 'staff-app.php'): string
{
    $return = trim($return);
    if ($return === '') {
        $return = 'staff-app.php';
    }

    return 'staff-signout.php?' . http_build_query(['return' => $return]);
}

function staffPortalSessionActive(): bool
{
    $staffId = (int) ($_SESSION['staff_portal_staff_id'] ?? 0);
    if ($staffId < 1) {
        return false;
    }

    if (staffPortalUsesRememberCookie()) {
        return true;
    }

    $last = (int) ($_SESSION['staff_portal_last_activity'] ?? $_SESSION['staff_portal_verified_at'] ?? 0);

    return $last > 0 && (time() - $last) <= STAFF_PORTAL_SESSION_TTL;
}

function touchStaffPortalActivity(): void
{
    if ((int) ($_SESSION['staff_portal_staff_id'] ?? 0) > 0) {
        $_SESSION['staff_portal_last_activity'] = time();
    }
}

/**
 * @return array<string, mixed>|null
 */
function getStaffFromPortalSession(PDO $pdo): ?array
{
    if ((int) ($_SESSION['staff_portal_staff_id'] ?? 0) < 1) {
        restoreStaffPortalFromRememberCookie($pdo);
    }

    $staffId = (int) ($_SESSION['staff_portal_staff_id'] ?? 0);
    if ($staffId < 1) {
        return null;
    }

    if (!staffPortalUsesRememberCookie()) {
        $last = (int) ($_SESSION['staff_portal_last_activity'] ?? $_SESSION['staff_portal_verified_at'] ?? 0);
        if ($last < 1 || (time() - $last) > STAFF_PORTAL_SESSION_TTL) {
            unset(
                $_SESSION['staff_portal_staff_id'],
                $_SESSION['staff_portal_verified_at'],
                $_SESSION['staff_portal_last_activity'],
                $_SESSION['staff_portal_remembered']
            );

            return null;
        }
    }

    touchStaffPortalActivity();

    return getStaffById($pdo, $staffId);
}

function renderStaffPortalBodyAttributes(?array $portalStaff, ?PDO $pdo = null): string
{
    if ($portalStaff === null) {
        return '';
    }

    $idleTtl = staffPortalUsesRememberCookie() ? 0 : STAFF_PORTAL_SESSION_TTL;

    $attrs = [
        'data-staff-portal-session' => '1',
        'data-session-idle-timeout' => (string) $idleTtl,
        'data-session-signout-url'  => staffPortalSignOutUrl('staff-app.php?signed_out=1'),
    ];

    if ($pdo !== null) {
        require_once __DIR__ . '/staff-portal-shift.php';
        foreach (staffPortalShiftBodyAttributes($pdo, $portalStaff) as $key => $value) {
            $attrs[$key] = $value;
        }
    }

    $parts = [];
    foreach ($attrs as $key => $value) {
        $parts[] = $key . '="' . h((string) $value) . '"';
    }

    return implode(' ', $parts);
}

function renderStaffPortalSessionIdleScript(?PDO $pdo = null, ?array $portalStaff = null): void
{
    $path = dirname(__DIR__) . '/assets/js/session-idle-timeout.js';
    $ver  = is_file($path) ? (string) filemtime($path) : '1';
    ?>
    <script src="assets/js/session-idle-timeout.js?v=<?= h($ver) ?>"></script>
    <?php
    if ($pdo !== null) {
        require_once __DIR__ . '/staff-portal-shift.php';
        renderStaffPortalShiftMonitorScript($pdo, $portalStaff);
    }
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/main-admin-bridge.php';

/**
 * Canonical public URLs on register.olasentra.com (not apply.olasentra.com).
 *
 * @return array{registration: string, staff_portal: string, staff_profile: string}
 */
function apply_canonical_public_urls(): array
{
    $reg = 'https://register.olasentra.com';

    $mainPdo = getMainAdminPdo();
    if ($mainPdo instanceof PDO) {
        $fromDb = trim(apply_read_main_setting($mainPdo, 'registration_site_url', ''));
        if ($fromDb !== '') {
            $reg = rtrim($fromDb, '/');
        }
    } elseif (defined('REGISTRATION_SITE_URL') && (string) REGISTRATION_SITE_URL !== '') {
        $reg = rtrim((string) REGISTRATION_SITE_URL, '/');
    }

    return [
        'registration'  => $reg . '/index.php',
        'staff_portal'  => $reg . '/staff-portal.php',
        'staff_profile' => $reg . '/staff-profile.php',
    ];
}

/** @return list<string> Basenames retired from apply.olasentra.com public docroot. */
function apply_retired_public_surfaces(): array
{
    return [
        'update-details.php',
        'index.php',
    ];
}

/**
 * 301 redirect legacy apply public pages to register.olasentra.com.
 */
function apply_redirect_legacy_public_surface(string $surface): void
{
    $urls = apply_canonical_public_urls();
    $target = match ($surface) {
        'update-details'    => $urls['staff_portal'],
        'legacy-apply-form' => $urls['registration'],
        default             => $urls['registration'],
    };

    header('Location: ' . $target, true, 301);
    exit;
}

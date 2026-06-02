<?php
/**
 * Single source for public-site branding — admin settings apply everywhere.
 */

require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/company.php';
require_once __DIR__ . '/theme.php';
require_once __DIR__ . '/site-urls.php';
require_once __DIR__ . '/brand-logo.php';

/**
 * Branding + URLs used on homepage, registration form, sign-in, emails, etc.
 *
 * @return array{
 *   companyName: string,
 *   siteName: string,
 *   tagline: string,
 *   email: string,
 *   phone: string,
 *   themeColor: string,
 *   registrationUrl: string,
 *   homeUrl: string,
 *   logoUrl: string,
 *   hasLogo: bool
 * }
 */
function getGlobalPublicSiteConfig(?PDO $pdo, string $assetBase = ''): array
{
    if ($pdo === null) {
        return [
            'companyName'     => 'Event Staff Ireland',
            'siteName'        => 'Event Staff System',
            'tagline'         => '',
            'email'           => '',
            'phone'           => '',
            'themeColor'      => '#2563eb',
            'registrationUrl' => 'index.php',
            'homeUrl'         => 'home.php',
            'logoUrl'         => '',
            'hasLogo'         => false,
        ];
    }

    $companyName = getCompanyName($pdo);

    return [
        'companyName'     => $companyName,
        'siteName'        => getSiteName($pdo),
        'tagline'         => getCompanyTagline($pdo),
        'email'           => getCompanyEmail($pdo),
        'phone'           => getCompanyPhone($pdo),
        'themeColor'      => getThemeColor($pdo),
        'registrationUrl' => getRegistrationFormUrl($pdo),
        'homeUrl'         => normalizePublicSiteUrl(getAppBaseUrl()) . '/home.php',
        'logoUrl'         => getCompanyLogoUrl($pdo, $assetBase),
        'hasLogo'         => hasCompanyLogo($pdo),
    ];
}

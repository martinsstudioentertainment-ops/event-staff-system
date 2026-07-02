<?php

declare(strict_types=1);

/**
 * Short registration URLs on register.olasentra.com (e.g. /steward → steward form).
 */

require_once __DIR__ . '/registration-forms.php';
require_once __DIR__ . '/site-urls.php';

/** Built-in paths served by .htaccess without an extra redirect hop. */
function getRegistrationBuiltinShortLinkSlugs(): array
{
    return ['dsp', 'static', 'both', 'steward'];
}

function isRegistrationBuiltinShortLinkSlug(string $slug): bool
{
    return in_array(normalizeRegistrationFormSlug($slug), getRegistrationBuiltinShortLinkSlugs(), true);
}

/**
 * Relative path for a short link (/steward) or full index.php?form= fallback.
 */
function registrationShortLinkPath(string $formSlug, bool $preferShort = true): string
{
    $slug = normalizeRegistrationFormSlug($formSlug);
    if ($slug === '') {
        return '/index.php';
    }

    if ($preferShort && isRegistrationBuiltinShortLinkSlug($slug)) {
        return '/' . $slug;
    }

    return '/index.php?form=' . rawurlencode($slug);
}

function registrationShortLinkUrl(?PDO $pdo, string $formSlug, bool $preferShort = true): string
{
    return getRegistrationSiteUrl($pdo) . registrationShortLinkPath($formSlug, $preferShort);
}

/**
 * @return array<string, string> slug => full short URL
 */
function getRegistrationBuiltinShortLinkUrls(?PDO $pdo = null): array
{
    $out = [];
    foreach (getRegistrationBuiltinShortLinkSlugs() as $slug) {
        $out[$slug] = registrationShortLinkUrl($pdo, $slug);
    }

    return $out;
}

/** Shortest branded link when go.olasentra.com subdomain is configured (cPanel). */
function getGoRegistrationSiteUrl(?PDO $pdo = null): string
{
    if (defined('GO_REGISTRATION_SITE_URL') && GO_REGISTRATION_SITE_URL !== '') {
        return normalizePublicSiteUrl((string) GO_REGISTRATION_SITE_URL);
    }

    if ($pdo !== null) {
        $fromDb = normalizePublicSiteUrl(getSetting($pdo, 'go_registration_site_url', ''));
        if ($fromDb !== '') {
            return $fromDb;
        }
    }

    return 'https://go.olasentra.com';
}

function registrationGoShortLinkUrl(?PDO $pdo, string $formSlug): string
{
    $slug = normalizeRegistrationFormSlug($formSlug);
    if ($slug === '' || !isRegistrationBuiltinShortLinkSlug($slug)) {
        return registrationShortLinkUrl($pdo, $formSlug);
    }

    return getGoRegistrationSiteUrl($pdo) . '/' . $slug;
}

/**
 * Resolve /r/{slug} or ?form= for any enabled registration form.
 */
function resolveRegistrationShortLinkTarget(PDO $pdo, string $slug): ?string
{
    $slug = normalizeRegistrationFormSlug($slug);
    if ($slug === '' || in_array($slug, getReservedRegistrationFormSlugs(), true)) {
        return null;
    }

    $form = getRegistrationForm($pdo, $slug);
    if ($form === null) {
        return null;
    }

    return 'index.php?form=' . rawurlencode($slug);
}

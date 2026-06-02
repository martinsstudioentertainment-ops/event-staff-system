<?php
/**
 * Public registration site vs admin panel URLs.
 * The staff form often lives on its own URL — not the main website or a subdomain of admin.
 */

require_once __DIR__ . '/settings-repository.php';

function isValidPublicSiteUrl(string $url): bool
{
    if ($url === '') {
        return true;
    }

    return filter_var($url, FILTER_VALIDATE_URL) !== false
        && preg_match('#^https?://#i', $url) === 1;
}

function normalizePublicSiteUrl(string $url): string
{
    return rtrim(trim($url), '/');
}

/**
 * Main marketing site (home.php, FAQ) — not the register subdomain.
 */
function getMarketingSiteUrl(?PDO $pdo = null): string
{
    if (defined('MAIN_SITE_URL') && MAIN_SITE_URL !== '') {
        return normalizePublicSiteUrl((string) MAIN_SITE_URL);
    }

    $admin = getAdminSiteUrl($pdo);
    if (str_ends_with($admin, '/admin')) {
        return normalizePublicSiteUrl(substr($admin, 0, -strlen('/admin')));
    }

    return normalizePublicSiteUrl(getAppBaseUrl());
}

function isRegistrationSubdomain(): bool
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;

    if ($host === '') {
        return false;
    }

    if (defined('REGISTRATION_SITE_URL') && REGISTRATION_SITE_URL !== '') {
        $regHost = parse_url(REGISTRATION_SITE_URL, PHP_URL_HOST);
        if (is_string($regHost) && $regHost !== '' && strtolower($regHost) === $host) {
            return true;
        }
    }

    return str_starts_with($host, 'register.');
}

/**
 * Base URL for the staff registration site (where index.php lives).
 */
function getRegistrationSiteUrl(?PDO $pdo = null): string
{
    if ($pdo === null && function_exists('getDB')) {
        try {
            $pdo = getDB();
        } catch (Throwable $e) {
            $pdo = null;
        }
    }

    if ($pdo !== null) {
        $fromDb = normalizePublicSiteUrl(getSetting($pdo, 'registration_site_url', ''));
        if ($fromDb !== '') {
            return $fromDb;
        }
    }

    if (defined('REGISTRATION_SITE_URL') && REGISTRATION_SITE_URL !== '') {
        return normalizePublicSiteUrl(REGISTRATION_SITE_URL);
    }

    return normalizePublicSiteUrl(getAppBaseUrl());
}

function getRegistrationFormUrl(?PDO $pdo = null, ?string $slug = null): string
{
    $base = getRegistrationSiteUrl($pdo) . '/index.php';

    if ($slug === null || trim($slug) === '') {
        return $base;
    }

    return $base . '?form=' . rawurlencode(strtolower(trim($slug)));
}

/**
 * Base URL for this admin installation (where /admin/ lives).
 */
function getAdminSiteUrl(?PDO $pdo = null): string
{
    if ($pdo === null && function_exists('getDB')) {
        try {
            $pdo = getDB();
        } catch (Throwable $e) {
            $pdo = null;
        }
    }

    if ($pdo !== null) {
        $fromDb = normalizePublicSiteUrl(getSetting($pdo, 'admin_site_url', ''));
        if ($fromDb !== '') {
            return $fromDb;
        }
    }

    if (defined('ADMIN_SITE_URL') && ADMIN_SITE_URL !== '') {
        return normalizePublicSiteUrl(ADMIN_SITE_URL);
    }

    if (isAdminSubdomain()) {
        return normalizePublicSiteUrl(getAppBaseUrl());
    }

    return normalizePublicSiteUrl(getAppBaseUrl()) . '/admin';
}

function isAdminSubdomain(): bool
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;

    if ($host === '') {
        return false;
    }

    if (defined('ADMIN_SITE_URL') && ADMIN_SITE_URL !== '') {
        $adminHost = parse_url(ADMIN_SITE_URL, PHP_URL_HOST);
        if (is_string($adminHost) && $adminHost !== '' && strtolower($adminHost) === $host) {
            return true;
        }
    }

    return str_starts_with($host, 'admin.');
}

function isAdminRequest(): bool
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

    return str_contains($script, '/admin/');
}

function registrationSiteIsExternal(?PDO $pdo = null): bool
{
    if ($pdo === null && function_exists('getDB')) {
        try {
            $pdo = getDB();
        } catch (Throwable $e) {
            return false;
        }
    }

    if ($pdo === null) {
        return false;
    }

    $configured = trim(getSetting($pdo, 'registration_site_url', ''));
    if ($configured !== '') {
        return true;
    }

    return defined('REGISTRATION_SITE_URL') && REGISTRATION_SITE_URL !== '';
}

function getWebsiteNoticeEditUrl(?PDO $pdo = null): string
{
    return getAdminSiteUrl($pdo) . '/website-global.php#notice_items';
}

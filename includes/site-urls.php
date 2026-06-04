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
/**
 * Guess apply.* host from admin.* (e.g. admin.olasentra.com → apply.olasentra.com).
 */
function inferApplySiteUrl(?PDO $pdo = null): string
{
    $adminUrls = [];

    if (defined('ADMIN_SITE_URL') && ADMIN_SITE_URL !== '') {
        $adminUrls[] = normalizePublicSiteUrl((string) ADMIN_SITE_URL);
    }

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
            $adminUrls[] = $fromDb;
        }
    }

    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;
    if ($host !== '' && str_starts_with($host, 'admin.')) {
        $adminUrls[] = 'https://' . $host;
    }

    foreach (array_values(array_unique($adminUrls)) as $adminUrl) {
        $adminHost = parse_url($adminUrl, PHP_URL_HOST);
        if (!is_string($adminHost) || $adminHost === '') {
            continue;
        }

        if (str_starts_with($adminHost, 'admin.')) {
            return normalizePublicSiteUrl('https://apply.' . substr($adminHost, 6));
        }
    }

    return '';
}

/**
 * Apply host document root (on server this is the apply/admin folder).
 * SSO lives at {root}/sso.php; ERP UI at {root}/admin/dashboard.php.
 */
function normalizeApplySiteUrl(string $url): string
{
    $url = normalizePublicSiteUrl($url);
    if (str_ends_with($url, '/admin')) {
        $url = substr($url, 0, -strlen('/admin'));
    }

    return $url;
}

/**
 * Apply / staff-profile site (apply.olasentra.com).
 */
function getApplySiteUrl(?PDO $pdo = null): string
{
    if ($pdo === null && function_exists('getDB')) {
        try {
            $pdo = getDB();
        } catch (Throwable $e) {
            $pdo = null;
        }
    }

    if ($pdo !== null) {
        $fromDb = normalizeApplySiteUrl(getSetting($pdo, 'apply_site_url', ''));
        if ($fromDb !== '') {
            return $fromDb;
        }
    }

    if (defined('APPLY_SITE_URL') && APPLY_SITE_URL !== '') {
        return normalizeApplySiteUrl((string) APPLY_SITE_URL);
    }

    $inferred = inferApplySiteUrl($pdo);
    if ($inferred !== '') {
        return $inferred;
    }

    if (function_exists('isProductionApp') && !isProductionApp()) {
        return normalizePublicSiteUrl(getAppBaseUrl()) . '/apply/admin';
    }

    return '';
}

/** Document root on the apply host (sso.php, index.php, sync/, admin/ UI). */
function getApplyAdminRootUrl(?PDO $pdo = null): string
{
    return getApplySiteUrl($pdo);
}

function getApplyAdminSsoUrl(?PDO $pdo = null): string
{
    $root = getApplyAdminRootUrl($pdo);

    return $root !== '' ? $root . '/sso.php' : '';
}

function getApplyAdminDashboardUrl(?PDO $pdo = null): string
{
    $root = getApplyAdminRootUrl($pdo);

    return $root !== '' ? $root . '/admin/dashboard.php' : '';
}

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

/**
 * Main domain for @addresses (SPF/DKIM should be on this host, not register.* subdomain).
 */
function getMainSiteEmailDomain(?PDO $pdo = null): string
{
    $url = getMarketingSiteUrl($pdo);
    $host = parse_url($url, PHP_URL_HOST);

    if (is_string($host) && $host !== '') {
        return strtolower($host);
    }

    return 'olasentra.com';
}

/**
 * Recommended addresses for production (one sender; links use register/admin URLs).
 *
 * @return array{from_name: string, from_email: string, contact_email: string, reply_hint: string}
 */
function getDefaultSmtpHost(?PDO $pdo = null): string
{
    return 'mail.' . getMainSiteEmailDomain($pdo);
}

function getRecommendedProductionEmails(?PDO $pdo = null): array
{
    $domain = getMainSiteEmailDomain($pdo);
    $name   = 'Olasentra';
    if ($pdo !== null) {
        $company = trim(getSetting($pdo, 'company_name', ''));
        if ($company !== '') {
            $name = $company;
        } elseif (trim(getSetting($pdo, 'site_name', '')) !== '') {
            $name = trim(getSetting($pdo, 'site_name', ''));
        } elseif (trim(getSetting($pdo, 'mail_from_name', '')) !== '') {
            $name = trim(getSetting($pdo, 'mail_from_name', ''));
        }
    }

    return [
        'from_name'     => $name,
        'from_email'    => 'noreply@' . $domain,
        'contact_email' => 'info@' . $domain,
        'reply_hint'    => 'Staff reply to contact email in General settings — not register@ or admin@ unless you create those mailboxes.',
    ];
}

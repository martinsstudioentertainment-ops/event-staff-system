<?php
/**
 * Production config template — copy to config.php on the server (never commit config.php).
 *
 * cPanel File Manager: duplicate this file → rename to config.php → edit DB_* and URLs below.
 */
define('DB_HOST', 'localhost');
define('DB_NAME', 'olastofx_eventstaff');
define('DB_USER', 'olastofx_dbuser');
define('DB_PASS', 'YOUR_DATABASE_PASSWORD_HERE');

/** Marketing site (home, FAQ, contact). */
define('MAIN_SITE_URL', 'https://olasentra.com');
/** Staff registration + staff-app (subdomain). Same DB as main site. */
define('REGISTRATION_SITE_URL', 'https://register.olasentra.com');
/** Admin ERP — admin subdomain (/login.php, /dashboard.php, …). */
define('ADMIN_SITE_URL', 'https://admin.olasentra.com');
define('APP_ENV', 'production');

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/app-environment.php';

if (shouldBootstrapSession()) {
    initSecureSession();
}

require_once __DIR__ . '/includes/site-urls.php';
require_once __DIR__ . '/includes/system-settings.php';
require_once __DIR__ . '/includes/i18n.php';

/**
 * @return PDO
 */
function getDB(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    return $pdo;
}

function getAppBaseUrl(): string
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return rtrim($protocol . '://' . $host, '/');
}

try {
    $bootPdo = getDB();
    applySystemRuntimeSettings($bootPdo);
    bootstrapAppLocale($bootPdo);
} catch (Throwable $e) {
    applySystemRuntimeSettings(null);
    bootstrapAppLocale(null);
}

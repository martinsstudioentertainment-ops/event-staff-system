<?php
/**
 * Writes config.php from environment variables (GitHub Actions / CI).
 * Usage: set DB_NAME, DB_USER, DB_PASS, then: php scripts/generate-production-config.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$out  = $root . '/config.php';

foreach (['DB_NAME', 'DB_USER', 'DB_PASS'] as $key) {
    $value = getenv($key);
    if ($value === false || $value === '') {
        fwrite(STDERR, "Skip: {$key} not set — config.php not generated.\n");
        exit(0);
    }
}

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = (string) getenv('DB_NAME');
$dbUser = (string) getenv('DB_USER');
$dbPass = (string) getenv('DB_PASS');
$regUrl = getenv('REGISTRATION_SITE_URL') ?: 'https://olasentra.com';
$admUrl = getenv('ADMIN_SITE_URL') ?: 'https://olasentra.com/admin';

$content = '<?php
/**
 * Event Staff System — Production config (auto-generated, do not commit)
 */
define(\'DB_HOST\', ' . var_export($dbHost, true) . ');
define(\'DB_NAME\', ' . var_export($dbName, true) . ');
define(\'DB_USER\', ' . var_export($dbUser, true) . ');
define(\'DB_PASS\', ' . var_export($dbPass, true) . ');

define(\'REGISTRATION_SITE_URL\', ' . var_export($regUrl, true) . ');
define(\'ADMIN_SITE_URL\', ' . var_export($admUrl, true) . ');
define(\'APP_ENV\', \'production\');

require_once __DIR__ . \'/includes/helpers.php\';
require_once __DIR__ . \'/includes/app-environment.php\';

if (shouldBootstrapSession()) {
    initSecureSession();
}

require_once __DIR__ . \'/includes/site-urls.php\';
require_once __DIR__ . \'/includes/system-settings.php\';
require_once __DIR__ . \'/includes/i18n.php\';

/**
 * @return PDO
 */
function getDB(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = \'mysql:host=\' . DB_HOST . \';dbname=\' . DB_NAME . \';charset=utf8mb4\';
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
    $protocol = (!empty($_SERVER[\'HTTPS\']) && $_SERVER[\'HTTPS\'] !== \'off\') ? \'https\' : \'http\';
    $host     = $_SERVER[\'HTTP_HOST\'] ?? \'localhost\';

    return rtrim($protocol . \'://\' . $host, \'/\');
}

try {
    $bootPdo = getDB();
    applySystemRuntimeSettings($bootPdo);
    bootstrapAppLocale($bootPdo);
} catch (Throwable $e) {
    applySystemRuntimeSettings(null);
    bootstrapAppLocale(null);
}
';

file_put_contents($out, $content);
echo "Wrote config.php for production deploy.\n";

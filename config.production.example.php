<?php
/**
 * Production config example — copy values into config.php on your live server.
 *
 * DO NOT commit real passwords to git.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');

/** Public staff registration site (HTTPS). */
define('REGISTRATION_SITE_URL', 'https://register.yourcompany.com');

/** Admin panel URL (HTTPS). Can match registration host if admin is in /admin/. */
define('ADMIN_SITE_URL', 'https://manage.yourcompany.com/admin');

/** Must be production on live servers. */
define('APP_ENV', 'production');

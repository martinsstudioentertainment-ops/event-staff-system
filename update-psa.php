<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/site-urls.php';

$pdo = null;
try {
    $pdo = getDB();
} catch (Throwable $e) {
    $pdo = null;
}

$admin = '';
if (defined('ADMIN_SITE_URL') && ADMIN_SITE_URL !== '') {
    $admin = rtrim((string) ADMIN_SITE_URL, '/');
}
if ($admin === '') {
    $admin = rtrim(getAdminSiteUrl($pdo), '/');
}
if ($admin === '' || str_contains(strtolower($admin), 'register.')) {
    $admin = 'https://admin.olasentra.com';
}

header('Location: ' . $admin . '/login.php?return=' . rawurlencode('psa-update.php'), true, 302);
exit;

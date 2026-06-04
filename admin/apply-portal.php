<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/site-urls.php';
require_once __DIR__ . '/../includes/apply-sso.php';

requireAdminCapability('apply');

$ssoUrl = getApplyAdminSsoUrl();
if ($ssoUrl === '') {
    setAdminFlash('error', 'Apply admin URL is not configured. Set APPLY_SITE_URL in config.php (e.g. https://apply.olasentra.com/admin).');
    header('Location: dashboard.php');
    exit;
}

$admin = getAdminUser();
if ($admin === null) {
    header('Location: login.php');
    exit;
}

$token = createApplySsoToken((int) $admin['id']);
$target = $ssoUrl . '?' . http_build_query(['token' => $token]);

header('Location: ' . $target);
exit;

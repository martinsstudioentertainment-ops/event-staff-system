<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/site-urls.php';
require_once __DIR__ . '/../includes/apply-sso.php';

requireAdminCapability('apply');

$dashboardUrl = getApplyAdminDashboardUrl();
if ($dashboardUrl === '') {
    setAdminFlash('error', 'Apply admin URL is not configured. Open ERP settings → General and set Apply admin URL (e.g. https://apply.olasentra.com).');
    header('Location: dashboard.php');
    exit;
}

$admin = getAdminUser();
if ($admin === null) {
    header('Location: login.php');
    exit;
}

// Shared cookie on .olasentra.com — apply reads this (no sso.php upload required).
setApplySsoCookie((int) $admin['id']);

header('Location: ' . $dashboardUrl);
exit;

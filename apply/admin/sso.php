<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/apply-sso.php';
require_once __DIR__ . '/includes/main-admin-bridge.php';

$token = trim((string) ($_GET['token'] ?? ''));
$payload = verifyApplySsoToken($token);

if ($payload === null) {
    require_once __DIR__ . '/includes/apply-friendly.php';
    apply_render_error_page(
        'Sign-in link expired',
        'This Apply admin sign-in link is invalid or has expired. Open Apply admin from the main ERP console (Apply admin) to get a new link.'
    );
}

$user = fetchMainAdminUser($payload['admin_id']);
if ($user === null || !applyAdminRoleAllowed((string) ($user['role'] ?? ''))) {
    require_once __DIR__ . '/includes/apply-friendly.php';
    apply_render_error_page(
        'Access denied',
        'Your account cannot access Apply admin. Use an administrator or manager account from the main ERP.'
    );
}

setApplyAdminSession($user);

header('Location: admin/dashboard.php');
exit;

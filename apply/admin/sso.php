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
    http_response_code(403);
    echo 'Invalid or expired sign-in link. Open Apply admin from the main ERP sidebar (Apply admin).';
    exit;
}

$user = fetchMainAdminUser($payload['admin_id']);
if ($user === null || !applyAdminRoleAllowed((string) ($user['role'] ?? ''))) {
    http_response_code(403);
    echo 'Your account cannot access Apply admin.';
    exit;
}

setApplyAdminSession($user);

header('Location: admin/dashboard.php');
exit;

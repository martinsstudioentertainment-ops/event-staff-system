<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/apply-sso.php';

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}

clearApplySsoCookie();
session_destroy();

$timeout = isset($_GET['timeout']) || (isset($_GET['reason']) && $_GET['reason'] === 'idle');
header('Location: login.php' . ($timeout ? '?timeout=1' : ''));
exit;

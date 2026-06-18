<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';

$timeout = isset($_GET['timeout']) || (isset($_GET['reason']) && $_GET['reason'] === 'idle');
logoutAdmin();
header('Location: login.php' . ($timeout ? '?timeout=1' : ''));
exit;

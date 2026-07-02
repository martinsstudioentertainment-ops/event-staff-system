<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-capabilities.php';

requireAdminCapability('dashboard');
header('Location: command-center.php');
exit;

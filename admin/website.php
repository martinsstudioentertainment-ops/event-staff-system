<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin/admin-nav.php';

$tab = (string) ($_GET['tab'] ?? '');
header('Location: ' . websiteTabRedirectUrl($tab), true, 302);
exit;

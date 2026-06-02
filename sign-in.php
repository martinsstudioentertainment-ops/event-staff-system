<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/settings-repository.php';
require_once __DIR__ . '/includes/event-sign-flow.php';

$pdo        = getDB();
$eventToken = trim((string) ($_GET['e'] ?? $_POST['e'] ?? ''));
$siteName   = getSiteName($pdo);
$state      = handleEventEmailSigninRequest($pdo, $eventToken, false);

renderEventSigninPage($state, $eventToken, false, $siteName);

<?php
header('Content-Type: text/plain');
error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once dirname(__DIR__) . '/config.php';
$files = [
    'includes/staff-app-v3-data.php',
    'includes/staff-portal-dashboard.php',
    'includes/staff-portal-shift.php',
    'includes/staff-portal-session.php',
    'includes/staff-google-oauth.php',
    'includes/staff-venue-checkin.php',
    'includes/staff-profile-gate.php',
    'includes/staff-messages.php',
    'includes/notification-center.php',
];
foreach ($files as $rel) {
    try {
        require_once dirname(__DIR__) . '/' . $rel;
        echo basename($rel) . ':ok ';
    } catch (Throwable $e) {
        echo basename($rel) . ':FAIL ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
        exit;
    }
}
echo 'ALL_OK';

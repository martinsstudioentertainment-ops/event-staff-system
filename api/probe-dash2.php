<?php
header('Content-Type: text/plain');
require_once dirname(__DIR__) . '/config.php';
guardDevOnlyEndpoint('Probe disabled in production.');

$files = [
    'auth.php',
    'validation.php',
    'registration-forms.php',
    'registration-options-repository.php',
    'staff-repository.php',
    'notifications.php',
    'system-settings.php',
    'staff-blacklist.php',
    'google-sheets-sync.php',
    'staff-registration-schema.php',
    'status-repository.php',
    'staff-psa.php',
    'staff-profile-gate.php',
    'registration-post-save.php',
    'staff-allocation.php',
    'staff-google-oauth.php',
];
echo 'config_ok ';
foreach ($files as $file) {
    require_once dirname(__DIR__) . '/includes/' . $file;
    echo $file . '_ok ';
}
echo 'done';

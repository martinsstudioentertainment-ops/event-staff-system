<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST']       = 'admin.olasentra.com';
$_SERVER['REQUEST_URI']     = '/events.php';
$_SERVER['HTTPS']           = 'on';
$_SERVER['REQUEST_METHOD']  = 'GET';

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/events-repository.php';
require_once dirname(__DIR__) . '/includes/venues-repository.php';
require_once dirname(__DIR__) . '/includes/google-sheets-sync.php';
require_once dirname(__DIR__) . '/includes/live-events-sync.php';
require_once dirname(__DIR__) . '/includes/admin-pagination.php';
require_once dirname(__DIR__) . '/includes/event-capacity.php';

$pdo       = getDB();
$allEvents = getAllEvents($pdo);
echo 'events=' . count($allEvents) . PHP_EOL;

$sheetStatus = countEventsGoogleSheetStatus($pdo);
echo 'sheets=' . json_encode($sheetStatus) . PHP_EOL;

$hasSa          = isGoogleServiceAccountConfigured();
$hasDriveFolder = getGoogleSheetsDriveParentFolderId($pdo) !== '';
$canAutoSheet   = $hasSa && $hasDriveFolder;
$driveSheets    = $canAutoSheet ? listGoogleDriveSpreadsheetsForAdmin($pdo) : [];
echo 'driveSheets=' . count($driveSheets) . PHP_EOL;
echo 'OK' . PHP_EOL;

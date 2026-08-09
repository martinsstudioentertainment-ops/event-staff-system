<?php

/**
 * Activate hibernated (PRE_CHECKED_IN) attendance when events reach start time.
 *
 * CLI:
 *   php cron/attendance-activate.php
 *
 * Web cron (same key as daily reminders — Admin → Email → reminder cron key):
 *   https://admin.olasentra.com/cron/attendance-activate.php?key=YOUR_SECRET
 *
 * Recommended: every 1–5 minutes on event days while feature_gps_attendance_v2 is ON.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/attendance-gps-phase1.php';
require_once dirname(__DIR__) . '/includes/attendance-gps-signout.php';

$isCli = PHP_SAPI === 'cli' || defined('STDIN');

if (!$isCli) {
    header('Content-Type: text/plain; charset=UTF-8');

    try {
        $pdo         = getDB();
        $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
        $providedKey = trim((string) ($_GET['key'] ?? ''));

        if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
            http_response_code(403);
            echo "Forbidden\n";
            exit;
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo "Database error\n";
        exit;
    }
}

$pdo = getDB();
$activated = activateHibernatedAttendanceForStartedEvents($pdo);
$signedOut = enforceStaleGeofenceSignouts($pdo);
$closedPast = closeStaleActiveAttendanceForPastEvents($pdo);
$noShowStats = processAutoNoShowsForPastEvents($pdo);

echo $isCli
    ? "Activated {$activated} hibernated attendance record(s). Signed out {$signedOut} stale off-venue record(s). Closed {$closedPast} past active record(s). Marked {$noShowStats['marked']} auto no-show(s).\n"
    : "OK activated={$activated} signed_out={$signedOut} closed_past_active={$closedPast} no_show_marked={$noShowStats['marked']}\n";

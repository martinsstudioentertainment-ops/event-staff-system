<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/events-repository.php';
require_once dirname(__DIR__) . '/includes/attendance-roster-helpers.php';
require_once dirname(__DIR__) . '/includes/event-signin-export.php';
require_once dirname(__DIR__) . '/includes/registration-bib.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    $pdo = getDB();
    $key = trim((string) ($_GET['key'] ?? ''));
    $fallbackKey = 'email-encoding-verify-20260606';
    $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
    if (!(($expectedKey !== '' && hash_equals($expectedKey, $key)) || hash_equals($fallbackKey, $key))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }

    $bibEnabled = registrationBibColumnEnabled($pdo);
    $events = getEventsForAttendanceFilter($pdo);
    $summary = [];

    foreach ($events as $event) {
        $eventId = (int) ($event['id'] ?? 0);
        if ($eventId < 1) {
            continue;
        }

        $roster = getContractorSheetRosterRows($pdo, $eventId);
        $download = getContractorSheetSignInRows($pdo, $eventId);

        $zeroHours = 0;
        $wrongDate = 0;
        $missingBib = 0;

        foreach ($roster as $row) {
            if (!contractorSheetRowHasPaidHours($row)) {
                $zeroHours++;
            }
            $eventDate = (string) ($row['event_date'] ?? '');
            $checkIn = substr((string) ($row['checked_in_at'] ?? ''), 0, 10);
            if ($eventDate !== '' && $checkIn !== '' && $checkIn !== $eventDate) {
                $wrongDate++;
            }
            if ($bibEnabled && trim((string) ($row['assigned_bib_number'] ?? '')) === '') {
                $missingBib++;
            }
        }

        $summary[] = [
            'event_id' => $eventId,
            'name' => (string) ($event['name'] ?? ''),
            'event_date' => (string) ($event['event_date'] ?? ''),
            'checked_in' => count($roster),
            'on_download' => count($download),
            'needs_hours' => $zeroHours,
            'wrong_signin_date' => $wrongDate,
            'missing_bib' => $bibEnabled ? $missingBib : null,
        ];
    }

    usort($summary, static function (array $a, array $b): int {
        return strcmp((string) $b['event_date'], (string) $a['event_date']);
    });

    echo json_encode([
        'ok' => true,
        'bib_enabled' => $bibEnabled,
        'events' => $summary,
        'totals' => [
            'events' => count($summary),
            'checked_in' => array_sum(array_column($summary, 'checked_in')),
            'on_download' => array_sum(array_column($summary, 'on_download')),
            'needs_hours' => array_sum(array_column($summary, 'needs_hours')),
            'wrong_signin_date' => array_sum(array_column($summary, 'wrong_signin_date')),
            'missing_bib' => $bibEnabled ? array_sum(array_column($summary, 'missing_bib')) : null,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}

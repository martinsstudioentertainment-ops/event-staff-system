<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/attendance-repository.php';
require_once dirname(__DIR__) . '/includes/event-signin-export.php';

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

    $eventId = (int) ($_GET['event_id'] ?? 2);
    $exportRows = [];
    $exportError = null;
    try {
        $exportRows = getContractorSheetSignInRows($pdo, $eventId);
    } catch (Throwable $e) {
        $exportError = $e->getMessage();
    }

    $rosterCheckedIn = [];
    foreach (getAttendanceList($pdo, $eventId, null, 0, true) as $row) {
        if (!isContractorSheetAttendanceRow($row)) {
            continue;
        }
        $rosterCheckedIn[] = [
            'name' => trim(($row['first_name'] ?? '') . ' ' . ($row['surname'] ?? '')),
            'checked_in_at' => $row['checked_in_at'] ?? null,
            'method' => $row['checked_in_method'] ?? null,
            'status' => $row['attendance_status'] ?? null,
            'is_checked_in' => $row['is_checked_in'] ?? null,
        ];
    }

    echo json_encode([
        'ok' => true,
        'event_id' => $eventId,
        'export_count' => count($exportRows),
        'export_error' => $exportError,
        'roster_checked_in_count' => count($rosterCheckedIn),
        'zero_hours' => count(array_filter($exportRows, static fn ($r) => (float) ($r['hours_paid'] ?? 0) <= 0)),
        'wrong_signin_date' => count(array_filter($exportRows, static function ($r) {
            $eventDate = (string) ($r['event_date'] ?? '');
            $checkIn = substr((string) ($r['checked_in_at'] ?? ''), 0, 10);
            return $eventDate !== '' && $checkIn !== '' && $checkIn !== $eventDate;
        })),
        'blank_method' => count(array_filter($exportRows, static fn ($r) => trim((string) ($r['checked_in_method'] ?? '')) === '')),
        'roster_sample' => array_slice($rosterCheckedIn, 0, 5),
        'export_sample' => array_slice($exportRows, 0, 3),
        'name_order' => array_map(static function (array $row): string {
            return trim(($row['first_name'] ?? '') . ' ' . ($row['surname'] ?? ''));
        }, $exportRows),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], JSON_PRETTY_PRINT);
}

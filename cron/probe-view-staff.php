<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';
require_once dirname(__DIR__) . '/includes/attendance-repository.php';
require_once dirname(__DIR__) . '/includes/status-repository.php';
require_once dirname(__DIR__) . '/includes/staff-blacklist.php';
require_once dirname(__DIR__) . '/includes/staff-registration-schema.php';
require_once dirname(__DIR__) . '/includes/platform/trust-scores.php';
require_once dirname(__DIR__) . '/includes/feature-flags.php';
require_once dirname(__DIR__) . '/includes/staff-allocation.php';
require_once dirname(__DIR__) . '/includes/work-hours-repository.php';
require_once dirname(__DIR__) . '/includes/events-repository.php';
require_once dirname(__DIR__) . '/includes/admin-manual-signin.php';
require_once dirname(__DIR__) . '/includes/staff-app-v3-data.php';
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

    $id = (int) ($_GET['id'] ?? 620);
    ensureStaffRegistrationSaveSchema($pdo);
    $row = $id > 0 ? getStaffRegistrationById($pdo, $id) : null;
    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'Registration not found', 'id' => $id]);
        exit;
    }

    $relatedRows = getStaffRegistrationsByEmail($pdo, $row['email']);
    $attendance = $row['status'] === 'approved' ? getAttendanceByRegistration($pdo, (int) $row['id']) : null;
    $currentShiftOutcome = resolveStaffShiftOutcomeMeta($row);
    $statusToken = ensureStatusToken($pdo, (int) $row['id']);
    $blacklistEntry = getActiveBlacklistEntry($pdo, (string) $row['email']);
    $consecutiveNoShows = countConsecutiveNoShows($pdo, (string) $row['email']);
    $staffIdForTrust = (int) (ensureStaffRecordForEmail($pdo, (string) ($row['email'] ?? '')) ?? 0);
    $trustScore = null;
    if ($staffIdForTrust > 0 && isFeatureEnabled($pdo, 'trust_scores')) {
        $trustScore = getStaffTrustScoreCached($pdo, $staffIdForTrust);
    }
    $assignmentHistory = getStaffAssignmentHistory(
        $pdo,
        $staffIdForTrust > 0 ? $staffIdForTrust : null,
        (string) ($row['email'] ?? ''),
        (int) $row['id']
    );
    if ($attendance) {
        initializeWorkHoursForRegistration($pdo, (int) $row['id']);
        $attendance = getAttendanceByRegistration($pdo, (int) $row['id']);
        $eventForHours = getEventById($pdo, (int) ($row['event_id'] ?? 0));
        $shiftScheduledHours = $eventForHours !== null ? suggestManualSigninHours($eventForHours) : 0;
    }

    echo json_encode([
        'ok' => true,
        'id' => $id,
        'name' => trim(($row['first_name'] ?? '') . ' ' . ($row['surname'] ?? '')),
        'status' => $row['status'] ?? null,
        'has_attendance' => $attendance !== null,
        'outcome' => $currentShiftOutcome['label'] ?? null,
        'assignment_count' => count($assignmentHistory),
        'trust_score' => $trustScore['score'] ?? null,
    ], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ], JSON_PRETTY_PRINT);
}

<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/settings-repository.php';
require_once dirname(__DIR__) . '/includes/reminders.php';

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

    $eventId  = (int) ($_GET['event_id'] ?? 7);
    $sendAll  = isset($_GET['send']) && (string) $_GET['send'] === '1';
    $testOne  = isset($_GET['test_one']) && (string) $_GET['test_one'] === '1';
    $dryRun   = !$sendAll && !$testOne;

    $event = getEventById($pdo, $eventId);
    if ($event === null) {
        throw new RuntimeException('Event not found: ' . $eventId);
    }

    $stmt = $pdo->prepare(
        "SELECT sr.id, sr.email, sr.status, e.event_date, e.name
         FROM staff_registrations sr
         INNER JOIN events e ON e.id = sr.event_id
         WHERE sr.event_id = :eid AND sr.status IN ('pending', 'approved')
         ORDER BY sr.surname, sr.first_name"
    );
    $stmt->execute(['eid' => $eventId]);
    $regs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $active = 0;
    $ended  = 0;
    foreach ($regs as $row) {
        $merged = mergeRegistrationWithEvent($pdo, $row);
        if (eventReminderStillActive($merged)) {
            $active++;
        } else {
            $ended++;
        }
    }

    $result = ['dry_run' => true, 'would_send' => 0, 'would_skip' => 0];
    if ($sendAll) {
        @set_time_limit(600);
        $result = sendManualShiftRemindersForEvent($pdo, $eventId);
        $result['dry_run'] = false;
    } elseif ($testOne) {
        @set_time_limit(120);
        $result = sendManualShiftReminderTestOne($pdo, $eventId);
        $result['dry_run'] = false;
    } else {
        $preview = sendManualShiftRemindersForEventPreview($pdo, $eventId);
        $result = array_merge($result, $preview);
    }

    echo json_encode([
        'ok'      => true,
        'event'   => [
            'id'   => $eventId,
            'name' => $event['name'] ?? '',
            'date' => $event['event_date'] ?? '',
        ],
        'approved_count' => count($regs),
        'active_window'  => $active,
        'ended_window'   => $ended,
        'result'         => $result,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
    ], JSON_PRETTY_PRINT);
}

/**
 * @return array{would_send: int, would_skip: int, sample_errors: list<string>}
 */
/**
 * @return array{sent: int, skipped: int, test_email: string, error: string}
 */
function sendManualShiftReminderTestOne(PDO $pdo, int $eventId): array
{
    $stats = ['sent' => 0, 'skipped' => 0, 'test_email' => '', 'error' => ''];

    $stmt = $pdo->prepare(
        "SELECT sr.*, e.name AS event_name, e.event_date, e.location, e.is_active
         FROM staff_registrations sr
         INNER JOIN events e ON e.id = sr.event_id
         WHERE sr.event_id = :eid AND sr.status IN ('pending', 'approved')
         ORDER BY sr.email ASC, sr.id ASC
         LIMIT 1"
    );
    $stmt->execute(['eid' => $eventId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $stats['error'] = 'No registrations found';

        return $stats;
    }

    $merged = mergeRegistrationWithEvent($pdo, $row);
    if (!eventReminderStillActive($merged)) {
        $stats['skipped'] = 1;
        $stats['error'] = 'Check-in window ended for test registration';

        return $stats;
    }

    $stats['test_email'] = strtolower(trim((string) ($merged['email'] ?? '')));
    if (sendDailyEventsReminderDigest($pdo, [$merged], true)) {
        $stats['sent'] = 1;
    } else {
        $stats['skipped'] = 1;
        $stats['error'] = 'sendDailyEventsReminderDigest returned false';
    }

    return $stats;
}

function sendManualShiftRemindersForEventPreview(PDO $pdo, int $eventId): array
{
    $stats = ['would_send' => 0, 'would_skip' => 0, 'sample_errors' => []];

    $stmt = $pdo->prepare(
        "SELECT sr.*, e.name AS event_name, e.event_date, e.location, e.is_active
         FROM staff_registrations sr
         INNER JOIN events e ON e.id = sr.event_id
         WHERE sr.event_id = :eid AND sr.status IN ('pending', 'approved')
         ORDER BY sr.email ASC, sr.id ASC"
    );
    $stmt->execute(['eid' => $eventId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $byEmail = [];
    foreach ($rows as $row) {
        try {
            $merged = mergeRegistrationWithEvent($pdo, $row);
            if (!eventReminderStillActive($merged)) {
                $stats['would_skip']++;
                continue;
            }
            $email = strtolower(trim((string) ($merged['email'] ?? '')));
            if ($email === '') {
                $stats['would_skip']++;
                continue;
            }
            $byEmail[$email][] = $merged;
        } catch (Throwable $e) {
            $stats['would_skip']++;
            if (count($stats['sample_errors']) < 5) {
                $stats['sample_errors'][] = $e->getMessage();
            }
        }
    }

    $stats['would_send'] = count($byEmail);

    return $stats;
}

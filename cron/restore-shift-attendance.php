<?php
/**
 * Restore shift attendance + hours after accidental check-in reset.
 *
 * CLI:
 *   php cron/restore-shift-attendance.php --registration_id=231 --hours=8.5 --confirm
 *
 * Web:
 *   /cron/restore-shift-attendance.php?key=KEY&registration_id=231&hours=8.5&confirm=1
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/admin-manual-signin.php';
require_once dirname(__DIR__) . '/includes/staff-repository.php';

$isCli = PHP_SAPI === 'cli' || defined('STDIN');
$opts  = $isCli ? getopt('', ['registration_id:', 'email:', 'event_id:', 'hours:', 'checked_in_at::', 'note::', 'confirm', 'key::']) : [];

function restore_shift_json(array $payload, int $code = 200): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (PHP_SAPI === 'cli') {
        echo PHP_EOL;
    }
    exit($code >= 400 ? 1 : 0);
}

if (!$isCli) {
    $pdo = getDB();
    require_once dirname(__DIR__) . '/includes/settings-repository.php';
    $expectedKey = trim(getSetting($pdo, 'reminder_cron_key', ''));
    $providedKey = trim((string) ($_GET['key'] ?? ''));
    if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
        restore_shift_json(['ok' => false, 'error' => 'Forbidden'], 403);
    }
}

$confirm = $isCli ? array_key_exists('confirm', $opts) : !empty($_GET['confirm']);
$registrationId = (int) ($opts['registration_id'] ?? $_GET['registration_id'] ?? 0);
$email          = strtolower(trim((string) ($opts['email'] ?? $_GET['email'] ?? '')));
$eventId        = (int) ($opts['event_id'] ?? $_GET['event_id'] ?? 0);
$hours          = (float) ($opts['hours'] ?? $_GET['hours'] ?? 0);
$checkedInAt    = trim((string) ($opts['checked_in_at'] ?? $_GET['checked_in_at'] ?? ''));
$note           = trim((string) ($opts['note'] ?? $_GET['note'] ?? ''));

try {
    $pdo = getDB();

    if ($registrationId < 1 && $email !== '') {
        if ($eventId > 0) {
            $stmt = $pdo->prepare(
                "SELECT id FROM staff_registrations
                 WHERE LOWER(TRIM(email)) = :email AND event_id = :event_id AND status = 'approved'
                 ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute(['email' => $email, 'event_id' => $eventId]);
        } else {
            $stmt = $pdo->prepare(
                "SELECT id FROM staff_registrations
                 WHERE LOWER(TRIM(email)) = :email AND status = 'approved'
                 ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute(['email' => $email]);
        }
        $registrationId = (int) $stmt->fetchColumn();
    }

    if ($registrationId < 1) {
        restore_shift_json(['ok' => false, 'error' => 'registration_id or email required'], 400);
    }

    if ($hours <= 0) {
        restore_shift_json(['ok' => false, 'error' => 'hours required'], 400);
    }

    $reg = getStaffRegistrationById($pdo, $registrationId);
    if ($reg === null) {
        restore_shift_json(['ok' => false, 'error' => 'Registration not found'], 404);
    }

    if (!$confirm) {
        restore_shift_json([
            'ok'              => true,
            'mode'            => 'preview',
            'registration_id' => $registrationId,
            'staff'           => trim((string) $reg['first_name'] . ' ' . (string) $reg['surname']),
            'email'           => (string) ($reg['email'] ?? ''),
            'event_id'        => (int) ($reg['event_id'] ?? 0),
            'hours'           => $hours,
            'already_checked_in' => hasCheckedIn($pdo, $registrationId),
            'hint'            => 'Add confirm=1 to restore',
        ]);
    }

    $result = restoreStaffShiftAttendance(
        $pdo,
        $registrationId,
        $hours,
        $note,
        $checkedInAt !== '' ? $checkedInAt : null,
        false
    );

    if (!($result['ok'] ?? false)) {
        restore_shift_json(['ok' => false, 'error' => (string) ($result['error'] ?? 'Restore failed')], 400);
    }

    restore_shift_json(['ok' => true, 'mode' => 'confirm', 'result' => $result]);
} catch (Throwable $e) {
    restore_shift_json(['ok' => false, 'error' => $e->getMessage()], 500);
}

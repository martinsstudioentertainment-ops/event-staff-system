<?php

declare(strict_types=1);

/**
 * Daily AVIVA Stadium reminder for 1 October 2026.
 * Time only — no check-in link. Stops after the event day.
 * The 27 Sep 2026 send was done manually and must not be repeated.
 */

function avivaShiftTimeReminderHandlesRegistration(array $row): bool
{
    return (int) ($row['event_id'] ?? 0) === 51
        && substr((string) ($row['event_date'] ?? ''), 0, 10) === '2026-10-01';
}

/**
 * @return array<string, int|string>
 */
function avivaShiftClockLabel(string $time): string
{
    $time = substr(trim($time), 0, 5);
    if (!preg_match('/^(\d{2}):(\d{2})$/', $time, $match)) {
        return $time;
    }
    $hour = (int) $match[1];
    $suffix = $hour >= 12 ? 'pm' : 'am';
    $hour12 = $hour % 12;
    if ($hour12 === 0) {
        $hour12 = 12;
    }

    return $hour12 . ':' . $match[2] . $suffix;
}

function sendAvivaShiftTimeRemindersIfDue(PDO $pdo): array
{
    if (trim((string) getSetting($pdo, 'aviva_oct1_reminder_hold', '1')) !== '0') {
        return ['status' => 'held'];
    }

    $tz = new DateTimeZone('Europe/Dublin');
    $now = new DateTimeImmutable('now', $tz);
    $today = $now->format('Y-m-d');
    $lastDay = '2026-10-01';

    if ($today > $lastDay) {
        return ['status' => 'event_ended'];
    }

    if ($today === '2026-09-27') {
        return ['status' => 'already_sent_today'];
    }

    $hour = (int) $now->format('G');
    if ($hour < 8 || $hour >= 11) {
        return ['status' => 'waiting_for_morning'];
    }

    $event = $pdo->query(
        "SELECT id, name, event_date, location, start_time, end_time
         FROM events WHERE id = 51 LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    if (
        !$event
        || substr((string) ($event['event_date'] ?? ''), 0, 10) !== $lastDay
        || stripos((string) ($event['name'] ?? ''), 'AVIVA') === false
    ) {
        return ['status' => 'event_mismatch'];
    }

    $state = json_decode((string) getSetting($pdo, 'aviva_oct1_shift_time_reminder', ''), true);
    $dateChanged = !is_array($state) || (string) ($state['date'] ?? '') !== $today;
    if ($dateChanged) {
        $state = ['date' => $today, 'sent' => []];
    }

    $sent = [];
    foreach ((array) ($state['sent'] ?? []) as $email) {
        $email = strtolower(trim((string) $email));
        if ($email !== '') {
            $sent[$email] = true;
        }
    }

    require_once __DIR__ . '/mailer.php';
    require_once __DIR__ . '/staff-blacklist.php';

    $rows = $pdo->query(
        "SELECT id, first_name, email, staff_role
         FROM staff_registrations
         WHERE event_id = 51
           AND status IN ('approved', 'pending')
           AND planned_start_time = '14:00'
         ORDER BY id ASC"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $subject = 'Olasentra — AVIVA Stadium reminder, 1 October 2026';
    $end = avivaShiftClockLabel((string) ($event['end_time'] ?? '23:00:00'));
    $when = (new DateTimeImmutable($lastDay, $tz))->format('l j F Y');
    $location = trim((string) ($event['location'] ?? ''));
    if ($location === '') {
        $location = 'AVIVA Stadium, Lansdowne Road, Dublin 4';
    }

    $sentNow = 0;
    $failed = 0;
    $attempts = 0;
    $limit = 25;

    foreach ($rows as $row) {
        if ($attempts >= $limit) {
            break;
        }

        $email = strtolower(trim((string) ($row['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || isset($sent[$email])) {
            continue;
        }
        if (isEmailBlacklisted($pdo, $email)) {
            continue;
        }

        $attempts++;
        $first = trim((string) preg_replace('/\s+/u', ' ', (string) ($row['first_name'] ?? '')));
        $role = (string) ($row['staff_role'] ?? '') === 'dsp' ? 'Door Supervisor (DSP)' : 'Static';
        $body = "Dear {$first},\n\n"
            . "Reminder for your shift at AVIVA Stadium on {$when}.\n\n"
            . "Sign-in starts: 1:00pm\n"
            . "Shift starts: 2:00pm\n"
            . "Finish time: {$end}\n"
            . "Location: {$location}\n"
            . "Role: {$role}\n\n"
            . "Sign in on your phone with Staff Sign:\n"
            . "1. Open https://register.olasentra.com/sign.php\n"
            . "2. Install Staff Sign. Android: tap Install as app. iPhone: open in Safari, tap Share, then Add to Home Screen.\n"
            . "3. Open Staff Sign from your home screen.\n"
            . "4. Choose 1 October 2026 and AVIVA Stadium.\n"
            . "5. Type your name and tap Find me.\n"
            . "6. Security: check your PSA, enter your BIB, then tap Sign in at the venue. You must be at the stadium. Steward: confirm your name, then sign in.\n"
            . "7. Keep the app open. At {$end} the phone rings until you tap Sign out.\n\n"
            . "One phone is for one person. Reply to this email if you can no longer attend.\n\n"
            . "Regards,\n"
            . "Olasentra\n";

        $ok = false;
        try {
            $ok = sendEmail($pdo, $email, $subject, $body, null);
        } catch (Throwable $e) {
            error_log('[EventStaff] aviva shift-time reminder: ' . $e->getMessage());
        }

        if ($ok) {
            $sent[$email] = true;
            $sentNow++;
        } else {
            $failed++;
        }
    }

    if ($dateChanged || $sentNow > 0) {
        setSetting($pdo, 'aviva_oct1_shift_time_reminder', json_encode([
            'date' => $today,
            'sent' => array_keys($sent),
        ], JSON_UNESCAPED_UNICODE));
    }

    if ($sentNow > 0 || $failed > 0) {
        error_log('[EventStaff] aviva shift-time reminder date=' . $today . ' sent=' . $sentNow . ' failed=' . $failed . ' total=' . count($sent));
    }

    return [
        'status' => 'ok',
        'date' => $today,
        'sent_now' => $sentNow,
        'failed' => $failed,
        'sent_total' => count($sent),
        'approved' => count($rows),
    ];
}

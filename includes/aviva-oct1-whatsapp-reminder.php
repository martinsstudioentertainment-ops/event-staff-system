<?php

declare(strict_types=1);

/**
 * One-day AVIVA WhatsApp group reminder (30 Sep 2026).
 * Slots: now (manual), 18, 21 — Europe/Dublin.
 */

require_once __DIR__ . '/mailer.php';

function avivaOct1WhatsappReminderLogPath(): string
{
    return dirname(__DIR__) . '/storage/logs/aviva-oct1-whatsapp-reminder.json';
}

/**
 * @return array<string, mixed>
 */
function avivaOct1WhatsappReminderLoadLog(): array
{
    $path = avivaOct1WhatsappReminderLogPath();
    if (!is_file($path)) {
        return ['slots' => []];
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : ['slots' => []];
}

/**
 * @param array<string, mixed> $log
 */
function avivaOct1WhatsappReminderSaveLog(array $log): void
{
    $path = avivaOct1WhatsappReminderLogPath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    file_put_contents($path, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/**
 * @return array<string, mixed>
 */
function sendAvivaOct1WhatsappReminder(PDO $pdo, string $slot, bool $force = false): array
{
    $slot = preg_replace('/[^a-z0-9_\-]/i', '', $slot) ?: 'manual';
    $log = avivaOct1WhatsappReminderLoadLog();
    if (!$force && !empty($log['slots'][$slot]['sent_at'])) {
        return [
            'ok' => true,
            'skipped' => true,
            'slot' => $slot,
            'reason' => 'already_sent',
            'previous' => $log['slots'][$slot],
        ];
    }

    $transport = getMailTransport($pdo);
    if ($transport === 'log') {
        return ['ok' => false, 'error' => 'mail transport is log-only', 'slot' => $slot];
    }

    $sql = "SELECT sr.id AS registration_id, s.first_name, s.surname, s.email, e.id AS event_id
            FROM staff_registrations sr
            INNER JOIN events e ON e.id = sr.event_id
            INNER JOIN staff s ON s.id = sr.staff_id
            WHERE e.event_date = '2026-10-01'
              AND LOWER(TRIM(sr.status)) = 'approved'
              AND s.email IS NOT NULL AND TRIM(s.email) <> ''
            ORDER BY s.surname, s.first_name, sr.id";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $subject = 'Olasentra - AVIVA Stadium tomorrow: WhatsApp group + ask for OLA';
    $sent = [];
    $failed = [];
    $seen = [];

    foreach ($rows as $row) {
        $email = strtolower(trim((string) $row['email']));
        if ($email === '' || isset($seen[$email])) {
            continue;
        }
        $seen[$email] = true;
        $first = trim((string) $row['first_name']);
        if ($first === '') {
            $first = 'Staff';
        }

        $plain = "Dear {$first},\n\n"
            . "This is a reminder for your AVIVA Stadium shift tomorrow, Thursday 1 October 2026.\n\n"
            . "Sign-in starts: 13:00 (1:00pm)\n"
            . "Shift starts: 14:00 (2:00pm)\n"
            . "Shift ends: 23:00\n\n"
            . "Meeting point: Gate 8\n"
            . "Eircode: D04 K5F9\n"
            . "Venue: AVIVA Stadium, Lansdowne Road, Dublin 4\n\n"
            . "Join the WhatsApp group for location and Eircode:\n"
            . "https://chat.whatsapp.com/KlmMltdHrbF3aRw8fQhMAg\n\n"
            . "When you are on site, ask for OLA.\n\n"
            . "Reply to this email if you cannot attend.\n\n"
            . "Regards,\nOlasentra\n";

        $html = '<p>Dear ' . htmlspecialchars($first, ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>This is a reminder for your AVIVA Stadium shift tomorrow, Thursday 1 October 2026.</p>'
            . '<p><strong>Sign-in starts:</strong> 13:00 (1:00pm)<br>'
            . '<strong>Shift starts:</strong> 14:00 (2:00pm)<br>'
            . '<strong>Shift ends:</strong> 23:00</p>'
            . '<p><strong>Meeting point:</strong> Gate 8<br>'
            . '<strong>Eircode:</strong> D04 K5F9<br>'
            . '<strong>Venue:</strong> AVIVA Stadium, Lansdowne Road, Dublin 4</p>'
            . '<p><strong>Join the WhatsApp group</strong> for location and Eircode:<br>'
            . '<a href="https://chat.whatsapp.com/KlmMltdHrbF3aRw8fQhMAg">https://chat.whatsapp.com/KlmMltdHrbF3aRw8fQhMAg</a></p>'
            . '<p>When you are on site, <strong>ask for OLA</strong>.</p>'
            . '<p>Reply to this email if you cannot attend.</p>'
            . '<p>Regards,<br>Olasentra</p>';

        $ok = sendEmail($pdo, $email, $subject, $plain, $html);
        $entry = [
            'email' => $email,
            'name' => trim($first . ' ' . (string) $row['surname']),
            'registration_id' => (int) $row['registration_id'],
        ];
        if ($ok) {
            $sent[] = $entry;
        } else {
            $failed[] = $entry;
        }
    }

    $result = [
        'ok' => count($failed) === 0,
        'slot' => $slot,
        'sent' => count($sent),
        'failed' => count($failed),
        'unique_emails' => count($seen),
        'approved_rows' => count($rows),
        'sent_at' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Dublin')))->format(DateTimeInterface::ATOM),
    ];

    $log['slots'][$slot] = [
        'sent_at' => $result['sent_at'],
        'sent' => $result['sent'],
        'failed' => $result['failed'],
        'unique_emails' => $result['unique_emails'],
    ];
    avivaOct1WhatsappReminderSaveLog($log);

    return $result;
}

/**
 * Heartbeat helper: 21:00 on 30 Sep 2026 and 06:00 on 1 Oct 2026 (Europe/Dublin).
 *
 * @return array<string, mixed>
 */
function sendAvivaOct1WhatsappRemindersIfDue(PDO $pdo): array
{
    $tz = new DateTimeZone('Europe/Dublin');
    $now = new DateTimeImmutable('now', $tz);
    $day = $now->format('Y-m-d');
    $hour = (int) $now->format('G');
    $minute = (int) $now->format('i');
    $out = ['status' => 'watching', 'day' => $day, 'hour' => $hour, 'minute' => $minute, 'ran' => []];

    // Fire in a 10-minute window so a ~90s heartbeat can catch it.
    if ($day === '2026-09-30' && $hour === 21 && $minute < 10) {
        $out['ran']['21'] = sendAvivaOct1WhatsappReminder($pdo, '21');
    }
    if ($day === '2026-10-01' && $hour === 6 && $minute < 10) {
        $out['ran']['06'] = sendAvivaOct1WhatsappReminder($pdo, '06');
    }

    if ($out['ran'] === []) {
        if ($day > '2026-10-01' || ($day === '2026-10-01' && $hour >= 7)) {
            $out['status'] = 'finished';
        }
    }

    return $out;
}

<?php

/**
 * Daily event reminders (from sign-up until event ends) and delayed signup nudges
 * for upcoming events the person has not registered for.
 */

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/events-repository.php';
require_once __DIR__ . '/attendance-repository.php';
require_once __DIR__ . '/status-repository.php';
require_once __DIR__ . '/site-urls.php';
require_once __DIR__ . '/email-copy.php';

function isReminderDailyEnabled(PDO $pdo): bool
{
    return getSetting($pdo, 'reminder_daily_enabled', '1') === '1';
}

function isReminderSignupNudgeEnabled(PDO $pdo): bool
{
    return getSetting($pdo, 'reminder_signup_nudge_enabled', '1') === '1';
}

function getReminderSignupNudgeDelayDays(PDO $pdo): int
{
    return max(0, (int) getSetting($pdo, 'reminder_signup_nudge_delay_days', '2'));
}

function getReminderSignupNudgeIntervalDays(PDO $pdo): int
{
    return max(1, (int) getSetting($pdo, 'reminder_signup_nudge_interval_days', '3'));
}

function eventReminderStillActive(array $event): bool
{
    $window = getEventCheckinWindow($event);

    return $window['status'] !== 'after';
}

/**
 * @return array<int, array<string, mixed>>
 */
function getRegistrationsDueDailyReminder(PDO $pdo): array
{
    $sql = "SELECT sr.*,
                   e.name AS event_name, e.main_security_company, e.event_date, e.start_time, e.end_time,
                   e.location, e.venue_eircode, e.is_active
            FROM staff_registrations sr
            INNER JOIN events e ON e.id = sr.event_id
            WHERE sr.status IN ('pending', 'approved')
              AND DATE(sr.created_at) <= CURDATE()
              AND (sr.last_event_reminder_date IS NULL OR sr.last_event_reminder_date < CURDATE())
            ORDER BY sr.id ASC";

    $rows = $pdo->query($sql)->fetchAll();

    return array_values(array_filter($rows, static function (array $row): bool {
        return eventReminderStillActive($row);
    }));
}

function markEventReminderSent(PDO $pdo, int $registrationId): void
{
    $stmt = $pdo->prepare(
        'UPDATE staff_registrations SET last_event_reminder_date = CURDATE() WHERE id = :id'
    );
    $stmt->execute(['id' => $registrationId]);

    logEmailReminder($pdo, (int) $registrationId, '', 'event_daily', $registrationId);
}

function logEmailReminder(PDO $pdo, int $registrationId, string $email, string $type, ?int $linkRegistrationId = null): void
{
    if ($email === '' && $registrationId > 0) {
        $row = getStaffRegistrationById($pdo, $registrationId);
        $email = (string) ($row['email'] ?? '');
    }

    if ($email === '') {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO email_reminder_log (email, registration_id, reminder_type) VALUES (:email, :registration_id, :type)'
    );
    $stmt->execute([
        'email'            => $email,
        'registration_id'  => $linkRegistrationId ?? ($registrationId > 0 ? $registrationId : null),
        'type'             => $type,
    ]);
}

function getLastSignupNudgeDate(PDO $pdo, string $email): ?string
{
    $stmt = $pdo->prepare(
        "SELECT DATE(sent_at) AS sent_date
         FROM email_reminder_log
         WHERE email = :email AND reminder_type = 'signup_nudge'
         ORDER BY sent_at DESC
         LIMIT 1"
    );
    $stmt->execute(['email' => $email]);
    $row = $stmt->fetch();

    return $row ? (string) $row['sent_date'] : null;
}

function getFirstRegistrationDate(PDO $pdo, string $email): ?string
{
    $stmt = $pdo->prepare(
        "SELECT DATE(MIN(created_at)) AS first_date
         FROM staff_registrations
         WHERE email = :email AND status != 'rejected'"
    );
    $stmt->execute(['email' => $email]);
    $row = $stmt->fetch();

    return $row && $row['first_date'] ? (string) $row['first_date'] : null;
}

/**
 * @return array<int, array<string, mixed>>
 */
function getMissingUpcomingEventsForEmail(PDO $pdo, string $email): array
{
    $stmt = $pdo->prepare(
        "SELECT e.*
         FROM events e
         WHERE e.is_active = 1
           AND e.id NOT IN (
               SELECT event_id FROM staff_registrations
               WHERE email = :email AND status != 'rejected'
           )
         ORDER BY e.event_date ASC, e.name ASC"
    );
    $stmt->execute(['email' => $email]);

    return array_values(array_filter($stmt->fetchAll(), static function (array $event): bool {
        if ((string) ($event['event_date'] ?? '') === '') {
            return false;
        }

        return eventReminderStillActive($event);
    }));
}

/**
 * @return array<int, string>
 */
function getEmailsDueSignupNudge(PDO $pdo): array
{
    $delay    = getReminderSignupNudgeDelayDays($pdo);
    $interval = getReminderSignupNudgeIntervalDays($pdo);
    $today    = new DateTimeImmutable('today');

    $stmt = $pdo->query(
        "SELECT DISTINCT email FROM staff_registrations WHERE status != 'rejected' ORDER BY email ASC"
    );
    $emails = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $due = [];
    foreach ($emails as $email) {
        $email = trim((string) $email);
        if ($email === '') {
            continue;
        }

        if (getMissingUpcomingEventsForEmail($pdo, $email) === []) {
            continue;
        }

        $firstDate = getFirstRegistrationDate($pdo, $email);
        if ($firstDate === null) {
            continue;
        }

        $first = new DateTimeImmutable($firstDate);
        if ($first->diff($today)->days < $delay) {
            continue;
        }

        $lastNudge = getLastSignupNudgeDate($pdo, $email);
        if ($lastNudge !== null) {
            $last = new DateTimeImmutable($lastNudge);
            if ($last->diff($today)->days < $interval) {
                continue;
            }
        }

        $due[] = $email;
    }

    return $due;
}

function sendDailyEventReminder(PDO $pdo, array $row): bool
{
    if (!isReminderDailyEnabled($pdo)) {
        return false;
    }

    $siteName = getSiteName($pdo);
    $event    = formatEventLabel($row);
    $role     = formatRoleLabel($row['staff_role']);
    $status   = formatStatusLabel($row['status']);
    $location = formatEventLocationLabel($row);
    $times    = formatEventTimeRangeLabel($row);
    $regId    = (int) $row['id'];

    $subject = $siteName . ' — Reminder: ' . (string) ($row['event_name'] ?? 'Your event');

    $bodyLines = [
        'Dear ' . $row['first_name'] . ',',
        '',
        'This is your daily reminder about your upcoming event registration.',
        '',
        'Event: ' . $event,
        'Time: ' . $times,
        'Location: ' . $location,
        'Role: ' . $role,
        'Status: ' . $status,
    ];

    $onSite = formatEmailOnSiteSecurityLine($pdo, $row);
    if ($onSite !== null) {
        $bodyLines[] = $onSite;
    }

    if ($row['status'] === 'approved') {
        $token = ensureCheckinToken($pdo, $regId);
        if ($token) {
            $bodyLines[] = '';
            $bodyLines[] = 'Personal check-in link for event day:';
            $bodyLines[] = getCheckinUrl($token, $pdo);
        }

        $eventId = (int) ($row['event_id'] ?? 0);
        if ($eventId > 0) {
            $signToken = ensureEventSigninToken($pdo, $eventId);
            if ($signToken) {
                $bodyLines[] = '';
                $bodyLines[] = 'Email sign-in (during check-in hours):';
                $bodyLines[] = getEventEmailSigninUrl($signToken, $pdo);
            }
        }
    } elseif ($row['status'] === 'pending') {
        $bodyLines[] = '';
        $bodyLines[] = 'Your application is still being reviewed. We will email you when it is approved.';
    }

    $statusToken = ensureStatusToken($pdo, $regId);
    if ($statusToken) {
        $bodyLines[] = '';
        $bodyLines[] = 'View your registration status:';
        $bodyLines[] = getStatusUrl($statusToken, $pdo);
    }

    $window = getEventCheckinWindow($row);
    $bodyLines[] = '';
    $bodyLines[] = 'Check-in window: ' . $window['opens_at']->format('d.m.Y H:i')
        . ' – ' . $window['closes_at']->format('d.m.Y H:i');
    $bodyLines[] = '';
    $bodyLines[] = 'Daily reminders stop automatically after this event ends.';
    $bodyLines = appendEmailPortalContext($pdo, $bodyLines);
    $bodyLines[] = '';
    $bodyLines[] = 'Regards,';
    $bodyLines[] = $siteName;

    $sent = sendEmail($pdo, (string) $row['email'], $subject, implode("\n", $bodyLines));
    if ($sent) {
        markEventReminderSent($pdo, $regId);
    }

    return $sent;
}

/**
 * @param array<int, array<string, mixed>> $missingEvents
 */
function sendSignupNudgeEmail(PDO $pdo, string $email, array $missingEvents): bool
{
    if ($missingEvents === []) {
        return false;
    }

    $siteName = getSiteName($pdo);
    $subject  = $siteName . ' — More events open for registration';

    $stmt = $pdo->prepare(
        "SELECT first_name FROM staff_registrations
         WHERE email = :email AND status != 'rejected'
         ORDER BY created_at ASC LIMIT 1"
    );
    $stmt->execute(['email' => $email]);
    $person   = $stmt->fetch();
    $firstName = $person ? (string) $person['first_name'] : 'there';

    $bodyLines = [
        'Dear ' . $firstName . ',',
        '',
        'You are registered for some events with us — thank you!',
        '',
        'These upcoming events are still open and you have not signed up yet:',
        '',
    ];

    foreach ($missingEvents as $event) {
        $bodyLines[] = '• ' . formatEventLabel([
            'event_name' => $event['name'],
            'event_date' => $event['event_date'],
        ]) . ' — ' . formatEventLocationLabel($event);
    }

    $bodyLines[] = '';
    $bodyLines[] = 'Register for additional events here:';
    $bodyLines[] = getRegistrationFormUrl($pdo);
    $bodyLines[] = '';
    $bodyLines[] = 'You can select multiple events on one form using the same email address.';
    $bodyLines[] = '';
    $bodyLines[] = 'These reminders stop once an event has passed or you register for it.';
    $bodyLines = appendEmailPortalContext($pdo, $bodyLines);
    $bodyLines[] = '';
    $bodyLines[] = 'Regards,';
    $bodyLines[] = $siteName;

    $sent = sendEmail($pdo, $email, $subject, implode("\n", $bodyLines));
    if ($sent) {
        logEmailReminder($pdo, 0, $email, 'signup_nudge');
    }

    return $sent;
}

/**
 * @return array{
 *     daily_sent: int,
 *     daily_skipped: int,
 *     nudge_sent: int,
 *     nudge_skipped: int,
 *     errors: int
 * }
 */
function runDailyReminders(PDO $pdo): array
{
    $stats = [
        'daily_sent'      => 0,
        'daily_skipped'   => 0,
        'nudge_sent'      => 0,
        'nudge_skipped'   => 0,
        'errors'          => 0,
    ];

    if (!isReminderDailyEnabled($pdo) && !isReminderSignupNudgeEnabled($pdo)) {
        return $stats;
    }

    if (isReminderDailyEnabled($pdo)) {
        foreach (getRegistrationsDueDailyReminder($pdo) as $row) {
            if (sendDailyEventReminder($pdo, $row)) {
                $stats['daily_sent']++;
            } else {
                $stats['errors']++;
            }
        }
    }

    if (isReminderSignupNudgeEnabled($pdo)) {
        foreach (getEmailsDueSignupNudge($pdo) as $email) {
            $missing = getMissingUpcomingEventsForEmail($pdo, $email);
            if ($missing === []) {
                $stats['nudge_skipped']++;
                continue;
            }

            if (sendSignupNudgeEmail($pdo, $email, $missing)) {
                $stats['nudge_sent']++;
            } else {
                $stats['errors']++;
            }
        }
    }

    return $stats;
}

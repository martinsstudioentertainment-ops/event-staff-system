<?php

declare(strict_types=1);

require_once __DIR__ . '/notification-center.php';
require_once __DIR__ . '/events-repository.php';
require_once __DIR__ . '/site-urls.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/email-copy.php';
require_once __DIR__ . '/email-layout.php';
require_once __DIR__ . '/email-branding.php';

/**
 * @return list<string>
 */
function listRegisteredStaffEmailsForAlerts(PDO $pdo): array
{
    try {
        $rows = $pdo->query(
            "SELECT DISTINCT LOWER(TRIM(email)) AS email FROM (
                SELECT email FROM staff
                 WHERE email IS NOT NULL AND TRIM(email) <> '' AND is_blacklisted = 0
                UNION
                SELECT email FROM staff_registrations
                 WHERE email IS NOT NULL AND TRIM(email) <> '' AND status != 'rejected'
            ) AS combined"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[EventStaff] listRegisteredStaffEmailsForAlerts: ' . $e->getMessage());

        return [];
    }

    $emails = [];
    foreach ($rows as $row) {
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[] = $email;
        }
    }

    return array_values(array_unique($emails));
}

/**
 * Staff who may be alerted about a shift — excludes anyone already on it (pending or approved).
 *
 * @return list<string>
 */
function listStaffEmailsEligibleForShiftAlert(PDO $pdo, int $eventId): array
{
    $all = listRegisteredStaffEmailsForAlerts($pdo);
    if ($all === [] || $eventId < 1) {
        return $all;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT LOWER(TRIM(email)) AS email
             FROM staff_registrations
             WHERE event_id = :event_id
               AND status IN ('pending', 'approved')
               AND email IS NOT NULL
               AND TRIM(email) <> ''"
        );
        $stmt->execute(['event_id' => $eventId]);
        $alreadyOn = array_map(
            static fn ($row): string => strtolower(trim((string) $row)),
            $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []
        );
    } catch (Throwable $e) {
        error_log('[EventStaff] listStaffEmailsEligibleForShiftAlert: ' . $e->getMessage());

        return $all;
    }

    if ($alreadyOn === []) {
        return $all;
    }

    $alreadySet = array_fill_keys($alreadyOn, true);

    return array_values(array_filter(
        $all,
        static fn (string $email): bool => !isset($alreadySet[$email])
    ));
}

function buildJobAlertEmailContent(PDO $pdo, array $event, string $intro, string $regUrl): array
{
    $jobData = buildEmailJobCardDataFromEvent($pdo, $event, $regUrl);
    $jobCard = buildEmailJobCard($pdo, $jobData);

    $textParts = [$intro];
    $textParts[] = $jobData['job_title'];
    if ($jobData['event_name'] !== '') {
        $textParts[] = 'Event: ' . $jobData['event_name'];
    }
    if ($jobData['location'] !== '') {
        $textParts[] = 'Location: ' . $jobData['location'];
    }
    if ($jobData['date'] !== '') {
        $textParts[] = 'Date: ' . $jobData['date'];
    }
    if ($jobData['time_range'] !== '') {
        $textParts[] = 'Time: ' . $jobData['time_range'];
    }
    if ($jobData['hours'] !== '') {
        $textParts[] = 'Hours: ' . $jobData['hours'];
    }
    if (trim((string) ($jobData['pay_rate'] ?? '')) !== '') {
        $textParts[] = 'Pay: ' . $jobData['pay_rate'];
    }
    if ($jobData['vacancies'] !== '') {
        $textParts[] = 'Vacancies: ' . $jobData['vacancies'];
    }
    $textParts[] = '';
    $textParts[] = 'Register for this event:';
    $textParts[] = $regUrl;

    $html = '<p style="margin:0 0 16px;">' . emailEsc($intro) . '</p>' . $jobCard;

    return [
        'text' => implode("\n", $textParts),
        'html' => $html,
    ];
}

function notifyRegisteredStaffNewEvent(PDO $pdo, int $eventId): int
{
    if (!isNotifyStaffShiftAlertsEnabled($pdo)) {
        return 0;
    }

    $event = getEventById($pdo, $eventId);
    if ($event === null || (int) ($event['is_active'] ?? 0) !== 1) {
        return 0;
    }

    if (!eventHasOpenStaffSlots($pdo, $eventId)) {
        return 0;
    }

    $eventName = trim((string) ($event['name'] ?? 'New event'));
    $regUrl    = getRegistrationFormUrl($pdo);
    $siteName  = getSiteName($pdo);

    $intro = 'A new shift is open. Register now if you want to work this event.';

    $bodyInApp = 'A new shift is open: ' . $eventName;
    if (formatEventDateLabel((string) ($event['event_date'] ?? '')) !== '') {
        $bodyInApp .= ' | ' . formatEventDateLabel((string) ($event['event_date'] ?? ''));
    }

    $titleInApp = 'New shift — ' . $eventName;
    $titleEmail = 'New shift - ' . $eventName;
    $notified   = 0;

    $emailContent = buildJobAlertEmailContent($pdo, $event, $intro, $regUrl);

    foreach (listStaffEmailsEligibleForShiftAlert($pdo, $eventId) as $email) {
        if (notifyStaffInApp($pdo, $email, 'new_event', $titleInApp, $bodyInApp, $regUrl, 'Register now', $eventId) !== null) {
            $notified++;
        }

        sendEmail($pdo, $email, '[' . $siteName . '] ' . $titleEmail, $emailContent['text'], $emailContent['html']);
    }

    return $notified;
}

function eventHasOpenStaffSlots(PDO $pdo, int $eventId): bool
{
    $event = getEventById($pdo, $eventId);
    if ($event === null || (int) ($event['is_active'] ?? 0) !== 1) {
        return false;
    }

    $needed = (int) ($event['staff_needed'] ?? 0);
    if ($needed <= 0) {
        return true;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM staff_registrations WHERE event_id = :eid AND status = 'approved'"
    );
    $stmt->execute(['eid' => $eventId]);
    $approved = (int) $stmt->fetchColumn();

    return $approved < $needed;
}

/**
 * Notify all known staff when a shift has space (rejection, cancellation, or activation).
 */
function notifyRegisteredStaffOpenShiftSlot(PDO $pdo, int $eventId, string $reason = 'A place is available'): int
{
    if (!isNotifyStaffShiftAlertsEnabled($pdo)) {
        return 0;
    }

    if (!eventHasOpenStaffSlots($pdo, $eventId)) {
        return 0;
    }

    $event = getEventById($pdo, $eventId);
    if ($event === null) {
        return 0;
    }

    $eventName = trim((string) ($event['name'] ?? 'Shift'));
    $regUrl    = getRegistrationFormUrl($pdo);
    $siteName  = getSiteName($pdo);
    $needed    = (int) ($event['staff_needed'] ?? 0);

    $intro = $reason . ' for this shift. Register now if you want this shift.';
    if ($needed > 0) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM staff_registrations WHERE event_id = :eid AND status = 'approved'"
        );
        $stmt->execute(['eid' => $eventId]);
        $approved = (int) $stmt->fetchColumn();
        $spaces   = max(0, $needed - $approved);
        if ($spaces > 0) {
            $intro = $reason . ': ' . $spaces . ' space' . ($spaces === 1 ? '' : 's') . ' available on ' . $eventName . '. Register now if you want this shift.';
        }
    }

    $bodyInApp = $intro;
    $titleInApp = 'Shift available — ' . $eventName;
    $titleEmail = 'Shift available - ' . $eventName;
    $notified   = 0;

    $emailContent = buildJobAlertEmailContent($pdo, $event, $intro, $regUrl);

    foreach (listStaffEmailsEligibleForShiftAlert($pdo, $eventId) as $email) {
        if (notifyStaffInApp($pdo, $email, 'open_shift', $titleInApp, $bodyInApp, $regUrl, 'Register now', $eventId) !== null) {
            $notified++;
        }

        sendEmail($pdo, $email, '[' . $siteName . '] ' . $titleEmail, $emailContent['text'], $emailContent['html']);
    }

    return $notified;
}

/**
 * After saving an event — notify when activated or when more lads are needed.
 *
 * @param array<string, mixed>|null $before Row before save; null treats as new activation.
 */
function maybeNotifyStaffAfterEventSave(PDO $pdo, int $eventId, ?array $before = null): int
{
    $after = getEventById($pdo, $eventId);
    if ($after === null || (int) ($after['is_active'] ?? 0) !== 1) {
        return 0;
    }

    if (!isNotifyStaffShiftAlertsEnabled($pdo) || !eventHasOpenStaffSlots($pdo, $eventId)) {
        return 0;
    }

    if ($before === null) {
        return notifyRegisteredStaffNewEvent($pdo, $eventId);
    }

    $wasActive = (int) ($before['is_active'] ?? 0) === 1;
    $oldNeeded = (int) ($before['staff_needed'] ?? 0);
    $newNeeded = (int) ($after['staff_needed'] ?? 0);

    if (!$wasActive) {
        return notifyRegisteredStaffNewEvent($pdo, $eventId);
    }

    if ($newNeeded > $oldNeeded) {
        $added = $newNeeded - $oldNeeded;

        return notifyRegisteredStaffOpenShiftSlot(
            $pdo,
            $eventId,
            $added . ' more place' . ($added === 1 ? '' : 's') . ' added on this shift'
        );
    }

    return 0;
}

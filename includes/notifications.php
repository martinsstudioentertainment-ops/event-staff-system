<?php
/**
 * Event Staff System — Staff notification emails
 */

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/events-repository.php';
require_once __DIR__ . '/attendance-repository.php';
require_once __DIR__ . '/status-repository.php';
require_once __DIR__ . '/access-pass-email.php';
require_once __DIR__ . '/email-copy.php';

function notifyStaffStatusChange(PDO $pdo, int $registrationId, string $newStatus): bool
{
    try {
        notifyStaffStatusChanges($pdo, [$registrationId], $newStatus);

        return true;
    } catch (Throwable $e) {
        error_log('[EventStaff] notifyStaffStatusChange: ' . $e->getMessage());

        return false;
    }
}

/**
 * Send at most one email per staff member for a batch status change (e.g. bulk approve).
 *
 * @param int[] $registrationIds
 */
function notifyStaffStatusChanges(PDO $pdo, array $registrationIds, string $newStatus): void
{
    if (!isNotifyStaffEnabled($pdo)) {
        return;
    }

    if (!in_array($newStatus, ['approved', 'rejected'], true)) {
        return;
    }

    $registrationIds = array_values(array_unique(array_filter(
        array_map('intval', $registrationIds),
        static fn (int $id): bool => $id > 0
    )));
    if ($registrationIds === []) {
        return;
    }

    $byEmail = [];
    foreach ($registrationIds as $id) {
        $row = getStaffRegistrationById($pdo, $id);
        if ($row === null) {
            continue;
        }
        if (($row['status'] ?? '') !== $newStatus) {
            continue;
        }

        $email = strtolower(trim((string) ($row['email'] ?? '')));
        if ($email === '') {
            continue;
        }

        $byEmail[$email][] = $row;
    }

    foreach ($byEmail as $rows) {
        if ($newStatus === 'approved') {
            sendConsolidatedAccessPassEmail($pdo, $rows);
        } else {
            sendConsolidatedRejectionEmail($pdo, $rows);
        }
    }

    try {
        require_once __DIR__ . '/notification-center.php';
        foreach ($registrationIds as $id) {
            $row = getStaffRegistrationById($pdo, $id);
            if ($row === null || ($row['status'] ?? '') !== $newStatus) {
                continue;
            }
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            if ($email === '') {
                continue;
            }
            notifyStaffStatusInApp($pdo, $email, $newStatus, $id, formatEventLabel($row));
        }
    } catch (Throwable $e) {
        error_log('[EventStaff] notifyStaffStatusChanges in-app: ' . $e->getMessage());
    }
}

/**
 * @param list<array<string, mixed>> $rows
 */
function sendConsolidatedRejectionEmail(PDO $pdo, array $rows): bool
{
    if ($rows === []) {
        return false;
    }

    $email = strtolower(trim((string) ($rows[0]['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $siteName  = getSiteName($pdo);
    $firstName = (string) ($rows[0]['first_name'] ?? '');
    $count     = count($rows);
    $subject   = $siteName . ' — Registration update';

    $bodyLines = [
        'Dear ' . $firstName . ',',
        '',
        $count === 1
            ? 'Thank you for your interest. Your staff registration was not approved at this time.'
            : 'Thank you for your interest. The following ' . $count . ' registration(s) were not approved at this time.',
        '',
    ];

    foreach ($rows as $row) {
        $bodyLines[] = '• ' . formatEventLabel($row) . ' — ' . formatRoleLabel($row['staff_role']);
        $onSite = formatEmailOnSiteSecurityLine($pdo, $row);
        if ($onSite !== null) {
            $bodyLines[] = '  ' . $onSite;
        }
    }

    $statusToken = ensureStatusToken($pdo, (int) $rows[0]['id']);
    if ($statusToken) {
        $bodyLines[] = '';
        $bodyLines[] = 'View your registration status anytime:';
        $bodyLines[] = getStatusUrl($statusToken, $pdo);
    }

    $bodyLines[] = '';
    $bodyLines[] = 'If you have questions, please contact us using the contact details on the website.';
    $bodyLines = appendEmailPortalContext($pdo, $bodyLines);
    $bodyLines[] = '';
    $bodyLines[] = 'Regards,';
    $bodyLines[] = $siteName;

    return sendEmail($pdo, $email, $subject, implode("\n", $bodyLines));
}

function notifyStaffRegistrationSubmitted(PDO $pdo, array $data, array $eventIds, array $registrationIds = []): bool
{
    if (getSetting($pdo, 'notify_on_registration', '0') !== '1') {
        return false;
    }

    $email = trim((string) ($data['email'] ?? ''));
    if ($email === '') {
        return false;
    }

    $eventLabels = [];
    foreach ($eventIds as $eventId) {
        $stmt = $pdo->prepare('SELECT name, event_date FROM events WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $eventId]);
        $eventRow = $stmt->fetch();
        if ($eventRow) {
            $eventLabels[] = formatEventLabel([
                'event_name' => $eventRow['name'],
                'event_date' => $eventRow['event_date'],
            ]);
        }
    }

    $siteName = getSiteName($pdo);
    $subject  = $siteName . ' — Registration Received';
    $bodyLines = [
        'Dear ' . trim((string) ($data['first_name'] ?? '')) . ',',
        '',
        'We have received your staff registration for the following event(s):',
        '',
        implode("\n", array_map(static fn(string $e): string => '• ' . $e, $eventLabels)),
        '',
        'Role: ' . formatRoleLabel((string) ($data['staff_role'] ?? '')),
        'Status: Pending approval',
    ];

    if ($registrationIds !== []) {
        $statusToken = ensureStatusToken($pdo, (int) $registrationIds[0]);
        if ($statusToken) {
            $bodyLines[] = '';
            $bodyLines[] = 'View your registration status anytime:';
            $bodyLines[] = getStatusUrl($statusToken, $pdo);
        }
    }

    $bodyLines[] = '';
    $bodyLines[] = 'You will receive one email when your application(s) are reviewed (not one per shift).';
    $bodyLines = appendEmailPortalContext($pdo, $bodyLines);
    $bodyLines[] = '';
    $bodyLines[] = 'Regards,';
    $bodyLines[] = $siteName;

    $body = implode("\n", $bodyLines);

    return sendEmail($pdo, $email, $subject, $body);
}

function notifyStaffCheckin(PDO $pdo, int $registrationId, string $method = 'self'): bool
{
    if (!isNotifyOnCheckinEnabled($pdo)) {
        return false;
    }

    $row = getStaffRegistrationById($pdo, $registrationId);
    if (!$row) {
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT checked_in_at FROM attendance WHERE registration_id = :id ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute(['id' => $registrationId]);
    $attendance = $stmt->fetch();
    $checkedInAt = $attendance ? (string) ($attendance['checked_in_at'] ?? '') : date('Y-m-d H:i:s');

    require_once __DIR__ . '/system-settings.php';

    $siteName   = getSiteName($pdo);
    $event      = formatEventLabel($row);
    $role       = formatRoleLabel($row['staff_role']);
    $times      = formatEventTimeRangeLabel($row);
    $location   = formatEventLocationLabel($row);
    $dateLabel  = formatSystemDateTime($checkedInAt, $pdo);
    $methodLabel = match ($method) {
        'admin' => 'Admin desk',
        'scan'  => 'QR scan',
        default => 'Self sign-in',
    };

    $subject = $siteName . ' — Check-in confirmed';
    $bodyLines = [
        'Dear ' . $row['first_name'] . ',',
        '',
        'You have successfully checked in.',
        '',
        'Event: ' . $event,
        'Role: ' . $role,
        'Reporting time: ' . $times,
        'Venue: ' . $location,
        'Checked in at: ' . $dateLabel,
        'Method: ' . $methodLabel,
    ];

    $onSite = formatEmailOnSiteSecurityLine($pdo, $row);
    if ($onSite !== null) {
        $bodyLines[] = $onSite;
    }

    $bodyLines[] = '';
    $bodyLines[] = 'Thank you for signing in. If this was not you, contact us immediately using the website contact details.';
    $bodyLines = appendEmailPortalContext($pdo, $bodyLines);
    $bodyLines[] = '';
    $bodyLines[] = 'Regards,';
    $bodyLines[] = $siteName;

    $body = implode("\n", $bodyLines);

    return sendEmail($pdo, (string) $row['email'], $subject, $body);
}

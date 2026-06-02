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
    if (!isNotifyStaffEnabled($pdo)) {
        return false;
    }

    if (!in_array($newStatus, ['approved', 'rejected'], true)) {
        return false;
    }

    if ($newStatus === 'approved') {
        return sendEventAccessPassEmail($pdo, $registrationId);
    }

    $row = getStaffRegistrationById($pdo, $registrationId);
    if (!$row) {
        return false;
    }

    $siteName = getSiteName($pdo);
    $event    = formatEventLabel($row);
    $role     = formatRoleLabel($row['staff_role']);
    $status   = formatStatusLabel($newStatus);
    $subject  = $siteName . ' — Registration Update';
    $intro    = 'Thank you for your interest. Your staff registration was not approved at this time.';

    $bodyLines = [
        'Dear ' . $row['first_name'] . ',',
        '',
        $intro,
        '',
        'Event: ' . $event,
        'Role: ' . $role,
        'Status: ' . $status,
    ];

    $onSite = formatEmailOnSiteSecurityLine($pdo, $row);
    if ($onSite !== null) {
        $bodyLines[] = $onSite;
    }

    $statusToken = ensureStatusToken($pdo, $registrationId);
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

    $body = implode("\n", $bodyLines);

    return sendEmail($pdo, $row['email'], $subject, $body);
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
    $bodyLines[] = 'You will receive another email when your application is reviewed.';
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

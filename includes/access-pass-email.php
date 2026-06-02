<?php

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/events-repository.php';
require_once __DIR__ . '/attendance-repository.php';
require_once __DIR__ . '/staff-pass.php';
require_once __DIR__ . '/maps.php';
require_once __DIR__ . '/signin-display.php';
require_once __DIR__ . '/site-urls.php';
require_once __DIR__ . '/email-copy.php';

/**
 * @param array<string, mixed> $row Registration row with event fields joined
 * @return array{subject: string, text: string, html: string}
 */
function buildEventAccessPassEmail(PDO $pdo, array $row): array
{
    $siteName  = getSiteName($pdo);
    $regId     = (int) $row['id'];
    $eventDate = (string) ($row['event_date'] ?? '');
    $passId    = formatStaffPassId($regId, $eventDate);
    $eventName = (string) ($row['event_name'] ?? 'Event');
    $dateLabel = $eventDate !== '' ? formatEventDateLabel($eventDate) : '';
    $times     = formatEventTimeRangeLabel($row);
    $location  = formatEventLocationLabel($row);
    $reporting = formatEventReportingLabel($row);
    $role      = formatRoleLabel($row['staff_role']);
    $firstName = (string) ($row['first_name'] ?? '');

    $token      = ensureCheckinToken($pdo, $regId);
    $checkinUrl = $token ? getCheckinUrl($token, $pdo) : '';
    $qrImageUrl = $checkinUrl !== '' ? getQrCodeImageUrl($checkinUrl, 280) : '';
    $mapsUrl    = buildGoogleMapsLink(
        normalizeCoordinate(isset($row['venue_lat']) ? (string) $row['venue_lat'] : null),
        normalizeCoordinate(isset($row['venue_lng']) ? (string) $row['venue_lng'] : null)
    );

    $subject = $siteName . ' — Registration confirmed — ' . $eventName
        . ($dateLabel !== '' ? ' ' . $dateLabel : '');

    $textLines = [
        'Dear ' . $firstName . ',',
        '',
        'Your application for this shift has been approved. Use the check-in details below on event day.',
        '',
        'Pass ID: ' . $passId,
        'Event: ' . formatEventLabel($row),
        'Reporting time: ' . $times,
        'Venue: ' . $location,
    ];
    if ($reporting !== '') {
        $textLines[] = 'Reporting point: ' . $reporting;
    }
    $textLines[] = 'Role: ' . $role;
    $onSite = formatEmailOnSiteSecurityLine($pdo, $row);
    if ($onSite !== null) {
        $textLines[] = $onSite;
    }
    if ($checkinUrl !== '') {
        $textLines[] = '';
        $textLines[] = 'Check-in link (scan QR or open on event day):';
        $textLines[] = $checkinUrl;
    }
    if ($mapsUrl !== '') {
        $textLines[] = '';
        $textLines[] = 'Venue map: ' . $mapsUrl;
    }
    $textLines[] = '';
    $textLines[] = 'Bring this email or the QR code on event day. Check-in opens 1 hour before start.';
    $textLines = appendEmailPortalContext($pdo, $textLines);
    $textLines[] = '';
    $textLines[] = 'Regards,';
    $textLines[] = $siteName;

    $text = implode("\n", array_filter($textLines, static fn ($line) => $line !== null));

    $esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    $html = '<!DOCTYPE html><html><body style="font-family:Segoe UI,Arial,sans-serif;line-height:1.55;color:#111;max-width:560px;margin:0 auto;padding:16px;">'
        . '<div style="border:2px solid #2563eb;border-radius:12px;padding:20px;background:#f8fafc;">'
        . '<p style="margin:0 0 4px;font-size:12px;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;">' . $esc($siteName) . '</p>'
        . '<h1 style="margin:0 0 12px;font-size:22px;color:#1e40af;">Registration confirmed</h1>'
        . '<p style="margin:0 0 8px;font-size:12px;color:#64748b;">' . $esc(getPortalLegalNotice($pdo)) . '</p>'
        . '<p style="margin:0 0 16px;">Dear ' . $esc($firstName) . ', your application for this shift is <strong>approved</strong>. Check-in details are below.</p>'
        . '<table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:16px;">'
        . '<tr><td style="padding:6px 0;color:#64748b;width:130px;">Pass ID</td><td style="padding:6px 0;font-weight:700;font-family:monospace;">' . $esc($passId) . '</td></tr>'
        . '<tr><td style="padding:6px 0;color:#64748b;">Event</td><td style="padding:6px 0;">' . $esc($eventName) . ($dateLabel !== '' ? ' · ' . $esc($dateLabel) : '') . '</td></tr>';

    $employer = formatEventMainSecurityLabel($row);
    if ($employer !== '') {
        $html .= '<tr><td style="padding:6px 0;color:#64748b;">Listed contractor (info)</td><td style="padding:6px 0;font-weight:700;">' . $esc($employer) . '</td></tr>';
    }

    $html .= '<tr><td style="padding:6px 0;color:#64748b;">Reporting time</td><td style="padding:6px 0;">' . $esc($times) . '</td></tr>'
        . '<tr><td style="padding:6px 0;color:#64748b;">Venue</td><td style="padding:6px 0;">' . $esc($location) . '</td></tr>';

    if ($reporting !== '') {
        $html .= '<tr><td style="padding:6px 0;color:#64748b;">Reporting point</td><td style="padding:6px 0;">' . $esc($reporting) . '</td></tr>';
    }

    $html .= '<tr><td style="padding:6px 0;color:#64748b;">Role</td><td style="padding:6px 0;">' . $esc($role) . '</td></tr>'
        . '</table>';

    if ($qrImageUrl !== '') {
        $html .= '<div style="text-align:center;margin:16px 0;">'
            . '<img src="' . $esc($qrImageUrl) . '" width="280" height="280" alt="Check-in QR code" style="display:block;margin:0 auto;border:1px solid #e2e8f0;border-radius:8px;">'
            . '<p style="font-size:12px;color:#64748b;margin:8px 0 0;">Scan at check-in or save this pass to your phone</p>'
            . '</div>'
            . '<p style="text-align:center;margin:0 0 16px;"><a href="' . $esc($checkinUrl) . '" style="color:#2563eb;">Open check-in link</a></p>';
    }

    if ($mapsUrl !== '') {
        $html .= '<p style="margin:0 0 8px;font-size:14px;"><a href="' . $esc($mapsUrl) . '" style="color:#2563eb;">Open venue in Google Maps</a></p>';
    }

    $html .= '<p style="margin:16px 0 0;font-size:13px;color:#475569;">Check-in opens 1 hour before the event starts and closes 1 hour after it ends.</p>'
        . '<p style="margin:12px 0 0;font-size:12px;color:#64748b;">' . $esc(getEmailSenderDisclaimer($pdo)) . '</p>'
        . '<p style="margin:8px 0 0;font-size:11px;color:#94a3b8;">Pass ID is for portal check-in only — not a security licence or employer ID.</p>'
        . '</div>'
        . '<p style="font-size:12px;color:#94a3b8;margin-top:16px;">' . $esc($siteName) . '</p>'
        . '</body></html>';

    return compact('subject', 'text', 'html');
}

function sendEventAccessPassEmail(PDO $pdo, int $registrationId): bool
{
    $row = getStaffRegistrationById($pdo, $registrationId);
    if (!$row || ($row['status'] ?? '') !== 'approved') {
        return false;
    }

    $email = trim((string) ($row['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $pass = buildEventAccessPassEmail($pdo, $row);
    $sent = sendEmail($pdo, $email, $pass['subject'], $pass['text'], $pass['html']);

    if ($sent) {
        require_once __DIR__ . '/pwa-push.php';
        if (isPwaPushEnabled($pdo)) {
            $siteName   = getSiteName($pdo);
            $eventLabel = formatEventLabel($row);
            $token      = ensureCheckinToken($pdo, $registrationId);
            $url        = $token ? getCheckinUrl($token, $pdo) : getRegistrationSiteUrl($pdo) . '/staff-app.php';
            notifyRegistrationPush(
                $pdo,
                $registrationId,
                $siteName . ' — Approved',
                'You are approved for ' . $eventLabel . '. Tap to check in.',
                $url
            );
        }
    }

    return $sent;
}

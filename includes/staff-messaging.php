<?php

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/staff-repository.php';
require_once __DIR__ . '/events-repository.php';
require_once __DIR__ . '/attendance-repository.php';
require_once __DIR__ . '/rich-text.php';

/**
 * @return array{sent: int, failed: int, total: int}
 */
function sendEventStaffBroadcast(PDO $pdo, int $eventId, string $subject, string $htmlMessage, bool $includeSigninLinks = true): array
{
    $event = getEventById($pdo, $eventId);
    if (!$event) {
        return ['sent' => 0, 'failed' => 0, 'total' => 0];
    }

    $subject = trim($subject);
    if ($subject === '') {
        $subject = getSiteName($pdo) . ' — ' . (string) $event['name'];
    }

    $bodyHtml = renderRichText($htmlMessage);
    if ($bodyHtml === '') {
        $bodyHtml = '<p>Please see the details below for your upcoming event assignment.</p>';
    }

    $siteName = getSiteName($pdo);
    $location = formatEventLocationLabel($event);
    $times    = formatEventTimeRangeLabel($event);
    $date     = formatEventDateLabel((string) $event['event_date']);

    $linksHtml = '';
    $linksText = '';
    if ($includeSigninLinks) {
        $signToken = ensureEventSigninToken($pdo, $eventId);
        if ($signToken) {
            $emailUrl = getEventEmailSigninUrl($signToken, $pdo);
            $venueUrl = getEventVenueSigninUrl($signToken, $pdo);
            $linksHtml = '<p><strong>Sign-in links</strong></p>'
                . '<p>Email sign-in (works remotely during check-in hours):<br><a href="' . htmlspecialchars($emailUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($emailUrl, ENT_QUOTES, 'UTF-8') . '</a></p>'
                . '<p>Venue QR sign-in (GPS required at the venue):<br><a href="' . htmlspecialchars($venueUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($venueUrl, ENT_QUOTES, 'UTF-8') . '</a></p>';
            $linksText = "\n\nSign-in links\n"
                . "Email sign-in: {$emailUrl}\n"
                . "Venue sign-in: {$venueUrl}\n";
        }
    }

    $eventBlockHtml = '<p><strong>Event:</strong> ' . htmlspecialchars((string) $event['name'], ENT_QUOTES, 'UTF-8') . '<br>'
        . '<strong>Date:</strong> ' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . '<br>'
        . '<strong>Time:</strong> ' . htmlspecialchars($times, ENT_QUOTES, 'UTF-8') . '<br>'
        . '<strong>Location:</strong> ' . htmlspecialchars($location, ENT_QUOTES, 'UTF-8') . '</p>';

    $eventBlockText = "Event: {$event['name']}\nDate: {$date}\nTime: {$times}\nLocation: {$location}\n";

    $staffRows = getApprovedStaffForEvent($pdo, $eventId);
    $sent      = 0;
    $failed    = 0;

    foreach ($staffRows as $row) {
        $greetingHtml = '<p>Dear ' . htmlspecialchars((string) $row['first_name'], ENT_QUOTES, 'UTF-8') . ',</p>';
        $greetingText = 'Dear ' . $row['first_name'] . ",\n\n";

        $personalLinksHtml = '';
        $personalLinksText = '';
        $token = ensureCheckinToken($pdo, (int) $row['id']);
        if ($token) {
            $checkinUrl = getCheckinUrl($token, $pdo);
            $personalLinksHtml = '<p><strong>Your personal check-in link:</strong><br><a href="' . htmlspecialchars($checkinUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($checkinUrl, ENT_QUOTES, 'UTF-8') . '</a></p>';
            $personalLinksText = "\nYour personal check-in link:\n{$checkinUrl}\n";
        }

        $html = $greetingHtml . $bodyHtml . $eventBlockHtml . $linksHtml . $personalLinksHtml
            . '<p>— ' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '</p>';

        $text = $greetingText . plainTextFromRich($htmlMessage, 5000) . "\n\n" . $eventBlockText . $linksText . $personalLinksText
            . "\n— {$siteName}\n";

        if (sendEmail($pdo, (string) $row['email'], $subject, $text, $html)) {
            $sent++;
        } else {
            $failed++;
        }
    }

    return ['sent' => $sent, 'failed' => $failed, 'total' => count($staffRows)];
}

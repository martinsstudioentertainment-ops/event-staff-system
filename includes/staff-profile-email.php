<?php

/**
 * Staff profile update link emails (not approval / verification emails).
 */

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/staff-onboarding.php';
require_once __DIR__ . '/site-urls.php';
require_once __DIR__ . '/email-copy.php';

/**
 * Send profile portal link only (no registration approval email).
 */
function sendStaffProfileUpdateLinkEmail(PDO $pdo, int $staffId): bool
{
    $staff = getStaffById($pdo, $staffId);
    if ($staff === null) {
        return false;
    }

    $email = trim((string) ($staff['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $profileUrl = getStaffProfileUrl($pdo, $staffId);
    $portalUrl  = getStaffPortalUrl($pdo);
    $siteName   = getSiteName($pdo);
    $firstName  = trim((string) ($staff['first_name'] ?? ''));

    $subject = $siteName . ' — complete your staff profile';

    $text = "Dear {$firstName},\n\n"
        . "Please update your staff profile (address, bank details, and PSA licence photos) before your shift can be approved.\n\n"
        . "Option 1 — sign in with email and date of birth:\n{$portalUrl}\n\n"
        . "Option 2 — use your personal link:\n{$profileUrl}\n\n"
        . getEmailSenderDisclaimer($pdo) . "\n\n— {$siteName}\n";

    $esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    $html = '<p>Dear ' . $esc($firstName !== '' ? $firstName : 'staff member') . ',</p>'
        . '<p>Please complete your staff profile (address, bank details, and PSA licence photos) before your shift can be approved.</p>'
        . '<p><strong>Sign in with email and date of birth:</strong><br><a href="' . $esc($portalUrl) . '">' . $esc($portalUrl) . '</a></p>'
        . '<p><strong>Or use your personal link:</strong><br><a href="' . $esc($profileUrl) . '">' . $esc($profileUrl) . '</a></p>'
        . '<p style="font-size:12px;color:#64748b;">' . $esc(getEmailSenderDisclaimer($pdo)) . '</p>'
        . '<p>— ' . $esc($siteName) . '</p>';

    return sendEmail($pdo, $email, $subject, $text, $html);
}

/**
 * @param array<int, int> $staffIds
 * @return array{sent: int, failed: int, skipped: int}
 */
function sendBulkStaffProfileUpdateLinkEmails(PDO $pdo, array $staffIds): array
{
    $sent    = 0;
    $failed  = 0;
    $skipped = 0;

    foreach (array_unique(array_map('intval', $staffIds)) as $staffId) {
        if ($staffId < 1) {
            $skipped++;
            continue;
        }

        if (sendStaffProfileUpdateLinkEmail($pdo, $staffId)) {
            $sent++;
        } else {
            $failed++;
        }
    }

    return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped];
}

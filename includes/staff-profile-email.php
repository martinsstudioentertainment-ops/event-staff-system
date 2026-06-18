<?php

/**
 * Staff profile update link emails (not approval / verification emails).
 */

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/staff-onboarding.php';
require_once __DIR__ . '/site-urls.php';
require_once __DIR__ . '/email-copy.php';
require_once __DIR__ . '/email-layout.php';

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

    $subject = $siteName . ' - complete your staff profile';

    $text = "Dear {$firstName},\n\n"
        . "Please update your staff profile (address, bank details, and PSA licence photos) before your shift can be approved.\n\n"
        . "Option 1 - sign in with email and date of birth:\n{$portalUrl}\n\n"
        . "Option 2 - use your personal link:\n{$profileUrl}\n\n"
        . getEmailShortFooter($pdo) . "\n\n- {$siteName}\n";

    $html = '<p style="margin:0 0 12px;">Dear ' . emailEsc($firstName !== '' ? $firstName : 'staff member') . ',</p>'
        . buildEmailNotificationCard(
            $pdo,
            'Complete your staff profile',
            '<p style="margin:0;">Please complete your staff profile (address, bank details, and PSA licence photos) before your shift can be approved.</p>',
            $portalUrl,
            'Sign in to staff portal'
        )
        . '<p style="margin:12px 0 0;font-size:14px;">Or use your personal link:<br><a href="' . emailEsc($profileUrl) . '" style="color:#2563eb;">' . emailEsc($profileUrl) . '</a></p>';

    return sendEmail($pdo, $email, $subject, $text, $html);
}

/**
 * Email when an admin cancels verification and staff must update their profile again.
 */
function sendStaffProfileReverifyEmail(PDO $pdo, int $staffId): bool
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

    $subject = $siteName . ' - please update your staff profile again';

    $text = "Dear {$firstName},\n\n"
        . "Your coordinator has asked you to confirm your staff profile again (address, bank details, and PSA licence photos).\n\n"
        . "Sign in with your email and date of birth:\n{$portalUrl}\n\n"
        . "Or use your personal link:\n{$profileUrl}\n\n"
        . getEmailShortFooter($pdo) . "\n\n- {$siteName}\n";

    $html = '<p style="margin:0 0 12px;">Dear ' . emailEsc($firstName !== '' ? $firstName : 'staff member') . ',</p>'
        . buildEmailNotificationCard(
            $pdo,
            'Profile update required',
            '<p style="margin:0;">Your coordinator has asked you to <strong>confirm your staff profile again</strong> - address, bank details, and PSA licence photos.</p>',
            $portalUrl,
            'Update my profile'
        )
        . '<p style="margin:12px 0 0;font-size:14px;">Or use your personal link:<br><a href="' . emailEsc($profileUrl) . '" style="color:#2563eb;">' . emailEsc($profileUrl) . '</a></p>';

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

<?php

declare(strict_types=1);

/**
 * Central branding and pay-rate display for all outbound emails.
 */

require_once __DIR__ . '/settings-repository.php';
require_once __DIR__ . '/brand-logo.php';
require_once __DIR__ . '/site-urls.php';

const EMAIL_DEFAULT_HOURLY_RATE = 15.41;

const EMAIL_BANNER_RELATIVE_PATH = 'storage/branding/olasentra-email-banner.png';

/**
 * Email hero banner — dedicated asset first, then admin WhatsApp/share image setting.
 */
function getEmailBannerRelativePath(PDO $pdo): string
{
    $candidates = [
        EMAIL_BANNER_RELATIVE_PATH,
        getCompanyShareImageRelativePath($pdo),
    ];

    foreach ($candidates as $relative) {
        $relative = trim($relative);
        if ($relative === '') {
            continue;
        }
        $full = dirname(__DIR__) . '/' . str_replace(['\\', '..'], ['/', ''], $relative);
        if (is_file($full)) {
            return $relative;
        }
    }

    return '';
}

/**
 * @return array{
 *     company_name: string,
 *     site_name: string,
 *     tagline: string,
 *     logo_url: string,
 *     event_staff_label: string,
 *     banner_url: string,
 *     primary_color: string,
 *     secondary_color: string,
 *     button_color: string,
 *     text_color: string,
 *     muted_color: string,
 *     card_bg: string,
 *     website_url: string,
 *     registration_url: string,
 *     admin_url: string,
 *     support_email: string,
 *     support_phone: string,
 *     privacy_url: string,
 *     terms_url: string,
 *     copyright: string,
 *     social_links: array<string, string>
 * }
 */
function getEmailBranding(PDO $pdo): array
{
    $siteName    = getSiteName($pdo);
    $companyName = trim(getSetting($pdo, 'company_name', ''));
    if ($companyName === '') {
        $companyName = 'Olasentra';
    }

    $baseUrl = getRegistrationSiteUrl($pdo);
    if ($baseUrl === '') {
        $baseUrl = getMarketingSiteUrl($pdo);
    }
    if ($baseUrl === '') {
        $baseUrl = 'https://register.olasentra.com';
    }

    $marketingUrl = getMarketingSiteUrl($pdo);
    if ($marketingUrl === '') {
        $marketingUrl = $baseUrl;
    }

    $logoUrl = getCompanyLogoAbsoluteUrl($pdo);

    $shareRelative = getEmailBannerRelativePath($pdo);
    $bannerUrl     = $shareRelative !== '' ? $baseUrl . '/' . ltrim($shareRelative, '/') : '';

    $primary = trim(getSetting($pdo, 'email_primary_color', ''));
    if ($primary === '' || !preg_match('/^#[0-9a-fA-F]{3,8}$/', $primary)) {
        $primary = '#F48221';
    }

    $secondary = trim(getSetting($pdo, 'email_secondary_color', ''));
    if ($secondary === '' || !preg_match('/^#[0-9a-fA-F]{3,8}$/', $secondary)) {
        $secondary = '#E35205';
    }

    $button = trim(getSetting($pdo, 'email_button_color', ''));
    if ($button === '' || !preg_match('/^#[0-9a-fA-F]{3,8}$/', $button)) {
        $button = '#F48221';
    }

    $supportEmail = trim(getSetting($pdo, 'company_email', ''));
    if ($supportEmail === '' || !filter_var($supportEmail, FILTER_VALIDATE_EMAIL)) {
        $supportEmail = trim(getSetting($pdo, 'mail_from_email', 'info@olasentra.com'));
    }

    $social = [];
    foreach ([
        'facebook'  => 'company_social_facebook',
        'linkedin'  => 'company_social_linkedin',
        'instagram' => 'company_social_instagram',
        'twitter'   => 'company_social_twitter',
    ] as $key => $settingKey) {
        $url = trim(getSetting($pdo, $settingKey, ''));
        if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
            $social[$key] = $url;
        }
    }

    $year = date('Y');

    return [
        'company_name'       => $companyName,
        'site_name'          => $siteName,
        'tagline'            => trim(getSetting($pdo, 'company_tagline', 'Event Staff registration portal')),
        'logo_url'           => $logoUrl,
        'event_staff_label'  => $siteName,
        'banner_url'         => $bannerUrl,
        'primary_color'      => $primary,
        'secondary_color'    => $secondary,
        'button_color'       => $button,
        'text_color'         => '#111827',
        'muted_color'        => '#64748b',
        'card_bg'            => '#f8fafc',
        'website_url'        => $marketingUrl,
        'registration_url'   => $baseUrl,
        'admin_url'          => getAdminSiteUrl($pdo),
        'support_email'      => $supportEmail,
        'support_phone'      => trim(getSetting($pdo, 'company_phone', '')),
        'privacy_url'        => $marketingUrl . '/privacy.php',
        'terms_url'          => $marketingUrl . '/terms.php',
        'copyright'          => '© ' . $year . ' ' . $companyName . '. All rights reserved.',
        'social_links'       => $social,
    ];
}

function getEmailDisplayHourlyRate(PDO $pdo, ?array $eventOrRow = null): float
{
    if ($eventOrRow !== null) {
        foreach (['staff_hourly_rate', 'event_hourly_rate', 'hourly_rate'] as $key) {
            if (isset($eventOrRow[$key]) && $eventOrRow[$key] !== '' && $eventOrRow[$key] !== null) {
                $custom = round((float) $eventOrRow[$key], 2);
                if ($custom > 0) {
                    return $custom;
                }
            }
        }
    }

    $configured = trim(getSetting($pdo, 'staff_display_hourly_rate', ''));
    if ($configured !== '' && is_numeric($configured)) {
        $rate = round((float) $configured, 2);
        if ($rate > 0) {
            return $rate;
        }
    }

    return EMAIL_DEFAULT_HOURLY_RATE;
}

function isEmailPayRateVisible(PDO $pdo): bool
{
    return getSetting($pdo, 'email_show_pay_rate', '1') === '1';
}

function formatEmailPayRateLabel(PDO $pdo, ?array $eventOrRow = null): string
{
    $rate = getEmailDisplayHourlyRate($pdo, $eventOrRow);

    return '€' . number_format($rate, 2) . ' per hour';
}

/**
 * Pay rate for staff emails only — empty when disabled in Settings → Email.
 */
function formatEmailPayRateLabelOptional(PDO $pdo, ?array $eventOrRow = null): string
{
    if (!isEmailPayRateVisible($pdo)) {
        return '';
    }

    return formatEmailPayRateLabel($pdo, $eventOrRow);
}

function formatEmailPayRateShort(PDO $pdo, ?array $eventOrRow = null): string
{
    $rate = getEmailDisplayHourlyRate($pdo, $eventOrRow);

    return '€' . number_format($rate, 2) . '/hour';
}

/**
 * Scheduled shift length in hours from event start/end times.
 */
function getEmailEventShiftHours(array $event): float
{
    $start = trim((string) ($event['start_time'] ?? $event['event_start_time'] ?? ''));
    $end   = trim((string) ($event['end_time'] ?? $event['event_end_time'] ?? ''));
    if ($start === '' || $end === '') {
        return 0.0;
    }

    try {
        $s = new DateTime('2000-01-01 ' . $start);
        $e = new DateTime('2000-01-01 ' . $end);
        if ($e <= $s) {
            $e->modify('+1 day');
        }
        $diff = $s->diff($e);

        return round($diff->h + ($diff->i / 60) + ($diff->s / 3600), 1);
    } catch (Throwable $e) {
        return 0.0;
    }
}

function formatEmailShiftHoursLabel(array $event): string
{
    $hours = getEmailEventShiftHours($event);
    if ($hours <= 0) {
        return '';
    }

    $label = number_format($hours, $hours === (float) (int) $hours ? 0 : 1);

    return $label . ' Hour' . ($hours === 1.0 ? '' : 's');
}

/**
 * @return array<string, mixed>
 */
function buildEmailJobCardDataFromEvent(PDO $pdo, array $event, string $applyUrl = ''): array
{
    require_once __DIR__ . '/email-copy.php';
    require_once __DIR__ . '/events-repository.php';
    require_once __DIR__ . '/venues-repository.php';

    $eventId   = (int) ($event['id'] ?? 0);
    $eventName = trim((string) ($event['name'] ?? $event['event_name'] ?? 'Event'));
    $dateLabel = formatEventDateLabel((string) ($event['event_date'] ?? ''));
    $location  = formatEventLocationLabelForEmail($event);
    $times     = formatEventTimeRangeLabelForEmail($event);
    $start     = trim((string) ($event['start_time'] ?? ''));
    $end       = trim((string) ($event['end_time'] ?? ''));

    $rolesDisplay = formatEventRolesNeededDisplay($event);
    $jobTitle     = $rolesDisplay !== '' ? $rolesDisplay : 'Security Officer';

    $employer = trim((string) ($event['main_security_company'] ?? ''));
    if ($employer === '') {
        $employer = trim(getSetting($pdo, 'company_name', 'Olasentra'));
    }

    $needed     = (int) ($event['staff_needed'] ?? 0);
    $vacancies  = 'Open';
    if ($needed > 0 && $eventId > 0) {
        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM staff_registrations WHERE event_id = :eid AND status = 'approved'"
            );
            $stmt->execute(['eid' => $eventId]);
            $approved = (int) $stmt->fetchColumn();
            $spaces   = max(0, $needed - $approved);
            if ($spaces > 0) {
                $vacancies = $spaces . ' vacanc' . ($spaces === 1 ? 'y' : 'ies');
            } else {
                $vacancies = 'Full';
            }
        } catch (Throwable $e) {
            $vacancies = (string) $needed . ' needed';
        }
    }

    if ($applyUrl === '') {
        $applyUrl = getRegistrationFormUrl($pdo);
    }

    return [
        'job_title'   => $jobTitle,
        'event_name'  => $eventName,
        'employer'    => $employer,
        'location'    => $location,
        'date'        => $dateLabel,
        'start_time'  => $start,
        'end_time'    => $end,
        'time_range'  => $times,
        'hours'       => formatEmailShiftHoursLabel($event),
        'pay_rate'    => formatEmailPayRateLabelOptional($pdo, $event),
        'vacancies'   => $vacancies,
        'apply_url'   => $applyUrl,
        'apply_label' => 'Apply Now',
    ];
}
